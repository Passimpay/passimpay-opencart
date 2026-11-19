# PassimPay для OpenCart 3 — 1.0.1

1. Сохраните резервную копию файлов модуля, базы и настроек оплаты.
2. Загрузите `passimpay.ocmod.zip` через Extensions → Installer. Если установщик запрещает повторную загрузку, скопируйте содержимое `upload/` поверх корня магазина. При переименованной папке админки направьте файлы `upload/admin/` в неё.
3. При первой установке установите PassimPay в Extensions → Extensions → Payments. При обновлении не нажимайте Uninstall. Откройте Edit, укажите API Key, Platform ID, Payment options, статусы платежей и Status = Enabled. Для карт включите Cards / Bank transfer в проекте PassimPay.
4. Скопируйте Notification URL и Return URL из настроек модуля в проект PassimPay. Для магазина в корне домена:

| Поле | Адрес |
| --- | --- |
| Notification URL | `https://shop.example/index.php?route=extension/payment/passimpay/callback` |
| Successful URL | `https://shop.example/index.php?route=extension/payment/passimpay/return` |
| Fail URL | `https://shop.example/index.php?route=checkout/failure` |

5. Обновите кеш модификаций и темы. Проверьте оформление заказа и автоматическую смену статуса после оплаты. Статус успешной оплаты должен отличаться от ожидающего и неуспешного.

Архив предназначен для OpenCart 3. В его корне находятся `upload/` и `install.xml`. Нужны PHP cURL и исходящий HTTPS к `https://api.passimpay.io`. API v2 используется автоматически; валюта и сумма берутся из заказа.

Статус оплаты определяется серверной проверкой платежа. Адрес уведомлений должен принимать POST JSON и заголовок `x-signature` без перенаправлений и проверок браузера. Актуальные IP-адреса отправителей для настройки сервера получите у [поддержки PassimPay](https://passimpay.io/en/contacts); требования описаны в [документации уведомлений](https://passimpay.gitbook.io/passimpay-api/webhook).

Platform ID и API Key доступны в настройках проекта в [кабинете PassimPay](https://account.passimpay.io/).
