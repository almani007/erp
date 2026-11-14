<?php

declare(strict_types=1);

namespace Storefront\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shared\Domain\Contract\Settings;
use Storefront\Application\Storefront;

final class StorefrontController
{
    public function show(string $slug, Storefront $store): JsonResponse
    {
        return new JsonResponse(['data' => $store->catalogue($store->open($slug))]);
    }

    public function order(Request $request, string $slug, Storefront $store): JsonResponse
    {
        $s = $store->open($slug);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s]{10,16}$/'],
            'fulfilment' => ['required', 'in:delivery,pickup'],
            'address' => ['required_if:fulfilment,delivery', 'nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:300'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999'],
            'website' => ['prohibited'], // honeypot: bots fill every field
        ]);

        return new JsonResponse(['data' => $store->placeOrder($s, $data)], 201);
    }

    public function slots(Request $request, string $slug, Storefront $store): JsonResponse
    {
        $s = $store->open($slug);
        $q = $request->validate(['product_id' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before:+60 days']]);

        return new JsonResponse(['data' => $store->slots($s, $q['product_id'], $q['date'])]);
    }

    public function book(Request $request, string $slug, Storefront $store): JsonResponse
    {
        $s = $store->open($slug);
        $data = $request->validate([
            'product_id' => ['required', 'uuid'], 'resource_id' => ['required', 'uuid'], 'starts_at' => ['required', 'date', 'after:now'],
            'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'regex:/^[0-9+\-\s]{10,16}$/'],
            'notes' => ['nullable', 'string', 'max:300'], 'website' => ['prohibited'],
        ]);

        return new JsonResponse(['data' => $store->book($s, $data)], 201);
    }

    // ── Settings (signed-in staff) ───────────────────────────────

    public function settings(Settings $settings): JsonResponse
    {
        $out = [];
        foreach (['enabled', 'branch_id', 'warehouse_id', 'title', 'about', 'whatsapp', 'min_order', 'delivery_fee_note', 'booking_enabled'] as $k) {
            $out[$k] = $settings->get('storefront', $k);
        }
        $out['slug'] = \Illuminate\Support\Facades\DB::table('tenants')->where('id', app(\Shared\Domain\Contract\TenantContext::class)->requireId())->value('slug');

        return new JsonResponse(['data' => $out]);
    }

    public function saveSettings(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'booking_enabled' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'uuid'], 'warehouse_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:120'], 'about' => ['nullable', 'string', 'max:1000'],
            'whatsapp' => ['nullable', 'string', 'max:20'], 'min_order' => ['nullable', 'numeric', 'min:0'],
            'delivery_fee_note' => ['nullable', 'string', 'max:200'],
        ]);
        foreach ($data as $k => $v) {
            $settings->set('storefront', $k, $v);
        }

        return new JsonResponse(['data' => ['saved' => true]]);
    }
}
