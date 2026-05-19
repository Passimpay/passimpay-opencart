<?php
/**
 * Passimpay payment - Catalog (ocStore 2.3.x)
 *
 * Routes:
 *   extension/payment/passimpay                 - confirm button on checkout
 *   extension/payment/passimpay/confirm         - create payment, redirect to Passimpay
 *   extension/payment/passimpay/callback        - server-to-server webhook from Passimpay
 *   extension/payment/passimpay/callback_return - browser return URL
 */
require_once DIR_SYSTEM . 'library/passimpay/api.php';

class ControllerExtensionPaymentPassimpay extends Controller {

    public function index() {
        $this->load->language('extension/payment/passimpay');
        $data['button_confirm']       = $this->language->get('button_confirm');
        $data['text_loading']         = $this->language->get('text_loading');
        $data['text_redirect_notice'] = $this->language->get('text_redirect_notice');
        $data['confirm_url']          = $this->url->link('extension/payment/passimpay/confirm', '', true);

        return $this->load->view('extension/payment/passimpay', $data);
    }

    /**
     * Create Passimpay order, redirect customer to hosted payment page.
     */
    public function confirm() {
        $this->load->language('extension/payment/passimpay');

        if (empty($this->session->data['payment_method']['code']) ||
            $this->session->data['payment_method']['code'] !== 'passimpay') {
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

        $api_key     = $this->config->get('passimpay_api_key');
        $platform_id = (int)$this->config->get('passimpay_platform_id');
        if (empty($api_key) || !$platform_id) {
            $this->_log('confirm: API Key or Platform ID not set');
            $this->response->redirect($this->url->link('checkout/failure', '', true));
            return;
        }

        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $amount = number_format((float)$amount, 2, '.', '');

        $api = new PassimpayMerchantAPI($platform_id, $api_key);
        $api->createOrder(array(
            'order_id'  => (string)$order_id,
            'amount'    => $amount,
            'symbol'    => $order_info['currency_code'],
            'type'      => (int)$this->config->get('passimpay_payment_type'),
            'firstName' => isset($order_info['firstname']) ? $order_info['firstname'] : '',
            'lastName'  => isset($order_info['lastname']) ? $order_info['lastname'] : '',
            'email'     => isset($order_info['email']) ? $order_info['email'] : '',
        ));

        $payment_url = $api->getPaymentUrl();
        if (!empty($api->getError()) || empty($payment_url)) {
            $this->_log('confirm: createorder failed. error=' . $api->getError() . ' response=' . $api->getResponse());
            $this->response->redirect($this->url->link('checkout/failure', '', true));
            return;
        }

        // Mark order as pending (only if not already at a final status)
        $pending_id = (int)$this->config->get('passimpay_order_status_pending_id');
        if (!$pending_id) {
            $pending_id = (int)$this->config->get('config_order_status_id');
        }
        if ($pending_id && (int)$order_info['order_status_id'] !== $pending_id) {
            $this->model_checkout_order->addOrderHistory($order_id, $pending_id, 'Passimpay: awaiting payment', false);
        }

        $this->response->redirect($payment_url);
    }

    /**
     * Webhook handler. Supports two formats:
     *
     *   v2 (JSON, official):
     *      Content-Type: application/json
     *      Body: {"platformId":1,"paymentId":72,"orderId":"5","txhash":"..."}
     *      Header: x-signature: HMAC-SHA256(platformId;json_encode(body);secret)
     *
     *   v1 (form-encoded, legacy for crypto):
     *      Content-Type: application/x-www-form-urlencoded
     *      Body: platform_id=1&payment_id=72&order_id=5&amount=...&hash=...
     *      Signature inside body, algorithm not publicly documented.
     *      We verify by re-querying /v2/orderstatus instead.
     */
    public function callback() {
        $raw_body    = file_get_contents('php://input');
        $api_key     = $this->config->get('passimpay_api_key');
        $platform_id = (int)$this->config->get('passimpay_platform_id');

        if (empty($api_key) || !$platform_id) {
            $this->_log('webhook: module not configured');
            $this->response->addHeader('HTTP/1.1 200 OK');
            $this->response->setOutput('ok');
            return;
        }

        // ---------- Detect format ----------
        $is_json     = false;
        $json_body   = null;
        if (!empty($raw_body)) {
            $tmp = json_decode($raw_body, true);
            if (is_array($tmp) && !empty($tmp)) {
                $is_json   = true;
                $json_body = $tmp;
            }
        }

        // ============================================================
        // FORMAT v2 - JSON body + x-signature header
        // ============================================================
        if ($is_json) {
            // Read x-signature (case-insensitive + HTTP_ fallback)
            $signature = '';
            if (function_exists('getallheaders')) {
                foreach (getallheaders() as $name => $value) {
                    if (strtolower($name) === 'x-signature') {
                        $signature = $value;
                        break;
                    }
                }
            }
            if ($signature === '' && isset($_SERVER['HTTP_X_SIGNATURE'])) {
                $signature = $_SERVER['HTTP_X_SIGNATURE'];
            }

            // Re-encode the decoded body (same way as sender does)
            $reencoded = json_encode($json_body);
            $expected  = hash_hmac('sha256', $platform_id . ';' . $reencoded . ';' . $api_key, $api_key);

            if (!hash_equals($expected, (string)$signature)) {
                $this->_log('webhook(v2): signature mismatch. expected=' . $expected . ' received=' . $signature . ' body=' . $reencoded);
                $this->response->addHeader('HTTP/1.1 403 Forbidden');
                $this->response->setOutput('invalid signature');
                return;
            }

            if (!empty($json_body['platformId']) && (int)$json_body['platformId'] !== $platform_id) {
                $this->_log('webhook(v2): platform mismatch');
                $this->response->addHeader('HTTP/1.1 200 OK');
                $this->response->setOutput('ok');
                return;
            }

            $order_id = 0;
            if (!empty($json_body['orderId']))  { $order_id = (int)$json_body['orderId']; }
            if (!$order_id && !empty($json_body['order_id'])) { $order_id = (int)$json_body['order_id']; }
            if (!$order_id) {
                $this->_log('webhook(v2): no orderId');
                $this->response->addHeader('HTTP/1.1 200 OK');
                $this->response->setOutput('ok');
                return;
            }

            $this->_log('webhook(v2): valid signature, orderId=' . $order_id . ' body=' . $raw_body);

            // v2 webhook does not carry status - fetch from API
            $api = new PassimpayMerchantAPI($platform_id, $api_key);
            $status = $api->getOrderStatus($order_id);
            $this->_log('webhook(v2): orderId=' . $order_id . ' apiStatus=' . $status . ' apiResp=' . $api->getResponse());

            $this->_applyOrderStatus($order_id, $status, 'webhook-v2');

            $this->response->addHeader('HTTP/1.1 200 OK');
            $this->response->setOutput('ok');
            return;
        }

        // ============================================================
        // FORMAT v1 - form-encoded (legacy crypto webhook)
        // ============================================================
        $payload = !empty($_POST) ? $_POST : array();
        if (empty($payload) && !empty($raw_body)) {
            parse_str($raw_body, $parsed);
            if (!empty($parsed)) { $payload = $parsed; }
        }

        if (empty($payload)) {
            $this->_log('webhook(v1): empty payload. ct='
                . (isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : 'none')
                . ' raw=' . substr((string)$raw_body, 0, 500));
            $this->response->addHeader('HTTP/1.1 200 OK');
            $this->response->setOutput('ok');
            return;
        }

        if (!empty($payload['platform_id']) && (int)$payload['platform_id'] !== $platform_id) {
            $this->_log('webhook(v1): platform mismatch. got=' . $payload['platform_id']);
            $this->response->addHeader('HTTP/1.1 200 OK');
            $this->response->setOutput('ok');
            return;
        }

        $order_id = isset($payload['order_id']) ? (int)$payload['order_id'] : 0;
        if (!$order_id) {
            $this->_log('webhook(v1): no order_id');
            $this->response->addHeader('HTTP/1.1 200 OK');
            $this->response->setOutput('ok');
            return;
        }

        $this->_log('webhook(v1): received orderId=' . $order_id . ' payload=' . $raw_body);

        // We cannot verify the v1 `hash` field (algorithm is not public).
        // Instead, defend by asking Passimpay's API directly for the actual status.
        // This makes the webhook a trigger to re-check, and the API response is the source of truth.
        $api = new PassimpayMerchantAPI($platform_id, $api_key);
        $status = $api->getOrderStatus($order_id);
        $this->_log('webhook(v1): orderId=' . $order_id . ' apiStatus=' . $status . ' apiResp=' . $api->getResponse());

        $this->_applyOrderStatus($order_id, $status, 'webhook-v1');

        $this->response->addHeader('HTTP/1.1 200 OK');
        $this->response->setOutput('ok');
    }

    /**
     * Browser return URL after the customer comes back from Passimpay.
     * (Method named callback_return because PHP 5.x rejects "return" as a method name.)
     */
    public function callback_return() {
        $order_id = isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 0;
        if ($order_id) {
            $this->response->redirect($this->url->link('checkout/success', '', true));
        } else {
            $this->response->redirect($this->url->link('common/home', '', true));
        }
    }

    /**
     * Apply Passimpay status to an OC order, with duplicate-protection.
     */
    private function _applyOrderStatus($order_id, $status, $source) {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            $this->_log($source . ': order ' . $order_id . ' not found');
            return;
        }

        $current    = (int)$order_info['order_status_id'];
        $success_id = (int)$this->config->get('passimpay_order_status_success_id');
        $pending_id = (int)$this->config->get('passimpay_order_status_pending_id');
        $failed_id  = (int)$this->config->get('passimpay_order_status_failed_id');
        if (!$success_id) { $success_id = 5; }
        if (!$pending_id) { $pending_id = (int)$this->config->get('config_order_status_id'); }
        if (!$failed_id)  { $failed_id  = 10; }

        if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED) {
            if ($current !== $success_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $success_id, 'Passimpay (' . $source . '): payment completed', true);
                $this->_log($source . ': order ' . $order_id . ' -> completed');
            }
        } elseif ($status === PassimpayMerchantAPI::PAYMENT_STATUS_ERROR) {
            if ($current !== $failed_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $failed_id, 'Passimpay (' . $source . '): payment failed', false);
                $this->_log($source . ': order ' . $order_id . ' -> failed');
            }
        } else {
            // wait / request_error — leave as pending (or set pending if not yet)
            if ($pending_id && $current !== $pending_id && $current !== $success_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $pending_id, 'Passimpay (' . $source . '): ' . $status, false);
            }
        }
    }

    private function _log($msg) {
        $this->log->write('[Passimpay] ' . $msg);
    }
}
