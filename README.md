# LiVAR Packaging CRM

A PHP 8.1+ / vanilla JavaScript WhatsApp inbox backed by Supabase, 360dialog, and a stateless n8n draft generator. It uses cURL/PostgREST and has no Composer or build step, so it can run on shared hosting.

## Setup

1. Upload the repository and point the web root at it.
2. Run `sql/schema.sql` in the Supabase SQL editor. It is designed to be safe to run again.
3. Copy `config/config.example.php` to `config/config.php` and replace every placeholder.
4. Ensure PHP can write below `storage/`.
5. Register the 360dialog webhook URL described below.

Required PHP extensions are `curl`, `json`, `fileinfo`, and `session`.

## Configuration

`config/config.php` is gitignored. Configure:

- `SUPABASE_URL` and the server-only `SUPABASE_SERVICE_KEY`
- `N8N_WEBHOOK_URL` and `N8N_TIMEOUT_SECONDS`
- `CRM_PASSWORD_HASH` (bcrypt, never plaintext)
- `D360_API_KEY`
- `WHATSAPP_WEBHOOK_TOKEN` (a long random value)
- `WHATSAPP_MAX_MEDIA_BYTES` (defaults to 16 MB in the example)
- `CUSTOMERS_PAGE_SIZE`

Generate the login hash with:

```sh
php -r "echo password_hash('replace-this-password', PASSWORD_BCRYPT), PHP_EOL;"
```

## 360dialog webhook

Register this URL, substituting the public CRM origin and configured token:

```text
https://your-crm.example.com/api/whatsapp_webhook.php?token=YOUR_TOKEN
```

360dialog does not sign these webhook requests. The endpoint returns 404 unless the URL token matches with `hash_equals`. Inbound messages create or reuse a customer by digits-only `wa_id`; webhook retries are deduplicated by the partial unique index on `wa_message_id`. Delivery/read/failure status updates are applied to outbound rows.

Media is downloaded eagerly through both 360dialog hops. It is stored as server-generated random filenames under `storage/media/YYYY/MM/`; outbound staging uses `storage/media/outbox/`. Apache denies direct access. Authenticated clients stream files through `api/media.php`, which checks the resolved path remains inside the storage root. Monitor disk quota because media is retained locally.

## n8n workflow

The CRM is the only writer to `n8n_chat_history`. n8n must not use Supabase insert nodes or Postgres Chat Memory.

Build the workflow as:

```text
Webhook → validate/build prompt from posted history → AI Agent → Respond to Webhook
```

The request contains `session_id`, `history: [{role, content}]`, and `customer`. Respond with:

```json
{"draft":"Suggested reply text"}
```

The Draft button places this text into the composer for editing. The Send button calls 360dialog directly and only then writes the outbound row. The UI and server both enforce the 24-hour customer-care window; template sending is intentionally unsupported.

## Main endpoints

- `api/customers.php` — authenticated directory/profile API
- `api/messages.php` — authenticated, bounded history with `since_id` polling
- `api/webhook.php` — authenticated stateless draft request to n8n
- `api/send.php` — authenticated outbound WhatsApp sends
- `api/upload.php` — authenticated MIME-validated outbound staging
- `api/media.php` — authenticated media streaming and lazy-fetch fallback
- `api/whatsapp_webhook.php` — token-authenticated inbound/status webhook

## Verification

Run `php -l` over all PHP files and `node --check assets/js/app.js`. Run the schema twice in the Supabase SQL editor. Then serve locally with `php -S localhost:8000` and verify logged-out page/API behavior.

For webhook testing, POST captured 360dialog text, image, video, document, audio, and location payloads with the correct URL token, then repeat the same payload to confirm deduplication. End-to-end outbound text/media/location and status checks require a configured 360dialog account and a real WhatsApp number.

