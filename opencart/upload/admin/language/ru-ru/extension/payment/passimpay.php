<?php
$_['heading_title']    = 'Passimpay';

$_['text_home']        = 'Главная';
$_['text_extension']   = 'Расширения';
$_['text_all_zones']   = 'Все зоны';
$_['text_success']     = 'Настройки модуля Passimpay обновлены.';
$_['text_edit']        = 'Редактировать Passimpay';
$_['text_enabled']     = 'Включено';
$_['text_disabled']    = 'Выключено';
$_['text_yes']         = 'Да';
$_['text_no']          = 'Нет';
$_['text_check_status_hint'] = 'Введите ID заказа, чтобы вручную запросить его статус у Passimpay. Используется, если webhook не пришёл.';

$_['entry_api_key']              = 'API ключ (Secret Key)';
$_['entry_platform_id']          = 'Platform ID';
$_['entry_payment_type']         = 'Способ оплаты';
$_['entry_payment_type_both']    = 'Карта и криптовалюта';
$_['entry_payment_type_crypto']  = 'Только криптовалюта';
$_['entry_payment_type_card']    = 'Только банковская карта';
$_['entry_card_notice']          = 'Оплату картой для вашего аккаунта включает поддержка Passimpay (в личном кабинете переключателя нет). Пока она не включена, страница оплаты картой будет без способов оплаты.';
$_['entry_order_status_success'] = 'Статус заказа (оплачен)';
$_['entry_order_status_pending'] = 'Статус заказа (ожидание)';
$_['entry_order_status_failed']  = 'Статус заказа (ошибка/отмена)';
$_['entry_status']               = 'Статус';
$_['entry_sort_order']           = 'Порядок сортировки';
$_['entry_geo_zone']             = 'Геозона';
$_['entry_callback_url']         = 'URL для уведомлений (callback)';
$_['entry_return_url']           = 'URL возврата';
$_['entry_check_status_order_id'] = 'ID заказа';

$_['tab_general']      = 'Основное';
$_['tab_tools']        = 'Инструменты';

$_['button_save']         = 'Сохранить';
$_['button_cancel']       = 'Отмена';
$_['button_check_status'] = 'Проверить статус';

$_['error_permission']  = 'Недостаточно прав для изменения модуля Passimpay.';
$_['error_api_key']     = 'Укажите API ключ';
$_['error_platform_id'] = 'Укажите Platform ID';
$_['error_allowed_ips'] = 'Не IP-адрес и не CIDR (префикс 1-32 для IPv4, 1-128 для IPv6): %s';
$_['error_form']        = 'Проверьте ошибки в форме.';
$_['text_api_key_saved'] = 'Сохранён (скрыт). Оставьте пустым, чтобы не менять, или введите новый ключ.';

$_['entry_title']            = 'Название на странице оформления';
$_['entry_title_help']       = 'Оставьте пустым, чтобы использовать стандартное название для выбранных вариантов оплаты.';
$_['entry_logo']             = 'Логотип';
$_['entry_logo_help']        = 'Показывается рядом с названием в списке способов оплаты. В письмах и в заказе не показывается.';
$_['entry_allowed_ips']      = 'Разрешённые IP для уведомлений';
$_['entry_allowed_ips_help'] = 'По одному IP или CIDR в строке. Пусто = уведомления принимаются с любого адреса (статус всегда проверяется в Passimpay). Сейчас Passimpay отправляет с 18.192.188.29.';
$_['text_card_method']       = 'Чтобы показать оплату картой отдельным способом, выберите здесь «Только криптовалюта» и установите «Passimpay (карта)» в разделе Модули / Расширения → Оплата.';
