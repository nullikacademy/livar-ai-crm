# LiVAR Packaging CRM

Mobile-first, WhatsApp-style CRM. PHP 8.1+ backend, vanilla HTML/CSS/JS
frontend, Supabase (PostgREST) for data, 360dialog for WhatsApp, and n8n for
editable AI drafts.
**No build step, no package manager, no test suite** — edit a file and
reload the page.

Read `README.md` first: it documents the setup, the request flow, and the
n8n workflow in detail. This file only covers what isn't obvious from it.

## Setup in a fresh clone

`config/config.php` is gitignored (it holds the Supabase `service_role`
secret key, and this repo is public). To run anything:

```bash
cp config/config.example.php config/config.php
# then fill in Supabase, login, n8n, and 360dialog constants
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
- **The CRM is the only writer to `n8n_chat_history`.** n8n is a stateless
  draft generator: it receives conversation history, returns text, and
  touches no table. `api/webhook.php` sends explicit `history` + `customer`
  context and returns `{draft}`. WhatsApp inbound/outbound routes persist the
  real messages.
- **The composer is outbound.** Its contents are what the agent will send to
  the customer. Drafting fills the composer; only `api/send.php` delivers.
- **All CRM and API pages require shared-password auth** except
  `api/whatsapp_webhook.php`, which uses its unguessable URL token. Never add
  a public send or upload route.
- **Errors:** log real causes and normally return generic browser errors.
  `api/send.php` is the deliberate exception: surface 360dialog's provider
  message so an agent knows why delivery failed.
- **Schema changes** go in `sql/schema.sql`, which must stay safe to re-run.
  A changed `RETURNS TABLE` signature needs an explicit `drop function`
  immediately before recreation.
- **Private media** lives below `storage/media/`, is always server-named, and
  is only served through authenticated `api/media.php` after a `realpath()`
  containment check. Never expose a stored path to the browser.

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
node --check assets/js/app.js
php -S localhost:8000   # then open http://localhost:8000
```
