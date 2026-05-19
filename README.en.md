# Passimpay for OpenCart 2.3 / ocStore 2.3

Passimpay payment module (cards and cryptocurrencies) for **ocStore / OpenCart 2.3.0.2.x**.

## Package contents

| File | Description |
|---|---|
| `INSTALL.en.md` | Detailed installation guide (recommended reading before installation) |
| `source/` | Unpacked source files (for developers) |

## Quick start

1. **Open `INSTALL.en.md`** and choose an installation method (via the OCMOD installer or manually via FTP).
2. Install the plugin following the instructions.
3. In the admin panel: **Extensions → Payment → Passimpay → Install → Edit**.
4. Enter your **API Key** and **Platform ID** (from your Passimpay account).
5. Copy the **notification URL (callback)** from the settings form and set it in your Passimpay account (Notification URL).
6. Enable **Status** → Save.

## System requirements

- ocStore 2.3.0.2.x (or a compatible OpenCart 2.3 build)
- PHP 5.6 or higher (tested from 5.6 through 8.x)
- PHP extensions: `curl`, `json`, `hash`
- Outbound access to `api.passimpay.io:443` (if your server uses a firewall)
- HTTPS on the store

## Support

If you have installation issues, contact Passimpay technical support.
