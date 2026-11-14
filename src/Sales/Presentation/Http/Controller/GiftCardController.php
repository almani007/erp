<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Sales\Application\GiftCards;
use Sales\Infrastructure\Persistence\Eloquent\GiftCard;
use Shared\Infrastructure\Validation\TenantRule;

final class GiftCardController
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:active,blocked,used,expired']])['status'] ?? null;

        return new JsonResponse(['data' => GiftCard::query()->with('customer:id,name,customer_code')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->limit(5000)->get()]);
    }

    /** Balance check at the till — by card number. */
    public function lookup(string $code): JsonResponse
    {
        $card = GiftCard::query()->where('code', GiftCards::normalise($code))->first();
        abort_if($card === null, 404, 'No gift card with that number.');

        return new JsonResponse(['data' => $card->only(['id', 'code', 'kind', 'balance', 'status', 'expires_on', 'customer_id'])]);
    }

    public function show(string $id): JsonResponse
    {
        $card = GiftCard::query()->with('customer:id,name,customer_code,phone')->findOrFail($id);
        $history = DB::table('gift_card_transactions')->where('gift_card_id', $card->id)->orderBy('created_at')->get();

        return new JsonResponse(['data' => $card->toArray() + ['history' => $history]]);
    }

    public function store(Request $request, GiftCards $cards, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', TenantRule::exists('companies')],
            'kind' => ['required', 'in:gift_card,voucher'],
            'value' => ['required', 'numeric', 'gt:0', 'max:10000000', 'decimal:0,2'],
            'paid_by' => ['required', Rule::in(GiftCards::PAID_BY)],
            'customer_id' => ['nullable', 'uuid', TenantRule::exists('customers')],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'code' => ['nullable', 'string', 'min:6', 'max:40'],
            'note' => ['nullable', 'string', 'max:300'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:500'], // a batch of identical vouchers
        ]);
        // Promotional value costs the business money: only managers may give it away.
        if ($data['paid_by'] === 'promotion') {
            abort_unless($request->user()->can('sales.giftcard.promote'), 403, 'You may not issue promotional vouchers.');
        }
        $quantity = (int) ($data['quantity'] ?? 1);
        if ($quantity > 1 && ! empty($data['code'])) {
            abort(422, 'Leave the card number empty to issue a batch; numbers are generated.');
        }

        $data['company_id'] ??= Company::query()->orderBy('created_at')->value('id');
        $data['branch_id'] = $branches->activeBranchId();
        $data['value'] = (string) $data['value'];

        $issued = DB::transaction(function () use ($cards, $data, $quantity): array {
            $out = [];
            for ($i = 0; $i < $quantity; $i++) {
                $out[] = $cards->issue($data);
            }

            return $out;
        });

        return new JsonResponse(['data' => $quantity === 1 ? $issued[0] : $issued], 201);
    }

    public function block(Request $request, string $id, GiftCards $cards): JsonResponse
    {
        $data = $request->validate(['blocked' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);

        return new JsonResponse(['data' => $cards->setBlocked($id, (bool) $data['blocked'], $data['reason'])]);
    }
}
