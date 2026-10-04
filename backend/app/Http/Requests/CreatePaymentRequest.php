<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Amount in minor currency units (e.g. ₦1,000.00 = 100000 kobo)
            'amount' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_method' => ['required', 'string', 'in:CARD,BANK_TRANSFER,USSD'],
            
            // Customer object
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email', 'max:255'],
            'customer.name' => ['nullable', 'string', 'max:150'],
            'customer.phone' => ['nullable', 'string', 'max:30'],

            // Optional Metadata
            'metadata' => ['nullable', 'array'],

            // Deterministic testing outcome for sandboxes
            'gateway_simulation' => ['nullable', 'string', 'in:SUCCESS,FAILED,PENDING'],

            // Card Object for Deterministic Card Simulation
            'card' => ['nullable', 'array'],
            'card.number' => ['nullable', 'string', 'max:25'],
            'card.exp_month' => ['nullable', 'string', 'max:2'],
            'card.exp_year' => ['nullable', 'string', 'max:4'],
            'card.cvv' => ['nullable', 'string', 'max:4'],
        ];
    }

    /**
     * Custom error messages for financial API consumers.
     */
    public function messages(): array
    {
        return [
            'amount.min' => 'The minimum allowable transaction amount is 100 minor units (e.g. ₦1.00).',
            'amount.integer' => 'The amount must be an integer represented in minor currency units (e.g. kobo or cents).',
            'currency.size' => 'The currency must be a valid 3-letter ISO code (e.g. NGN, USD, GBP, EUR).',
            'customer.email.required' => 'A valid customer email is required to associate with the payment record.',
        ];
    }
}
