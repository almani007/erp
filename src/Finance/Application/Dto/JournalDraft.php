<?php

declare(strict_types=1);

namespace Finance\Application\Dto;

final readonly class JournalDraft
{
    /**
     * @param  string  $postingDate  Y-m-d
     * @param  list<JournalLineDraft>  $lines
     */
    public function __construct(
        public string $companyId,
        public string $postingDate,
        public string $sourceType,
        public ?string $sourceId,
        public string $description,
        public array $lines,
        public ?string $reversesEntryId = null,
    ) {}
}
