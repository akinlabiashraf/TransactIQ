<?php

namespace App\Services\Gateway\Contracts;

use App\Models\Transaction;
use App\Services\Gateway\DTOs\GatewayResponse;

interface PaymentGatewayInterface
{
    /**
     * Get the unique identifier/name for this gateway provider.
     */
    public function getName(): string;

    /**
     * Charge a customer payment method for a transaction.
     *
     * @param Transaction $transaction The transaction entity
     * @param array $paymentDetails Card, bank account, or simulation payload
     * @return GatewayResponse Structured response DTO
     */
    public function charge(Transaction $transaction, array $paymentDetails): GatewayResponse;
}
