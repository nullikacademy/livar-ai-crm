# LiVAR Packaging — WhatsApp CRM

An authenticated, mobile-first WhatsApp inbox for LiVAR Packaging. PHP 8.1+
talks to Supabase through PostgREST, sends and receives WhatsApp messages
through the 360dialog Cloud API, and asks n8n for editable AI drafts. The
frontend is vanilla HTML/CSS/JS with no build step.

## 1. Requirements

- PHP 8.1+ with curl, fileinfo, and sessions
- Apache 2.4+ or equivalent Nginx rules
- A Supabase PostgreSQL project
- A 360dialog WhatsApp channel and API key
- An n8n workflow that returns draft text
- HTTPS in production (the login cookie is always Secure)

No native Postgres driver, Composer dependency, Node build, or .env file is
required.

## 2. Install and configure

Run sql/schema.sql in the Supabase SQL editor, then create the untracked
configuration file:

~~~bash
cp config/config.example.php config/config.php
~~~

Set these constants in config/config.php:

~~~php
const SUPABASE_URL = 'https://your-project-ref.supabase.co';
const SUPABASE_SERVICE_KEY = 'your-service-role-secret-key';

const CRM_PASSWORD_HASH = 'your-bcrypt-password-hash';

const N8N_WEBHOOK_URL = 'https://n8n.example.com/webhook/your-workflow-id';
const N8N_TIMEOUT_SECONDS = 45;

const D360_API_KEY = 'your-360dialog-api-key';
const WHATSAPP_WEBHOOK_TOKEN = 'a-long-random-secret-token';
const WHATSAPP_MAX_MEDIA_BYTES = 16 * 1024 * 1024;

const CUSTOMERS_PAGE_SIZE = 30;
~~~

Generate the login hash and webhook token locally:

~~~bash
php -r "echo password_hash('choose-a-strong-password', PASSWORD_BCRYPT), PHP_EOL;"
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
~~~

CRM_PASSWORD_HASH is a bcrypt output, never plaintext.
SUPABASE_SERVICE_KEY, D360_API_KEY, WHATSAPP_WEBHOOK_TOKEN, and the password
hash belong only in config/config.php. That file and storage/media/ are
gitignored.

## 3. Database

sql/schema.sql is safe to run repeatedly. It:

- enables pg_trgm before creating the customer search index;
- creates or upgrades livar_customer;
- extends the existing n8n_chat_history table with direction, timestamps,
  WhatsApp IDs/statuses, media metadata, and location fields;
- adds partial unique indexes for webhook deduplication and WhatsApp customer
  race protection;
- drops and recreates get_customers_with_preview(text, int, int) because its
  RETURNS TABLE shape changed;
- makes sidebar previews media-aware.

message remains the canonical LangChain JSONB shape:

~~~json
{"type":"human","content":"Hello"}
~~~

Operational values are separate columns so WhatsApp message deduplication,
status updates, media lookup, and ordering can use indexes.

**The CRM is the only writer to n8n_chat_history. n8n must not insert into it.**

## 4. 360dialog webhook

Register this HTTPS endpoint with 360dialog, using the exact secret from
WHATSAPP_WEBHOOK_TOKEN:

~~~text
https://crm.example.com/api/whatsapp_webhook.php?token=YOUR_LONG_RANDOM_TOKEN
~~~

360dialog does not sign webhook bodies. The unguessable URL token is compared
with hash_equals; a mismatch returns 404.

The webhook accepts Cloud API-shaped entry[].changes[].value payloads:

- an unknown wa_id is upserted to livar_customer with deterministic
  session_id = "wa_" + wa_id and its WhatsApp profile name;
- text, image, video, audio, document, sticker, location, status, and provider
  error events are handled;
- wa_message_id is unique, so retries acknowledge successfully without
  duplicating a row;
- last_inbound_at opens the 24-hour free-form reply window;
- media is downloaded after the HTTP acknowledgement when PHP-FPM exposes
  fastcgi_finish_request(), otherwise inline.

360dialog media download is two-hop. The application resolves the media ID,
rewrites lookaside.fbsbx.com to waba-v2.360dialog.io, and sends
D360-API-KEY on both requests.

## 5. n8n draft workflow

The **Draft** button calls api/webhook.php. PHP loads the customer and the
bounded conversation history, then posts this shape to n8n:

~~~json
{
  "session_id": "wa_971500000000",
  "history": [
    {"role": "user", "content": "Do you have this in stock?"},
    {"role": "assistant", "content": "Yes, we do."}
  ],
  "customer": {
    "first_name": null,
    "wa_profile_name": "Customer",
    "wa_id": "971500000000"
  }
}
~~~

Build the n8n workflow as:

~~~text
Webhook → validate/build prompt from history + customer → AI Agent
        → Respond to Webhook {"draft":"..."}
~~~

Remove the Supabase insert nodes and the Postgres Chat Memory node. Conversation
history is already explicit in the request; a memory node or insert node would
double-write. Keep the workflow timeout below the PHP timeout and return a
non-2xx response when generation fails.

The CRM places the returned draft in the composer. The agent edits it and
clicks **Send**. Drafting never sends a message and never writes a chat row.

## 6. Sending and the 24-hour window

api/send.php requires a logged-in session, loads the customer's wa_id, and
rejects free-form sending with HTTP 409 when the most recent inbound message is
more than 24 hours old. Template sending is intentionally not implemented.

Supported outbound types:

- text, with link previews;
- JPEG/PNG images;
- MP4/3GPP video;
- allowed documents;
- latitude/longitude location cards.

Files are first uploaded to the private outbox with api/upload.php. Server-side
finfo_file() determines the MIME type; the browser-supplied MIME is not trusted.
PHP uploads the saved file to POST /media, sends the returned media ID through
POST /messages, then writes the outbound row. Delivery, read, and failure ticks
update from statuses[] webhook callbacks.

## 7. Private media storage

~~~text
storage/
  .htaccess                 # Require all denied
  media/
    YYYY/MM/<32-hex>.<ext>  # inbound media
    outbox/
      <32-hex>.<ext>        # outbound bytes
      <32-hex>.json         # server-owned opaque metadata
~~~

All filenames are generated with bin2hex(random_bytes(16)); extensions come
from a MIME allowlist. Browser clients never receive disk paths.
api/media.php?id={message-row-id}:

- requires CRM authentication;
- resolves the stored path with realpath();
- asserts it remains below storage/media;
- lazily fetches from 360dialog if eager download has not completed;
- streams with the stored MIME, Content-Disposition: inline, nosniff, and a
  long private cache header.

Media is retained because Meta may delete provider copies after roughly 30
days. Watch the cPanel disk quota; successful webhook media batches log current
storage usage. A pruning policy is not included.

## 8. Application flow

- login.php verifies the shared bcrypt hash and rotates the PHP session ID.
- index.php and every API route except api/whatsapp_webhook.php require the
  login session.
- The sidebar polls its first page every 25 seconds.
- An open conversation polls every 8 seconds with
  since_id=<largest-rendered-id> and appends only new rows.
- Polling pauses while the document is hidden and resumes immediately.
- Images open in a lightbox; audio/video use native controls; documents stream
  through the authenticated media endpoint; locations link to Google Maps.
- Plain HTTP(S) links are built with DOM nodes and rel="noopener noreferrer".
  Media URLs are assigned through DOM properties, never HTML interpolation.

## 9. API summary

| Endpoint | Method | Purpose |
|---|---:|---|
| api/customers.php | GET/POST/PUT | List, create, fetch, or edit customers |
| api/messages.php | GET | Bounded history, incremental rows, and statuses |
| api/webhook.php | POST | Generate an editable n8n draft |
| api/send.php | POST | Deliver an approved reply through 360dialog |
| api/upload.php | POST | Validate and stage an outbound attachment |
| api/media.php | GET | Authenticated media stream |
| api/whatsapp_webhook.php | POST | Token-authenticated provider callback |

## 10. Verification

There is no automated test suite. Before deployment:

~~~bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
git diff --check
~~~

Run sql/schema.sql twice in the Supabase SQL editor. Then verify:

1. logged-out index.php redirects to login.php;
2. logged-out api/customers.php returns 401 JSON;
3. an incorrect password fails and the configured password reaches the app;
4. a bad WhatsApp token returns 404;
5. replaying the same captured webhook payload inserts one message;
6. inbound text, image, video, audio, document, and location rows render;
7. a real phone receives outbound text, JPEG, MP4, PDF, and location;
8. sent → delivered → read ticks update;
9. backdating last_inbound_at disables Send and api/send.php returns 409.

For production, serve only over HTTPS and confirm both config/ and storage/
are denied by the web server.
