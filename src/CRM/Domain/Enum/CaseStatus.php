<?php

declare(strict_types=1);

namespace CRM\Domain\Enum;

enum CaseStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Escalated = 'escalated';
}
