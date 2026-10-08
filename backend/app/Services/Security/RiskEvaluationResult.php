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
        public array $metadata = [],
        public ?float $mlAnomalyScore = null,
        public ?string $mlRiskLevel = null,
        public array $mlAnomalyFactors = []
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
            'ml_anomaly_score' => $this->mlAnomalyScore,
            'ml_risk_level' => $this->mlRiskLevel,
            'ml_anomaly_factors' => $this->mlAnomalyFactors,
            'metadata' => $this->metadata,
        ];
    }
}
