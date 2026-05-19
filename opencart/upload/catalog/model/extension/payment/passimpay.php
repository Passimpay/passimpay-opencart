<?php
/**
 * Passimpay payment method - Catalog Model (ocStore 2.3.x)
 *
 * Note: in ocStore 2.3 model is loaded by checkout controller as
 *       $this->load->model('extension/payment/passimpay'), so the file
 *       lives in catalog/model/extension/payment/.
 */
class ModelExtensionPaymentPassimpay extends Model {
    public function getMethod($address, $total) {
        $this->load->language('extension/payment/passimpay');

        if (!$this->config->get('passimpay_status')) {
            return array();
        }

        $geo_zone_id = (int)$this->config->get('passimpay_geo_zone_id');
        if ($geo_zone_id) {
            $q = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$geo_zone_id . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
            if (!$q->num_rows) {
                return array();
            }
        }

        $payment_type = (int)$this->config->get('passimpay_payment_type');
        $titles = array(
            0 => $this->language->get('text_title_both'),
            1 => $this->language->get('text_title_crypto'),
            2 => $this->language->get('text_title_card'),
        );
        $title = isset($titles[$payment_type]) ? $titles[$payment_type] : $this->language->get('text_title');

        return array(
            'code'       => 'passimpay',
            'title'      => $title,
            'terms'      => $this->language->get('text_redirect_notice'),
            'sort_order' => (int)$this->config->get('passimpay_sort_order'),
        );
    }
}
