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

        // The API key is never sent back to the browser; an empty field keeps the saved key.
        if ($this->request->server['REQUEST_METHOD'] == 'POST' && isset($this->request->post['passimpay_api_key'])
            && trim($this->request->post['passimpay_api_key']) === '') {
            $this->request->post['passimpay_api_key'] = (string)$this->config->get('passimpay_api_key');
        }
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
            'entry_check_status_order_id','entry_title','entry_title_help','entry_logo','entry_logo_help',
            'entry_allowed_ips','entry_allowed_ips_help','text_card_method','text_api_key_saved',
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
        $data['error_allowed_ips'] = isset($this->error['allowed_ips']) ? $this->error['allowed_ips'] : '';

        $data['breadcrumbs'] = array(
            array('text' => $this->language->get('text_home'),      'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], true)),
            array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true)),
            array('text' => $this->language->get('heading_title'),  'href' => $this->url->link('extension/payment/passimpay', 'token=' . $this->session->data['token'], true)),
        );

        $data['action'] = $this->url->link('extension/payment/passimpay', 'token=' . $this->session->data['token'], true);
        $data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true);
        $data['check_status_url'] = $this->url->link('extension/payment/passimpay/checkOrderStatus', 'token=' . $this->session->data['token'], true);

        $keys = array(
            'passimpay_platform_id','passimpay_payment_type',
            'passimpay_order_status_success_id','passimpay_order_status_pending_id','passimpay_order_status_failed_id',
            'passimpay_status','passimpay_sort_order','passimpay_geo_zone_id',
            'passimpay_title','passimpay_logo','passimpay_allowed_ips',
        );
        foreach ($keys as $key) {
            $data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : $this->config->get($key);
        }
        $data['api_key_saved'] = (string)$this->config->get('passimpay_api_key') !== '';
        $data['passimpay_payment_type'] = $data['passimpay_payment_type'] === null
            ? self::PAYMENT_TYPE_BOTH : (int)$data['passimpay_payment_type'];
        if (!$data['passimpay_order_status_success_id']) { $data['passimpay_order_status_success_id'] = 5; }
        if (!$data['passimpay_order_status_pending_id']) { $data['passimpay_order_status_pending_id'] = $this->config->get('config_order_status_id'); }
        if (!$data['passimpay_order_status_failed_id'])  { $data['passimpay_order_status_failed_id']  = 10; }

        if (!is_array($data['passimpay_title'])) { $data['passimpay_title'] = array(); }
        if ($data['passimpay_allowed_ips'] === null) { $data['passimpay_allowed_ips'] = ''; }
        $this->load->model('localisation/language');
        $data['languages'] = $this->model_localisation_language->getLanguages();
        $this->load->model('tool/image');
        if ($data['passimpay_logo'] === null) { $data['passimpay_logo'] = PassimpayMerchantAPI::DEFAULT_LOGO; }
        $data['placeholder'] = $this->model_tool_image->resize('no_image.png', 100, 100);
        $data['thumb'] = $data['passimpay_logo'] && is_file(DIR_IMAGE . $data['passimpay_logo'])
            ? $this->model_tool_image->resize($data['passimpay_logo'], 100, 100) : $data['placeholder'];
        $data['card_method_url'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true);

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        $base = $this->_catalogBase();
        $data['callback_url'] = $base . '/index.php?route=extension/payment/passimpay/callback';
        $data['return_url']   = $base . '/index.php?route=extension/payment/passimpay/callback_return';

        $data['token']       = $this->session->data['token'];
        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/passimpay', $data));
    }

    /**
     * AJAX endpoint: a signed link to the catalog route extension/payment/passimpay/check,
     * which asks /v2/orderstatus for the order's invoices and applies the result (the
     * OpenCart 2.3 admin cannot change order history itself). The browser then opens it.
     */
    public function checkOrderStatus() {
        $this->load->language('extension/payment/passimpay');
        $this->response->addHeader('Content-Type: application/json');

        if (!$this->user->hasPermission('modify', 'extension/payment/passimpay')) {
            $this->response->setOutput(json_encode(array('error' => $this->language->get('error_permission'))));
            return;
        }
        $order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;
        if (!$order_id) {
            $this->response->setOutput(json_encode(array('error' => 'order_id required')));
            return;
        }
        if (!$this->config->get('passimpay_api_key') || !$this->config->get('passimpay_platform_id')) {
            $this->response->setOutput(json_encode(array('error' => 'Module not configured')));
            return;
        }

        $this->response->setOutput(json_encode(array(
            'url' => $this->_catalogBase() . '/index.php?route=extension/payment/passimpay/check&order_id=' . $order_id
                . '&sig=' . PassimpayMerchantAPI::checkSignature($order_id, $this->config->get('passimpay_api_key')),
        )));
    }

    /** Store front URL (webhook, return and check links). */
    private function _catalogBase() {
        $base = $this->config->get('config_url');
        if ($this->config->get('config_secure') && $this->config->get('config_ssl')) {
            $base = $this->config->get('config_ssl');
        }
        if (empty($base) && defined('HTTPS_CATALOG')) { $base = HTTPS_CATALOG; }
        if (empty($base) && defined('HTTP_CATALOG'))  { $base = HTTP_CATALOG; }
        if (empty($base) && defined('HTTP_SERVER'))   { $base = HTTP_SERVER; }
        return rtrim($base, '/');
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/passimpay')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        if (empty($this->request->post['passimpay_api_key'])) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }
        if (!isset($this->request->post['passimpay_platform_id'])
            || !is_string($this->request->post['passimpay_platform_id'])
            || !preg_match('/^[1-9][0-9]*$/D', $this->request->post['passimpay_platform_id'])
            || filter_var($this->request->post['passimpay_platform_id'], FILTER_VALIDATE_INT) === false) {
            $this->error['platform_id'] = $this->language->get('error_platform_id');
        }
        if (!isset($this->request->post['passimpay_payment_type'])
            || !in_array($this->request->post['passimpay_payment_type'], array('0', '1', '2'), true)) {
            $this->error['warning'] = $this->language->get('error_form');
        }
        $ips = isset($this->request->post['passimpay_allowed_ips']) ? html_entity_decode($this->request->post['passimpay_allowed_ips'], ENT_QUOTES, 'UTF-8') : '';
        $bad = PassimpayMerchantAPI::invalidIpEntries($ips);
        if ($bad) {
            $this->error['allowed_ips'] = sprintf($this->language->get('error_allowed_ips'), implode(', ', $bad));
        }
        if ($this->error && !isset($this->error['warning'])) {
            $this->error['warning'] = $this->language->get('error_form');
        }
        return !$this->error;
    }

    /**
     * Called by Extensions > Payments > Install. The route is also reachable directly by
     * anyone with access to this page, so it checks the modify permission itself (install
     * resets the settings).
     */
    public function install() {
        if (!$this->user->hasPermission('modify', 'extension/extension/payment')) {
            return;
        }
        $this->db->query(PassimpayMerchantAPI::invoiceTableSql(DB_PREFIX));
        $this->db->query(PassimpayMerchantAPI::latePaymentTableSql(DB_PREFIX));
        PassimpayMerchantAPI::upgradeTables($this->db, DB_PREFIX);
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('passimpay', array(
            'passimpay_payment_type'            => self::PAYMENT_TYPE_BOTH,
            'passimpay_order_status_success_id' => 5,
            'passimpay_order_status_pending_id' => $this->config->get('config_order_status_id'),
            'passimpay_order_status_failed_id'  => 10,
            'passimpay_geo_zone_id'             => 0,
            'passimpay_status'                  => 0,
            'passimpay_sort_order'              => 0,
            'passimpay_allowed_ips'             => '',
            'passimpay_logo'                    => PassimpayMerchantAPI::DEFAULT_LOGO,
        ));
    }

    public function uninstall() {
        // Settings and the passimpay_invoice / passimpay_late_payment tables are kept for re-install convenience.
    }
}
