/**
 * TransactIQ — k6 Load & Concurrency Stress Testing Shared Config
 */

export const CONFIG = {
    // Base URL of the running TransactIQ backend
    BASE_URL: __ENV.BASE_URL || 'http://127.0.0.1:8000',
    
    // Seeded deterministic test API key for SwiftPay Retail Enterprises
    MERCHANT_API_KEY: __ENV.API_KEY || 'tiq_test_sec_swiftpay_stress_key_000000000000',
    
    // Default headers for financial API requests
    HEADERS: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Api-Key': __ENV.API_KEY || 'tiq_test_sec_swiftpay_stress_key_000000000000',
        'X-Stress-Test': 'true',
    },

    // Deterministic Test Card PANs
    CARDS: {
        SUCCESS: '4000000000000001',
        INSUFFICIENT_FUNDS: '4000000000000051',
        EXPIRED: '4000000000000033',
        TIMEOUT_FALLOVER: '4000000000000091',
        PENDING_3DS: '4000000000000002',
    }
};

/**
 * Generate a randomized payment request payload
 */
export function generatePaymentPayload(cardNumber = CONFIG.CARDS.SUCCESS, customAmount = null) {
    const randomId = Math.floor(Math.random() * 1000000);
    return {
        amount: customAmount || (100000 + Math.floor(Math.random() * 900000)), // ₦1,000 to ₦10,000 in kobo
        currency: 'NGN',
        payment_method: 'CARD',
        customer: {
            email: `load_test_${randomId}@example.com`,
            name: `Load Test User ${randomId}`,
            phone: '+2348012345678'
        },
        card: {
            number: cardNumber,
            exp_month: '12',
            exp_year: '2028',
            cvv: '123'
        },
        metadata: {
            source: 'k6_stress_suite',
            iteration_id: randomId
        }
    };
}
