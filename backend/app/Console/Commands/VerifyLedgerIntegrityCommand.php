<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerService;
use Illuminate\Console\Command;

class VerifyLedgerIntegrityCommand extends Command
{
    protected $signature = 'ledger:verify {--currency= : Specific currency to verify (e.g. NGN)} {--json : Output raw JSON results}';
    protected $description = 'Verify mathematical integrity and zero-drift invariance across all double-entry ledger journals';

    public function handle(LedgerService $ledgerService): int
    {
        $currency = $this->option('currency') ?: null;
        $result = $ledgerService->verifyLedgerIntegrity($currency);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return $result['is_balanced'] ? 0 : 1;
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>====================================================================</>');
        $this->line('<fg=cyan;options=bold>            TRANSACTIQ — DOUBLE-ENTRY LEDGER INTEGRITY AUDIT        </>');
        $this->line('<fg=cyan;options=bold>====================================================================</>');
        $this->newLine();

        $rows = [
            ['Currency Scope', $result['currency'] ?: 'ALL CURRENCIES'],
            ['Total Journal Entries', number_format($result['total_entries'])],
            ['Total Debits (Minor Units)', '₦' . number_format($result['total_debits'] / 100, 2)],
            ['Total Credits (Minor Units)', '₦' . number_format($result['total_credits'] / 100, 2)],
            ['Discrepancy / Variance', $result['discrepancy'] === 0 ? '<fg=green>0 (ZERO DRIFT)</>' : '<fg=red;options=bold>' . $result['discrepancy'] . '</>'],
            ['Invariant Status', $result['is_balanced'] ? '<fg=green;options=bold>BALANCED (DEBITS === CREDITS)</>' : '<fg=red;options=bold>UNBALANCED VARIANCE DETECTED</>'],
            ['Audit Timestamp', $result['verified_at']],
        ];

        $this->table(['Audit Metric', 'Value'], $rows);
        $this->newLine();

        if ($result['is_balanced']) {
            $this->info('✓ [SUCCESS] Mathematical invariance verified across all financial ledger journals.');
            return 0;
        }

        $this->error('✗ [CRITICAL] Ledger variance detected! Sum of debits does not equal sum of credits.');
        return 1;
    }
}
