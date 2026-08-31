# LiVAR Packaging — CRM

A mobile-first, WhatsApp-style CRM for customer support and sales — and a
real WhatsApp inbox. Messages from customers arrive by webhook from
**360dialog**, replies go out over the same channel, and OpenAI drafts
each reply for an agent to edit before sending. PHP + PostgreSQL
(Supabase) on the backend, vanilla HTML/CSS/JS on the front — no build
step.

## 1. Requirements

- PHP 8.1+ with the `curl` and `fileinfo` extensions (both on by default
  almost everywhere, including shared cPanel hosting)
- A Supabase PostgreSQL database with the two tables + one SQL function
  described below
- A **360dialog** WhatsApp Business account and its API key
- An **OpenAI** API key, for drafting replies and describing photos
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
safe to re-run — and re-running it is exactly what an existing install
needs after an upgrade, since every column it adds is guarded. This
creates:
- `livar_customer` and `n8n_chat_history` (if they don't already exist)
- the WhatsApp columns on both, all nullable, so existing rows are fine
- `avatar_path` and `label` on the customer, and `wa_buttons` /
  `wa_template` on the chat history
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

#### OpenAI

```php
const OPENAI_API_KEY  = 'sk-...';
const OPENAI_BASE_URL = 'https://api.openai.com/v1';
```

The **model** and the **system prompt** are deliberately not here — they
live in the database and are edited on the settings page. See section 5.

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

**Open the gear icon in the sidebar first** — `settings.php` checks every
connection live and usually names the problem outright. It tests the
configuration, Supabase, whether `sql/schema.sql` has actually been run,
the 360dialog API key, whether the webhook registered with 360dialog
points at *this* install, OpenAI, the media directory and the PHP
environment. Each row says what is wrong and what to do about it.

A few notes on how to read it:

- **Check** (amber) means reachable but unconfirmed — not necessarily
  broken. For example, not every 360dialog plan exposes the endpoint that
  reports the registered webhook URL.
- The page never shows a key or token. The webhook URL appears with its
  token replaced by a short fingerprint, which is still enough to compare
  what is registered against what this install answers on.
- **Run a live draft test** is separate because it really calls OpenAI
  and therefore costs a model call. Nothing on the page does that unless
  you click it.
- The AI model and prompt are editable here, and the **catalog** is
  uploaded here; API keys are not editable, and stay in
  `config/config.php`.

If the page itself will not load, or you need the underlying detail, the
app logs the real error and shows only a generic message in the browser.
Check your PHP error log — on cPanel that's **Metrics → Errors**
or `public_html/error_log` — for lines starting with `[Supabase]`,
`[WhatsApp]` or `[AI]`. Common ones:

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
    ai.php              OpenAI client (curl-based) — chat and vision
    media.php           media store layout, mime allowlist, path safety
    countries.php       dialling prefix → country name and flag
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
    avatar.php          GET/POST/DELETE — a customer's profile photo
    catalog.php         GET/POST/DELETE — the one-click catalog file
    templates.php       GET  — the approved templates on this number
    draft.php           POST — ask OpenAI for a draft reply
    settings.php        GET/PUT — the editable AI settings
    health.php          GET  — one connection check per request
/assets
    css/style.css       mobile-first styles
    js/app.js           all inbox logic
    js/settings.js      drives the connection-health page
/components
    sidebar.php, chat.php, customer_form.php
/storage
    .htaccess           Require all denied
    media/YYYY/MM/…     inbound media (gitignored)
    media/outbox/…      staged outbound media (gitignored)
    media/avatars/…     customer profile photos (gitignored)
    media/catalog/…     the uploaded catalog file (gitignored)
index.php               the app (behind login)
login.php               sign in; login.php?logout=1 signs out
settings.php            AI prompt/model + connection health
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

- **Draft** sends the conversation history and customer context to
  `api/draft.php`, which calls OpenAI and returns `{ draft }`. The draft
  goes into the composer for the agent to edit. Nothing is written
  anywhere. See section 5.
- **Send** POSTs to `api/send.php`, which checks the 24-hour window,
  delivers over 360dialog, and stores the row with `direction='out'` and
  the provider's message id. Delivery receipts arrive later as
  `statuses[]` on the same webhook and update the ticks in place.
- **Attachments** are staged by `api/upload.php` first and only uploaded to
  WhatsApp when Send is pressed. The file's mime is re-read from its own
  bytes with `finfo_file()`, never taken from the browser.

### The 24-hour window, and templates

WhatsApp only allows a free-form reply within 24 hours of the customer's
last inbound message. After that the only thing Meta will carry is a
**template approved in advance**. The header shows how long is left, and
`api/send.php` enforces the rule server-side with a `409` — a template is
the one message type that skips that check, because it is the one type
WhatsApp still delivers.

When the window closes, the notice above the composer offers **Send a
template**. The picker lists every template on the number, fetched live
from 360dialog, and shows the ones it cannot send greyed out with the
reason — still in review, or needing a header/button value this page has
nowhere to put. Choosing one draws an input per `{{1}}` placeholder and
previews the finished message; what gets stored in the thread is that
finished text, not the raw `Hi {{1}}`, so the next agent to open the
conversation reads what the customer actually received.

### Asking a question

**Attach → Ask a question** sends up to three tappable answers alongside
the question. The tap comes back on the webhook as an ordinary inbound
message, so the answer lands in the thread — and in the AI's context —
without anyone having to interpret "yeah the small one I think". The
options the customer was offered are drawn under the question in the
thread, so the answer that follows is never a non sequitur.

WhatsApp's limits apply and are enforced before sending: three buttons,
20 characters each.

### The catalog

**Settings → Catalog** takes one file for the whole business. After that
**Attach → Send catalog** delivers it in one tap, with whatever is in the
composer as the caption. Nothing about which file is sent comes from the
browser — `api/send.php` reads the stored setting itself — so it is
always the current version rather than whichever copy was on someone's
laptop.

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
of them, since it gates sending. Neither is `avatar_path` — it is a
location inside `storage/`, and `api/avatar.php` is the only thing that
writes it, from bytes it validated itself.

Three things about a customer are not typed in:

- **The country.** It is in the dialling prefix, so `config/countries.php`
  works it out and the flag appears beside the name in the list, the
  header and the details panel. The country *field* is filled in once on
  first contact and is editable after that — a number can be a roaming
  SIM or a virtual line, and a prefix table must never overwrite what an
  agent knows. The panel shows what the number suggests as a note beside
  the field, with a one-click **Use**.
- **The label.** A number the webhook has just met is marked **New
  customer**; an agent moves them to **Old customer**, or clears it, from
  the details panel. A closed set of two rather than free text, because
  the point is to tell first contacts apart at a glance and that stops
  working the moment `New` and `new customer` are two different labels.
- **Not** the profile photo. WhatsApp does not hand out a contact's own
  picture — the Cloud API exposes the business profile's photo and
  nothing about the person on the other end — so **Change photo** in the
  details panel is how one gets set. Without one, the avatar is initials,
  exactly as before.

## 5. The AI

Drafting runs inside this app. There is no n8n in the path: the CRM
already owns the conversation, so it owns the prompt and the model call
too — one fewer service to be down, and one fewer place the prompt can
drift out of sync with reality.

### What you configure, and where

| Setting | Where | Why there |
|---|---|---|
| `OPENAI_API_KEY` | `config/config.php` | It is a secret. The app never writes to that file. |
| Model | **Settings page** | Changes often; no reason to need a file edit and a re-upload. |
| System prompt | **Settings page** | Same — and the person tuning it is rarely the person with SSH. |
| Catalog file | **Settings page** | Uploaded, not typed: `api/settings.php` will not write the keys behind it, because one of them is a path inside `storage/`. |

The model and prompt live in the `livar_settings` table. A fresh install
runs on the built-in defaults in `SETTING_DEFAULTS`
(`config/db_functions.php`), so drafting works before anyone opens the
settings page. Saving an empty prompt restores the default.

The model picker autocompletes from the models your key can actually
use, fetched live from OpenAI — a hardcoded list would offer models that
only fail at draft time. Free text is still accepted.

**Any model id works, old or new.** OpenAI renamed `max_tokens` to
`max_completion_tokens`, and the newer models — the o-series, and gpt-5
and later — reject a request that uses the old name outright, as do they
a `temperature` other than the default. `config/ai.php` sends the modern
spelling, and if the provider objects it reads the parameter name out of
the provider's own 400 and retries corrected, remembering the answer for
the rest of the request. So a model this CRM has never heard of still
drafts, and an OpenAI-compatible service behind a custom
`OPENAI_BASE_URL` that only knows the legacy name works too.

One thing to know about reasoning models: they spend the same token
budget on thinking as on writing, so a small cap can be used up before a
single word of the reply exists. That comes back as a specific error
naming the budget rather than a bare "empty reply".

### Pressing Draft

`api/draft.php` builds the request:

1. Your system prompt.
2. A second system message with what the CRM knows about the customer —
   name, company, city, notes. Separate on purpose, so editing the prompt
   cannot accidentally delete the customer context.
3. Up to 40 turns of conversation, oldest first.

The reply goes into the composer. **Nothing is written to the database
until the agent presses Send** — a draft they discard leaves no trace.

### Photos

WhatsApp is a visual channel; a customer will send a picture of the can
they want rather than describe it. Photos are handled two ways at once:

- **The most recent three photos travel as real images.** The model sees
  the actual picture, so it can answer questions a canned description
  would have thrown away — a lid diameter, a print defect, the wording on
  a label.
- **Every photo also gets a one-line caption** when it arrives, written
  by the same model. That caption is what the sidebar shows instead of
  "📷 Photo", and it stands in for older photos that fall outside the
  three-image window.

Attaching every photo in a long thread would cost a fortune in image
tokens for pictures nobody is asking about; captioning alone would be
lossy the moment someone asks a specific question. Doing both is why
`DRAFT_IMAGE_LIMIT` exists in `api/draft.php` — raise it if your
conversations are more visual than most.

Captioning is never fatal. If OpenAI is unconfigured or failing, the
photo still arrives, still renders, and still attaches to a draft; only
the caption is missing.

Other media does not travel as a file — the model cannot watch a video —
so videos, documents and locations appear as short labels like
`[sent a document: pedido.pdf]`.

### Costs worth knowing

- The system prompt is billed on **every** draft. The settings page warns
  if it grows past ~6,000 characters.
- Each inbound photo costs one small vision call, once.
- **Run a live draft test** on the settings page is the only thing that
  spends money without an agent asking for a draft, and it never runs on
  its own.

### Migrating from the old n8n workflow

Earlier versions posted the conversation to an n8n webhook. If you are
upgrading:

1. Copy the system prompt out of your AI Agent node and paste it into the
   settings page.
2. Remove `N8N_WEBHOOK_URL` and `N8N_TIMEOUT_SECONDS` from
   `config/config.php`, and add `OPENAI_API_KEY`.
3. Deactivate the workflow in n8n. Nothing calls it any more.

If that workflow also had Supabase insert nodes or a Postgres Chat Memory
node, they were already writing history the CRM owns — deactivating it
fixes that too.
