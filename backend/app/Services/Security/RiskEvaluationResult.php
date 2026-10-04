<?php

namespace App\Services\Security;

class RiskEvaluationResult
{
    public const DECISION_ALLOW = 'ALLOW';
    public const DECISION_REVIEW = 'REVIEW';
    public const DECISION_BLOCK = 'BLOCK';

    public function __construct(
        public int $score,
        public string $decision,
        public array $flags = [],
        public ?string $reason = null,
        public array $metadata = []
    ) {}

    public function isAllowed(): bool
    {
        return $this->decision === self::DECISION_ALLOW;
    }

    public function isReview(): bool
    {
        return $this->decision === self::DECISION_REVIEW;
    }

    public function isBlocked(): bool
    {
        return $this->decision === self::DECISION_BLOCK;
    }

    public function getReason(): ?string
    {
        return $this->reason ?: (!empty($this->flags) ? implode(', ', $this->flags) : 'Low risk');
    }

    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'decision' => $this->decision,
            'flags' => $this->flags,
            'reason' => $this->getReason(),
            'metadata' => $this->metadata,
        ];
    }
}
