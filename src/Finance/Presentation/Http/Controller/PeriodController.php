<?php

declare(strict_types=1);

namespace Finance\Presentation\Http\Controller;

use Finance\Domain\Enum\PeriodStatus;
use Finance\Infrastructure\Persistence\Eloquent\AccountingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shared\Domain\Contract\AuditTrail;
use Shared\Infrastructure\Validation\TenantRule;

final class PeriodController
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['company_id' => ['required', 'uuid', TenantRule::exists('companies')]]);

        return new JsonResponse(['data' => AccountingPeriod::query()->where('company_id', $q['company_id'])->orderBy('start_date')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'name' => ['required', 'string', 'max:60'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $overlaps = AccountingPeriod::query()
            ->where('company_id', $data['company_id'])
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->exists();

        if ($overlaps) {
            throw new \DomainException('This period overlaps an existing accounting period.');
        }

        return new JsonResponse(['data' => AccountingPeriod::query()->create($data + ['status' => PeriodStatus::Open])], 201);
    }

    public function close(Request $request, string $periodId, AuditTrail $audit): JsonResponse
    {
        return $this->transition($request, $periodId, PeriodStatus::Open, PeriodStatus::Closed, $audit);
    }

    public function reopen(Request $request, string $periodId, AuditTrail $audit): JsonResponse
    {
        return $this->transition($request, $periodId, PeriodStatus::Closed, PeriodStatus::Open, $audit);
    }

    private function transition(Request $request, string $periodId, PeriodStatus $from, PeriodStatus $to, AuditTrail $audit): JsonResponse
    {
        $period = AccountingPeriod::findOrFail($periodId);

        if ($period->status !== $from) {
            throw new \DomainException("Period '{$period->name}' is {$period->status->value}; it must be {$from->value} for this action.");
        }

        $closing = $to === PeriodStatus::Closed;
        $period->update([
            'status' => $to,
            'closed_at' => $closing ? now() : null,
            'closed_by' => $closing ? $request->user()->id : null,
        ]);

        $audit->record('finance.period.'.$to->value, 'accounting_period', $period->id, ['status' => $from->value], ['status' => $to->value]);

        return new JsonResponse(['data' => $period->fresh()]);
    }
}
