<?php
/**
 * Passimpay payment method - Catalog (redirect, callback, return)
 */
class ControllerExtensionPaymentPassimpay extends Controller {
    private const API_URL = 'https://api.passimpay.io';

    /**
     * Some themes submit the confirm form to this action. Redirect to index (createorder + redirect).
     */
    public function confirm() {
        $this->response->redirect($this->url->link('extension/payment/passimpay', '', true));
    }

    /**
     * Called after order is created. Creates Passimpay order and redirects to payment page.
     */
    public function index() {
        $this->load->language('extension/payment/passimpay');
        $order_id = isset($this->session->data['order_id']) ? (int) $this->session->data['order_id'] : 0;
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
        $api_key = $this->config->get('payment_passimpay_api_key');
        $platform_id = (int) $this->config->get('payment_passimpay_platform_id');
        if (empty($api_key) || !$platform_id) {
            $this->log->write('Passimpay: API Key or Platform ID not set');
            $this->response->redirect($this->url->link('checkout/failure', '', true));
            return;
        }
        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $amount = number_format((float) $amount, 2, '.', '');
        $currency = strtoupper($order_info['currency_code']);
        $payment_type = (int) $this->config->get('payment_passimpay_payment_type');
        if (!in_array($payment_type, [0, 1, 2], true) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            $this->response->redirect($this->url->link('checkout/failure', '', true));
            return;
        }
        $body = [
            'platformId' => $platform_id,
            'orderId'    => (string) $order_id,
            'amount'     => $amount,
            'symbol'     => $currency,
            'type'       => $payment_type,
        ];
        $json_body = json_encode($body);
        $signature_string = $platform_id . ';' . $json_body . ';' . $api_key;
        $signature = hash_hmac('sha256', $signature_string, $api_key);
        $ch = curl_init(self::API_URL . '/v2/createorder');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json_body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-signature: ' . $signature,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            $this->log->write('Passimpay createorder CURL error: ' . $err);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
            return;
        }
        $result = json_decode($response, true);
        $parts = is_array($result) && isset($result['url']) && is_string($result['url']) ? parse_url($result['url']) : false;
        if ($http_code === 200 && is_array($result) && isset($result['result']) && $result['result'] === 1
            && $parts && filter_var($result['url'], FILTER_VALIDATE_URL) && $parts['scheme'] === 'https'
            && !isset($parts['user']) && !isset($parts['pass'])) {
            $this->response->redirect($result['url']);
            return;
        }
        $msg = is_array($result) && isset($result['message']) && is_string($result['message']) ? $result['message'] : 'Invalid API response';
        $this->log->write('Passimpay createorder failed: ' . $msg);
        $this->response->redirect($this->url->link('checkout/failure', '', true));
    }

    /**
     * Server callback (IPN) from Passimpay. Check order status and update.
     */
    public function callback() {
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->reply(405, 'Method not allowed');
            return;
        }
        $raw_body = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw_body === false || strlen($raw_body) > 65536) {
            $this->reply(413, 'Invalid body');
            return;
        }
        $data = json_decode($raw_body, true);
        $api_key = $this->config->get('payment_passimpay_api_key');
        $platform_id = (int) $this->config->get('payment_passimpay_platform_id');
        if (!is_string($api_key) || $api_key === '' || $platform_id <= 0) {
            $this->reply(503, 'Module not configured');
            return;
        }
        if (!$this->validateWebhook($data, $platform_id)) {
            $this->reply(400, 'Invalid payload');
            return;
        }
        $received_signature = $this->request->server['HTTP_X_SIGNATURE'] ?? '';
        $payload = json_encode($data);
        if (!is_string($received_signature) || !preg_match('/^[a-f0-9]{64}$/D', $received_signature)
            || $payload === false || !hash_equals(hash_hmac('sha256', $platform_id . ';' . $payload . ';' . $api_key, $api_key), $received_signature)) {
            $this->reply(403, 'Invalid signature');
            return;
        }
        $order_id = (int) $data['orderId'];
        // Последовательная обработка не позволяет повторному уведомлению откатить статус.
        $lock = 'pp_oc_' . substr(hash('sha256', DB_DATABASE . ';' . DB_PREFIX . ';' . $order_id), 0, 50);
        $locked = $this->db->query("SELECT GET_LOCK('" . $lock . "', 0) AS locked");
        if ((int) $locked->row['locked'] !== 1) {
            $this->reply(503, 'Order is busy');
            return;
        }
        try {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($order_id);
            if (!$order_info || $order_info['payment_code'] !== 'passimpay') {
                $this->reply(404, 'Order not found');
                return;
            }
            $pending_id = (int) $this->config->get('payment_passimpay_order_status_pending_id');
            $success_id = (int) $this->config->get('payment_passimpay_order_status_success_id');
            $failed_id = (int) $this->config->get('payment_passimpay_order_status_failed_id');
            if ($success_id <= 0 || $failed_id <= 0) {
                $this->reply(503, 'Order statuses not configured');
                return;
            }
            if ((int) $order_info['order_status_id'] === $success_id) {
                $this->reply(200, 'ok');
                return;
            }
            $body = [
                'platformId' => $platform_id,
                'orderId'    => (string) $order_id,
            ];
            $json_body = json_encode($body);
            $signature_string = $platform_id . ';' . $json_body . ';' . $api_key;
            $signature = hash_hmac('sha256', $signature_string, $api_key);
            $ch = curl_init(self::API_URL . '/v2/orderstatus');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json_body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-signature: ' . $signature,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $result = is_string($response) ? json_decode($response, true) : null;
            if ($http_code !== 200 || !is_array($result) || !isset($result['result'], $result['status']) || $result['result'] !== 1
                || !in_array($result['status'], ['paid', 'wait', 'error'], true)) {
                $this->reply(503, 'Status check failed');
                return;
            }
            $status = $result['status'];
            $target_id = $status === 'paid' ? $success_id : ($status === 'error' ? $failed_id : $pending_id);
            $comments = ['paid' => 'Passimpay: payment completed', 'error' => 'Passimpay: payment failed', 'wait' => 'Passimpay: pending'];
            if ($target_id > 0 && (int) $order_info['order_status_id'] !== $target_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $target_id, $comments[$status], false);
            }
            $this->reply(200, 'ok');
        } catch (\Throwable $e) {
            $this->log->write('Passimpay: notification processing failed');
            $this->reply(503, 'Notification processing failed');
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . $lock . "')");
        }
    }

    private function reply($code, $text) {
        $reasons = [200 => 'OK', 400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found',
            405 => 'Method Not Allowed', 413 => 'Payload Too Large', 503 => 'Service Unavailable'];
        $this->response->addHeader('HTTP/1.1 ' . $code . ' ' . $reasons[$code]);
        $this->response->setOutput($text);
    }


    /**
     * Return URL - customer comes back from Passimpay. Redirect to success.
     */
    public function return() {
        $order_id = isset($this->session->data['order_id']) ? (int) $this->session->data['order_id'] : 0;
        if ($order_id) {
            $this->response->redirect($this->url->link('checkout/success', '', true));
        } else {
            $this->response->redirect($this->url->link('common/home', '', true));
        }
    }
    private function validateWebhook($params, $platform_id)
    {
        if (!is_array($params) || !isset($params['type'], $params['platformId'], $params['orderId'])
            || $params['type'] !== 'deposit' || !is_int($params['platformId']) || $params['platformId'] <= 0
            || $params['platformId'] !== $platform_id || !is_string($params['orderId'])
            || strlen($params['orderId']) > 64 || !preg_match('/^[1-9][0-9]*$/D', $params['orderId'])
            || filter_var($params['orderId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return false;
        }
        foreach (['paymentId', 'confirmations', 'destinationTag'] as $field) {
            if (array_key_exists($field, $params) && (!is_int($params[$field]) || $params[$field] < ($field === 'paymentId' ? 1 : 0))) {
                return false;
            }
        }
        foreach (['amount', 'amountReceive', 'feeService', 'feeNetwork'] as $field) {
            if (array_key_exists($field, $params) && (!is_string($params[$field]) || strlen($params[$field]) > 128
                || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $params[$field]))) {
                return false;
            }
        }
        foreach (['txhash', 'addressFrom', 'addressTo'] as $field) {
            if (array_key_exists($field, $params) && (!is_string($params[$field]) || strlen($params[$field]) > 512)) {
                return false;
            }
        }
        return true;
    }
}
