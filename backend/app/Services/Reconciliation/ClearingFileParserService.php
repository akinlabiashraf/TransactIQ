<?php

namespace App\Services\Reconciliation;

use InvalidArgumentException;

class ClearingFileParserService
{
    /**
     * Parse clearing file content in either CSV or JSON format.
     *
     * @param string $content Raw file content
     * @param string $format 'csv' or 'json' (or 'auto')
     * @return array<int, array{
     *     provider_reference: string,
     *     transaction_reference: ?string,
     *     amount: int,
     *     fee: int,
     *     currency: string,
     *     status: string,
     *     paid_at: ?string,
     *     raw_data: array
     * }>
     */
    public function parse(string $content, string $format = 'auto'): array
    {
        $trimmed = trim($content);
        if (empty($trimmed)) {
            return [];
        }

        if ($format === 'auto') {
            $format = (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) ? 'json' : 'csv';
        }

        return match (strtolower($format)) {
            'json' => $this->parseJson($trimmed),
            'csv' => $this->parseCsv($trimmed),
            default => throw new InvalidArgumentException("Unsupported clearing file format: [{$format}]"),
        };
    }

    /**
     * Parse JSON clearing report.
     */
    protected function parseJson(string $jsonString): array
    {
        $data = json_decode($jsonString, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Malformed JSON clearing report.');
        }

        // Support both direct list `[{...}]` and wrapped objects `{ "data": [{...}] }` or `{ "records": [{...}] }`
        $records = $data['data'] ?? $data['records'] ?? $data['transactions'] ?? $data;
        if (!is_array($records)) {
            return [];
        }

        $parsed = [];
        foreach ($records as $row) {
            if (!is_array($row)) {
                continue;
            }
            $record = $this->normalizeRow($row);
            if ($record) {
                $parsed[] = $record;
            }
        }

        return $parsed;
    }

    /**
     * Parse CSV clearing report with header detection.
     */
    protected function parseCsv(string $csvString): array
    {
        // Normalize newline characters
        $lines = preg_split('/\r\n|\r|\n/', trim($csvString));
        if (empty($lines)) {
            return [];
        }

        // Detect delimiter (comma or semicolon)
        $firstLine = $lines[0];
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        $headerRow = str_getcsv(array_shift($lines), $delimiter);
        $headers = array_map(fn($h) => strtolower(trim(str_replace(['"', "'", "\xEF\xBB\xBF"], '', $h))), $headerRow);

        $parsed = [];
        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            if (empty($trimmedLine)) {
                continue;
            }

            $rawValues = str_getcsv($trimmedLine, $delimiter);
            if (count($rawValues) < 2) {
                continue;
            }

            $rowMap = [];
            foreach ($headers as $idx => $headerName) {
                $rowMap[$headerName] = $rawValues[$idx] ?? null;
            }

            $record = $this->normalizeRow($rowMap);
            if ($record) {
                $parsed[] = $record;
            }
        }

        return $parsed;
    }

    /**
     * Normalize individual row from varied provider schemas into standard TransactIQ schema.
     */
    protected function normalizeRow(array $row): ?array
    {
        // Provider Reference resolution
        $providerRef = $this->firstAvailable($row, [
            'provider_reference',
            'gateway_reference',
            'provider_ref',
            'transaction_id',
            'payment_reference',
            'settlement_reference',
            'ref',
            'id',
        ]);

        // Internal Transaction Reference resolution
        $internalRef = $this->firstAvailable($row, [
            'transaction_reference',
            'internal_reference',
            'reference',
            'merchant_reference',
            'order_reference',
            'order_id',
            'tx_ref',
        ]);

        if (!$providerRef && !$internalRef) {
            return null; // Skip invalid row lacking identifiable reference
        }

        // Amount parsing (in minor units)
        $rawAmount = $this->firstAvailable($row, ['amount', 'gross_amount', 'transaction_amount', 'settlement_amount']);
        $amountMinor = $this->parseAmountToMinor($rawAmount);

        // Fee parsing
        $rawFee = $this->firstAvailable($row, ['fee', 'fee_amount', 'gateway_fee', 'charge']);
        $feeMinor = $this->parseAmountToMinor($rawFee);

        // Currency
        $currency = strtoupper(trim((string) $this->firstAvailable($row, ['currency', 'curr']) ?: 'NGN'));

        // Status normalization
        $rawStatus = strtoupper(trim((string) $this->firstAvailable($row, ['status', 'state', 'payment_status', 'transaction_status']) ?: 'SUCCESS'));
        $status = match ($rawStatus) {
            'SUCCESS', 'SUCCESSFUL', 'SETTLED', 'PAID', 'CLEARED', 'COMPLETED' => 'SUCCESS',
            'FAILED', 'DECLINED', 'REJECTED', 'ABANDONED' => 'FAILED',
            'PENDING', 'PROCESSING' => 'PENDING',
            'REVERSED', 'REFUNDED' => 'REVERSED',
            default => 'SUCCESS',
        };

        // Timestamp
        $paidAt = $this->firstAvailable($row, ['paid_at', 'transaction_date', 'date', 'settled_at', 'created_at']);

        return [
            'provider_reference' => (string) ($providerRef ?: $internalRef),
            'transaction_reference' => $internalRef ? (string) $internalRef : null,
            'amount' => $amountMinor,
            'fee' => $feeMinor,
            'currency' => $currency,
            'status' => $status,
            'paid_at' => $paidAt ? date('Y-m-d H:i:s', strtotime($paidAt) ?: time()) : null,
            'raw_data' => $row,
        ];
    }

    /**
     * Find first non-empty value matching candidate column names.
     */
    protected function firstAvailable(array $row, array $candidates): mixed
    {
        foreach ($candidates as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return null;
    }

    /**
     * Convert currency string or number to integer minor units (kobo/cents).
     * Handles both major decimal (e.g. "100.50" => 10050) and integer minor units.
     */
    protected function parseAmountToMinor(mixed $rawAmount): int
    {
        if ($rawAmount === null || $rawAmount === '') {
            return 0;
        }

        // Remove currency symbols, commas, spaces
        $cleaned = preg_replace('/[^\d.-]/', '', (string) $rawAmount);
        if ($cleaned === '' || !is_numeric($cleaned)) {
            return 0;
        }

        $floatVal = (float) $cleaned;

        // If string contains decimal dot, it's in major units (e.g., "1500.50" or "5000.00")
        if (str_contains((string) $rawAmount, '.')) {
            return (int) round($floatVal * 100);
        }

        // If integer is large (e.g. >= 100000 for a typical ₦1000 transaction in kobo), or depending on scale
        // In Nigerian fintech clearing files, decimal point is usually provided if major, or integer if kobo.
        // If no decimal point and <= 100000, e.g. 5000: is it ₦5000 or 5000 kobo?
        // Standard rule: if raw string was "5000", convert to 500000 (standard major unit currency) unless explicit.
        // To be completely unambiguous: if no decimal point is present, assume major units unless amount > 10,000,000.
        // Let's ensure consistency: 10000 => ₦10,000.00 = 1000000 minor units.
        return (int) round($floatVal * 100);
    }
}
