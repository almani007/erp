<?php

declare(strict_types=1);

namespace Integration\Application;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Integration\Infrastructure\Persistence\Eloquent\MessageLog;
use Shared\Domain\Contract\Settings;

/**
 * Sends WhatsApp, SMS and email using the business's own accounts
 * (settings group `messaging`):
 *
 *   whatsapp_enabled, whatsapp_phone_number_id, whatsapp_token, whatsapp_template, whatsapp_template_language
 *     — WhatsApp Business Cloud API. Messages to a customer who has not written
 *       in the last 24 hours must use an approved template; set whatsapp_template
 *       to a template with one body parameter and the text is sent as that parameter.
 *   sms_enabled, sms_url, sms_method (GET|POST), sms_body
 *     — any HTTP SMS gateway; {to} and {message} are replaced in the URL / body.
 *   email via the mail settings in .env (MAIL_*).
 *
 * Every attempt is written to the message log.
 */
final class Messenger
{
    public function __construct(private readonly Settings $settings) {}

    /** @return list<string> channels switched on */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->settings->get('messaging', 'whatsapp_enabled') ? 'whatsapp' : null,
            $this->settings->get('messaging', 'sms_enabled') ? 'sms' : null,
            config('mail.default') && config('mail.default') !== 'log' ? 'email' : null,
        ]));
    }

    public function send(string $channel, string $to, string $body, ?string $subject = null, ?string $relatedType = null, ?string $relatedId = null, ?string $actorId = null): MessageLog
    {
        $status = 'sent';
        $error = null;
        $providerId = null;

        try {
            $providerId = match ($channel) {
                'whatsapp' => $this->whatsapp($to, $body),
                'sms' => $this->sms($to, $body),
                'email' => $this->email($to, $subject ?? 'Message', $body),
                default => throw new \InvalidArgumentException("Unknown channel {$channel}."),
            };
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 1000);
        }

        return MessageLog::query()->create([
            'channel' => $channel, 'recipient' => $to, 'subject' => $subject, 'body' => $body, 'status' => $status, 'error' => $error,
            'related_type' => $relatedType, 'related_id' => $relatedId, 'provider_id' => $providerId, 'created_by' => $actorId,
        ]);
    }

    /** Pakistani mobile numbers in international form (03xx… → 923xx…). */
    public static function msisdn(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return str_starts_with($digits, '0') ? '92'.substr($digits, 1) : $digits;
    }

    private function whatsapp(string $to, string $body): ?string
    {
        $id = (string) $this->settings->get('messaging', 'whatsapp_phone_number_id');
        $token = (string) $this->settings->get('messaging', 'whatsapp_token');
        if ($id === '' || $token === '') {
            throw new \RuntimeException('WhatsApp is not set up: add the phone number ID and access token.');
        }

        $template = (string) $this->settings->get('messaging', 'whatsapp_template');
        $message = $template !== ''
            ? ['type' => 'template', 'template' => [
                'name' => $template,
                'language' => ['code' => (string) ($this->settings->get('messaging', 'whatsapp_template_language') ?: 'en')],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($body, 0, 1000)]]]],
            ]]
            : ['type' => 'text', 'text' => ['body' => $body, 'preview_url' => true]];

        $response = Http::withToken($token)->timeout(15)
            ->post("https://graph.facebook.com/v21.0/{$id}/messages", ['messaging_product' => 'whatsapp', 'to' => self::msisdn($to)] + $message);
        if (! $response->successful()) {
            throw new \RuntimeException('WhatsApp: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return $response->json('messages.0.id');
    }

    private function sms(string $to, string $body): ?string
    {
        $url = (string) $this->settings->get('messaging', 'sms_url');
        if ($url === '') {
            throw new \RuntimeException('SMS is not set up: add the gateway URL.');
        }
        $fill = static fn (string $t, bool $encode): string => strtr($t, [
            '{to}' => $encode ? rawurlencode(self::msisdn($to)) : self::msisdn($to),
            '{message}' => $encode ? rawurlencode($body) : $body,
        ]);

        $response = strtoupper((string) $this->settings->get('messaging', 'sms_method')) === 'POST'
            ? Http::timeout(15)->withBody($fill((string) $this->settings->get('messaging', 'sms_body'), false), 'application/json')->post($fill($url, true))
            : Http::timeout(15)->get($fill($url, true));
        if (! $response->successful()) {
            throw new \RuntimeException('SMS gateway: HTTP '.$response->status().' '.mb_substr($response->body(), 0, 200));
        }

        return null;
    }

    private function email(string $to, string $subject, string $body): ?string
    {
        Mail::raw($body, static fn ($m) => $m->to($to)->subject($subject));

        return null;
    }
}
