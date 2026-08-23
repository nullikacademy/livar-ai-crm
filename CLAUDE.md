# LiVAR Packaging CRM

Mobile-first, WhatsApp-style CRM — and a real WhatsApp inbox on the
360dialog Cloud API. PHP 8.1+ backend, vanilla HTML/CSS/JS frontend,
Supabase (PostgREST) for data, n8n for AI-drafted replies.
**No build step, no package manager, no test suite** — edit a file and
reload the page.

Read `README.md` first: it documents the setup, the request flow, the
360dialog wiring and the n8n workflow in detail. This file only covers
what isn't obvious from it.

## Setup in a fresh clone

`config/config.php` is gitignored (it holds the Supabase `service_role`
secret key, the 360dialog API key, the webhook token and the CRM
password hash — and this repo is public). To run anything:

```bash
cp config/config.example.php config/config.php
# then fill in SUPABASE_URL, SUPABASE_SERVICE_KEY, N8N_WEBHOOK_URL,
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
  `config/whatsapp.php`, media-on-disk rules through `config/media.php`.
- **The CRM is the only writer to `n8n_chat_history`.** Inbound rows come
  from `api/whatsapp_webhook.php`, outbound ones from `api/send.php`.
  n8n is a stateless draft generator: it receives conversation history,
  returns text, and touches no table. Its workflow must have **no
  Supabase insert nodes and no Postgres Chat Memory node** — either
  would double-write the history the CRM now owns.
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
- **Errors:** log the real cause with `error_log('[Supabase] ...')` or
  `error_log('[WhatsApp] ...')` and return a generic message to the
  browser via `json_error()`. The one exception is `api/send.php`, which
  passes the provider's own wording through: "message failed" with no
  reason can't tell an agent whether to retry, fix the number, or phone
  the customer.
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
- Frontend is one file, `assets/js/app.js` — plain DOM APIs and `fetch`,
  no framework, no bundler. `index.php`'s `asset()` helper appends
  `?v=<filemtime>` for cache-busting, so no manual version bumps.
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
