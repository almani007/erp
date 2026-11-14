<?php

declare(strict_types=1);

namespace Integration\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class FiscalSubmission extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'fiscal_submissions';

    protected $fillable = ['branch_id', 'system', 'document_type', 'document_id', 'document_no', 'status', 'attempts', 'next_attempt_at', 'request', 'response', 'fiscal_invoice_no', 'last_error', 'sent_at'];

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
