# LiVAR Packaging — CRM

A mobile-first, WhatsApp-style CRM for customer support and sales — and a
real WhatsApp inbox. Messages from customers arrive by webhook from
**360dialog**, replies go out over the same channel, and n8n drafts each
reply for an agent to edit before sending. PHP + PostgreSQL (Supabase) on
the backend, vanilla HTML/CSS/JS on the front — no build step.

## 1. Requirements

- PHP 8.1+ with the `curl` and `fileinfo` extensions (both on by default
  almost everywhere, including shared cPanel hosting)
- A Supabase PostgreSQL database with the two tables + one SQL function
  described below
- A **360dialog** WhatsApp Business account and its API key
- Apache or Nginx (an `.htaccess` is included for Apache)
- A writable `storage/` directory for downloaded media

**No `pdo_pgsql` needed.** Many budget/shared hosts (cPanel included) don't
compile that extension in and won't let you enable it. This CRM talks to
Supabase over its REST API (PostgREST) instead of a native Postgres
connection, using only `curl` — which is virtually always available.

## 2. Setup

Four steps. All configuration lives in **one file**: `config/config.php`.

### Step 1 — Get the files onto the server

Clone the repo, or on cPanel: **File Manager** → upload a zip of it into
`public_html` or a subdomain folder → **Extract**, so that `index.php` sits
directly in the web root.

Make sure you're on **PHP 8.1+**. On cPanel that's under **Select PHP
Version**. No extension checkboxes are usually needed — `curl` and
`fileinfo` are enabled by default virtually everywhere.

**HTTPS is required.** 360dialog will not deliver webhooks to a plain
HTTP URL, and the login cookie is only marked `secure` over TLS.

### Step 2 — Run the database schema

In the **Supabase SQL editor**, run the contents of `sql/schema.sql`. It's
safe to re-run. This creates:
- `livar_customer` and `n8n_chat_history` (if they don't already exist)
- the WhatsApp columns on both, all nullable, so existing rows are fine
- the unique indexes on `wa_id` and `wa_message_id` that make first
  contact race-free and webhook retries harmless
- the `get_customers_with_preview()` function, which the app calls to fetch
  each customer along with their latest message in a single request

### Step 3 — Create and fill in `config/config.php`

`config/config.php` is **not in the repository** — it holds the Supabase
`service_role` secret key, the 360dialog API key, the webhook token and
the CRM password hash, and this repo is public. It is listed in
`.gitignore` so it can never be committed by accident. Create it from the
template that *is* tracked:

```bash
cp config/config.example.php config/config.php
```

Then open it and fill in these values.

#### Supabase — dashboard → **Project Settings → API**

```php
const SUPABASE_URL         = 'https://your-project-ref.supabase.co';
const SUPABASE_SERVICE_KEY = 'your-service-role-secret-key';
```

Use the **service_role** key, not the public `anon` key — service_role is
what lets the CRM's backend read and write every customer's rows regardless
of Row Level Security. It's only ever used server-side in PHP and is never
sent to the browser.

#### Sign-in

The CRM is behind a single shared password. Store a **hash**, never the
plaintext. Generate it on any machine with PHP:

```bash
php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
```

```php
const CRM_PASSWORD_HASH = '$2y$12$...the whole string...';
```

#### WhatsApp (360dialog)

```php
const D360_API_KEY             = 'your-360dialog-api-key';
const D360_BASE_URL            = 'https://waba-v2.360dialog.io';
const WHATSAPP_WEBHOOK_TOKEN   = 'a-long-random-string';
const WHATSAPP_MAX_MEDIA_BYTES = 16 * 1024 * 1024;
const WHATSAPP_WINDOW_HOURS    = 24;
```

Find the API key in the 360dialog Hub against your WhatsApp number.
Generate the webhook token with:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

#### n8n

```php
const N8N_WEBHOOK_URL     = 'https://your-n8n-host.example.com/webhook/your-webhook-uuid';
const N8N_TIMEOUT_SECONDS = 45;
```

That's the whole configuration. There is no `.env` file, no `secrets.php`,
and no environment variables or `SetEnv` directives to set — which is
exactly why this works the same on cPanel as anywhere else. The `config/`
folder is blocked from web access by `config/.htaccess`, so these values
are never reachable from a browser.

> **Upgrading later:** keep a copy of your `config/config.php` before
> overwriting the app with a newer version, then put it back.

### Step 4 — Register the webhook with 360dialog

Point 360dialog at your CRM, **including the token**:

```
https://your-crm.example.com/api/whatsapp_webhook.php?token=<WHATSAPP_WEBHOOK_TOKEN>
```

Set it in the 360dialog Hub, or with their API:

```bash
curl -X POST https://waba-v2.360dialog.io/v1/configs/webhook \
     -H "D360-API-KEY: <your key>" \
     -H "Content-Type: application/json" \
     -d '{"url":"https://your-crm.example.com/api/whatsapp_webhook.php?token=<token>"}'
```

360dialog does **not** sign its webhook calls, so that token in the URL is
the whole credential. Treat the full URL as a secret: anyone who has it
can inject messages into your CRM. Rotate it by changing
`WHATSAPP_WEBHOOK_TOKEN` and re-registering.

To check it works, send a WhatsApp message to your business number. A
customer should appear in the sidebar within a few seconds. If not, check
your PHP error log for lines starting with `[WhatsApp]`.

### If something doesn't work

The app logs the real underlying error and shows only a generic message in
the browser. Check your PHP error log — on cPanel that's **Metrics → Errors**
or `public_html/error_log` — for lines starting with `[Supabase]` or
`[WhatsApp]`. Common ones:

| Log line | Cause | Fix |
|---|---|---|
| `config/config.php is missing` | Never copied from the template | `cp config/config.example.php config/config.php`, then Step 3 |
| `config/config.php has not been filled in yet` | Placeholder key still in place | Step 3 above |
| `HTTP 401` / `Invalid API key` | Wrong key, or the `anon` key instead of `service_role` | Recopy the service_role key |
| `HTTP 404` + `Could not find the function` | Schema not run, or run before this function existed | Re-run `sql/schema.sql` (Step 2) |
| `HTTP 404` + `relation ... does not exist` | Tables missing | Re-run `sql/schema.sql` (Step 2) |
| `Could not resolve host` | Typo in `SUPABASE_URL` | Recopy the Project URL |
| `CRM_PASSWORD_HASH is not set` | Sign-in not configured | Step 3, "Sign-in" |
| `[WhatsApp] webhook called with a bad token` | 360dialog registered without the `?token=` | Re-register the full URL (Step 4) |
| `[WhatsApp] D360_API_KEY has not been set` | Key still a placeholder | Step 3, "WhatsApp" |
| `[WhatsApp] media download failed` | Media expired at Meta (~30 days), or a network blip | Nothing to do; the message itself is intact |
| `no unique or exclusion constraint matching the ON CONFLICT` | An older, partial version of an index | Re-run `sql/schema.sql` (Step 2) |

Note: cPanel's built-in **PostgreSQL Databases** feature manages a *local*
Postgres instance on the server. It is not used here — Supabase is external
and reached over HTTPS — so you can ignore it entirely.

## 3. Project structure

```
/config
    config.example.php  template — copy to config.php (tracked in git)
    config.php          ← THE ONLY FILE YOU EDIT (all settings; gitignored)
    load_config.php     loads config.php, with a clear error if it's missing
    database.php        Supabase REST client (curl-based)
    whatsapp.php        360dialog Cloud API client (curl-based)
    media.php           media store layout, mime allowlist, path safety
    auth.php            shared-password sessions; require_auth()
    app.php             small request/response helpers
    db_functions.php    all data access lives here, one function per operation
/api
    customers.php       GET (list/search/one), POST (create), PUT (update)
    messages.php        GET (chat history, with since_id for polling)
    whatsapp_webhook.php  ← 360dialog posts here (token in the URL, no login)
    send.php            POST — deliver a message over WhatsApp
    upload.php          POST — stage an outbound attachment
    media.php           GET  — stream a stored media file
    webhook.php         POST — ask n8n for a draft reply
/assets
    css/style.css       mobile-first styles
    js/app.js           all frontend logic
/components
    sidebar.php, chat.php, customer_form.php
/storage
    .htaccess           Require all denied
    media/YYYY/MM/…     inbound media (gitignored)
    media/outbox/…      staged outbound media (gitignored)
index.php               the app (behind login)
login.php               sign in; login.php?logout=1 signs out
sql/schema.sql
```

## 4. How the pieces fit together

### Signing in

Everything except the 360dialog webhook is behind `require_auth()`. There
is one shared password and no user table; `/api` callers get a `401` JSON
body, page loads get redirected to `login.php`.

`api/whatsapp_webhook.php` is the deliberate exception — 360dialog cannot
log in — so it authenticates on the token in its own URL instead, compared
with `hash_equals()`, and answers `404` on a mismatch rather than
confirming to a prober that the endpoint exists.

### A message arrives

1. 360dialog POSTs to `api/whatsapp_webhook.php?token=…`.
2. The sender's `wa_id` is looked up; an unknown number creates a customer
   with its WhatsApp profile name and a `wa_<digits>` session id. That
   insert is an upsert on the unique `wa_id` index, so two deliveries
   racing on a first message converge on one row.
3. The message is stored in `n8n_chat_history` with `direction='in'` and
   its type-specific columns. A duplicate `wa_message_id` is rejected by a
   unique index and treated as success — that is 360dialog retrying.
4. `last_inbound_at` is stamped on the customer. This one column drives
   the 24-hour reply window.
5. The webhook **acknowledges before downloading media**, using
   `fastcgi_finish_request()` where php-fpm provides it. It always answers
   `200`, even on an internal failure: a non-2xx makes 360dialog redeliver
   the whole batch, which after a partial insert is worse than a logged
   error.
6. Media is downloaded in two hops — metadata, then the `lookaside.fbsbx.com`
   URL with its host rewritten back to 360dialog — and saved under
   `storage/media/YYYY/MM/` with a random hex name.

The open conversation polls every 8s and the sidebar every 25s, so the new
message appears without a reload. Both pause while the tab is hidden.

### A reply goes out

- **Draft** posts the conversation history and customer context to
  `api/webhook.php`, which asks n8n and returns `{ draft }`. The draft goes
  into the composer for the agent to edit. Nothing is written anywhere.
- **Send** POSTs to `api/send.php`, which checks the 24-hour window,
  delivers over 360dialog, and stores the row with `direction='out'` and
  the provider's message id. Delivery receipts arrive later as
  `statuses[]` on the same webhook and update the ticks in place.
- **Attachments** are staged by `api/upload.php` first and only uploaded to
  WhatsApp when Send is pressed. The file's mime is re-read from its own
  bytes with `finfo_file()`, never taken from the browser.

### The 24-hour window

WhatsApp only allows a free-form reply within 24 hours of the customer's
last inbound message. After that a business must send an **approved
template**, which this CRM does not do — so it blocks sending instead and
says why. The header shows how long is left; `api/send.php` enforces the
same rule server-side and answers `409`.

### Media

Nothing under `storage/` is served directly — Apache denies it. Media
reaches the browser only through `api/media.php`, which requires a session,
resolves the path with `realpath()` and asserts it is inside the media
root, and streams with the stored mime plus `nosniff`. Filenames on disk
are server-generated hex with an extension from a mime allowlist, so
nothing a sender controls ever becomes part of a path.

Meta deletes media after roughly 30 days, which is why the webhook
downloads eagerly. `api/media.php` can re-fetch a file that never landed,
but only while it still exists upstream — that is a fallback, not the plan.

Media accumulates against your hosting quota with no pruning job. The
webhook logs the store's total size periodically; watch for
`[WhatsApp] media store now holds …` in the error log.

### Customers

**New Chat** creates a blank `livar_customer` row and opens the details
panel. Such a record has no `wa_id`, so it has no WhatsApp thread and
cannot be sent to until a real message links a number to it. The details
panel edits the profile fields; `last_inbound_at` is deliberately not one
of them, since it gates sending.

## 5. n8n workflow — step by step

This is the workflow the **Draft** button calls. Build it as a separate
n8n workflow behind the webhook URL configured in `N8N_WEBHOOK_URL`.

> **The CRM now owns `n8n_chat_history`.** n8n is a stateless draft
> generator: it receives the conversation, returns text, and writes to no
> table. If you are upgrading an older workflow, **delete both Supabase
> insert nodes and the Postgres Chat Memory node** — the CRM already
> stores every turn, and leaving them in would write each message twice.

### Step 1 — Webhook node
- Node: **Webhook**
- HTTP Method: `POST`
- Path: the UUID from your URL — the same one you put in `N8N_WEBHOOK_URL`
- Respond: **Using "Respond to Webhook" node** (not immediately) — this lets
  you wait for the AI Agent step to finish before answering the CRM.
- Body the CRM sends:
  ```json
  {
    "session_id": "wa_34600111222",
    "history": [
      { "role": "user",      "content": "Hola, necesito 500 cajas" },
      { "role": "assistant", "content": "Con mucho gusto, ¿de qué medida?" },
      { "role": "user",      "content": "[photo] Como esta" }
    ],
    "customer": {
      "first_name": "Marta", "last_name": "Ruiz", "company": null,
      "country": null, "city": null, "email": null,
      "phone": "+34600111222", "wa_id": "34600111222", "notes": null
    }
  }
  ```
  `history` is up to 40 turns, oldest first. Media turns arrive as short
  labels — `[photo]`, `[voice message]`, `[document: pedido.pdf]` — so the
  model knows something was sent even though it cannot see it.

### Step 2 — Build the prompt
- Node: **Code** (or **Set**) named "Build Prompt"
- Turn `{{$json.body.history}}` into whatever your model node wants. For a
  Basic LLM Chain, joining the turns is enough:
  ```js
  const body = $input.first().json.body;
  const who = c => [c.first_name, c.last_name].filter(Boolean).join(' ') || c.wa_id;
  return [{ json: {
    session_id: body.session_id,
    customer: who(body.customer),
    transcript: body.history
      .map(t => `${t.role === 'user' ? 'Customer' : 'LiVAR'}: ${t.content}`)
      .join('\n'),
  }}];
  ```
- Add an **IF** node after it: if `session_id` or `history` is empty,
  branch to "Respond to Webhook" with a 400 and
  `{ "success": false, "error": "session_id and history are required" }`,
  so a bad request never reaches the model.

### Step 3 — AI Agent
- Node: **AI Agent** (LangChain), or **Basic LLM Chain** if you don't need
  tool use.
- Chat Model: your model of choice.
- **Memory: none.** The CRM sends the whole conversation on every call.
  A Postgres Chat Memory node here would both duplicate that context and
  write rows into `n8n_chat_history` behind the CRM's back.
- System Prompt: LiVAR Packaging Solutions' support/sales persona — tone,
  product knowledge, escalation rules. Add a line telling it to write the
  reply only, with no preamble, since the text goes straight into an
  agent's composer.
- User Message: `{{$json.transcript}}` (plus `{{$json.customer}}` for
  context if your prompt uses it).

### Step 4 — Respond to Webhook
- Node: **Respond to Webhook**
- Response Code: `200`
- Response Body (JSON):
  ```json
  { "draft": "{{ $json.output }}" }
  ```
  Use whichever field your model node produces — commonly `output` or
  `text`; check the node's output panel. The CRM also accepts `output`,
  `text`, `reply`, `message` or `content` at the top level, and a bare
  JSON string, so a small mismatch here won't break it.

### Step 5 — Error handling
- Set **On Error** = "Continue Using Error Output" on the AI Agent, and
  route the error output to a small "Respond with error" branch:
  - Node: **Respond to Webhook**
  - Response Code: `500`
  - Body: `{ "success": false, "error": "AI generation failed. Please try again." }`
- Add a **Workflow Timeout** (Settings → Timeout Workflow) of ~40s so a
  hung model call can't leave the CRM's request waiting past its own 45s
  timeout without an answer.
- Optional but recommended: an **Error Trigger** workflow that posts
  failures to a Slack/email channel.

### Resulting flow

```
Webhook (POST { session_id, history, customer })
   -> Build Prompt (Code/Set)
   -> IF valid? --no--> Respond to Webhook (400)
        |yes
   -> AI Agent (no memory node — history comes in the request)
   -> Respond to Webhook (200, { draft })

Any node error -> Respond to Webhook (500, { success: false, error })
```

Nothing in this workflow touches the database. The draft is returned to
the CRM, dropped into the composer, and only becomes a message in
`n8n_chat_history` if the agent presses **Send** — at which point
`api/send.php` writes it, right after WhatsApp confirms delivery.
