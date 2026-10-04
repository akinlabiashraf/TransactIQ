<?php

namespace App\Services\Gateway\DTOs;

class GatewayResponse
{
    public function __construct(
        public string $status,
        public string $provider,
        public ?string $providerReference = null,
        public ?string $approvalCode = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?array $gatewayRequest = null,
        public ?array $gatewayResponse = null,
        public int $latencyMs = 0,
        public bool $isRetryable = false
    ) {}

    public static function success(
        string $provider,
        string $providerReference,
        string $approvalCode = '00',
        ?array $gatewayRequest = null,
        ?array $gatewayResponse = null,
        int $latencyMs = 65
    ): self {
        return new self(
            status: 'SUCCESS',
            provider: $provider,
            providerReference: $providerReference,
            approvalCode: $approvalCode,
            gatewayRequest: $gatewayRequest,
            gatewayResponse: $gatewayResponse ?? [
                'provider_reference' => $providerReference,
                'status' => 'SUCCESS',
                'approval_code' => $approvalCode,
            ],
            latencyMs: $latencyMs,
            isRetryable: false
        );
    }

    public static function failure(
        string $provider,
        string $errorCode,
        string $errorMessage,
        ?string $providerReference = null,
        ?string $approvalCode = null,
        ?array $gatewayRequest = null,
        ?array $gatewayResponse = null,
        int $latencyMs = 85,
        bool $isRetryable = false
    ): self {
        return new self(
            status: 'FAILED',
            provider: $provider,
            providerReference: $providerReference,
            approvalCode: $approvalCode,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            gatewayRequest: $gatewayRequest,
            gatewayResponse: $gatewayResponse ?? [
                'provider_reference' => $providerReference,
                'status' => 'FAILED',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ],
            latencyMs: $latencyMs,
            isRetryable: $isRetryable
        );
    }

    public static function pending(
        string $provider,
        string $providerReference,
        string $approvalCode = '02',
        ?string $message = 'Additional authorization or 3D Secure verification required.',
        ?array $gatewayRequest = null,
        ?array $gatewayResponse = null,
        int $latencyMs = 95
    ): self {
        return new self(
            status: 'PENDING',
            provider: $provider,
            providerReference: $providerReference,
            approvalCode: $approvalCode,
            errorMessage: $message,
            gatewayRequest: $gatewayRequest,
            gatewayResponse: $gatewayResponse ?? [
                'provider_reference' => $providerReference,
                'status' => 'PENDING',
                'approval_code' => $approvalCode,
                'message' => $message,
            ],
            latencyMs: $latencyMs,
            isRetryable: false
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === 'SUCCESS';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }
}
