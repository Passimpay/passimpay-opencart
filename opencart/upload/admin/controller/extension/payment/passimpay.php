<?php
/**
 * Passimpay payment method - Admin
 * @author Passimpay
 * @see https://passimpay.io/
 */
class ControllerExtensionPaymentPassimpay extends Controller {
    private $error = [];

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

        // В админке HTTP_SERVER указывает на admin; уведомления идут в каталог магазина.
        $base = defined('HTTPS_CATALOG') && HTTPS_CATALOG ? HTTPS_CATALOG : HTTP_CATALOG;
        $base = rtrim($base, '/');
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
        if (!isset($this->request->post['payment_passimpay_api_key']) || !is_string($this->request->post['payment_passimpay_api_key']) || trim($this->request->post['payment_passimpay_api_key']) === '') {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }
        if (!isset($this->request->post['payment_passimpay_platform_id']) || filter_var($this->request->post['payment_passimpay_platform_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error['platform_id'] = $this->language->get('error_platform_id');
        }
        if (!isset($this->request->post['payment_passimpay_payment_type'])
            || !in_array($this->request->post['payment_passimpay_payment_type'], ['0', '1', '2'], true)) {
            $this->error['warning'] = $this->language->get('error_payment_type');
        }
        $allowed_statuses = array_map('intval', array_column($this->model_localisation_order_status->getOrderStatuses(), 'order_status_id'));
        foreach (['success', 'pending', 'failed'] as $status) {
            $value = $this->request->post['payment_passimpay_order_status_' . $status . '_id'] ?? null;
            if ($status === 'pending' && ($value === null || $value === '' || $value === '0')) {
                continue;
            }
            if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || !in_array((int)$value, $allowed_statuses, true)) {
                $this->error['warning'] = $this->language->get('error_order_status');
            }
        }
        return !$this->error;
    }
}
