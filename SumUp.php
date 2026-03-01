<?php
/**
 * SumUp Payment Gateway Module for WiseCP
 * 
 * This module integrates SumUp payment processing with WiseCP.
 * It uses the Merchant method where the customer enters their card
 * details directly in WiseCP and the payment is processed in the background.
 * 
 * @version 1.0
 * @author WiseCP Module Developer
 */
class SumUp extends PaymentGatewayModule
{
    private $api_base = 'https://api.sumup.com/v0.1';

    public function __construct()
    {
        $this->name = __CLASS__;
        $this->standard_card = true;
        parent::__construct();
    }

    /**
     * Process the payment (capture)
     * 
     * This method is called when the customer submits their card details.
     * It creates a checkout in SumUp and processes the payment.
     * 
     * @param array $params Payment parameters including card and customer info
     * @return array Payment result
     */
    public function capture($params = [])
    {
        $api_key       = $this->config['settings']['api_key'] ?? '';
        $merchant_code = $this->config['settings']['merchant_code'] ?? '';

        // Validate configuration
        if (empty($api_key) || empty($merchant_code)) {
            return [
                'status'  => 'error',
                'message' => 'SumUp API Key or Merchant Code not configured.',
            ];
        }

        // Prepare checkout reference (unique identifier)
        $checkout_reference = 'wisecp_' . $params['checkout_id'] . '_' . time();
        
        // Get currency from settings (must match SumUp account currency)
        $currency = $this->config['settings']['currency'] ?? 'EUR';

        // Convert amount to configured SumUp currency when needed
        $amount = (float) ($params['amount'] ?? 0);
        $checkout_currency = $params['currency'] ?? ($params['data']['currency'] ?? null);
        $checkout_currency_code = $this->getCurrencyCode($checkout_currency);
        $configured_currency_code = $this->getCurrencyCode($currency);

        if ($checkout_currency_code && $configured_currency_code && $checkout_currency_code !== $configured_currency_code) {
            Helper::Load(['Money']);
            $from_id = $this->getCurrencyId($checkout_currency);
            $to_id   = $this->getCurrencyId($currency);
            if ($from_id && $to_id) {
                $converted = Money::exChange($amount, $from_id, $to_id);
                if ($converted !== null && $converted !== false) {
                    $amount = (float) $converted;
                }
            } else {
                $converted = Money::exChange($amount, $checkout_currency_code, $configured_currency_code);
                if ($converted !== null && $converted !== false) {
                    $amount = (float) $converted;
                }
            }
        }
        
        // Build description from client info
        $description = 'Payment #' . $params['checkout_id'];
        if (isset($params['clientInfo']) && $params['clientInfo']->full_name) {
            $description .= ' - ' . $params['clientInfo']->full_name;
        }

        // Build the return URL for 3DS redirect
        // Use checkout_reference since we don't have SumUp checkout ID yet
        $return_url = Controllers::$init->CRLink("payment", [__CLASS__, $this->get_auth_token(), 'callback'], "none");
        $return_url .= (strpos($return_url, '?') !== false ? '&' : '?') . 'ref=' . urlencode($checkout_reference);
        $return_url .= '&checkout_id=' . $params['checkout_id'];

        // Step 1: Create checkout with redirect_url
        $checkoutData = [
            'checkout_reference' => $checkout_reference,
            'amount'            => round($amount, 2),
            'currency'          => $currency,
            'merchant_code'     => $merchant_code,
            'description'       => $description,
            'redirect_url'      => $return_url,
        ];

        $checkoutResult = $this->sumupRequest('POST', '/checkouts', $checkoutData, $api_key);

        if (!$checkoutResult || !isset($checkoutResult['id'])) {
            $errorMsg = 'Failed to create SumUp checkout.';
            if (isset($checkoutResult['message'])) {
                $errorMsg = $checkoutResult['message'];
            } elseif (isset($checkoutResult['error_message'])) {
                $errorMsg = $checkoutResult['error_message'];
            } elseif (isset($checkoutResult['error'])) {
                $errorMsg = $checkoutResult['error'];
            }
            
            return [
                'status'  => 'error',
                'message' => $errorMsg,
            ];
        }

        $checkout_id = $checkoutResult['id'];

        // Step 2: Process checkout with card details
        $expiry_year = $params['expiry_y'];
        // Ensure year is in correct format (2 or 4 digits accepted)
        if (strlen($expiry_year) == 2) {
            $expiry_year = '20' . $expiry_year;
        }

        // Format expiry month (must be 2 digits: 01-12)
        $expiry_month = str_pad($params['expiry_m'], 2, '0', STR_PAD_LEFT);

        $processData = [
            'payment_type' => 'card',
            'card'         => [
                'name'         => $params['holder_name'],
                'number'       => $params['num'],
                'expiry_year'  => $expiry_year,
                'expiry_month' => $expiry_month,
                'cvv'          => $params['cvc'],
            ],
        ];

        $processResult = $this->sumupRequest('PUT', '/checkouts/' . $checkout_id, $processData, $api_key);

        if (!$processResult) {
            return [
                'status'  => 'error',
                'message' => 'Failed to process SumUp payment.',
            ];
        }

        // Check for 3DS redirect
        if (isset($processResult['next_step']) && isset($processResult['next_step']['url'])) {
            // 3DS authentication required - redirect customer
            return [
                'status'   => 'redirect',
                'redirect' => $processResult['next_step']['url'],
                'message'  => [
                    'SumUp Checkout ID'  => $checkout_id,
                    'WiseCP Checkout ID' => $params['checkout_id'],
                ],
            ];
        }

        // Check payment status
        $status = $processResult['status'] ?? '';

        if ($status === 'PAID') {
            return [
                'status'  => 'successful',
                'message' => [
                    'SumUp Checkout ID'    => $checkout_id,
                    'Transaction Code'     => $processResult['transaction_code'] ?? 'N/A',
                    'Transaction ID'       => $processResult['transaction_id'] ?? 'N/A',
                ],
                'paid'    => [
                    'amount'   => round($amount, 2),
                    'currency' => $currency,
                ],
            ];
        } elseif ($status === 'PENDING') {
            return [
                'status'  => 'pending',
                'message' => ['Checkout ID' => $checkout_id],
            ];
        } elseif ($status === 'FAILED') {
            $errorMsg = 'Payment failed.';
            if (isset($processResult['transactions']) && !empty($processResult['transactions'])) {
                $lastTransaction = end($processResult['transactions']);
                if (isset($lastTransaction['status'])) {
                    $errorMsg = 'Payment status: ' . $lastTransaction['status'];
                }
            }
            return [
                'status'  => 'error',
                'message' => $errorMsg,
            ];
        }

        // Handle unexpected response
        $errorMsg = 'Unexpected response from SumUp.';
        if (isset($processResult['message'])) {
            $errorMsg = $processResult['message'];
        } elseif (isset($processResult['error_message'])) {
            $errorMsg = $processResult['error_message'];
        }

        return [
            'status'  => 'error',
            'message' => $errorMsg,
        ];
    }

    /**
     * Handle payment result notification from SumUp
     * This is called when SumUp redirects back after 3DS
     * 
     * @return array Payment result
     */
    public function payment_result()
    {
        return $this->callback();
    }

    /**
     * Handle callback from SumUp (for 3DS redirects)
     * 
     * @return array Callback result
     */
    public function callback()
    {
        $api_key = $this->config['settings']['api_key'] ?? '';
        
        // Get SumUp checkout ID from various possible parameters
        $sumup_checkout_id = Filter::init('GET/checkout_id', 'hclear');
        
        if (empty($sumup_checkout_id)) {
            $sumup_checkout_id = Filter::init('GET/sumup_id', 'hclear');
        }
        
        if (empty($sumup_checkout_id)) {
            $sumup_checkout_id = Filter::init('GET/id', 'hclear');
        }
        
        if (empty($sumup_checkout_id)) {
            $sumup_checkout_id = Filter::init('POST/checkout_id', 'hclear');
        }

        // Get our checkout reference (contains WiseCP checkout ID)
        $checkout_ref = Filter::init('GET/ref', 'hclear');
        
        // Extract WiseCP checkout ID from ref (format: wisecp_CHECKOUTID_TIMESTAMP)
        $wisecp_checkout_id = 0;
        if (!empty($checkout_ref) && strpos($checkout_ref, 'wisecp_') === 0) {
            $parts = explode('_', $checkout_ref);
            if (isset($parts[1])) {
                $wisecp_checkout_id = (int) $parts[1];
            }
        }

        // Use cart page with error message as fallback since /pay/unsuccessful has a WiseCP bug
        $fail_url = 'https://web.c-servers.co.uk/cart?payment=failed';

        if (empty($sumup_checkout_id)) {
            $this->immediateRedirect($fail_url);
            return [
                'status'     => 'ERROR',
                'status_msg' => 'Checkout ID not provided in callback.',
                'return_msg' => 'OK',
            ];
        }

        // Retrieve checkout status from SumUp
        $checkoutResult = $this->sumupRequest('GET', '/checkouts/' . $sumup_checkout_id, null, $api_key);

        if (!$checkoutResult) {
            $this->immediateRedirect($fail_url);
            return [
                'status'     => 'ERROR',
                'status_msg' => 'Failed to retrieve checkout status.',
                'return_msg' => 'OK',
            ];
        }

        // Validate SumUp reference against callback reference to prevent tampering
        $sumup_checkout_ref = $checkoutResult['checkout_reference'] ?? '';
        if (!empty($checkout_ref) && !empty($sumup_checkout_ref) && $checkout_ref !== $sumup_checkout_ref) {
            $this->immediateRedirect($fail_url);
            return [
                'status'     => 'ERROR',
                'status_msg' => 'Checkout reference mismatch.',
                'return_msg' => 'OK',
            ];
        }

        // If callback ref is missing, trust SumUp reference and parse checkout id from it
        if (empty($checkout_ref) && !empty($sumup_checkout_ref)) {
            $checkout_ref = $sumup_checkout_ref;
            if (strpos($checkout_ref, 'wisecp_') === 0) {
                $parts = explode('_', $checkout_ref);
                if (isset($parts[1])) {
                    $wisecp_checkout_id = (int) $parts[1];
                }
            }
        }

        $status = $checkoutResult['status'] ?? '';

        // Get the configured currency
        $configured_currency = $this->config['settings']['currency'] ?? 'EUR';

        // Load Basket helper to work with checkouts
        Helper::Load(['Basket']);

        // Get the WiseCP checkout
        $checkout = null;
        if ($wisecp_checkout_id > 0) {
            $checkout = Basket::get_checkout($wisecp_checkout_id);
        }

        if ($status === 'PAID') {
            // Never mark as paid without a valid WiseCP checkout
            if (!$checkout) {
                $this->immediateRedirect($fail_url);
                return [
                    'status'     => 'ERROR',
                    'status_msg' => 'WiseCP checkout not found.',
                    'return_msg' => 'OK',
                ];
            }

            // Update checkout status if we have it
            $checkout_data = $checkout['data'] ?? [];
            $checkout_data['sumup_checkout_id'] = $sumup_checkout_id;
            $checkout_data['sumup_transaction_code'] = $checkoutResult['transaction_code'] ?? '';
            
            Basket::set_checkout($wisecp_checkout_id, [
                'status' => 'paid',
                'data'   => Utility::jencode($checkout_data),
            ]);

            // Schedule redirect to success page after WiseCP finishes processing
            $this->scheduleRedirect('https://web.c-servers.co.uk/pay/successful');

            return [
                'status'     => 'SUCCESS',
                'checkout'   => $checkout,
                'message'    => [
                    'SumUp Checkout ID' => $sumup_checkout_id,
                    'Transaction Code'  => $checkoutResult['transaction_code'] ?? 'N/A',
                ],
                'paid'       => [
                    'amount'   => (float) ($checkoutResult['amount'] ?? 0),
                    'currency' => $configured_currency,
                ],
                'return_msg' => 'OK',
            ];
        } elseif ($status === 'PENDING') {
            // For pending/failed, redirect immediately to avoid WiseCP error
            $this->immediateRedirect($fail_url);
            return [
                'status'     => 'PENDING',
                'status_msg' => 'Payment is still pending.',
                'return_msg' => 'OK',
            ];
        }

        // Payment failed - redirect immediately
        $this->immediateRedirect($fail_url);
        return [
            'status'     => 'ERROR',
            'status_msg' => 'Payment was not successful. Status: ' . $status,
            'return_msg' => 'OK',
        ];
    }

    /**
     * Immediately redirect the user (for error cases)
     * This prevents WiseCP from processing the error and hitting its own bug
     * 
     * @param string $url URL to redirect to
     * @return void
     */
    private function immediateRedirect($url)
    {
        // Clear any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Try header redirect first
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
            exit;
        }
        
        // Fallback to JavaScript/meta redirect
        $safe_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html><head>';
        echo '<meta http-equiv="refresh" content="0;url=' . $safe_url . '">';
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        echo '</head><body>Redirecting...</body></html>';
        exit;
    }

    /**
     * Schedule a redirect to happen after WiseCP finishes processing
     * Uses register_shutdown_function to ensure redirect happens last
     * 
     * @param string $url URL to redirect to
     * @return void
     */
    private function scheduleRedirect($url)
    {
        // Store the URL in a static variable so the shutdown function can access it
        $GLOBALS['sumup_redirect_url'] = $url;
        
        // Register shutdown function only once
        static $registered = false;
        if (!$registered) {
            $registered = true;
            register_shutdown_function(function() {
                if (isset($GLOBALS['sumup_redirect_url'])) {
                    $url = $GLOBALS['sumup_redirect_url'];
                    
                    // Clear any output buffers
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    
                    // Try header redirect first
                    if (!headers_sent()) {
                        header('Location: ' . $url, true, 302);
                        exit;
                    }
                    
                    // Fallback to JavaScript/meta redirect
                    $safe_url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                    echo '<!DOCTYPE html><html><head>';
                    echo '<meta http-equiv="refresh" content="0;url=' . $safe_url . '">';
                    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
                    echo '</head><body>Redirecting...</body></html>';
                    exit;
                }
            });
        }
    }

    /**
     * Area payment method - handles the redirect-based flow
     * This is called by WiseCP for redirect payments
     * 
     * @param string $result Success or fail page
     * @return void
     */
    public function area($result = '')
    {
        // WiseCP calls this method for redirect-based payment results
        // Redirect to appropriate page based on result
        if ($result === 'success') {
            header('Location: https://web.c-servers.co.uk/pay/successful', true, 302);
            exit;
        }

        if ($result === 'fail') {
            header('Location: https://web.c-servers.co.uk/pay/unsuccessful', true, 302);
            exit;
        }

        // Default: redirect based on checkout status check
        return null;
    }

    /**
     * Process refund
     * 
     * @param array $params Refund parameters
     * @return array|bool Refund result
     */
    public function refund($params = [])
    {
        $api_key = $this->config['settings']['api_key'] ?? '';

        // Get transaction ID from the original payment
        $transaction_id = $params['transaction_id'] ?? '';
        
        if (empty($transaction_id)) {
            $this->error = 'Transaction ID not found for refund.';
            return false;
        }

        $refundData = [];
        
        // If partial refund, specify amount
        if (isset($params['amount']) && $params['amount'] > 0) {
            $refundData['amount'] = (float) $params['amount'];
        }

        $result = $this->sumupRequest('POST', '/me/refund/' . $transaction_id, $refundData, $api_key);

        if ($result === null || (isset($result['error']) || isset($result['error_message']))) {
            $this->error = $result['error_message'] ?? $result['message'] ?? 'Refund failed.';
            return false;
        }

        return true;
    }

    /**
     * Make a request to the SumUp API
     * 
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $path API endpoint path
     * @param array|null $body Request body (for POST/PUT)
     * @param string $apiKey API key for authentication
     * @return array|null Response data or null on error
     */
    private function sumupRequest($method, $path, $body = null, $apiKey = null)
    {
        $url = $this->api_base . $path;

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($body !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            case 'GET':
            default:
                // GET is the default
                break;
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            
            // Log only critical transport errors
            if (class_exists('Modules')) {
                Modules::save_log('Payment', __CLASS__, 'sumupRequest', [
                    'method' => $method,
                    'path'   => $path,
                    'body'   => $this->sanitizeForLog($body),
                ], 'cURL Error: ' . $error);
            }
            
            return null;
        }

        curl_close($ch);

        $result = json_decode($response, true);

        // Log only API errors (4xx/5xx or explicit error payload)
        $hasApiError = $httpCode >= 400
            || isset($result['error'])
            || isset($result['error_message'])
            || isset($result['message']);

        if ($hasApiError && class_exists('Modules')) {
            Modules::save_log('Payment', __CLASS__, 'sumupRequest', [
                'method'    => $method,
                'path'      => $path,
                'body'      => $this->sanitizeForLog($body),
                'http_code' => $httpCode,
            ], $this->sanitizeForLog($result));
        }

        return $result;
    }

    /**
     * Convert WiseCP currency ID to ISO currency code
     * 
     * @param mixed $currency Currency ID or code
     * @return string ISO currency code
     */
    private function getCurrencyCode($currency)
    {
        // If it's already a currency code string
        if (is_string($currency) && strlen($currency) === 3) {
            return strtoupper($currency);
        }

        // Try to load Money helper and get currency info
        if (function_exists('Helper::Load')) {
            Helper::Load(['Money']);
        }

        if (class_exists('Money')) {
            $currencyInfo = Money::Currency($currency);
            if ($currencyInfo && isset($currencyInfo['code'])) {
                return strtoupper($currencyInfo['code']);
            }
        }

        // Default to EUR if we can't determine the currency
        return 'EUR';
    }

    /**
     * Convert WiseCP currency to currency ID (if possible)
     *
     * @param mixed $currency Currency ID or code
     * @return int|false Currency ID or false if not found
     */
    private function getCurrencyId($currency)
    {
        if (is_numeric($currency)) {
            return (int) $currency;
        }

        if (function_exists('Helper::Load')) {
            Helper::Load(['Money']);
        }

        if (class_exists('Money')) {
            $currencyInfo = Money::Currency($currency);
            if ($currencyInfo && isset($currencyInfo['id'])) {
                return (int) $currencyInfo['id'];
            }
        }

        return false;
    }

    /**
     * Remove sensitive payment data before logs.
     *
     * @param mixed $data
     * @return mixed
     */
    private function sanitizeForLog($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $masked_keys = [
            'number',
            'cvv',
            'cvc',
            'security_code',
            'card_number',
            'pan',
        ];

        $sanitized = [];
        foreach ($data as $key => $value) {
            $normalized_key = strtolower((string) $key);
            if (in_array($normalized_key, $masked_keys, true)) {
                $sanitized[$key] = '***MASKED***';
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitizeForLog($value) : $value;
        }

        return $sanitized;
    }

}
