<?php

declare(strict_types=1);

namespace CRM\Domain\Enum;

enum FeedbackSentiment: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
}
