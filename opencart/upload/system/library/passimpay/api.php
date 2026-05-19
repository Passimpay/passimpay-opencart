<?php
/**
 * Passimpay Merchant API Client
 *
 * Single point of communication with Passimpay v2 API:
 *  - createorder  : generate payment page URL
 *  - orderstatus  : query current payment status
 *  - verifyWebhookSignature : validate incoming webhooks
 *
 * Signature scheme (v2):
 *  HMAC-SHA256 of "{platformId};{rawJsonBody};{secretKey}" using secretKey as key.
 *  Passed in x-signature header for outgoing requests, and arrives in the
 *  x-signature header for incoming webhooks.
 *
 * @see https://passimpay.gitbook.io/passimpay-api
 */
class PassimpayMerchantAPI {
    const API_URL                     = 'https://api.passimpay.io';
    const PAYMENT_STATUS_COMPLETED    = 'paid';
    const PAYMENT_STATUS_PROCESSING   = 'wait';
    const PAYMENT_STATUS_ERROR        = 'error';
    const PAYMENT_STATUS_REQUEST_FAIL = 'request_error';

    private $_platformId;
    private $_secretKey;
    private $_lastError;
    private $_lastResponse;
    private $_paymentUrl;

    public function __construct($platformId, $secretKey) {
        $this->_platformId   = (int)$platformId;
        $this->_secretKey    = (string)$secretKey;
        $this->_lastError    = '';
        $this->_lastResponse = '';
        $this->_paymentUrl   = '';
    }

    public function getError() {
        return $this->_lastError;
    }

    public function getResponse() {
        return $this->_lastResponse;
    }

    public function getPaymentUrl() {
        return $this->_paymentUrl;
    }

    /**
     * Create payment link via /v2/createorder.
     * Result URL available via $this->paymentUrl on success.
     *
     * @param array $args  Keys: order_id, amount, symbol, type (optional),
     *                     firstName/lastName/email (optional)
     * @return self
     */
    public function createOrder(array $args) {
        $body = array(
            'platformId' => $this->_platformId,
            'orderId'    => (string)$args['order_id'],
            'amount'     => (string)$args['amount'],
        );
        if (!empty($args['symbol']))    { $body['symbol']    = strtoupper($args['symbol']); }
        if (isset($args['type']))       { $body['type']      = (int)$args['type']; }
        if (!empty($args['firstName'])) { $body['firstName'] = $args['firstName']; }
        if (!empty($args['lastName']))  { $body['lastName']  = $args['lastName']; }
        if (!empty($args['email']))     { $body['email']     = $args['email']; }

        $this->_send(self::API_URL . '/v2/createorder', $body);
        return $this;
    }

    /**
     * Query /v2/orderstatus.
     * @param string $orderId
     * @return string  One of: paid, wait, error, request_error
     */
    public function getOrderStatus($orderId) {
        $body = array(
            'platformId' => $this->_platformId,
            'orderId'    => (string)$orderId,
        );
        $this->_send(self::API_URL . '/v2/orderstatus', $body);

        $json = json_decode($this->_lastResponse, true);
        if (is_array($json) && isset($json['result']) && (int)$json['result'] === 1) {
            return isset($json['status']) ? $json['status'] : self::PAYMENT_STATUS_ERROR;
        }
        return self::PAYMENT_STATUS_REQUEST_FAIL;
    }

    /**
     * Verify a webhook signature.
     *
     * @param string $rawJsonBody       Raw body from php://input
     * @param string $receivedSignature Value of x-signature header
     * @return bool
     */
    public function verifyWebhookSignature($rawJsonBody, $receivedSignature) {
        if (empty($receivedSignature) || empty($rawJsonBody)) {
            return false;
        }
        $contract = $this->_platformId . ';' . $rawJsonBody . ';' . $this->_secretKey;
        $expected = hash_hmac('sha256', $contract, $this->_secretKey);
        return hash_equals($expected, (string)$receivedSignature);
    }

    /**
     * Send a signed POST to the v2 API.
     */
    private function _send($url, array $body) {
        $this->_lastError    = '';
        $this->_lastResponse = '';
        $this->_paymentUrl   = '';

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        $contract = $this->_platformId . ';' . $jsonBody . ';' . $this->_secretKey;
        $signature = hash_hmac('sha256', $contract, $this->_secretKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'x-signature: ' . $signature,
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_FOLLOWLOCATION => true,
        ));
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->_lastError = 'cURL: ' . curl_error($ch);
            curl_close($ch);
            return;
        }
        curl_close($ch);

        $this->_lastResponse = $response;
        $json = json_decode($response, true);
        if (!is_array($json)) {
            $this->_lastError = 'Invalid JSON response';
            return;
        }
        if (isset($json['result']) && (int)$json['result'] === 1) {
            if (!empty($json['url'])) {
                $this->_paymentUrl = $json['url'];
            }
        } else {
            $this->_lastError = isset($json['message']) ? $json['message'] : 'API error';
        }
    }
}
