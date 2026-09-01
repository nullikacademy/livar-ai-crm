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
  from `api/whatsapp_webhook.php`, outbound ones from `api/send.php` —
  and, on a coexistence number, from the same webhook's
  `smb_message_echoes` branch, which mirrors messages sent from the
  WhatsApp app. The table keeps its legacy name; nothing outside this app
  writes to it.
- **A phone contact is not a customer.** `smb_app_state_sync` replays the
  business's whole address book, most of which has never messaged them.
  It lands in `livar_wa_contact`, and only becomes a name on a
  `livar_customer` when that number actually writes in
  (`getOrCreateCustomerByWaId()`). Creating a customer per contact would
  bury the real conversations in the sidebar. The sync writes in ONE bulk
  upsert because it runs before the webhook acks and the initial replay
  can be hundreds of rows. A contact deleted on the phone clears
  `wa_contact_name` and nothing else — never the customer, never history.
- **There is no way to get a customer's profile picture.** Not the Cloud
  API, not coexistence; Meta withholds it deliberately. Anything offering
  it drives an unofficial WhatsApp Web session and risks the number, so
  never wire one in. `api/avatar.php` (an agent uploading one) is the
  only source, and initials are the fallback.
- **An echo is outbound, and reads `to`, not `from`.** In
  `smb_message_echoes` the business is the sender, so `from` is our own
  number; filing on it would put every mirrored message against a
  customer record for ourselves. Echoes never call `touchLastInbound()`
  either: the 24-hour window is opened by the customer speaking, and us
  replying from a phone is not that. `wa_source` records which side wrote
  a message — `'crm'` from here, `'app'` from a phone.
- **Drafting runs in-app, not in n8n.** `api/draft.php` calls OpenAI
  directly. A draft is never persisted — it goes into the composer, and
  only becomes a row if the agent presses Send.
- **The model and system prompt are database settings, not constants.**
  They live in `livar_settings` so the settings page can change them;
  `SETTING_DEFAULTS` in `db_functions.php` holds the fallbacks. API keys
  stay in `config/config.php`, which the app must never write to. Any new
  setting needs a key in `SETTING_DEFAULTS` — `api/settings.php` refuses
  to read or write anything not declared there — and, separately, a place
  in `SETTING_AGENT_EDITABLE` if it may be written from a JSON body.
  `catalog_path` is in the first list and not the second, because it is a
  location inside `storage/`: an endpoint that took it from a request
  would be letting the browser name a file for `api/send.php` to open.
  It is written by `api/catalog.php`, which takes bytes instead.
- **Never send `max_tokens` to OpenAI, and never branch on a model name.**
  `max_tokens` is the deprecated spelling and the newer models reject it
  outright. `AI::chat()` sends `max_completion_tokens`, and when a
  provider objects it reads the parameter out of the provider's own 400
  and retries corrected (same for a refused `temperature`), remembering
  the answer for the rest of the request. A hardcoded list of model
  families would be wrong the week a new one ships, and `OPENAI_BASE_URL`
  may not even be OpenAI.
- **A path on disk never comes from the browser.** `avatar_path` and
  `catalog_path` are both written only by the endpoint that stored the
  bytes, and neither is in `CUSTOMER_PROFILE_FIELDS` or
  `SETTING_AGENT_EDITABLE`. `customerForBrowser()` swaps `avatar_path`
  for an `api/avatar.php` URL on the way out, so it never leaves the
  server at all.
- **A customer's country is derived, not stored — except once.**
  `config/countries.php` maps a dialling prefix to a country on every
  read, so old rows get a flag without a migration and fixing a number
  fixes the flag. The `country` *column* is written only on the webhook's
  first-contact insert, and is an agent's to edit after that: a number
  can be a roaming SIM, and a prefix table must not overwrite what a
  person knows.
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
  `api/media.php` and `api/avatar.php` are the only readers, both through
  `media_stream()`, and every path is resolved with `media_abs_path()`,
  which asserts it sits inside the media root. Filenames are
  server-generated hex and extensions come from a mime allowlist — never
  from anything a sender supplied. A new endpoint that serves bytes uses
  `media_stream()` rather than writing the headers again.
- **WhatsApp is not Markdown, and drafts are converted, not asked
  nicely.** Bold is one asterisk; a model's `**bold**` reaches the
  customer wearing a spare asterisk at each end.
  `WhatsApp::fromMarkdown()` rewrites emphasis, headings, bullets and
  links, and `api/draft.php` runs every draft through it. The system
  prompt says the same thing, but a prompt is a request — the conversion
  is the guarantee. Never apply it to text an agent typed: they meant it.
- **Flags are images, never emoji.** Windows ships no country-flag
  glyphs, so 🇦🇪 renders there as the letters "AE" — which is the bug
  this replaced. The artwork is vendored under `assets/flags/<cc>.svg`
  (MIT, see its LICENSE) rather than fetched from a CDN, so the CRM keeps
  its no-third-party-runtime-dependency property. `buildFlag()` in
  `app.js` validates the code before it goes into a URL and falls back to
  the emoji if a file is ever missing.
- **Sending outside the 24-hour window is templates only.**
  `api/send.php` enforces the window for every type except `template`,
  which is the one thing WhatsApp still delivers. A template row stores
  the message with its placeholders already filled in — what the customer
  received — not the raw `Hi {{1}}`.
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
- **Bump `APP_VERSION` in `config/version.php` on every change** — patch
  for a fix, minor for a feature. It is shown in the settings footer
  beside the deployed commit, which is how anyone tells whether a `git
  pull` on the server actually landed. A version that silently stops
  moving makes that footer worse than useless, because it still looks
  authoritative.
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
  `src`/`href` through a DOM property (`img.src = url`). The same goes
  for any other attribute carrying data — a `title`, for instance: that
  is why `paintAvatar()` and `paintName()` exist rather than a longer
  template literal in `buildCustomerItem()`.
- **WhatsApp emphasis is rendered, not shown raw** — `renderWhatsAppText()`
  turns `*bold*`, `_italic_`, `~strike~` and ```mono``` into elements, so
  the thread reads the way the customer's phone does. It builds nodes and
  never an HTML string. **No regex lookbehind in frontend code**: Safari
  gained it only in 16.4 and an unsupported group in a regex *literal* is
  a parse error that takes the whole file down, not just the feature.
  Requiring a non-space at both edges is what keeps `2 * 3` literal.
- **Unread is `last_read_at` versus inbound rows only.** A message we
  sent is never unread. The time is stamped server-side by
  `api/read.php`; `last_read_at` is not in `CUSTOMER_PROFILE_FIELDS`, so
  the details form cannot write it. Adding it backfills existing rows as
  read exactly once, inside a `do $$` guard — with plain
  `add column if not exists` every conversation would badge on upgrade,
  and a re-run would wipe genuine unread state.
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
