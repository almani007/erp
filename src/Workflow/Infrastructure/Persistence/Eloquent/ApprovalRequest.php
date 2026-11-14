<?php

declare(strict_types=1);

namespace Workflow\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class ApprovalRequest extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'approval_requests';

    protected $fillable = ['rule_id', 'document_type', 'summary', 'amount', 'payload', 'branch_id', 'requested_by', 'current_level', 'levels', 'history', 'status', 'result_type', 'result_id', 'result_label', 'error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'levels' => 'array', 'history' => 'array'];
    }
}
