<?php

declare(strict_types=1);

namespace Integration\Application;

use Shared\Domain\Contract\Settings;

/**
 * FBR settings for a branch (settings group `fbr`, saved per branch):
 *   pos_enabled, pos_environment (sandbox|live), pos_id, pos_token, pos_url (optional override)
 *   di_enabled,  di_environment,  di_token, di_url, seller_ntn, seller_name, seller_province, seller_address
 *
 * The default endpoints are the ones in FBR's published specifications at the
 * time of writing; FBR changes them from time to time, so they can be overridden.
 */
final class FbrSettings
{
    public const array POS_URLS = [
        'sandbox' => 'https://esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData',
        'live' => 'https://gw.fbr.gov.pk/imsp/v1/api/Live/PostData',
    ];

    public const array DI_URLS = [
        'sandbox' => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
        'live' => 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata',
    ];

    public const array KEYS = [
        'pos_enabled', 'pos_environment', 'pos_id', 'pos_token', 'pos_url',
        'di_enabled', 'di_environment', 'di_token', 'di_url', 'seller_ntn', 'seller_name', 'seller_province', 'seller_address', 'di_scenario_id',
    ];

    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, mixed> */
    public function forBranch(string $branchId): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = $this->settings->get('fbr', $key, null, $branchId);
        }

        return $out;
    }

    public function posUrl(array $s): string
    {
        return (string) ($s['pos_url'] ?: self::POS_URLS[$s['pos_environment'] === 'live' ? 'live' : 'sandbox']);
    }

    public function diUrl(array $s): string
    {
        return (string) ($s['di_url'] ?: self::DI_URLS[$s['di_environment'] === 'live' ? 'live' : 'sandbox']);
    }
}
