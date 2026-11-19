<?php
$_['heading_title']    = 'Passimpay';

$_['text_home']        = 'Home';
$_['text_extension']   = 'Extensions';
$_['text_all_zones']   = 'All Zones';
$_['text_success']     = 'Success: You have modified Passimpay payment module!';
$_['text_edit']        = 'Edit Passimpay';
$_['text_enabled']     = 'Enabled';
$_['text_disabled']    = 'Disabled';
$_['text_yes']         = 'Yes';
$_['text_no']          = 'No';
$_['text_check_status_hint'] = 'Enter an order ID to manually pull its status from Passimpay. Useful if a webhook was missed.';

$_['entry_api_key']              = 'API Key (Secret Key)';
$_['entry_platform_id']          = 'Platform ID';
$_['entry_payment_type']         = 'Payment options';
$_['entry_payment_type_both']    = 'Card and cryptocurrency';
$_['entry_payment_type_crypto']  = 'Cryptocurrency only';
$_['entry_payment_type_card']    = 'Bank card only';
$_['entry_card_notice']          = 'Card payments must be enabled for your Passimpay account by Passimpay support (there is no switch in the dashboard). Until then the card payment page shows no payment methods.';
$_['entry_order_status_success'] = 'Order Status (paid)';
$_['entry_order_status_pending'] = 'Order Status (pending)';
$_['entry_order_status_failed']  = 'Order Status (failed/cancelled)';
$_['entry_status']               = 'Status';
$_['entry_sort_order']           = 'Sort Order';
$_['entry_geo_zone']             = 'Geo Zone';
$_['entry_callback_url']         = 'Notification URL (callback)';
$_['entry_return_url']           = 'Return URL';
$_['entry_check_status_order_id'] = 'Order ID';

$_['tab_general']      = 'General';
$_['tab_tools']        = 'Tools';

$_['button_save']         = 'Save';
$_['button_cancel']       = 'Cancel';
$_['button_check_status'] = 'Check status';

$_['error_permission']  = 'Warning: You do not have permission to modify payment Passimpay!';
$_['error_api_key']     = 'API Key required';
$_['error_platform_id'] = 'Platform ID required';
$_['error_allowed_ips'] = 'Not an IP address or CIDR (prefix 1-32 for IPv4, 1-128 for IPv6): %s';
$_['error_form']        = 'Please check the form for errors.';
$_['text_api_key_saved'] = 'Saved (hidden). Leave empty to keep it, or enter a new key.';

$_['entry_title']            = 'Title at checkout';
$_['entry_title_help']       = 'Leave empty to use the default title for the payment options above.';
$_['entry_logo']             = 'Logo';
$_['entry_logo_help']        = 'Shown next to the title in the list of payment methods. Not shown in emails or the order.';
$_['entry_allowed_ips']      = 'Allowed webhook IPs';
$_['entry_allowed_ips_help'] = 'One IP or CIDR per line. Empty = webhooks are accepted from any address (the status is always checked with Passimpay). Passimpay currently sends from 18.192.188.29.';
$_['text_card_method']       = 'To offer cards as a separate payment method, set this to "Cryptocurrency only" and install "Passimpay (card)" in Extensions → Payments.';
