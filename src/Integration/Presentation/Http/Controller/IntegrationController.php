<?php

declare(strict_types=1);

namespace Integration\Presentation\Http\Controller;

use Identity\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Integration\Application\FbrReporter;
use Integration\Application\FbrSettings;
use Integration\Application\ImportData;
use Integration\Application\Messenger;
use Integration\Infrastructure\Persistence\Eloquent\FiscalSubmission;
use Integration\Infrastructure\Persistence\Eloquent\MessageLog;
use Integration\Infrastructure\Persistence\Eloquent\WebhookDelivery;
use Integration\Infrastructure\Persistence\Eloquent\WebhookSubscription;
use Organization\Domain\Contract\BranchContext;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;

final class IntegrationController
{
    // ── FBR ──────────────────────────────────────────────────────

    public function fbrSettings(Request $request, FbrSettings $fbr, BranchContext $branches): JsonResponse
    {
        $branchId = $request->validate(['branch_id' => ['required', 'uuid']])['branch_id'];
        abort_unless($branches->canAccess($branchId), 404);
        $s = $fbr->forBranch($branchId);
        // Tokens are write-only: the screen sees only whether one is set.
        $s['pos_token'] = $s['pos_token'] ? '••••••' : null;
        $s['di_token'] = $s['di_token'] ? '••••••' : null;

        return new JsonResponse(['data' => $s + ['default_urls' => ['pos' => FbrSettings::POS_URLS, 'di' => FbrSettings::DI_URLS]]]);
    }

    public function saveFbrSettings(Request $request, Settings $settings, BranchContext $branches, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'uuid'],
            'pos_enabled' => ['sometimes', 'boolean'], 'pos_environment' => ['sometimes', 'in:sandbox,live'], 'pos_id' => ['nullable', 'string', 'max:40'],
            'pos_token' => ['nullable', 'string', 'max:2000'], 'pos_url' => ['nullable', 'url', 'max:300'],
            'di_enabled' => ['sometimes', 'boolean'], 'di_environment' => ['sometimes', 'in:sandbox,live'], 'di_token' => ['nullable', 'string', 'max:4000'],
            'di_url' => ['nullable', 'url', 'max:300'], 'di_scenario_id' => ['nullable', 'string', 'max:20'],
            'seller_ntn' => ['nullable', 'string', 'max:30'], 'seller_name' => ['nullable', 'string', 'max:200'],
            'seller_province' => ['nullable', 'string', 'max:40'], 'seller_address' => ['nullable', 'string', 'max:300'],
        ]);
        abort_unless($branches->canAccess($data['branch_id']), 404);
        foreach ($data as $key => $value) {
            if ($key === 'branch_id' || (in_array($key, ['pos_token', 'di_token'], true) && ($value === null || $value === '••••••'))) {
                continue; // keep the stored token unless a new one is typed
            }
            $settings->set('fbr', $key, $value, null, $data['branch_id']);
        }
        $audit->record('integration.fbr.configured', 'branch', $data['branch_id'], null, array_diff_key($data, ['pos_token' => 1, 'di_token' => 1]));

        return new JsonResponse(['data' => ['saved' => true]]);
    }

    public function fiscalSubmissions(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:pending,sent,failed']])['status'] ?? null;

        return new JsonResponse(['data' => FiscalSubmission::query()->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit(1000)->get(['id', 'branch_id', 'system', 'document_type', 'document_id', 'document_no', 'status', 'attempts', 'fiscal_invoice_no', 'last_error', 'sent_at', 'created_at'])]);
    }

    /** Reports a sale to FBR now (the till calls this right after the sale, to print the fiscal number). */
    public function submitSale(string $saleId, FbrReporter $fbr): JsonResponse
    {
        Sale::query()->findOrFail($saleId);
        $sub = $fbr->queue('sale', $saleId);

        return new JsonResponse(['data' => $sub ? $fbr->submit($sub)->only(['status', 'fiscal_invoice_no', 'last_error']) : null]);
    }

    public function retrySubmission(string $id, FbrReporter $fbr): JsonResponse
    {
        $sub = FiscalSubmission::query()->findOrFail($id);
        if ($sub->status === 'failed') {
            $sub->update(['status' => 'pending', 'attempts' => 0]);
        }

        return new JsonResponse(['data' => $fbr->submit($sub)]);
    }

    // ── Webhooks ─────────────────────────────────────────────────

    public function webhooks(): JsonResponse
    {
        $stats = WebhookDelivery::query()->where('created_at', '>=', now()->subDays(7))->groupBy('subscription_id', 'status')
            ->selectRaw('subscription_id, status, count(*) as c')->get()->groupBy('subscription_id');

        return new JsonResponse(['data' => WebhookSubscription::query()->orderBy('name')->get()->map(fn (WebhookSubscription $s): array => [
            'id' => $s->id, 'name' => $s->name, 'url' => $s->url, 'events' => $s->events, 'status' => $s->status,
            'secret_hint' => '…'.substr($s->secret, -4),
            'last_7_days' => ($stats[$s->id] ?? collect())->pluck('c', 'status'),
        ])]);
    }

    public function saveWebhook(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:100'],
            'url' => [$id ? 'sometimes' : 'required', 'url:https,http', 'max:500'],
            'events' => [$id ? 'sometimes' : 'required', 'array', 'min:1'],
            'events.*' => ['string', 'max:120'],
            'status' => ['sometimes', 'in:active,paused'],
        ]);
        if ($id === null) {
            $secret = 'whsec_'.Str::random(40);
            $sub = WebhookSubscription::query()->create($data + ['secret' => $secret, 'status' => 'active', 'created_by' => $request->user()->id]);

            // The secret is shown once, to be stored by the receiving system.
            return new JsonResponse(['data' => $sub->only(['id', 'name', 'url', 'events', 'status']) + ['secret' => $secret]], 201);
        }
        $sub = WebhookSubscription::query()->findOrFail($id);
        $sub->update($data);

        return new JsonResponse(['data' => $sub->only(['id', 'name', 'url', 'events', 'status'])]);
    }

    public function deliveries(string $id): JsonResponse
    {
        WebhookSubscription::query()->findOrFail($id);

        return new JsonResponse(['data' => WebhookDelivery::query()->where('subscription_id', $id)->orderByDesc('created_at')->limit(200)->get()]);
    }

    /** Event names subscribers can choose from. */
    public function eventTypes(): JsonResponse
    {
        return new JsonResponse(['data' => DB::table('outbox_events')->where('tenant_id', app(TenantContext::class)->requireId())
            ->distinct()->orderBy('event_type')->pluck('event_type')
            ->merge(['sales.sale.completed', 'sales.sale.returned', 'purchasing.goods.received'])->unique()->sort()->values()]);
    }

    // ── API keys ─────────────────────────────────────────────────

    public function apiClients(): JsonResponse
    {
        $users = User::query()->inCurrentTenant()->where('is_api_client', true)->orderBy('name')->get();
        $tokens = DB::table('personal_access_tokens')->whereIn('tokenable_id', $users->pluck('id'))->groupBy('tokenable_id')
            ->selectRaw('tokenable_id, max(last_used_at) as last_used_at, count(*) as tokens')->get()->keyBy('tokenable_id');

        return new JsonResponse(['data' => $users->map(fn (User $u): array => [
            'id' => $u->id, 'name' => $u->name, 'status' => $u->status, 'roles' => $u->getRoleNames(),
            'last_used_at' => $tokens[$u->id]->last_used_at ?? null, 'active_keys' => (int) ($tokens[$u->id]->tokens ?? 0),
        ])]);
    }

    /** A machine login for another system, with the access of one role. The key is shown once. */
    public function createApiClient(Request $request, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'role' => ['required', 'string', 'exists:roles,name', 'not_in:tenant_owner']]);

        $user = new User(['name' => $data['name'], 'email' => 'api+'.Str::lower(Str::random(12)).'@clients.invalid', 'password' => Str::random(40), 'status' => 'active', 'all_branches' => true]);
        $user->tenant_id = app(TenantContext::class)->requireId();
        $user->forceFill(['is_api_client' => true])->save();
        $user->assignRole($data['role']);
        $token = $user->createToken($data['name'])->plainTextToken;
        $audit->record('integration.api_client.created', 'user', $user->id, null, ['name' => $data['name'], 'role' => $data['role']]);

        return new JsonResponse(['data' => ['id' => $user->id, 'name' => $user->name, 'api_key' => $token]], 201);
    }

    public function revokeApiClient(string $id, AuditTrail $audit): JsonResponse
    {
        $user = User::query()->inCurrentTenant()->where('is_api_client', true)->findOrFail($id);
        $user->tokens()->delete();
        $user->update(['status' => 'inactive']);
        $audit->record('integration.api_client.revoked', 'user', $user->id, null, ['name' => $user->name]);

        return new JsonResponse(['data' => ['revoked' => true]]);
    }

    // ── Messaging ────────────────────────────────────────────────

    public function messagingSettings(Settings $settings, Messenger $messenger): JsonResponse
    {
        $keys = ['whatsapp_enabled', 'whatsapp_phone_number_id', 'whatsapp_template', 'whatsapp_template_language', 'sms_enabled', 'sms_url', 'sms_method', 'sms_body',
            'overdue_reminders', 'overdue_reminder_days', 'appointment_reminders', 'receipt_after_sale'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $settings->get('messaging', $k);
        }
        $out['whatsapp_token'] = $settings->get('messaging', 'whatsapp_token') ? '••••••' : null;

        return new JsonResponse(['data' => $out + ['channels' => $messenger->channels(), 'mail_configured' => config('mail.default') !== 'log']]);
    }

    public function saveMessagingSettings(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate([
            'whatsapp_enabled' => ['sometimes', 'boolean'], 'whatsapp_phone_number_id' => ['nullable', 'string', 'max:40'],
            'whatsapp_token' => ['nullable', 'string', 'max:2000'], 'whatsapp_template' => ['nullable', 'string', 'max:100'],
            'whatsapp_template_language' => ['nullable', 'string', 'max:10'],
            'sms_enabled' => ['sometimes', 'boolean'], 'sms_url' => ['nullable', 'string', 'max:500'], 'sms_method' => ['nullable', 'in:GET,POST'],
            'sms_body' => ['nullable', 'string', 'max:1000'],
            'overdue_reminders' => ['sometimes', 'boolean'], 'overdue_reminder_days' => ['nullable', 'string', 'max:50'],
            'appointment_reminders' => ['sometimes', 'boolean'], 'receipt_after_sale' => ['sometimes', 'boolean'],
        ]);
        foreach ($data as $k => $v) {
            if ($k === 'whatsapp_token' && ($v === null || $v === '••••••')) {
                continue;
            }
            $settings->set('messaging', $k, $v);
        }

        return new JsonResponse(['data' => ['saved' => true]]);
    }

    public function send(Request $request, Messenger $messenger): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', 'in:whatsapp,sms,email'],
            'to' => ['required', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
            'related_type' => ['nullable', 'string', 'max:60'],
            'related_id' => ['nullable', 'uuid'],
        ]);
        $log = $messenger->send($data['channel'], $data['to'], $data['body'], $data['subject'] ?? null, $data['related_type'] ?? null, $data['related_id'] ?? null, $request->user()->id);

        return new JsonResponse(['data' => $log], $log->status === 'sent' ? 201 : 422);
    }

    public function messages(): JsonResponse
    {
        return new JsonResponse(['data' => MessageLog::query()->orderByDesc('created_at')->limit(1000)->get()]);
    }

    // ── Sharing and imports ──────────────────────────────────────

    /** A link the customer can open without logging in, valid for 60 days. */
    public function shareSale(string $saleId): JsonResponse
    {
        $sale = Sale::query()->findOrFail($saleId);

        return new JsonResponse(['data' => ['url' => URL::temporarySignedRoute('share.sale', now()->addDays(60), ['id' => $sale->id])]]);
    }

    public function import(Request $request, string $entity, ImportData $import): JsonResponse
    {
        abort_unless(array_key_exists($entity, ImportData::COLUMNS), 404);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'], 'dry_run' => ['sometimes', 'boolean']]);

        return new JsonResponse(['data' => $import->run($entity, $data['file']->getRealPath(), (bool) ($data['dry_run'] ?? true))]);
    }

    public function importTemplate(string $entity): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(array_key_exists($entity, ImportData::COLUMNS), 404);

        return response(implode(',', ImportData::COLUMNS[$entity])."\n", 200, [
            'Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$entity}-template.csv\"",
        ]);
    }
}
