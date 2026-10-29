<?php
/**
 * Passimpay (card) payment - Catalog (ocStore 2.3.x)
 *
 * Only the checkout confirm button lives here; it posts to extension/payment/passimpay/confirm,
 * which handles both methods. Webhook and return URL are the main method's.
 */
require_once DIR_APPLICATION . 'controller/extension/payment/passimpay.php';

class ControllerExtensionPaymentPassimpayCard extends ControllerExtensionPaymentPassimpay {
}
