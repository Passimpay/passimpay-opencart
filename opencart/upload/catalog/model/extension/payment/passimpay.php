<?php
/**
 * Passimpay payment method - Catalog Model
 */
class ModelExtensionPaymentPassimpay extends Model {
    public function getMethod($address, $total) {
        $this->load->language('extension/payment/passimpay');
        $status = $this->config->get('payment_passimpay_status');
        if (!$status) {
            return [];
        }
        $geo_zone_id = (int) $this->config->get('payment_passimpay_geo_zone_id');
        if ($geo_zone_id) {
            $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int) $geo_zone_id . "' AND country_id = '" . (int) $address['country_id'] . "' AND (zone_id = '" . (int) $address['zone_id'] . "' OR zone_id = '0')");
            if (!$query->num_rows) {
                return [];
            }
        }
        $payment_type = (int) $this->config->get('payment_passimpay_payment_type');
        $titles = [
            0 => $this->language->get('text_title_both'),
            1 => $this->language->get('text_title_crypto'),
            2 => $this->language->get('text_title_card'),
        ];
        $title = $titles[$payment_type] ?? $this->language->get('text_title');
        $sort_order = (int) $this->config->get('payment_passimpay_sort_order');
        return [
            'code'       => 'passimpay',
            'title'      => $title,
            'terms'      => $this->language->get('text_redirect_notice'),
            'sort_order' => $sort_order,
        ];
    }
}
