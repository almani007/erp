<?php

declare(strict_types=1);

namespace Integration\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;

/**
 * Messages sent without anyone pressing a button, each only when the
 * business has switched it on (settings group `messaging`):
 *   receipt_after_sale     — a link to the receipt / invoice after a sale to a known customer;
 *   overdue_reminders      — invoices that are N days past due, N from overdue_reminder_days ("1,7,30");
 *   appointment_reminders  — the evening before an appointment.
 * WhatsApp is used when switched on, otherwise SMS.
 */
final class AutoMessages
{
    public function __construct(private readonly TenantContext $tenant, private readonly Settings $settings, private readonly Messenger $messenger) {}

    public function afterSale(string $saleId): void
    {
        if (! $this->settings->get('messaging', 'receipt_after_sale') || ($channel = $this->channel()) === null) {
            return;
        }
        $sale = DB::table('sales as s')->join('customers as c', 'c.id', '=', 's.customer_id')->where('s.id', $saleId)
            ->first(['s.id', 's.sale_no', 's.grand_total', 's.balance_due', 's.due_date', 'c.name', 'c.phone']);
        if ($sale === null || ! $sale->phone || DB::table('message_log')->where('related_type', 'sale')->where('related_id', $saleId)->exists()) {
            return;
        }
        $text = "Thank you, {$sale->name}. Your bill {$sale->sale_no}: Rs ".number_format((float) $sale->grand_total, 2)
            .((float) $sale->balance_due > 0 ? ', due by '.$sale->due_date : '').'. View: '.$this->link($saleId);
        $this->messenger->send($channel, $sale->phone, $text, null, 'sale', $saleId);
    }

    /** @return int messages sent */
    public function daily(): int
    {
        $sent = 0;
        foreach (DB::table('tenants')->where('status', 'active')->pluck('id') as $tenantId) {
            $this->tenant->set($tenantId);
            $channel = $this->channel();
            if ($channel === null) {
                continue;
            }
            if ($this->settings->get('messaging', 'overdue_reminders')) {
                $sent += $this->overdue($channel);
            }
            if ($this->settings->get('messaging', 'appointment_reminders')) {
                $sent += $this->appointments($channel);
            }
        }

        return $sent;
    }

    private function overdue(string $channel): int
    {
        $days = array_filter(array_map('intval', explode(',', (string) ($this->settings->get('messaging', 'overdue_reminder_days') ?: '1,7,30'))));
        $dates = array_map(static fn (int $d): string => today()->subDays($d)->toDateString(), $days);
        $rows = DB::table('sales as s')->join('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.tenant_id', $this->tenant->requireId())->where('s.status', 'completed')->where('s.balance_due', '>', 0)
            ->whereIn('s.due_date', $dates)->whereNotNull('c.phone')
            ->get(['s.id', 's.sale_no', 's.balance_due', 's.due_date', 'c.name', 'c.phone']);
        foreach ($rows as $r) {
            $text = "Dear {$r->name}, invoice {$r->sale_no} of Rs ".number_format((float) $r->balance_due, 2)." was due on {$r->due_date}. Please arrange payment. View: ".$this->link($r->id);
            $this->messenger->send($channel, $r->phone, $text, null, 'sale_reminder', $r->id);
        }

        return $rows->count();
    }

    private function appointments(string $channel): int
    {
        $rows = DB::table('appointments')->where('tenant_id', $this->tenant->requireId())->whereIn('status', ['booked', 'confirmed'])
            ->whereNull('reminded_at')->whereNotNull('customer_phone')
            ->whereBetween('starts_at', [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()])->get();
        foreach ($rows as $a) {
            $at = \Carbon\Carbon::parse($a->starts_at)->setTimezone(config('app.timezone'))->format('h:i A');
            $log = $this->messenger->send($channel, $a->customer_phone, "Reminder: your appointment tomorrow at {$at}. Reply to confirm or reschedule.", null, 'appointment', $a->id);
            if ($log->status === 'sent') {
                DB::table('appointments')->where('id', $a->id)->update(['reminded_at' => now()]);
            }
        }

        return $rows->count();
    }

    private function channel(): ?string
    {
        return $this->settings->get('messaging', 'whatsapp_enabled') ? 'whatsapp' : ($this->settings->get('messaging', 'sms_enabled') ? 'sms' : null);
    }

    private function link(string $saleId): string
    {
        return URL::temporarySignedRoute('share.sale', now()->addDays(60), ['id' => $saleId]);
    }
}
