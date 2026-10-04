<?php

namespace App\Services\Security;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use Carbon\Carbon;

class RiskService
{
    /** Known fraudulent / stolen test card suffixes */
    public const BLACKLISTED_CARD_ENDINGS = ['9999', '8888', '0000'];

    /** Minor units threshold for high ticket alert (₦2,000,000 = 200,000,000 kobo) */
    public const HIGH_TICKET_THRESHOLD_MINOR = 200000000;

    /** Velocity limit: max transactions per rolling 60 seconds */
    public const VELOCITY_MAX_PER_MINUTE = 5;

    /**
     * Evaluate payment payload in real-time before gateway dispatch.
     */
    public function evaluate(?Merchant $merchant, ?Customer $customer, array $payload): RiskEvaluationResult
    {
        $score = 0;
        $flags = [];
        $reasons = [];

        $cardDetails = $payload['card'] ?? [];
        $cardNumber = preg_replace('/\D/', '', $cardDetails['number'] ?? '');
        $amount = (int) ($payload['amount'] ?? 0);
        $customerEmail = strtolower(trim($payload['customer']['email'] ?? ($customer?->email ?? '')));

        // Rule 1: Stolen / Compromised Card Blacklist Check
        if ($this->isCardBlacklisted($cardNumber)) {
            $score = 100;
            $flags[] = 'CARD_BLACKLISTED';
            $reasons[] = 'Card number is on the global stolen / compromised blacklist.';

            return new RiskEvaluationResult(
                score: 100,
                decision: RiskEvaluationResult::DECISION_BLOCK,
                flags: $flags,
                reason: implode(' ', $reasons),
                metadata: ['rule' => 'CARD_BLACKLIST', 'card_last4' => substr($cardNumber, -4)]
            );
        }

        // Rule 2: Velocity Spikes Check (Rolling 60 seconds)
        $velocityCount = $this->checkVelocity($merchant?->id, $customerEmail);
        if ($velocityCount >= self::VELOCITY_MAX_PER_MINUTE) {
            $score += 60;
            $flags[] = 'VELOCITY_SPIKE_DETECTED';
            $reasons[] = "Velocity limit exceeded ({$velocityCount} attempts in past 60s).";
        } elseif ($velocityCount >= 3) {
            $score += 25;
            $flags[] = 'VELOCITY_ELEVATED';
            $reasons[] = "Rapid payment attempts detected ({$velocityCount} in past 60s).";
        }

        // Rule 3: Consecutive Payment Failures
        if ($customer) {
            $consecutiveFailures = $this->checkConsecutiveFailures($customer);
            if ($consecutiveFailures >= 3) {
                $score += 35;
                $flags[] = 'CONSECUTIVE_FAILURES_EXCEEDED';
                $reasons[] = "Customer has {$consecutiveFailures} consecutive declined transactions.";
            }
        }

        // Rule 4: High Single Ticket Size Alert
        if ($amount >= self::HIGH_TICKET_THRESHOLD_MINOR) {
            $score += 30;
            $flags[] = 'HIGH_TICKET_ALERT';
            $formattedAmount = number_format($amount / 100, 2);
            $reasons[] = "Single transaction volume (₦{$formattedAmount}) exceeds high-ticket threshold.";
        }

        // Rule 5: Disposable / Invalid Customer Email Pattern
        if ($this->isSuspiciousEmail($customerEmail)) {
            $score += 20;
            $flags[] = 'SUSPICIOUS_EMAIL_DOMAIN';
            $reasons[] = 'Customer email matches disposable or suspicious pattern.';
        }

        // Compute Decision based on Cumulative Score
        $score = min(100, $score);
        $decision = match (true) {
            $score >= 90 => RiskEvaluationResult::DECISION_BLOCK,
            $score >= 70 => RiskEvaluationResult::DECISION_REVIEW,
            default => RiskEvaluationResult::DECISION_ALLOW,
        };

        return new RiskEvaluationResult(
            score: $score,
            decision: $decision,
            flags: $flags,
            reason: !empty($reasons) ? implode(' ', $reasons) : 'Transaction passed all fraud and velocity heuristics.',
            metadata: [
                'velocity_count' => $velocityCount,
                'high_ticket' => in_array('HIGH_TICKET_ALERT', $flags, true),
                'score_breakdown' => [
                    'base' => 0,
                    'velocity' => $velocityCount >= 5 ? 60 : ($velocityCount >= 3 ? 25 : 0),
                    'consecutive_failures' => isset($consecutiveFailures) && $consecutiveFailures >= 3 ? 35 : 0,
                    'high_ticket' => $amount >= self::HIGH_TICKET_THRESHOLD_MINOR ? 30 : 0,
                ],
            ]
        );
    }

    /**
     * Interactive Risk Simulator for operational triage and tuning.
     */
    public function evaluateSimulator(array $payload): RiskEvaluationResult
    {
        $dummyMerchant = new Merchant(['id' => '00000000-0000-0000-0000-000000000000']);
        $email = $payload['customer_email'] ?? 'test@example.com';
        $dummyCustomer = new Customer(['email' => $email]);

        $adaptedPayload = [
            'amount' => (int) ($payload['amount'] ?? 10000),
            'currency' => $payload['currency'] ?? 'NGN',
            'card' => ['number' => $payload['card_number'] ?? ''],
            'customer' => ['email' => $email],
        ];

        return $this->evaluate($dummyMerchant, $dummyCustomer, $adaptedPayload);
    }

    /**
     * Aggregated real-time metrics for the security operations dashboard.
     */
    public function getRiskMetrics(?string $merchantId = null): array
    {
        $query = Transaction::query();
        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        $totalTransactions = (clone $query)->count();

        // Count transactions flagged or blocked by risk
        $blockedEvents = TransactionEvent::where('event_type', 'PAYMENT_BLOCKED_BY_RISK')
            ->when($merchantId, function ($q) use ($merchantId) {
                $q->whereHas('transaction', fn ($tq) => $tq->where('merchant_id', $merchantId));
            })->count();

        $activeRules = [
            [
                'id' => 'rule_velocity_spike',
                'name' => 'Velocity Spike Limiter',
                'description' => 'Restricts customers from exceeding 5 transactions within 60 seconds.',
                'status' => 'ACTIVE',
                'threshold' => '5 txns / 60s',
                'action' => 'BLOCK (Score +60)',
            ],
            [
                'id' => 'rule_card_blacklist',
                'name' => 'Stolen & Compromised Card Blacklist',
                'description' => 'Instantly blocks transactions using recognized fraudulent BINs or reported stolen card numbers.',
                'status' => 'ACTIVE',
                'threshold' => 'Immediate Match',
                'action' => 'BLOCK (Score 100)',
            ],
            [
                'id' => 'rule_consecutive_failures',
                'name' => 'Consecutive Decline Guard',
                'description' => 'Identifies customers with 3 or more consecutive card declines.',
                'status' => 'ACTIVE',
                'threshold' => '>= 3 Declines',
                'action' => 'REVIEW (Score +35)',
            ],
            [
                'id' => 'rule_high_ticket',
                'name' => 'High-Ticket Volume Anomaly',
                'description' => 'Highlights transactions over ₦2,000,000 for secondary compliance review.',
                'status' => 'ACTIVE',
                'threshold' => '> ₦2,000,000',
                'action' => 'REVIEW (Score +30)',
            ],
        ];

        return [
            'total_evaluated' => $totalTransactions,
            'total_blocked' => $blockedEvents,
            'block_rate_percentage' => $totalTransactions > 0 ? round(($blockedEvents / $totalTransactions) * 100, 2) : 0,
            'active_rules_count' => count($activeRules),
            'rules' => $activeRules,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Check if card number is on blacklist.
     */
    public function isCardBlacklisted(string $cardNumber): bool
    {
        if (empty($cardNumber)) {
            return false;
        }

        foreach (self::BLACKLISTED_CARD_ENDINGS as $ending) {
            if (str_ends_with($cardNumber, $ending)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check rolling velocity in the last 60 seconds.
     */
    protected function checkVelocity(?string $merchantId, string $email): int
    {
        if (empty($email) || empty($merchantId)) {
            return 0;
        }

        $oneMinuteAgo = Carbon::now()->subSeconds(60);

        return Transaction::where('merchant_id', $merchantId)
            ->whereHas('customer', function ($q) use ($email) {
                $q->where('email', $email);
            })
            ->where('created_at', '>=', $oneMinuteAgo)
            ->count();
    }

    /**
     * Check consecutive declines for customer.
     */
    protected function checkConsecutiveFailures(Customer $customer): int
    {
        $recentTransactions = Transaction::where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->pluck('status');

        $consecutive = 0;
        foreach ($recentTransactions as $status) {
            if ($status === Transaction::STATUS_FAILED) {
                $consecutive++;
            } else {
                break;
            }
        }

        return $consecutive;
    }

    /**
     * Check for disposable email patterns.
     */
    protected function isSuspiciousEmail(string $email): bool
    {
        $suspiciousDomains = ['tempmail.com', 'throwaway.com', '10minutemail.com', 'guerrillamail.com', 'fraudtest.org'];
        $parts = explode('@', $email);
        $domain = strtolower(end($parts));

        return in_array($domain, $suspiciousDomains, true);
    }
}
