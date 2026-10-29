<?php
/**
 * Passimpay payment - Catalog (ocStore 2.3.x)
 *
 * Routes:
 *   extension/payment/passimpay                 - confirm button on checkout
 *   extension/payment/passimpay/confirm         - create payment, redirect to Passimpay
 *                                                 (both methods: passimpay and passimpay_card)
 *   extension/payment/passimpay/callback        - server-to-server webhook from Passimpay
 *   extension/payment/passimpay/callback_return - browser return URL
 *   extension/payment/passimpay/check           - status re-check for the admin "Check status"
 *                                                 button (signed link, see admin checkOrderStatus)
 *
 * The webhook and the return page never take a status from the request: they ask
 * /v2/orderstatus, and the API answer decides the order status. The status is written
 * before the reply (model applyStatus); only the customer's "paid" email is sent after it.
 */
require_once DIR_SYSTEM . 'library/passimpay/api.php';

class ControllerExtensionPaymentPassimpay extends Controller {
    const MAX_ATTEMPTS = 20;

    public function index() {
        $this->load->language('extension/payment/passimpay');
        $data['button_confirm']       = $this->language->get('button_confirm');
        $data['text_loading']         = $this->language->get('text_loading');
        $data['text_redirect_notice'] = $this->language->get('text_redirect_notice');
        $data['confirm_url']          = $this->url->link('extension/payment/passimpay/confirm', '', true);

        return $this->load->view('extension/payment/passimpay', $data);
    }

    /**
     * Create (or reuse) the Passimpay invoice and redirect the customer to the payment page.
     */
    public function confirm() {
        $this->load->language('extension/payment/passimpay');

        $code = isset($this->session->data['payment_method']['code']) ? $this->session->data['payment_method']['code'] : '';
        if ($code !== 'passimpay' && $code !== 'passimpay_card') {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }
        $order_id = isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 0;
        if (!$order_id) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            $this->response->redirect($this->url->link('checkout/checkout', '', true));
            return;
        }

        $api = $this->_api();
        if (!$api) {
            $this->_log('confirm: API Key or Platform ID not set');
            $this->response->redirect($this->url->link('checkout/failure', '', true));
            return;
        }

        $this->load->model('extension/payment/' . $code);
        $method = $this->{'model_extension_payment_' . $code};
        $this->load->model('extension/payment/passimpay');
        $model = $this->model_extension_payment_passimpay;

        $amount   = $model->orderAmount($order_info);
        $currency = strtoupper($order_info['currency_code']);
        $type     = $method->paymentType();

        // Same order and amount: reuse the open invoice. Closed (expired, or the order was
        // closed by the merchant): next attempt, with a new invoice id. Paid: done.
        // The local record decides first; Passimpay's status is applied to the order like a
        // webhook (applyStatus), never only shown to the customer.
        $invoice_id  = '';
        $payment_url = '';
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $invoice_id = PassimpayMerchantAPI::makeInvoiceId($order_id, $amount, $currency, $type, $attempt);
            $row = $model->invoiceForConfirm($order_id, $invoice_id);
            if (!$row) {
                break;
            }
            $page = ModelExtensionPaymentPassimpay::confirmPage($row);
            if ($page === 'next') {
                continue;
            }
            if ($page !== 'open') {
                $this->_log('confirm: order ' . $order_id . ' invoice ' . $invoice_id . ' ' . (is_array($row) ? $row['status'] : $row) . ' -> ' . $page);
                $this->response->redirect($this->url->link('checkout/' . $page, '', true));
                return;
            }
            $status = $api->getOrderStatus($invoice_id);
            if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED || $status === PassimpayMerchantAPI::PAYMENT_STATUS_ERROR) {
                list($page, $outcome) = $model->applyForConfirm($order_id, $invoice_id, $status, $api->getPaidInfo());
                $this->_log('confirm: order ' . $order_id . ' invoice ' . $invoice_id . ' ' . $status . ' -> ' . $outcome . ' -> ' . $page);
                if ($page === 'next') {
                    continue;
                }
                $this->_redirectNow($this->url->link('checkout/' . $page, '', true));
                $model->sendNotifications();
                exit();
            }
            // Recorded, but createorder never answered (url empty): created again with the same id.
            $payment_url = $row['url'];
            break;
        }
        if ($attempt >= self::MAX_ATTEMPTS) {
            $invoice_id = PassimpayMerchantAPI::makeInvoiceId($order_id, $amount, $currency, $type, time());
        }

        if ($payment_url === '') {
            // Recorded before Passimpay knows the id, so a webhook that comes before the
            // answer finds an open invoice with its amount.
            $model->saveInvoice($invoice_id, $order_id, $amount, $currency, $type, '');
            $api->createOrder(array(
                'order_id'  => $invoice_id,
                'amount'    => $amount,
                'symbol'    => $currency,
                'type'      => $type,
                'firstName' => isset($order_info['firstname']) ? $order_info['firstname'] : '',
                'lastName'  => isset($order_info['lastname']) ? $order_info['lastname'] : '',
                'email'     => isset($order_info['email']) ? $order_info['email'] : '',
            ));
            $payment_url = $api->getPaymentUrl();
            if ($api->getError() !== '' || empty($payment_url)) {
                $this->_log('confirm: order ' . $order_id . ' invoice ' . $invoice_id . ' createorder failed: ' . $api->getError()
                    . ', invoice ' . $model->closeUnsent($order_id, $invoice_id));
                $this->response->redirect($this->url->link('checkout/failure', '', true));
                return;
            }
            $model->saveInvoice($invoice_id, $order_id, $amount, $currency, $type, $payment_url);
            $this->_log('confirm: order ' . $order_id . ' invoice ' . $invoice_id . ' ' . $amount . ' ' . $currency . ' type ' . $type);
        }

        $method->setOrderPaymentTitle($order_id);

        $current    = (int)$order_info['order_status_id'];
        $pending_id = (int)$this->config->get('passimpay_order_status_pending_id');
        if (!$pending_id) {
            $pending_id = (int)$this->config->get('config_order_status_id');
        }
        if (!$pending_id || $current === $pending_id) {
            $this->response->redirect($payment_url);
            return;
        }

        // The first status change sends the order emails, which can take longer than the
        // web server waits, so the customer is redirected first.
        $this->_redirectNow($payment_url);
        $model->markPending($order_id, $invoice_id, $pending_id, 'Passimpay: awaiting payment, invoice ' . $invoice_id);
        exit();
    }

    /**
     * Webhook handler. Supports two formats:
     *
     *   v2 (JSON, official):
     *      Content-Type: application/json
     *      Body: {"platformId":1,"paymentId":72,"orderId":"5-0-1a2b3c4d","txhash":"..."}
     *      Header: x-signature: HMAC-SHA256(platformId;body;secret)
     *
     *   v1 (form-encoded, legacy, older platforms):
     *      Content-Type: application/x-www-form-urlencoded
     *      Body: platform_id=1&payment_id=72&order_id=5&amount=...&hash=...
     *      hash = HMAC-SHA256(http_build_query(fields), secret), see verifyLegacyHash().
     *
     * Both formats are rejected without a valid signature, before any API call.
     * Replies: 200 handled or ignored, 403 sender IP or signature rejected, 500 the status
     * check failed or the order is busy, 503 still "wait" after polling on an open invoice
     * (Passimpay retries non-200 replies). The order status is written
     * before the reply; the customer's "paid" email is sent after it.
     */
    public function callback() {
        $raw_body = file_get_contents('php://input');
        $ip       = isset($this->request->server['REMOTE_ADDR']) ? $this->request->server['REMOTE_ADDR'] : '';

        $api = $this->_api();
        if (!$api) {
            $this->_log('webhook: module not configured');
            $this->_reply(200, 'ok');
            return;
        }
        if (!PassimpayMerchantAPI::ipAllowed($ip, $this->config->get('passimpay_allowed_ips'))) {
            $this->_log('webhook: rejected IP ' . $ip);
            $this->_reply(403, 'forbidden');
            return;
        }

        $json_body = !empty($raw_body) ? json_decode($raw_body, true) : null;

        if (is_array($json_body) && !empty($json_body)) {
            // ---------- v2: JSON body + x-signature header ----------
            if (!$api->verifyWebhookSignature($raw_body, $this->_signatureHeader())) {
                $this->_log('webhook(v2): signature mismatch from ' . $ip);
                $this->_reply(403, 'invalid signature');
                return;
            }
            if (!empty($json_body['platformId']) && (int)$json_body['platformId'] !== (int)$this->config->get('passimpay_platform_id')) {
                $this->_log('webhook(v2): platform mismatch');
                $this->_reply(200, 'ok');
                return;
            }
            if (isset($json_body['type']) && $json_body['type'] !== 'deposit') {
                $this->_reply(200, 'ok');
                return;
            }
            $invoice_id = '';
            if (!empty($json_body['orderId']))  { $invoice_id = (string)$json_body['orderId']; }
            if ($invoice_id === '' && !empty($json_body['order_id'])) { $invoice_id = (string)$json_body['order_id']; }
            $source = 'webhook-v2';
            $this->_log('webhook(v2): invoice ' . $invoice_id . ' amount ' . (isset($json_body['amount']) ? $json_body['amount'] : '-') . ' from ' . $ip);
        } else {
            // ---------- v1: form-encoded (legacy crypto webhook) ----------
            $payload = !empty($_POST) ? $_POST : array();
            if (empty($payload) && !empty($raw_body)) {
                parse_str($raw_body, $parsed);
                if (!empty($parsed)) { $payload = $parsed; }
            }
            if (empty($payload)) {
                $this->_log('webhook(v1): empty payload from ' . $ip . ', content-type '
                    . (isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : 'none') . ', ' . strlen((string)$raw_body) . ' bytes');
                $this->_reply(200, 'ok');
                return;
            }
            if (!$api->verifyLegacyHash($payload, $raw_body)) {
                $this->_log('webhook(v1): signature mismatch from ' . $ip);
                $this->_reply(403, 'invalid signature');
                return;
            }
            if (!empty($payload['platform_id']) && (int)$payload['platform_id'] !== (int)$this->config->get('passimpay_platform_id')) {
                $this->_log('webhook(v1): platform mismatch');
                $this->_reply(200, 'ok');
                return;
            }
            $invoice_id = isset($payload['order_id']) ? (string)$payload['order_id'] : '';
            $source = 'webhook-v1';
            $this->_log('webhook(v1): invoice ' . $invoice_id . ' from ' . $ip);
        }

        // "<order>-<attempt>-<hash>", or a bare order number for invoices made by earlier versions of this module.
        $order_id = PassimpayMerchantAPI::orderIdFromInvoiceId($invoice_id);
        if (!$order_id) {
            $this->_log($source . ': no order id in "' . $invoice_id . '", ignored');
            $this->_reply(200, 'ok');
            return;
        }

        $status = PassimpayMerchantAPI::pollWhileWaiting(function () use ($api, $invoice_id) {
            return $api->getOrderStatus($invoice_id);
        }, PassimpayMerchantAPI::WEBHOOK_WAIT_RETRIES, PassimpayMerchantAPI::WEBHOOK_WAIT_DELAY);
        if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_REQUEST_FAIL) {
            $this->_log($source . ': orderstatus failed for ' . $invoice_id . ': ' . $api->getError());
            $this->_reply(500, 'status check failed');
            return;
        }

        $this->load->model('extension/payment/passimpay');
        $model   = $this->model_extension_payment_passimpay;
        $outcome = $model->applyStatus($order_id, $invoice_id, $status, $api->getPaidInfo(), $source);
        $this->_log($source . ': order ' . $order_id . ' invoice ' . $invoice_id . ' ' . $status . ' -> ' . $outcome);
        if ($outcome === ModelExtensionPaymentPassimpay::BUSY) {
            $this->_reply(500, 'busy');
            return;
        }
        // Still "wait" after polling: not 200, so Passimpay sends it again (twice, per support).
        // A final invoice takes no more events, so there is nothing to wait for.
        if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING && strpos($outcome, ModelExtensionPaymentPassimpay::INVOICE_FINAL) !== 0) {
            $this->_replyNow(503, 'payment not confirmed yet');
            $model->sendNotifications();
            exit();
        }

        $this->_replyNow(200, 'ok');
        $model->sendNotifications();
        exit();
    }

    /**
     * Browser return URL after the customer comes back from Passimpay (successful and failed).
     * (Method named callback_return because PHP 5.x rejects "return" as a method name.)
     */
    public function callback_return() {
        $order_id = isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 0;
        if (!$order_id) {
            $this->response->redirect($this->url->link('common/home', '', true));
            return;
        }

        $this->load->model('extension/payment/passimpay');
        $model    = $this->model_extension_payment_passimpay;
        $invoices = $model->getInvoicesByOrder($order_id);
        $api      = $this->_api();
        if (!$invoices || !$api) {
            $this->response->redirect($this->url->link('checkout/success', '', true));
            return;
        }

        $invoice_id = $invoices[0]['invoice_id'];
        $status = $api->getOrderStatus($invoice_id);
        if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_REQUEST_FAIL) {
            $this->_log('return: orderstatus failed for ' . $invoice_id . ': ' . $api->getError());
            $this->response->redirect($this->url->link('checkout/success', '', true));
            return;
        }

        $outcome = $model->applyStatus($order_id, $invoice_id, $status, $api->getPaidInfo(), 'return');
        $this->_log('return: order ' . $order_id . ' invoice ' . $invoice_id . ' ' . $status . ' -> ' . $outcome);

        $target = $status === PassimpayMerchantAPI::PAYMENT_STATUS_ERROR ? 'checkout/failure' : 'checkout/success';
        $this->_redirectNow($this->url->link($target, '', true));
        $model->sendNotifications();
        exit();
    }

    /**
     * Re-check an order's invoices and apply the result. The admin gets the link from
     * admin checkOrderStatus: sig = HMAC-SHA256("check:<order_id>", API key).
     */
    public function check() {
        $order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
        $sig      = isset($this->request->get['sig']) ? (string)$this->request->get['sig'] : '';
        $api      = $this->_api();
        $json     = array('order_id' => $order_id);

        if (!$api || !$order_id || !hash_equals(PassimpayMerchantAPI::checkSignature($order_id, $this->config->get('passimpay_api_key')), $sig)) {
            $json['error'] = 'forbidden';
        } else {
            $this->load->model('extension/payment/passimpay');
            $model = $this->model_extension_payment_passimpay;
            $ids = array();
            foreach ($model->getInvoicesByOrder($order_id) as $row) {
                $ids[] = $row['invoice_id'];
            }
            if (!$ids) {
                $ids[] = (string)$order_id;
            }
            // Oldest first, so a paid invoice is applied after an expired older one.
            foreach (array_reverse($ids) as $invoice_id) {
                $status = $api->getOrderStatus($invoice_id);
                $item   = array('invoice' => $invoice_id, 'status' => $status) + $api->getPaidInfo();
                if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_REQUEST_FAIL) {
                    $item['error'] = $api->getError();
                } else {
                    $item['outcome'] = $model->applyStatus($order_id, $invoice_id, $status, $api->getPaidInfo(), 'admin');
                    $this->_log('admin check: order ' . $order_id . ' invoice ' . $invoice_id . ' ' . $status . ' -> ' . $item['outcome']);
                }
                $json['invoices'][] = $item;
            }
        }

        $this->_replyNow(200, json_encode($json), 'application/json');
        if (isset($model)) {
            $model->sendNotifications();
        }
        exit();
    }

    /** API client, or null if the API key / Platform ID is not set. */
    private function _api() {
        $api_key     = trim((string)$this->config->get('passimpay_api_key'));
        $platform_id = (int)$this->config->get('passimpay_platform_id');
        if ($api_key === '' || !$platform_id) {
            return null;
        }
        return new PassimpayMerchantAPI($platform_id, $api_key);
    }

    private function _signatureHeader() {
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower($name) === 'x-signature') {
                    return (string)$value;
                }
            }
        }
        return isset($_SERVER['HTTP_X_SIGNATURE']) ? (string)$_SERVER['HTTP_X_SIGNATURE'] : '';
    }

    private function _reply($code, $text) {
        $names = array(200 => 'OK', 403 => 'Forbidden', 500 => 'Internal Server Error');
        $this->response->addHeader('HTTP/1.1 ' . $code . ' ' . $names[$code]);
        $this->response->setOutput($text);
    }

    /** Send the reply now and keep running (the order emails come after). */
    private function _replyNow($code, $text, $type = 'text/plain') {
        http_response_code($code);
        header('Content-Type: ' . $type . '; charset=utf-8');
        header('Content-Length: ' . strlen($text));
        header('Connection: close');
        echo $text;
        $this->_finishRequest();
    }

    /** Send the redirect now and keep running. */
    private function _redirectNow($url) {
        header('Location: ' . str_replace(array('&amp;', "\n", "\r"), array('&', '', ''), $url), true, 302);
        header('Content-Length: 0');
        header('Connection: close');
        $this->_finishRequest();
    }

    private function _finishRequest() {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
        }
    }

    private function _log($msg) {
        $this->log->write('[Passimpay] ' . $msg);
    }
}
