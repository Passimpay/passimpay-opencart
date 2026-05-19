# Installing Passimpay for ocStore 2.3

---

## Manual installation (via FTP/SSH/file manager)

Suitable for any server configuration, including cases where the OCMOD installer does not work.

### Via FTP client (FileZilla / WinSCP / Cyberduck)

1. Extract the `passimpay-opencart-2.3.zip` archive locally on your computer.
2. Inside you will find three folders: `admin/`, `catalog/`, and `system/`.
3. Connect to the server via FTP/SFTP.
4. Go to the store root (where `index.php` and `config.php` are located).
5. Upload these three folders to the store root:
   - When asked *"Folder already exists"*, choose **"Merge"** or **"Overwrite"**.
   - Do NOT choose *"Delete and replace"* — that will wipe your store!
6. Continue to the **"Module activation"** section below.

### Via SSH

```bash
# Upload the archive to the server
scp passimpay-opencart-2.3.zip user@server:/tmp/

# On the server
cd /tmp
unzip passimpay-opencart-2.3.zip
cp -rv upload/* /path/to/your/opencart/

# Set ownership to match the rest of the store files
# (find which user php-fpm runs as: ps aux | grep php-fpm)
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

## What should appear on disk after installation

Ten files in the store:

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

Verify that all 10 files are in place.

---

## Module activation

1. In the admin panel, open **System → Users → User Groups**.
2. Edit the administrator group (or the group you use).
3. Under **Access** and **Modify**, find and enable `extension/payment/passimpay`.
4. Save.
5. Open **Extensions → Payment**.
6. Find **Passimpay** in the list → click the green plus **Install**.
7. Click the blue pencil **Edit**.
8. Fill in the settings:
   - **API Key (Secret Key)** — from your Passimpay account
   - **Platform ID** — from your Passimpay account
   - **Payment method** — Card and cryptocurrency / Cryptocurrency only / Bank card only
   - **Order status (paid)** — usually "Complete"
   - **Order status (pending)** — usually "Pending"
   - **Order status (failed/cancelled)** — usually "Failed"
   - **Status** — Enabled
   - **Geo zone** — All zones (or select the one you need)
9. **Important:** copy the **notification URL (callback)** from the form (it looks like `https://your-site/index.php?route=extension/payment/passimpay/callback`).
10. Go to your Passimpay account → platform settings → set this URL as **Notification URL**.
11. Click **Save** in the OpenCart admin panel.

---

## Verifying operation

1. Open the store in incognito mode (so there is no admin session).
2. Add a product to the cart.
3. Place an order → on the "Payment method" step, **Passimpay** should appear.
4. Select it and confirm → you will be redirected to the Passimpay payment page.
5. Complete a test payment.
6. After the payment is confirmed, Passimpay will send a webhook to your callback URL → the order status in the admin panel will automatically change to "Complete".

### If the order status did not change

This is normal if:
- A cryptocurrency payment has not yet been confirmed on the blockchain (wait 5–15 minutes).
- Card authorization has not yet been confirmed by the bank (wait 1–5 minutes).

To check the status manually: **Extensions → Payment → Passimpay → Edit → "Tools" tab** → enter the order ID → click **"Check status"**. If Passimpay has already confirmed the payment, the order status will be updated automatically.

---
