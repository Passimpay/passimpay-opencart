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
        $body = [
            'platformId' => $platform_id,
            'orderId'    => (string) $order_id,
            'amount'     => $amount,
            'symbol'     => $currency,
            'type'       => $payment_type,
        ];
        $json_body = json_encode($body, JSON_UNESCAPED_SLASHES);
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
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            $this->log->write('Passimpay createorder CURL error: ' . $err);
            $this->response->redirect($this->url->link('checkout/failure', '', true));
            return;
        }
        $result = json_decode($response, true);
        if (!empty($result['result']) && (int) $result['result'] === 1 && !empty($result['url'])) {
            $this->response->redirect($result['url']);
            return;
        }
        $msg = isset($result['message']) ? $result['message'] : 'Unknown error';
        $this->log->write('Passimpay createorder failed: ' . $msg);
        $this->response->redirect($this->url->link('checkout/failure', '', true));
    }

    /**
     * Server callback (IPN) from Passimpay. Check order status and update.
     */
    public function callback() {
        $order_id = 0;
        if (!empty($this->request->get['order_id'])) {
            $order_id = (int) $this->request->get['order_id'];
        } elseif (!empty($this->request->post['order_id'])) {
            $order_id = (int) $this->request->post['order_id'];
        } elseif (!empty($this->request->post['orderId'])) {
            $order_id = (int) $this->request->post['orderId'];
        }
        if (!$order_id) {
            $this->response->addHeader('HTTP/1.1 400 Bad Request');
            $this->response->setOutput('order_id required');
            return;
        }
        $api_key = $this->config->get('payment_passimpay_api_key');
        $platform_id = (int) $this->config->get('payment_passimpay_platform_id');
        if (empty($api_key) || !$platform_id) {
            $this->response->addHeader('HTTP/1.1 500 Internal Server Error');
            return;
        }
        $body = [
            'platformId' => $platform_id,
            'orderId'    => (string) $order_id,
        ];
        $json_body = json_encode($body, JSON_UNESCAPED_SLASHES);
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
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response, true);
        if (empty($result['result']) || (int) $result['result'] !== 1) {
            $this->response->addHeader('HTTP/1.1 200 OK');
            $this->response->setOutput('ok');
            return;
        }
        $status = isset($result['status']) ? $result['status'] : '';
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if (!$order_info) {
            $this->response->addHeader('HTTP/1.1 200 OK');
            $this->response->setOutput('ok');
            return;
        }
        $pending_id = (int) $this->config->get('payment_passimpay_order_status_pending_id');
        $success_id = (int) $this->config->get('payment_passimpay_order_status_success_id');
        $failed_id = (int) $this->config->get('payment_passimpay_order_status_failed_id');
        if ($status === 'paid') {
            $this->model_checkout_order->addOrderHistory($order_id, $success_id, 'Passimpay: payment completed', false);
        } elseif ($status === 'error') {
            $this->model_checkout_order->addOrderHistory($order_id, $failed_id, 'Passimpay: payment failed', false);
        } else {
            if ($pending_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $pending_id, 'Passimpay: pending', false);
            }
        }
        $this->response->addHeader('HTTP/1.1 200 OK');
        $this->response->setOutput('ok');
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
}
