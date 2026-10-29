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

    /** Webhook: re-ask orderstatus this many times, this many seconds apart, while it says "wait". */
    const WEBHOOK_WAIT_RETRIES        = 4;
    const WEBHOOK_WAIT_DELAY          = 5;

    /** Default checkout logo (image/ folder), used until the admin picks another one or clears it. */
    const DEFAULT_LOGO                = 'catalog/passimpay/passimpay_logo.png';

    private $_platformId;
    private $_secretKey;
    private $_lastError;
    private $_lastResponse;
    private $_paymentUrl;

    public function __construct($platformId, $secretKey) {
        $this->_platformId   = (int)$platformId;
        $this->_secretKey    = trim((string)$secretKey);
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
        $receivedSignature = strtolower(trim((string)$receivedSignature));
        if ($receivedSignature === '' || empty($rawJsonBody) || $this->_secretKey === '' || !$this->_platformId) {
            return false;
        }
        if (hash_equals($this->_sign($rawJsonBody), $receivedSignature)) {
            return true;
        }
        $decoded = json_decode($rawJsonBody, true);
        if (!is_array($decoded)) {
            return false;
        }
        return hash_equals($this->_sign(json_encode($decoded)), $receivedSignature);
    }

    /**
     * Status of the last getOrderStatus() call: amountPaid and currency ('' if absent).
     */
    public function getPaidInfo() {
        $json = json_decode($this->_lastResponse, true);
        return array(
            'amountPaid' => isset($json['amountPaid']) ? (string)$json['amountPaid'] : '',
            'currency'   => isset($json['currency']) ? (string)$json['currency'] : '',
        );
    }

    /**
     * PassimPay orderId for an OpenCart order: <order>-<attempt>-<8 hex>.
     * createorder returns the existing invoice (old amount) for a reused orderId, so the id
     * changes with the amount, currency and type, and an expired invoice moves to the next attempt.
     */
    public static function makeInvoiceId($order_id, $amount, $currency, $type, $attempt) {
        $order_id = (int)$order_id;
        $attempt  = (int)$attempt;
        return $order_id . '-' . $attempt . '-' . substr(sha1($order_id . '|' . $amount . '|' . strtoupper($currency) . '|' . (int)$type . '|' . $attempt), 0, 8);
    }

    /**
     * Call $fetch() until it returns something other than "wait", at most 1 + $retries
     * times, calling $sleep($delay) in between. Returns the last status.
     * Passimpay can send the webhook a few seconds before orderstatus reports "paid"
     * (seen live: 7 s), and doesn't always send another one after.
     */
    public static function pollWhileWaiting($fetch, $retries, $delay, $sleep = 'sleep') {
        $status = call_user_func($fetch);
        for ($i = 0; $i < $retries && $status === self::PAYMENT_STATUS_PROCESSING; $i++) {
            call_user_func($sleep, $delay);
            $status = call_user_func($fetch);
        }
        return $status;
    }

    /**
     * OpenCart order id from a PassimPay orderId: "<order>-<attempt>-<hash>" or a bare
     * order number (invoices made by earlier versions of this module). 0 if neither.
     */
    public static function orderIdFromInvoiceId($invoice_id) {
        return preg_match('/^(\d+)(?:-\d+-[0-9a-f]{8})?$/D', (string)$invoice_id, $m) ? (int)$m[1] : 0;
    }

    /**
     * Sender check for webhooks. $listText: IPs or CIDRs, one per line or comma-separated.
     * Empty list = any sender. Entries that don't parse (see parseIpEntry) never match,
     * so a typo closes the filter instead of opening it.
     */
    public static function ipAllowed($ip, $listText) {
        $list = preg_split('/[\s,]+/', (string)$listText, -1, PREG_SPLIT_NO_EMPTY);
        if (!$list) {
            return true;
        }
        $addr = @inet_pton((string)$ip);
        if ($addr === false) {
            return false;
        }
        foreach ($list as $entry) {
            $net = self::parseIpEntry($entry);
            if (!$net || strlen($net[0]) !== strlen($addr)) {
                continue;
            }
            list($subnet, $bits) = $net;
            $bytes = (int)floor($bits / 8);
            $rest  = $bits % 8;
            if (substr($addr, 0, $bytes) !== substr($subnet, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = chr((0xff << (8 - $rest)) & 0xff);
            if ((substr($addr, $bytes, 1) & $mask) === (substr($subnet, $bytes, 1) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * One allow-list entry: array(packed address, prefix bits), or null if it is not a plain
     * IPv4/IPv6 address or CIDR with a prefix of 1..32 (IPv4) / 1..128 (IPv6). /0 is refused:
     * an empty list already means "any sender".
     */
    public static function parseIpEntry($entry) {
        if (!preg_match('~^([0-9A-Fa-f:.]+)(?:/(\d{1,3}))?$~D', trim((string)$entry), $m)) {
            return null;
        }
        $subnet = @inet_pton($m[1]);
        if ($subnet === false) {
            return null;
        }
        $max  = strlen($subnet) * 8;
        $bits = isset($m[2]) ? (int)$m[2] : $max;
        if ($bits < 1 || $bits > $max) {
            return null;
        }
        return array($subnet, $bits);
    }

    /** Allow-list entries that parseIpEntry() refuses (shown as an error when saving). */
    public static function invalidIpEntries($listText) {
        $bad = array();
        foreach (preg_split('/[\s,]+/', (string)$listText, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
            if (!self::parseIpEntry($entry)) {
                $bad[] = $entry;
            }
        }
        return $bad;
    }

    /**
     * Legacy form webhook (older platforms): hash = HMAC-SHA256(http_build_query(fields), key),
     * fields in the order platform_id, payment_id, order_id, amount, txhash, address_from,
     * address_to, fee[, confirmations], as the official WordPress/PrestaShop modules check it.
     * Also accepted: the HMAC over the raw body as sent, without its "hash" field.
     *
     * @param array  $fields  the posted fields
     * @param string $rawBody php://input
     * @return bool
     */
    public function verifyLegacyHash(array $fields, $rawBody) {
        $received = isset($fields['hash']) ? strtolower(trim((string)$fields['hash'])) : '';
        if ($received === '' || $this->_secretKey === '') {
            return false;
        }
        $data = array();
        foreach (array('platform_id', 'payment_id', 'order_id', 'amount', 'txhash', 'address_from', 'address_to', 'fee') as $k) {
            $data[$k] = isset($fields[$k]) ? (string)$fields[$k] : '';
        }
        if (isset($fields['confirmations'])) {
            $data['confirmations'] = (string)$fields['confirmations'];
        }
        if (hash_equals(hash_hmac('sha256', http_build_query($data), $this->_secretKey), $received)) {
            return true;
        }
        $pairs = array();
        foreach (explode('&', (string)$rawBody) as $pair) {
            if ($pair !== '' && strpos($pair, 'hash=') !== 0) {
                $pairs[] = $pair;
            }
        }
        return $pairs && hash_equals(hash_hmac('sha256', implode('&', $pairs), $this->_secretKey), $received);
    }

    /** Invoice records: Passimpay orderId -> OpenCart order (created on install and on first use). */
    public static function invoiceTableSql($prefix) {
        return "CREATE TABLE IF NOT EXISTS `" . $prefix . "passimpay_invoice` (
            `invoice_id` VARCHAR(64) NOT NULL,
            `order_id` INT(11) NOT NULL,
            `amount` VARCHAR(32) NOT NULL,
            `currency` VARCHAR(8) NOT NULL,
            `type` TINYINT(1) NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL DEFAULT 'wait',
            `history_id` INT(11) NULL DEFAULT NULL,
            `amount_paid` VARCHAR(64) NOT NULL DEFAULT '',
            `url` TEXT NOT NULL,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`invoice_id`),
            KEY `order_id` (`order_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8";
    }

    /**
     * Payments the module did not apply to an order, kept for a manual reconciliation. One
     * row per invoice and amount paid (as /v2/orderstatus reports it): a repeat of the same
     * report adds nothing, more money on the same invoice adds a row. reason: "error" /
     * "closed" (the invoice was already closed), "partial" (the invoice expired part paid),
     * "duplicate" (the order was already paid by another invoice), "mismatch" (paid for
     * another amount), "unrecorded" (an invoice id this shop has no record of), "no order"
     * (the order was deleted).
     */
    public static function latePaymentTableSql($prefix) {
        return "CREATE TABLE IF NOT EXISTS `" . $prefix . "passimpay_late_payment` (
            `invoice_id` VARCHAR(64) NOT NULL,
            `order_id` INT(11) NOT NULL,
            `reason` VARCHAR(16) NOT NULL,
            `amount_paid` VARCHAR(64) NOT NULL DEFAULT '',
            `currency` VARCHAR(16) NOT NULL DEFAULT '',
            `source` VARCHAR(16) NOT NULL DEFAULT '',
            `date_added` DATETIME NOT NULL,
            PRIMARY KEY (`invoice_id`, `amount_paid`),
            KEY `order_id` (`order_id`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8";
    }

    /**
     * Brings tables made by 2.3-p.4/p.5 up to date: invoice.history_id, and late payments
     * keyed by invoice and amount (was one row per invoice). $db is OpenCart's DB object.
     */
    public static function upgradeTables($db, $prefix) {
        if (!$db->query("SHOW COLUMNS FROM `" . $prefix . "passimpay_invoice` LIKE 'history_id'")->num_rows) {
            $db->query("ALTER TABLE `" . $prefix . "passimpay_invoice` ADD `history_id` INT(11) NULL DEFAULT NULL AFTER `status`");
        }
        if ($db->query("SHOW INDEX FROM `" . $prefix . "passimpay_late_payment` WHERE Key_name = 'PRIMARY'")->num_rows < 2) {
            $db->query("ALTER TABLE `" . $prefix . "passimpay_late_payment` DROP PRIMARY KEY, ADD PRIMARY KEY (`invoice_id`, `amount_paid`)");
        }
    }

    /** Signature for the admin "Check status" link (catalog route extension/payment/passimpay/check). */
    public static function checkSignature($order_id, $secretKey) {
        return hash_hmac('sha256', 'check:' . (int)$order_id, trim((string)$secretKey));
    }

    private function _sign($body) {
        return hash_hmac('sha256', $this->_platformId . ';' . $body . ';' . $this->_secretKey, $this->_secretKey);
    }

    /**
     * Send a signed POST to the v2 API.
     */
    /** curl_close() does nothing since PHP 8.0 and is deprecated in 8.5. */
    private function _closeCurl($ch) {
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    }

    private function _send($url, array $body) {
        $this->_lastError    = '';
        $this->_lastResponse = '';
        $this->_paymentUrl   = '';

        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        $signature = $this->_sign($jsonBody);

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
            $this->_closeCurl($ch);
            return;
        }
        $this->_closeCurl($ch);

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
