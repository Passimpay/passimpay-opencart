<?php
/**
 * Passimpay payment method - Catalog Model (ocStore 2.3.x)
 *
 * Note: in ocStore 2.3 model is loaded by checkout controller as
 *       $this->load->model('extension/payment/passimpay'), so the file
 *       lives in catalog/model/extension/payment/.
 *
 * Also holds the invoice records (table passimpay_invoice) and the order status rules,
 * shared with the "Passimpay (card)" method (model passimpay_card extends this one).
 */
require_once DIR_SYSTEM . 'library/passimpay/api.php';

class ModelExtensionPaymentPassimpay extends Model {
    protected $code = 'passimpay';

    /** applyStatus() result when another request holds the order lock. */
    const BUSY = 'busy';

    /** Start of the applyStatus() result for an event on a final invoice (nothing changed). */
    const INVOICE_FINAL = 'invoice final';

    /** Invoice closed because the merchant closed the order (Failed, Canceled...) while it was open. */
    const STATUS_CLOSED = 'closed';

    private static $tableChecked = false;

    /** Orders marked paid without the customer email yet (sent by sendNotifications()). */
    private $notifyPaid = array();

    public function getMethod($address, $total) {
        $this->load->language('extension/payment/passimpay');

        if (!$this->config->get($this->code . '_status')) {
            return array();
        }
        if (!$this->config->get('passimpay_api_key') || !$this->config->get('passimpay_platform_id')) {
            return array();
        }

        $geo_zone_id = (int)$this->config->get($this->code . '_geo_zone_id');
        if ($geo_zone_id) {
            $q = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$geo_zone_id . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
            if (!$q->num_rows) {
                return array();
            }
        }

        $title = $this->safeTitle();
        $logo  = $this->config->get($this->code . '_logo');
        $logo  = $logo === null ? PassimpayMerchantAPI::DEFAULT_LOGO : (string)$logo;
        if ($logo !== '' && is_file(DIR_IMAGE . $logo)) {
            $base  = !empty($this->request->server['HTTPS']) && $this->request->server['HTTPS'] !== 'off' ? $this->config->get('config_ssl') : $this->config->get('config_url');
            $title = '<img src="' . htmlspecialchars($base . 'image/' . $logo, ENT_QUOTES, 'UTF-8') . '" alt="' . $title . '" style="max-height:28px;vertical-align:middle;margin-right:6px;" /> ' . $title;
        }

        return array(
            'code'       => $this->code,
            'title'      => $title,
            'terms'      => $this->language->get('text_redirect_notice'),
            'sort_order' => (int)$this->config->get($this->code . '_sort_order'),
        );
    }

    /**
     * Method title without the logo: the "Title" setting for the store language, or the
     * default for the payment type. Used for the order record and emails.
     */
    public function plainTitle() {
        $this->load->language('extension/payment/passimpay');

        $titles = (array)$this->config->get($this->code . '_title');
        $language_id = (int)$this->config->get('config_language_id');
        if (!empty($titles[$language_id])) {
            return (string)$titles[$language_id];
        }

        if ($this->code === 'passimpay_card') {
            return $this->language->get('text_title_card');
        }
        $defaults = array(
            0 => $this->language->get('text_title_both'),
            1 => $this->language->get('text_title_crypto'),
            2 => $this->language->get('text_title_card'),
        );
        $payment_type = (int)$this->config->get('passimpay_payment_type');
        return isset($defaults[$payment_type]) ? $defaults[$payment_type] : $this->language->get('text_title');
    }

    /**
     * plainTitle() as HTML: escaped once. Admin settings arrive HTML-encoded already
     * (OpenCart's Request class), so the text is decoded first and nothing is encoded twice.
     */
    public function safeTitle() {
        return htmlspecialchars(html_entity_decode($this->plainTitle(), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
    }

    /** Order total in the order currency, as sent to createorder ("10.50"). */
    public function orderAmount(array $order_info) {
        $amount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        return number_format((float)$amount, 2, '.', '');
    }

    /** Payment page type for createorder: 0 card and crypto, 1 crypto, 2 card. */
    public function paymentType() {
        return $this->code === 'passimpay_card' ? 2 : (int)$this->config->get('passimpay_payment_type');
    }

    /** The checkout stores the title with the logo; the order keeps the plain text. */
    public function setOrderPaymentTitle($order_id) {
        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET payment_method = '" . $this->db->escape($this->safeTitle()) . "' WHERE order_id = '" . (int)$order_id . "'");
    }

    // ------------------------------------------------------------------ invoices

    public function ensureTable() {
        if (self::$tableChecked) {
            return;
        }
        $this->db->query(PassimpayMerchantAPI::invoiceTableSql(DB_PREFIX));
        $this->db->query(PassimpayMerchantAPI::latePaymentTableSql(DB_PREFIX));
        PassimpayMerchantAPI::upgradeTables($this->db, DB_PREFIX);
        self::$tableChecked = true;
    }

    public function getInvoice($invoice_id) {
        $this->ensureTable();
        $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "passimpay_invoice` WHERE invoice_id = '" . $this->db->escape((string)$invoice_id) . "'");
        return $q->row;
    }

    /** Newest first. */
    public function getInvoicesByOrder($order_id) {
        $this->ensureTable();
        $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "passimpay_invoice` WHERE order_id = '" . (int)$order_id . "' ORDER BY date_added DESC, invoice_id DESC");
        return $q->rows;
    }

    /**
     * Records an open invoice. confirm() calls it before createorder (url '') and again with
     * the payment URL; the second call only sets the url. history_id is the order's last
     * order_history row when the invoice was recorded: later rows are after the invoice
     * (see merchantCloseSince()), whatever their timestamps.
     */
    public function saveInvoice($invoice_id, $order_id, $amount, $currency, $type, $url) {
        $this->ensureTable();
        $this->db->query("INSERT INTO `" . DB_PREFIX . "passimpay_invoice` SET invoice_id = '" . $this->db->escape($invoice_id) . "', order_id = '" . (int)$order_id . "', amount = '" . $this->db->escape($amount) . "', currency = '" . $this->db->escape($currency) . "', type = '" . (int)$type . "', status = 'wait', history_id = (SELECT COALESCE(MAX(order_history_id), 0) FROM `" . DB_PREFIX . "order_history` WHERE order_id = '" . (int)$order_id . "'), url = '" . $this->db->escape($url) . "', date_added = NOW(), date_modified = NOW() ON DUPLICATE KEY UPDATE url = VALUES(url), date_modified = NOW()");
    }

    /**
     * Final status for an open invoice. Writes only while the invoice is "wait": a final
     * invoice (paid, mismatch, error, closed) is never changed.
     */
    public function setInvoiceStatus($invoice_id, $status, $amount_paid) {
        $this->ensureTable();
        $this->db->query("UPDATE `" . DB_PREFIX . "passimpay_invoice` SET status = '" . $this->db->escape($status) . "', amount_paid = '" . $this->db->escape($amount_paid) . "', date_modified = NOW() WHERE invoice_id = '" . $this->db->escape($invoice_id) . "' AND status = 'wait'");
    }

    /**
     * True for an invoice that is done: paid, paid for the wrong amount ("mismatch"), expired
     * ("error"), or closed because the merchant closed the order ("closed"). Its events
     * change neither the invoice nor the order.
     */
    public static function isFinal(array $invoice) {
        return isset($invoice['status']) && $invoice['status'] !== PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING;
    }

    /** True for a final invoice that was not paid (expired or closed): a retry needs a new invoice id. */
    public static function isClosed(array $invoice) {
        return isset($invoice['status']) && in_array($invoice['status'], array(PassimpayMerchantAPI::PAYMENT_STATUS_ERROR, self::STATUS_CLOSED), true);
    }

    /**
     * Records the closure of an open invoice whose order the merchant closed after the
     * invoice was recorded: set it to a status other than the module's pending/paid ones and
     * the store's processing/complete ones (Failed, Canceled, Denied, Voided...). Statuses
     * written by this module are left out (its Failed comes with the invoice's own "error").
     * Called under the order lock, before any event for the invoice is processed. Returns the
     * invoice row, with status "closed" if it was closed.
     */
    public function closeIfOrderClosed(array $invoice) {
        if (!$invoice || $invoice['status'] !== PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING) {
            return $invoice;
        }
        if ($this->merchantCloseSince($invoice)) {
            $this->setInvoiceStatus($invoice['invoice_id'], self::STATUS_CLOSED, '');
            $invoice['status'] = self::STATUS_CLOSED;
        }
        return $invoice;
    }

    // ------------------------------------------------------------------ status rules

    /**
     * Apply a Passimpay status to an OpenCart order. Safe to call repeatedly and from
     * parallel requests (webhook, return page, admin check): one order at a time, under a
     * MySQL named lock.
     * First, before any write: the invoice's final state. While an invoice is "wait" it
     * waits for Passimpay; once it is final (paid, mismatch, error, closed, see isFinal())
     * its events change neither the invoice nor the order (result starts with self::INVOICE_FINAL).
     * An open invoice whose order the merchant closed is closed first (closeIfOrderClosed).
     * For an open invoice:
     *   paid  -> paid status, once; never undone. Only if the invoice amount and currency
     *            still match the order; otherwise the invoice is "mismatch" and the payment
     *            goes to reconciliation.
     *   error -> failed status, only for the order's latest invoice and only while the
     *            order is unconfirmed or pending (an expired older invoice changes nothing).
     *   wait  -> pending status if the order is not confirmed yet; the invoice stays open.
     * The order is written first and the invoice's final status last, so a request that dies
     * in between leaves the invoice open and the retry finishes it.
     * An invoice id without a record (bare order number from earlier versions) is recorded
     * on its first paid or error, never marked paid (its amount is unknown).
     * Money the module does not apply goes to passimpay_late_payment for a manual
     * reconciliation, with one order note, the order keeps its status: a paid closed
     * invoice, part of an amount on an expired invoice, a wrong amount, a second invoice
     * paid after the order was paid by another one, an unrecorded invoice id, a deleted
     * order. The reconciliation row is written before the invoice's final status, so a retry
     * after a crash still writes it. The full state table is in STATES.md.
     *
     * Every status is written before this returns. For a confirmed order the paid status
     * is written without the customer email, which sendNotifications() sends afterwards
     * (after the HTTP reply). An unconfirmed order (status 0) gets its order emails here,
     * because OpenCart sends them with the first status.
     *
     * @param int    $order_id
     * @param string $invoice_id Passimpay orderId
     * @param string $status     paid | wait | error | request_error
     * @param array  $paid       amountPaid, currency (PassimpayMerchantAPI::getPaidInfo())
     * @param string $source     webhook | return | admin
     * @return string what happened (for the log), self::BUSY if the order is locked
     */
    public function applyStatus($order_id, $invoice_id, $status, array $paid, $source) {
        if (!$this->lockOrder($order_id)) {
            return self::BUSY;
        }
        try {
            return $this->applyStatusLocked((int)$order_id, (string)$invoice_id, $status, $paid, $source);
        } finally {
            $this->unlockOrder($order_id);
        }
    }

    /** Customer emails for orders marked paid by applyStatus(). Call after the reply. */
    public function sendNotifications() {
        $this->load->language('extension/payment/passimpay');
        $this->load->model('checkout/order');
        foreach ($this->notifyPaid as $order_id => $status_id) {
            $this->model_checkout_order->addOrderHistory($order_id, $status_id, $this->language->get('text_paid_notify'), true);
        }
        $this->notifyPaid = array();
    }

    private function applyStatusLocked($order_id, $invoice_id, $status, array $paid, $source) {
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        $invoice    = $this->getInvoice($invoice_id);
        if ($invoice && (int)$invoice['order_id'] !== $order_id) {
            return 'invoice belongs to order ' . (int)$invoice['order_id'] . ', ignored';
        }

        // Final state first, before any write: a final invoice is never changed, nor its order.
        $invoice = $this->closeIfOrderClosed($invoice);
        if ($invoice && self::isFinal($invoice)) {
            return $this->finalInvoiceEvent($order_info, $invoice, $status, $paid, $source);
        }
        if (!$order_info) {
            if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED) {
                $this->recordLatePayment(array(), $order_id, $invoice_id, 'no order', $paid, $source);
                return 'order ' . $order_id . ' not found, payment recorded for reconciliation';
            }
            return 'order ' . $order_id . ' not found';
        }

        $current    = (int)$order_info['order_status_id'];
        $success_id = (int)$this->config->get('passimpay_order_status_success_id');
        $pending_id = (int)$this->config->get('passimpay_order_status_pending_id');
        $failed_id  = $this->failedStatusId();
        if (!$success_id) { $success_id = 5; }
        if (!$pending_id) { $pending_id = (int)$this->config->get('config_order_status_id'); }
        $amount_paid = isset($paid['amountPaid']) ? (string)$paid['amountPaid'] : '';

        $note = 'Passimpay (' . $source . '): invoice ' . $invoice_id . ' ' . $status;
        if ($amount_paid !== '') {
            $note .= ', paid ' . $amount_paid . ' ' . $paid['currency'];
        }

        if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED) {
            if (!$invoice) {
                // No record: the amount is unknown, so never marked paid.
                $this->recordLatePayment($order_info, $order_id, $invoice_id, 'unrecorded', $paid, $source);
                $this->recordInvoice($invoice_id, $order_id, 'mismatch');
                return 'unrecorded invoice, amount unknown, payment recorded for reconciliation';
            }
            if ($this->otherInvoicePaid($order_id, $invoice_id)) {
                $this->recordLatePayment($order_info, $order_id, $invoice_id, 'duplicate', $paid, $source);
                $this->setInvoiceStatus($invoice_id, $status, $amount_paid);
                return 'order already paid by another invoice, payment recorded for reconciliation';
            }
            if ($current === $success_id || $this->wasPaid($order_id, $success_id)) {
                $this->setInvoiceStatus($invoice_id, $status, $amount_paid);
                return 'already paid';
            }
            $order_amount   = $this->orderAmount($order_info);
            $order_currency = strtoupper($order_info['currency_code']);
            if ($invoice['amount'] !== $order_amount || strtoupper($invoice['currency']) !== $order_currency) {
                $this->recordLatePayment($order_info, $order_id, $invoice_id, 'mismatch', $paid, $source,
                    'the invoice was for ' . $invoice['amount'] . ' ' . $invoice['currency'] . ' and the order total is now ' . $order_amount . ' ' . $order_currency);
                $this->setInvoiceStatus($invoice_id, 'mismatch', $amount_paid);
                return 'amount mismatch (invoice ' . $invoice['amount'] . ' ' . $invoice['currency'] . ', order ' . $order_amount . ' ' . $order_currency . '), payment recorded for reconciliation';
            }
            if ($current === 0) {
                $this->model_checkout_order->addOrderHistory($order_id, $success_id, $note, true);
            } else {
                $this->model_checkout_order->addOrderHistory($order_id, $success_id, $note, false);
                $this->notifyPaid[$order_id] = $success_id;
            }
            // The admin's order page doesn't take the order lock: a merchant close saved between
            // the check above and the paid status is found here and wins (see restoreClosed()).
            $closed_id = $this->merchantCloseSince($invoice);
            if ($closed_id) {
                return $this->restoreClosed($order_id, $invoice, $closed_id, $success_id, $paid, $source);
            }
            $this->setInvoiceStatus($invoice_id, $status, $amount_paid);
            return 'marked paid';
        }

        if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_ERROR) {
            // Expired part paid (Invoice Link): the money goes to reconciliation, the invoice still expires.
            if (self::hasAmount($paid)) {
                $this->recordLatePayment($order_info, $order_id, $invoice_id, 'partial', $paid, $source);
            }
            $invoices = $this->getInvoicesByOrder($order_id);
            if ($invoices && $invoices[0]['invoice_id'] !== $invoice_id) {
                $outcome = 'older invoice expired, order unchanged';
            } elseif ($current === 0 || $current === $pending_id) {
                $this->model_checkout_order->addOrderHistory($order_id, $failed_id, $note, false);
                $outcome = 'marked failed';
            } else {
                $outcome = 'invoice expired, order left at status ' . $current;
            }
            if ($invoice) {
                $this->setInvoiceStatus($invoice_id, $status, '');
            } else {
                $this->recordInvoice($invoice_id, $order_id, $status);
            }
            return $outcome;
        }

        if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING && $current === 0 && $pending_id) {
            $this->model_checkout_order->addOrderHistory($order_id, $pending_id, $note, false);
            return 'marked pending';
        }
        return 'no change (' . $status . ')';
    }

    /**
     * A merchant close found right after the paid status was written. If the paid status is
     * still the order's latest, the close came first: the merchant's status is put back (no
     * customer email), the payment goes to reconciliation and the invoice is closed. Order
     * first, invoice last: a retry finds the closure first. If the merchant's status is the
     * latest, it came after the payment: the invoice is paid.
     */
    private function restoreClosed($order_id, array $invoice, $closed_id, $success_id, array $paid, $source) {
        $order_info = $this->model_checkout_order->getOrder($order_id);
        if ((int)$order_info['order_status_id'] !== $success_id) {
            $this->setInvoiceStatus($invoice['invoice_id'], PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED, isset($paid['amountPaid']) ? (string)$paid['amountPaid'] : '');
            return 'marked paid (the merchant changed the order after)';
        }
        unset($this->notifyPaid[$order_id]);
        $this->model_checkout_order->addOrderHistory($order_id, $closed_id, 'Passimpay (' . $source . '): invoice '
            . $invoice['invoice_id'] . ' was paid while the order was being closed: status put back.', false);
        $order_info['order_status_id'] = $closed_id;
        $this->recordLatePayment($order_info, $order_id, $invoice['invoice_id'], self::STATUS_CLOSED, $paid, $source);
        $this->setInvoiceStatus($invoice['invoice_id'], self::STATUS_CLOSED, '');
        return 'order closed by the merchant during payment, status put back, payment recorded for reconciliation';
    }

    /**
     * The invoice row for confirm(), under the order lock (closed first if the merchant
     * closed the order, see closeIfOrderClosed()), so it can't close an invoice a webhook is
     * applying. Returns self::BUSY if the order is locked.
     */
    public function invoiceForConfirm($order_id, $invoice_id) {
        if (!$this->lockOrder($order_id)) {
            return self::BUSY;
        }
        try {
            return $this->closeIfOrderClosed($this->getInvoice($invoice_id));
        } finally {
            $this->unlockOrder($order_id);
        }
    }

    /**
     * What confirm() does with a recorded invoice (invoiceForConfirm()): "success" only for
     * a paid invoice; "failure" for a mismatch (the money is in reconciliation, the order is
     * not paid) or busy; "next" for an expired or closed invoice (next attempt, new id);
     * "open" for a waiting one (Passimpay is asked).
     */
    public static function confirmPage($row) {
        if ($row === self::BUSY) {
            return 'failure';
        }
        if (self::isClosed($row)) {
            return 'next';
        }
        if (!self::isFinal($row)) {
            return 'open';
        }
        return $row['status'] === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED ? 'success' : 'failure';
    }

    /**
     * Passimpay's paid or error for confirm()'s open invoice, applied like a webhook. Returns
     * array(page, applyStatus() result). The page comes from the invoice afterwards:
     * "success" only if it is paid; "next" if it expired; "failure" otherwise (busy, closed
     * or mismatched during the payment: the money is in reconciliation, a new invoice would
     * be paid twice).
     */
    public function applyForConfirm($order_id, $invoice_id, $status, array $paid) {
        $outcome = $this->applyStatus($order_id, $invoice_id, $status, $paid, 'confirm');
        $row     = $outcome === self::BUSY ? array() : $this->getInvoice($invoice_id);
        if ($row && $row['status'] === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED) {
            return array('success', $outcome);
        }
        if ($status === PassimpayMerchantAPI::PAYMENT_STATUS_ERROR && $row && self::isClosed($row)) {
            return array('next', $outcome);
        }
        return array('failure', $outcome);
    }

    /**
     * confirm() recorded the invoice but createorder failed: closed as "error", so the next
     * attempt gets a new id. If Passimpay did create it after all, its payment goes to
     * reconciliation. Busy: left open, the next confirm() retries the same id.
     */
    public function closeUnsent($order_id, $invoice_id) {
        if (!$this->lockOrder($order_id)) {
            return self::BUSY;
        }
        try {
            $this->setInvoiceStatus($invoice_id, PassimpayMerchantAPI::PAYMENT_STATUS_ERROR, '');
            return 'closed';
        } finally {
            $this->unlockOrder($order_id);
        }
    }

    /**
     * Pending status set by confirm() for $invoice_id, under the order lock and against
     * the current state, so a webhook that ran meanwhile wins: no change if the order is
     * (or was) paid, or if this invoice is no longer "wait" (expired: Failed stays Failed).
     * A new invoice after an earlier one expired is "wait", so a retry still sets Pending.
     */
    public function markPending($order_id, $invoice_id, $pending_id, $comment) {
        if (!$this->lockOrder($order_id)) {
            return self::BUSY;
        }
        try {
            $this->load->model('checkout/order');
            $order_info = $this->model_checkout_order->getOrder($order_id);
            $current    = $order_info ? (int)$order_info['order_status_id'] : 0;
            $success_id = (int)$this->config->get('passimpay_order_status_success_id');
            if (!$success_id) { $success_id = 5; }
            if (!$order_info || $current === (int)$pending_id || $current === $success_id || $this->wasPaid($order_id, $success_id)) {
                return 'no change';
            }
            $invoice = $this->closeIfOrderClosed($this->getInvoice($invoice_id));
            if (!$invoice || $invoice['status'] !== PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING) {
                return 'no change (invoice ' . ($invoice ? $invoice['status'] : 'not recorded') . ')';
            }
            $this->model_checkout_order->addOrderHistory($order_id, $pending_id, $comment, false);
            return 'marked pending';
        } finally {
            $this->unlockOrder($order_id);
        }
    }

    /**
     * An event for a final invoice: nothing changes. Money on an invoice that was closed
     * unpaid (expired or closed), paid or part paid ("error" with an amount), is recorded in
     * passimpay_late_payment for a manual reconciliation, with one note on the order (its
     * status is kept).
     */
    private function finalInvoiceEvent(array $order_info, array $invoice, $status, array $paid, $source) {
        $final = self::INVOICE_FINAL . ' (' . $invoice['status'] . ')';
        $money = $status === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED
            || ($status === PassimpayMerchantAPI::PAYMENT_STATUS_ERROR && self::hasAmount($paid));
        if (!$money || !self::isClosed($invoice)) {
            return $final . ', ' . $status . ' ignored';
        }
        $this->recordLatePayment($order_info, (int)$invoice['order_id'], $invoice['invoice_id'], $invoice['status'], $paid, $source);
        return $final . ', late payment recorded for reconciliation';
    }

    /**
     * A payment the module does not apply: one row in passimpay_late_payment and, the first
     * time, one note on the order (status kept, no email; none for a deleted or unconfirmed order).
     */
    private function recordLatePayment(array $order_info, $order_id, $invoice_id, $reason, array $paid, $source, $why = '') {
        $amount   = isset($paid['amountPaid']) ? (string)$paid['amountPaid'] : '';
        $currency = isset($paid['currency']) ? (string)$paid['currency'] : '';
        $reasons  = array(
            'duplicate'  => 'the order was already paid by another invoice',
            'partial'    => 'the invoice expired before it was paid in full',
            'unrecorded' => 'this shop has no record of that invoice (an earlier module version?), its amount is unknown',
        );
        if ($why === '') {
            $why = isset($reasons[$reason]) ? $reasons[$reason] : 'the invoice was already closed (' . $reason . ')';
        }
        if ($this->claimLatePayment($invoice_id, $order_id, $reason, $amount, $currency, $source) && !empty($order_info['order_status_id'])) {
            $this->model_checkout_order->addOrderHistory((int)$order_id, (int)$order_info['order_status_id'],
                'Passimpay (' . $source . '): payment ' . trim($amount . ' ' . $currency) . ' for invoice ' . $invoice_id
                . ', but ' . $why . ': order not changed, reconcile manually.', false);
        }
    }

    /** True if Passimpay reports money received on the invoice. */
    private static function hasAmount(array $paid) {
        return isset($paid['amountPaid']) && (float)$paid['amountPaid'] > 0;
    }

    /** Records a late payment: true for the first caller for that invoice and amount only. */
    protected function claimLatePayment($invoice_id, $order_id, $reason, $amount_paid, $currency, $source) {
        $this->ensureTable();
        $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "passimpay_late_payment` SET invoice_id = '" . $this->db->escape($invoice_id) . "', order_id = '" . (int)$order_id . "', reason = '" . $this->db->escape($reason) . "', amount_paid = '" . $this->db->escape($amount_paid) . "', currency = '" . $this->db->escape($currency) . "', source = '" . $this->db->escape(substr($source, 0, 16)) . "', date_added = NOW()");
        return $this->db->countAffected() > 0;
    }

    /** True if another invoice of the order is recorded as paid. */
    protected function otherInvoicePaid($order_id, $invoice_id) {
        foreach ($this->getInvoicesByOrder($order_id) as $row) {
            if ($row['invoice_id'] !== $invoice_id && $row['status'] === PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED) {
                return true;
            }
        }
        return false;
    }

    /**
     * The status the merchant last closed the order with after the invoice was recorded (a
     * status outside openStatusIds(), not from this module), 0 if none. "After" is an
     * order_history row newer than the invoice's history_id. Invoices recorded by p.4/p.5
     * have none and fall back to the time (same second counts as after).
     */
    protected function merchantCloseSince(array $invoice) {
        $after = isset($invoice['history_id']) && $invoice['history_id'] !== null && $invoice['history_id'] !== ''
            ? "order_history_id > '" . (int)$invoice['history_id'] . "'"
            : "date_added >= '" . $this->db->escape((string)$invoice['date_added']) . "'";
        $q = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order_history` WHERE order_id = '" . (int)$invoice['order_id'] . "' AND order_status_id NOT IN (" . implode(',', array_map('intval', $this->openStatusIds())) . ") AND " . $after . " AND comment NOT LIKE 'Passimpay (%' ORDER BY order_history_id DESC LIMIT 1");
        return $q->num_rows ? (int)$q->row['order_status_id'] : 0;
    }

    /** Statuses that keep an invoice open: the module's pending and paid, the store's processing and complete. */
    protected function openStatusIds() {
        $success_id = (int)$this->config->get('passimpay_order_status_success_id');
        $pending_id = (int)$this->config->get('passimpay_order_status_pending_id');
        $ids = array_merge(array(0, $success_id ? $success_id : 5, $pending_id ? $pending_id : (int)$this->config->get('config_order_status_id')),
            (array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status'));
        return array_values(array_unique(array_map('intval', $ids)));
    }

    protected function failedStatusId() {
        $failed_id = (int)$this->config->get('passimpay_order_status_failed_id');
        return $failed_id ? $failed_id : 10;
    }

    /** Records an invoice id this shop had no record of, already final ($status). */
    protected function recordInvoice($invoice_id, $order_id, $status) {
        $this->ensureTable();
        $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "passimpay_invoice` SET invoice_id = '" . $this->db->escape($invoice_id) . "', order_id = '" . (int)$order_id . "', amount = '', currency = '', type = 0, status = '" . $this->db->escape($status) . "', url = '', date_added = NOW(), date_modified = NOW()");
    }

    /** MySQL named lock per order, so parallel requests apply a status one at a time. */
    protected function lockOrder($order_id) {
        $q = $this->db->query("SELECT GET_LOCK('" . $this->db->escape($this->lockName($order_id)) . "', 15) AS l");
        return isset($q->row['l']) && (int)$q->row['l'] === 1;
    }

    protected function unlockOrder($order_id) {
        $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($this->lockName($order_id)) . "')");
    }

    private function lockName($order_id) {
        return substr(DB_DATABASE . '.' . DB_PREFIX . 'passimpay_' . (int)$order_id, -64);
    }

    /**
     * True if the order has ever been in the paid status (an admin may have moved it on).
     * A paid status this module wrote for an invoice that is now closed doesn't count: it
     * was put back by restoreClosed() (the merchant closed the order first), and that
     * payment is in reconciliation.
     */
    protected function wasPaid($order_id, $success_id) {
        $this->ensureTable();
        $q = $this->db->query("SELECT 1 FROM `" . DB_PREFIX . "order_history` h WHERE h.order_id = '" . (int)$order_id . "' AND h.order_status_id = '" . (int)$success_id . "' AND NOT EXISTS (SELECT 1 FROM `" . DB_PREFIX . "passimpay_invoice` i WHERE i.order_id = h.order_id AND i.status = '" . self::STATUS_CLOSED . "' AND h.comment LIKE CONCAT('Passimpay (%): invoice ', i.invoice_id, ' paid%')) LIMIT 1");
        return (bool)$q->num_rows;
    }
}
