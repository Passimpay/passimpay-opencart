<?php
/**
 * Passimpay (card) payment method - Catalog Model (ocStore 2.3.x)
 *
 * A second checkout option that opens the Passimpay payment page with cards only.
 * Uses the credentials, webhook and return URL of the main Passimpay method.
 */
require_once DIR_APPLICATION . 'model/extension/payment/passimpay.php';

class ModelExtensionPaymentPassimpayCard extends ModelExtensionPaymentPassimpay {
    protected $code = 'passimpay_card';
}
