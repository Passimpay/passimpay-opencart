<?php
/**
 * Passimpay (card) payment - Admin (ocStore 2.3.x)
 *
 * A second checkout option that opens the Passimpay payment page with cards only.
 * API key, Platform ID, order statuses, webhook and return URL come from the main
 * Passimpay method, which must be installed and configured.
 */
require_once DIR_SYSTEM . 'library/passimpay/api.php';

class ControllerExtensionPaymentPassimpayCard extends Controller {
    private $error = array();

    public function index() {
        $this->load->language('extension/payment/passimpay_card');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('passimpay_card', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true));
        }

        $data = array();
        foreach (array(
            'heading_title', 'text_edit', 'text_enabled', 'text_disabled', 'text_all_zones', 'text_uses_main',
            'entry_card_notice', 'entry_title', 'entry_title_help', 'entry_logo', 'entry_logo_help',
            'entry_status', 'entry_sort_order', 'entry_geo_zone', 'button_save', 'button_cancel',
        ) as $key) {
            $data[$key] = $this->language->get($key);
        }
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['main_configured'] = $this->config->get('passimpay_api_key') && $this->config->get('passimpay_platform_id');
        $data['text_main_missing'] = $this->language->get('text_main_missing');

        $data['breadcrumbs'] = array(
            array('text' => $this->language->get('text_home'),      'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], true)),
            array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true)),
            array('text' => $this->language->get('heading_title'),  'href' => $this->url->link('extension/payment/passimpay_card', 'token=' . $this->session->data['token'], true)),
        );
        $data['action'] = $this->url->link('extension/payment/passimpay_card', 'token=' . $this->session->data['token'], true);
        $data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true);
        $data['main_url'] = $this->url->link('extension/payment/passimpay', 'token=' . $this->session->data['token'], true);

        foreach (array('passimpay_card_status', 'passimpay_card_sort_order', 'passimpay_card_geo_zone_id', 'passimpay_card_title', 'passimpay_card_logo') as $key) {
            $data[$key] = isset($this->request->post[$key]) ? $this->request->post[$key] : $this->config->get($key);
        }
        if (!is_array($data['passimpay_card_title'])) { $data['passimpay_card_title'] = array(); }

        $this->load->model('localisation/language');
        $data['languages'] = $this->model_localisation_language->getLanguages();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
        $this->load->model('tool/image');
        if ($data['passimpay_card_logo'] === null) { $data['passimpay_card_logo'] = PassimpayMerchantAPI::DEFAULT_LOGO; }
        $data['placeholder'] = $this->model_tool_image->resize('no_image.png', 100, 100);
        $data['thumb'] = $data['passimpay_card_logo'] && is_file(DIR_IMAGE . $data['passimpay_card_logo'])
            ? $this->model_tool_image->resize($data['passimpay_card_logo'], 100, 100) : $data['placeholder'];

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/passimpay_card', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/payment/passimpay_card')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }

    /** See the main module: checks the modify permission itself (install resets the settings). */
    public function install() {
        if (!$this->user->hasPermission('modify', 'extension/extension/payment')) {
            return;
        }
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('passimpay_card', array(
            'passimpay_card_status'      => 0,
            'passimpay_card_sort_order'  => 0,
            'passimpay_card_geo_zone_id' => 0,
            'passimpay_card_logo'        => PassimpayMerchantAPI::DEFAULT_LOGO,
        ));
    }
}
