# Установка Passimpay для ocStore 2.3

---

## Ручная установка (через FTP/SSH/файловый менеджер)

Подходит для любых конфигураций сервера, включая случаи когда установщик OCMOD не работает.

### Через FTP-клиент (FileZilla / WinSCP / Cyberduck)

1. Распакуйте архив `passimpay-opencart-2.3.zip` локально на компьютере.
2. Внутри будут три папки: `admin/`, `catalog/` и `system/`.
3. Подключитесь к серверу по FTP/SFTP.
4. Перейдите в корень магазина (там, где лежат `index.php`, `config.php`).
5. Перенесите эти три папки в корень магазина:
   - На вопрос *"Папка уже существует"* выберите **"Объединить"** (Merge) или **"Перезаписать"**.
   - НЕ выбирайте *"Удалить и заменить"* — это сотрёт магазин!
6. Перейдите к разделу **"Активация модуля"** ниже.

### Через SSH

```bash
# Загрузите архив на сервер
scp passimpay-opencart-2.3.zip user@server:/tmp/

# На сервере
cd /tmp
unzip passimpay-opencart-2.3.zip
cp -rv upload/* /path/to/your/opencart/

# Установите владельца как у остальных файлов магазина
# (узнайте под кем работает php-fpm: ps aux | grep php-fpm)
chown -R www-data:www-data \
    /path/to/your/opencart/admin/controller/extension/payment/passimpay.php \
    /path/to/your/opencart/admin/language/*/extension/payment/passimpay.php \
    /path/to/your/opencart/admin/view/template/extension/payment/passimpay.tpl \
    /path/to/your/opencart/catalog/controller/extension/payment/passimpay.php \
    /path/to/your/opencart/catalog/model/extension/payment/passimpay.php \
    /path/to/your/opencart/catalog/language/*/extension/payment/passimpay.php \
    /path/to/your/opencart/catalog/view/theme/default/template/extension/payment/passimpay.tpl \
    /path/to/your/opencart/system/library/passimpay

find /path/to/your/opencart -name "passimpay.*" -type f -exec chmod 644 {} \;
chmod 644 /path/to/your/opencart/system/library/passimpay/api.php
```

---

## Что должно появиться на диске после установки

Десять файлов в магазине:

```
admin/controller/extension/payment/passimpay.php
admin/language/en-gb/extension/payment/passimpay.php
admin/language/ru-ru/extension/payment/passimpay.php
admin/view/template/extension/payment/passimpay.tpl
catalog/controller/extension/payment/passimpay.php
catalog/language/en-gb/extension/payment/passimpay.php
catalog/language/ru-ru/extension/payment/passimpay.php
catalog/model/extension/payment/passimpay.php
catalog/view/theme/default/template/extension/payment/passimpay.tpl
system/library/passimpay/api.php
```

Проверьте, что все 10 файлов на месте.

---

## Активация модуля

1. В админке откройте **Система → Пользователи → Группы пользователей**.
2. Отредактируйте группу администратора (или ту, под которой работаете).
3. В разделах **Доступ** и **Изменение** найдите и включите галочку у `extension/payment/passimpay`.
4. Сохраните.
5. Откройте **Дополнения → Способы оплаты**.
6. Найдите в списке **Passimpay** → нажмите зелёный плюс **Установить**.
7. Нажмите синий карандаш **Редактировать**.
8. Заполните настройки:
   - **API Key (Secret Key)** — из личного кабинета Passimpay
   - **Platform ID** — из личного кабинета Passimpay
   - **Способ оплаты** — Карта и криптовалюта / Только криптовалюта / Только банковская карта
   - **Статус заказа (оплачен)** — обычно "Complete"
   - **Статус заказа (ожидание)** — обычно "Pending"
   - **Статус заказа (ошибка/отмена)** — обычно "Failed"
   - **Статус** — Включено
   - **Геозона** — Все зоны (или выберите нужную)
9. **Важно:** скопируйте **URL для уведомлений (callback)** из формы (выглядит как `https://ваш-сайт/index.php?route=extension/payment/passimpay/callback`).
10. Перейдите в личный кабинет Passimpay → настройки платформы → пропишите этот URL как **Notification URL**.
11. Нажмите **Сохранить** в админке OpenCart.

---

## Проверка работы

1. Откройте магазин в режиме инкогнито (чтобы не было сессии админа).
2. Добавьте товар в корзину.
3. Оформите заказ → на шаге "Способ оплаты" должен появиться **Passimpay**.
4. Выберите его, подтвердите → вас перенаправит на страницу оплаты Passimpay.
5. Произведите тестовую оплату.
6. После подтверждения платежа Passimpay отправит webhook на ваш callback URL → статус заказа в админке автоматически изменится на "Complete".

### Если статус заказа не изменился

Это нормально, если:
- Платёж криптовалютой ещё не получил подтверждение в блокчейне (подождите 5-15 минут).
- Карточная авторизация ещё не подтверждена банком (подождите 1-5 минут).

Для проверки статуса вручную: **Дополнения → Способы оплаты → Passimpay → Редактировать → вкладка "Инструменты"** → введите ID заказа → нажмите **"Проверить статус"**. Если Passimpay уже подтвердил оплату, статус заказа обновится автоматически.

---