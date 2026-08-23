# LiVAR Packaging CRM

Mobile-first, WhatsApp-style CRM. PHP 8.1+ backend, vanilla HTML/CSS/JS
frontend, Supabase (PostgREST) for data, n8n for AI replies.
**No build step, no package manager, no test suite** — edit a file and
reload the page.

Read `README.md` first: it documents the setup, the request flow, and the
n8n workflow in detail. This file only covers what isn't obvious from it.

## Setup in a fresh clone

`config/config.php` is gitignored (it holds the Supabase `service_role`
secret key, and this repo is public). To run anything:

```bash
cp config/config.example.php config/config.php
# then fill in SUPABASE_URL, SUPABASE_SERVICE_KEY, N8N_WEBHOOK_URL
```

**Never commit `config/config.php`, and never put a real key, URL, or
webhook UUID into `config/config.example.php` or the README** — the
template stays placeholders-only.

## Architecture rules

- **No native Postgres driver.** `pdo_pgsql` is deliberately unused —
  shared/cPanel hosts often don't have it. All data access goes through
  `config/database.php`, a curl-based Supabase REST client. Don't
  introduce PDO, an ORM, or Composer dependencies.
- **All data access lives in `config/db_functions.php`**, one function per
  operation. Routes in `/api` include that file; they never call
  `Supabase::client()` directly.
- **The CRM is the only writer to `n8n_chat_history`.** n8n is a stateless draft generator: it receives conversation history, returns text, and touches no table.
  `api/webhook.php` forwards explicit `{ session_id, history, customer }`
  context and returns a draft for the composer. Only a successful inbound
  webhook or outbound send inserts a chat row; **Supabase is always the
  source of truth**.
- **Errors:** log the real cause with `error_log('[Supabase] ...')` and
  return a generic message to the browser via `json_error()`. Don't leak
  internals in responses.
- **Schema changes** go in `sql/schema.sql`, which must stay safe to
  re-run (`create ... if not exists`; drop/recreate functions whose return
  tables change).

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

