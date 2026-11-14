<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\DocumentNumbers;

/**
 * Gap-free sequential document numbers from a counter row that is locked
 * for the duration of the caller's transaction.
 *
 * Call it inside the transaction that inserts the document: if that
 * transaction rolls back, the number is released with it.
 */
final class LockedCounterDocumentNumbers implements DocumentNumbers
{
    public function next(string $tenantId, string $type, string $scopeId, string $prefix): string
    {
        return DB::transaction(function () use ($tenantId, $type, $scopeId, $prefix): string {
            // Create the counter if it is missing, then lock it. Two first-time
            // callers cannot both insert: the primary key makes one a no-op.
            DB::table('document_sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'type' => $type,
                'scope' => $scopeId,
                'current_value' => 0,
            ]);

            $row = DB::table('document_sequences')
                ->where('tenant_id', $tenantId)
                ->where('type', $type)
                ->where('scope', $scopeId)
                ->lockForUpdate()
                ->first();

            $next = ((int) $row->current_value) + 1;

            DB::table('document_sequences')
                ->where('tenant_id', $tenantId)
                ->where('type', $type)
                ->where('scope', $scopeId)
                ->update(['current_value' => $next]);

            return sprintf('%s-%06d', $prefix, $next);
        });
    }
}
