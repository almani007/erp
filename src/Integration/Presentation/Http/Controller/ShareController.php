<?php

declare(strict_types=1);

namespace Integration\Presentation\Http\Controller;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Shared\Domain\Contract\TenantContext;

/**
 * Public, signed links to a document (sent by WhatsApp / SMS / email).
 * No login: the signature in the URL is the permission, and it expires.
 */
final class ShareController
{
    public function sale(string $id, TenantContext $tenant): View
    {
        $sale = Sale::query()->withoutGlobalScope('tenant')->findOrFail($id);
        $tenant->set($sale->tenant_id);

        return view('share.sale', [
            'sale' => $sale->load(['items', 'customer:id,name,phone,address,tax_no']),
            'company' => DB::table('companies')->where('id', $sale->company_id)->first(['legal_name', 'tax_registration_no']),
            'branch' => DB::table('branches')->where('id', $sale->branch_id)->first(['name', 'phone']),
        ]);
    }
}
