# LiVAR Packaging CRM

Mobile-first, WhatsApp-style CRM — and a real WhatsApp inbox on the
360dialog Cloud API. PHP 8.1+ backend, vanilla HTML/CSS/JS frontend,
Supabase (PostgREST) for data, OpenAI for AI-drafted replies.
**No build step, no package manager, no test suite** — edit a file and
reload the page.

Read `README.md` first: it documents the setup, the request flow, the
360dialog wiring and how the AI is configured. This file only covers
what isn't obvious from it.

## Setup in a fresh clone

`config/config.php` is gitignored (it holds the Supabase `service_role`
secret key, the 360dialog API key, the webhook token and the CRM
password hash — and this repo is public). To run anything:

```bash
cp config/config.example.php config/config.php
# then fill in SUPABASE_URL, SUPABASE_SERVICE_KEY, OPENAI_API_KEY,
# CRM_PASSWORD_HASH, D360_API_KEY and WHATSAPP_WEBHOOK_TOKEN
```

**Never commit `config/config.php`, and never put a real key, URL,
password, token or webhook UUID into `config/config.example.php` or the
README** — the template stays placeholders-only.

## Architecture rules

- **No native Postgres driver.** `pdo_pgsql` is deliberately unused —
  shared/cPanel hosts often don't have it. All data access goes through
  `config/database.php`, a curl-based Supabase REST client. Don't
  introduce PDO, an ORM, or Composer dependencies.
- **All data access lives in `config/db_functions.php`**, one function per
  operation. Routes in `/api` include that file; they never call
  `Supabase::client()` directly. WhatsApp calls go through
  `config/whatsapp.php`, OpenAI through `config/ai.php`, media-on-disk
  rules through `config/media.php`.
- **The CRM is the only writer to `n8n_chat_history`.** Inbound rows come
  from `api/whatsapp_webhook.php`, outbound ones from `api/send.php`. The
  table keeps its legacy name; nothing outside this app writes to it.
- **Drafting runs in-app, not in n8n.** `api/draft.php` calls OpenAI
  directly. A draft is never persisted — it goes into the composer, and
  only becomes a row if the agent presses Send.
- **The model and system prompt are database settings, not constants.**
  They live in `livar_settings` so the settings page can change them;
  `SETTING_DEFAULTS` in `db_functions.php` holds the fallbacks. API keys
  stay in `config/config.php`, which the app must never write to. Any new
  editable setting needs a key in `SETTING_DEFAULTS` — `api/settings.php`
  refuses to read or write anything not declared there.
- **Photos reach the model two ways.** The most recent `DRAFT_IMAGE_LIMIT`
  photos are attached as real images; every photo also gets a one-line
  `ai_caption` on arrival, used for the sidebar preview and for older
  photos. Captioning must never be fatal — an unconfigured or failing AI
  cannot be allowed to cost us the inbound message.
- **Everything is behind `require_auth()` except `api/whatsapp_webhook.php`.**
  360dialog can't log in, so that one endpoint authenticates on an
  unguessable token in its own URL, compared with `hash_equals()`, and
  answers 404 on a mismatch. Any new `/api` route needs the auth guard.
- **Media is never served from disk.** `storage/` is denied by Apache;
  `api/media.php` is the only reader, and it resolves paths through
  `realpath()` and asserts they sit inside the media root. Filenames are
  server-generated hex and extensions come from a mime allowlist — never
  from anything a sender supplied.
- **`api/health.php` reports failures instead of raising them.** It is the
  one place that turns a broken dependency into a description rather than
  an exception, so its checks must be honest: an unproven check is `warn`,
  never `ok`, and a check that could not run says so instead of guessing
  a cause. It must never return a key, token or hash — presence only, and
  the webhook token is shown as a fingerprint.
- **Errors:** log the real cause with `error_log('[Supabase] ...')`,
  `'[WhatsApp] ...'` or `'[AI] ...'` and return a generic message to the
  browser via `json_error()`. The exceptions are `api/send.php` and
  `api/draft.php`, which pass the provider's own wording through: neither
  "message failed" nor "the AI failed" can tell an agent whether to
  retry, fix a number, top up a balance, or phone the customer.
- **Schema changes** go in `sql/schema.sql`, which must stay safe to
  re-run (`create ... if not exists`, `create or replace function`). A
  function whose `returns table` changes needs an explicit
  `drop function if exists` before it — Postgres refuses to replace one.
- **Unique indexes used by `on conflict` must not be partial.** Postgres
  cannot infer a partial index, and both the get-or-create on `wa_id` and
  the retry-dedup on `wa_message_id` depend on that inference. A plain
  unique index already allows unlimited NULLs, so the predicate would
  buy nothing.

## Frontend rules

- **Never interpolate a URL into an HTML string.** `escapeHtml()` does
  not escape quotes, so attribute interpolation is injectable. Set every
  `src`/`href` through a DOM property (`img.src = url`).
- Poll with `appendMessages()`, not `renderMessages()`. The latter is a
  full teardown and rebuild, which re-requests and re-flashes every
  image on each 8s tick; keep it for conversation switches.

## Conventions

- `declare(strict_types=1);` at the top of every PHP file.
- Typed function signatures and return types; docblocks on public helpers.
- Two frontend files, both plain DOM APIs and `fetch`, no framework and
  no bundler: `assets/js/app.js` is the inbox, `assets/js/settings.js`
  the settings page. They share nothing on purpose — a diagnostics page
  should not load the chat logic. `asset()` appends `?v=<filemtime>` for
  cache-busting, so no manual version bumps.
- 4-space indent in PHP and JS.

## Verifying a change

There are no tests. Check syntax on what you touched, then exercise the
page manually:

```bash
php -l path/to/file.php
php -S localhost:8000   # then open http://localhost:8000
```

Webhook work is driven with `curl` and a captured 360dialog payload:

```bash
curl -X POST 'http://localhost:8000/api/whatsapp_webhook.php?token=<WHATSAPP_WEBHOOK_TOKEN>' \
     -H 'Content-Type: application/json' --data @payload.json
```

Posting the same payload twice must insert exactly one row — that is the
retry-dedup path, and a duplicate is success, not an error. A bad token
must answer 404.
