<?php
/**
 * Passimpay payment method - Admin
 * @author Passimpay
 * @see https://passimpay.io/
 */
class ControllerExtensionPaymentPassimpay extends Controller {
    private const PAYMENT_TYPE_BOTH = 0;
    private const PAYMENT_TYPE_CRYPTO = 1;
    private const PAYMENT_TYPE_CARD = 2;

    public function index() {
        $this->load->language('extension/payment/passimpay');
        $this->load->model('setting/setting');
        $this->load->model('localisation/order_status');

        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_passimpay', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['entry_api_key'] = $this->language->get('entry_api_key');
        $data['entry_platform_id'] = $this->language->get('entry_platform_id');
        $data['entry_payment_type'] = $this->language->get('entry_payment_type');
        $data['entry_payment_type_both'] = $this->language->get('entry_payment_type_both');
        $data['entry_payment_type_crypto'] = $this->language->get('entry_payment_type_crypto');
        $data['entry_payment_type_card'] = $this->language->get('entry_payment_type_card');
        $data['entry_card_notice'] = $this->language->get('entry_card_notice');
        $data['entry_order_status_success'] = $this->language->get('entry_order_status_success');
        $data['entry_order_status_pending'] = $this->language->get('entry_order_status_pending');
        $data['entry_order_status_failed'] = $this->language->get('entry_order_status_failed');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_sort_order'] = $this->language->get('entry_sort_order');
        $data['entry_geo_zone'] = $this->language->get('entry_geo_zone');
        $data['entry_callback_url'] = $this->language->get('entry_callback_url');
        $data['entry_return_url'] = $this->language->get('entry_return_url');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');
        $data['tab_general'] = $this->language->get('tab_general');
        $data['text_home'] = $this->language->get('text_home');
        $data['text_extension'] = $this->language->get('text_extension');
        $data['text_all_zones'] = $this->language->get('text_all_zones');
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['error_api_key'] = isset($this->error['api_key']) ? $this->error['api_key'] : '';
        $data['error_platform_id'] = isset($this->error['platform_id']) ? $this->error['platform_id'] : '';

        $data['breadcrumbs'] = [];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        ];
        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/passimpay', 'user_token=' . $this->session->data['user_token'], true)
        ];

        $data['action'] = $this->url->link('extension/payment/passimpay', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $keys = [
            'payment_passimpay_api_key',
            'payment_passimpay_platform_id',
            'payment_passimpay_payment_type',
            'payment_passimpay_order_status_success_id',
            'payment_passimpay_order_status_pending_id',
            'payment_passimpay_order_status_failed_id',
            'payment_passimpay_status',
            'payment_passimpay_sort_order',
            'payment_passimpay_geo_zone_id',
        ];
        foreach ($keys as $key) {
            if (isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
            } else {
                $data[$key] = $this->config->get($key);
            }
        }
        $data['payment_passimpay_payment_type'] = (int)($data['payment_passimpay_payment_type'] ?? self::PAYMENT_TYPE_BOTH);
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $base = $this->config->get('config_url') ?? (HTTP_CATALOG ?? $this->request->server['HTTP_ORIGIN'] ?? '');
        if (defined('HTTP_SERVER')) {
            $base = rtrim(HTTP_SERVER, '/');
        }
        if (empty($base) && !empty($this->request->server['HTTP_HOST'])) {
            $protocol = (!empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $protocol . '://' . $this->request->server['HTTP_HOST'] . rtrim(dirname($this->request->server['PHP_SELF']), '/.\\');
        }
        $data['callback_url'] = $base . '/index.php?route=extension/payment/passimpay/callback';
        $data['return_url'] = $base . '/index.php?route=extension/payment/passimpay/return';

        $data['user_token'] = $this->session->data['user_token'];
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/passimpay', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/passimpay')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        if (empty($this->request->post['payment_passimpay_api_key'])) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }
        if (empty($this->request->post['payment_passimpay_platform_id'])) {
            $this->error['platform_id'] = $this->language->get('error_platform_id');
        }
        return !$this->error;
    }
}
