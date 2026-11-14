<?php

declare(strict_types=1);

namespace Workflow\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class ApprovalRule extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'approval_rules';

    protected $fillable = ['document_type', 'min_amount', 'levels', 'status'];

    protected function casts(): array
    {
        return ['levels' => 'array'];
    }
}
