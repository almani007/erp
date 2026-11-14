<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Organization\Domain\Contract\BranchContext;
use Organization\Domain\Exception\BranchAccessDenied;
use Organization\Infrastructure\Persistence\Eloquent\Warehouse;
use Sales\Application\LoyaltyProgram;
use Sales\Application\ManageShift;
use Sales\Infrastructure\Persistence\Eloquent\PosShift;
use Sales\Infrastructure\Persistence\Eloquent\PosTerminal;
use Shared\Domain\Contract\Settings;
use Shared\Infrastructure\Validation\TenantRule;

final class ShiftController
{
    // ── Terminals ────────────────────────────────────────────────

    public function terminals(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = PosTerminal::query()
            ->with('branch:id,name')
            ->where('status', 'active')
            ->when($allowed !== null, fn ($b) => $b->whereIn('branch_id', $allowed))
            ->orderBy('name')
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function storeTerminal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', TenantRule::exists('warehouses')],
            'code' => ['required', 'string', 'max:30', 'alpha_dash'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        // The branch comes from the warehouse, so a terminal can only sell its own branch's stock.
        $data['branch_id'] = Warehouse::findOrFail($data['warehouse_id'])->branch_id;

        $request->validate(['code' => [Rule::unique('pos_terminals', 'code')->where('branch_id', $data['branch_id'])]]);

        return new JsonResponse(['data' => PosTerminal::query()->create($data + ['status' => 'active'])], 201);
    }

    /** Settings the screens adapt to: the kind of business and the loyalty rates. */
    public function config(LoyaltyProgram $loyalty, Settings $settings): JsonResponse
    {
        return new JsonResponse(['data' => [
            'business_type' => (string) ($settings->get('business', 'type') ?? 'general'),
            // Per-feature switches that override the business type's defaults.
            'features' => (object) ((array) ($settings->get('business', 'features') ?? [])),
            'loyalty' => $loyalty->config(),
            // What the plan includes; the menu hides switched-off modules and features.
            'entitlements' => app(\Saas\Application\Entitlements::class)->summary(),
            // Letterhead for printed quotations, invoices and statements.
            'company' => \Organization\Infrastructure\Persistence\Eloquent\Company::query()->orderBy('created_at')
                ->first(['id', 'legal_name', 'tax_registration_no', 'base_currency']),
            'print' => [
                'invoice_terms' => $settings->get('sales', 'invoice_terms'),
                'quotation_terms' => $settings->get('sales', 'quotation_terms'),
            ],
        ]]);
    }

    // ── Shifts ───────────────────────────────────────────────────

    /** The caller's open shift with its running totals, or null. */
    public function current(Request $request, ManageShift $shifts): JsonResponse
    {
        $shift = $this->openShift($request);

        return new JsonResponse(['data' => $shift === null ? null : $this->present($shift, $shifts)]);
    }

    public function open(Request $request, ManageShift $shifts, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'terminal_id' => ['required', 'uuid'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $terminal = PosTerminal::query()->where('status', 'active')->findOrFail($data['terminal_id']);

        if (! $branches->canAccess($terminal->branch_id)) {
            throw BranchAccessDenied::forBranch($terminal->branch_id);
        }

        $shift = $shifts->open($terminal, $request->user()->id, (string) $data['opening_cash']);

        return new JsonResponse(['data' => $this->present($shift, $shifts)], 201);
    }

    public function cashMovement(Request $request, ManageShift $shifts): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:paid_in,paid_out'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $shift = $this->openShift($request) ?? throw \Sales\Domain\Exception\ShiftNotOpen::forCashier();

        $shifts->recordCashMovement($shift, $data['type'], (string) $data['amount'], $data['reason'], $request->user()->id);

        return new JsonResponse(['data' => $this->present($shift, $shifts)], 201);
    }

    public function close(Request $request, ManageShift $shifts): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = $this->openShift($request) ?? throw \Sales\Domain\Exception\ShiftNotOpen::forCashier();
        $closed = $shifts->close($shift, (string) $data['counted_cash'], $data['notes'] ?? null);

        return new JsonResponse(['data' => $this->present($closed, $shifts)]);
    }

    public function index(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        $rows = PosShift::query()
            ->with('terminal:id,name,code')
            ->when($allowed !== null, fn ($b) => $b->whereIn('branch_id', $allowed))
            ->orderByDesc('opened_at')
            ->limit(200)
            ->get();

        return new JsonResponse(['data' => $rows]);
    }

    private function openShift(Request $request): ?PosShift
    {
        return PosShift::query()->with('terminal:id,name,code,warehouse_id,branch_id')
            ->where('cashier_id', $request->user()->id)->where('status', 'open')->first();
    }

    /** @return array<string, mixed> */
    private function present(PosShift $shift, ManageShift $shifts): array
    {
        return $shift->toArray() + ['summary' => $shifts->summary($shift)];
    }
}
