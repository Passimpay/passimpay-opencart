<?php
/**
 * Passimpay payment - Admin (ocStore 2.3.x)
 */
require_once DIR_SYSTEM . 'library/passimpay/api.php';

class ControllerExtensionPaymentPassimpay extends Controller {
    const PAYMENT_TYPE_BOTH   = 0;
    const PAYMENT_TYPE_CRYPTO = 1;
    const PAYMENT_TYPE_CARD   = 2;

    private $error = array();

    public function index() {
        $this->load->language('extension/payment/passimpay');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('passimpay', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true));
        }

        $strings = array(
            'heading_title','text_edit','text_enabled','text_disabled','text_home','text_extension',
            'text_all_zones','text_yes','text_no','text_check_status_hint',
            'entry_api_key','entry_platform_id','entry_payment_type','entry_payment_type_both',
            'entry_payment_type_crypto','entry_payment_type_card','entry_card_notice',
            'entry_order_status_success','entry_order_status_pending','entry_order_status_failed',
            'entry_status','entry_sort_order','entry_geo_zone','entry_callback_url','entry_return_url',
            'entry_check_status_order_id',
            'button_save','button_cancel','button_check_status',
            'tab_general','tab_tools',
        );
        $data = array();
        foreach ($strings as $key) {
            $data[$key] = $this->language->get($key);
        }

        $data['error_warning']     = isset($this->error['warning'])     ? $this->error['warning']     : '';
        $data['error_api_key']     = isset($this->error['api_key'])     ? $this->error['api_key']     : '';
        $data['error_platform_id'] = isset($this->error['platform_id']) ? $this->error['platform_id'] : '';

        $data['breadcrumbs'] = array(
            array('text' => $this->language->get('text_home'),      'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], true)),
            array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true)),
            array('text' => $this->language->get('heading_title'),  'href' => $this->url->link('extension/payment/passimpay', 'token=' . $this->session->data['token'], true)),
        );

        $data['action'] = $this->url->link('extension/payment/passimpay', 'token=' . $this->session->data['token'], true);
        $data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true);
        $data['check_status_url'] = $this->url->link('extension/payment/passimpay/checkOrderStatus', 'token=' . $this->session->data['token'], true);

        $keys = array(
            'passimpay_api_key','passimpay_platform_id','passimpay_payment_type',
            'passimpay_order_status_success_id','passimpay_order_status_pending_id','passimpay_order_status_failed_id',
            'passimpay_status','passimpay_sort_order','passimpay_geo_zone_id',
        );
        foreach ($keys as $key) {
            $data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : $this->config->get($key);
        }
        $data['passimpay_payment_type'] = $data['passimpay_payment_type'] === null
            ? self::PAYMENT_TYPE_BOTH : (int)$data['passimpay_payment_type'];
        if (!$data['passimpay_order_status_success_id']) { $data['passimpay_order_status_success_id'] = 5; }
        if (!$data['passimpay_order_status_pending_id']) { $data['passimpay_order_status_pending_id'] = $this->config->get('config_order_status_id'); }
        if (!$data['passimpay_order_status_failed_id'])  { $data['passimpay_order_status_failed_id']  = 10; }

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        // Build catalog base URL for webhook + return endpoints
        $base = $this->config->get('config_url');
        if ($this->config->get('config_secure') && $this->config->get('config_ssl')) {
            $base = $this->config->get('config_ssl');
        }
        if (empty($base) && defined('HTTP_CATALOG')) { $base = HTTP_CATALOG; }
        if (empty($base) && defined('HTTP_SERVER'))  { $base = HTTP_SERVER; }
        $base = rtrim($base, '/');
        $data['callback_url'] = $base . '/index.php?route=extension/payment/passimpay/callback';
        $data['return_url']   = $base . '/index.php?route=extension/payment/passimpay/callback_return';

        $data['token']       = $this->session->data['token'];
        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/passimpay', $data));
    }

    /**
     * AJAX endpoint: manually pull /v2/orderstatus and update the OC order status.
     * Useful when a webhook was missed (e.g. the shop was down).
     */
    public function checkOrderStatus() {
        $this->load->language('extension/payment/passimpay');
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', 'extension/payment/passimpay')) {
            $this->response->setOutput(json_encode(array('error' => $this->language->get('error_permission'))));
            return;
        }
        $order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
        if (!$order_id && isset($this->request->post['order_id'])) {
            $order_id = (int)$this->request->post['order_id'];
        }
        if (!$order_id) {
            $this->response->setOutput(json_encode(array('error' => 'order_id required')));
            return;
        }

        $api_key     = $this->config->get('passimpay_api_key');
        $platform_id = (int)$this->config->get('passimpay_platform_id');
        if (empty($api_key) || !$platform_id) {
            $this->response->setOutput(json_encode(array('error' => 'Module not configured')));
            return;
        }

        $api = new PassimpayMerchantAPI($platform_id, $api_key);
        $status = $api->getOrderStatus($order_id);
        $this->log->write('[Passimpay] admin checkStatus order=' . $order_id . ' status=' . $status . ' resp=' . $api->getResponse());

        // Apply to OC order — duplicate-safe (will skip if already at target status)
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if ($order_info) {
            $current    = (int)$order_info['order_status_id'];
            $success_id = (int)$this->config->get('passimpay_order_status_success_id') ?: 5;
            $pending_id = (int)$this->config->get('passimpay_order_status_pending_id') ?: (int)$this->config->get('config_order_status_id');
            $failed_id  = (int)$this->config->get('passimpay_order_status_failed_id')  ?: 10;

            if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED && $current !== $success_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $success_id, 'Passimpay (admin check): completed', true);
            } elseif ($status === PassimpayMerchantAPI::PAYMENT_STATUS_ERROR && $current !== $failed_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $failed_id, 'Passimpay (admin check): failed', false);
            } elseif ($status === PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING && $current !== $pending_id && $current !== $success_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $pending_id, 'Passimpay (admin check): wait', false);
            }
        }

        $this->response->setOutput(json_encode(array(
            'order_id' => $order_id,
            'status'   => $status,
            'response' => json_decode($api->getResponse(), true),
            'error'    => $api->getError(),
        )));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/passimpay')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        if (empty($this->request->post['passimpay_api_key'])) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }
        if (empty($this->request->post['passimpay_platform_id'])) {
            $this->error['platform_id'] = $this->language->get('error_platform_id');
        }
        return !$this->error;
    }

    public function install() {
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('passimpay', array(
            'passimpay_payment_type'            => self::PAYMENT_TYPE_BOTH,
            'passimpay_order_status_success_id' => 5,
            'passimpay_order_status_pending_id' => $this->config->get('config_order_status_id'),
            'passimpay_order_status_failed_id'  => 10,
            'passimpay_geo_zone_id'             => 0,
            'passimpay_status'                  => 0,
            'passimpay_sort_order'              => 0,
        ));
    }

    public function uninstall() {
        // Settings kept on uninstall for re-install convenience.
    }
}
