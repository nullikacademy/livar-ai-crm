# LiVAR Packaging — CRM

A mobile-first, WhatsApp-style CRM for customer support and sales. PHP + PostgreSQL
(Supabase) on the backend, vanilla HTML/CSS/JS on the front — no build step.

## 1. Requirements

- PHP 8.1+ with the `curl` extension enabled (on by default almost everywhere,
  including shared cPanel hosting)
- A Supabase PostgreSQL database with the two tables + one SQL function
  described below
- Apache or Nginx (an `.htaccess` is included for Apache)

**No `pdo_pgsql` needed.** Many budget/shared hosts (cPanel included) don't
compile that extension in and won't let you enable it. This CRM talks to
Supabase over its REST API (PostgREST) instead of a native Postgres
connection, using only `curl` — which is virtually always available.

## 2. Setup

Three steps. All configuration lives in **one file**: `config/config.php`.

### Step 1 — Get the files onto the server

Clone the repo, or on cPanel: **File Manager** → upload a zip of it into
`public_html` or a subdomain folder → **Extract**, so that `index.php` sits
directly in the web root.

Make sure you're on **PHP 8.1+**. On cPanel that's under **Select PHP
Version**. No extension checkboxes are needed — `curl` is enabled by default
virtually everywhere.

### Step 2 — Run the database schema

In the **Supabase SQL editor**, run the contents of `sql/schema.sql`. It's
safe to re-run. This creates:
- `livar_customer` and `n8n_chat_history` (if they don't already exist)
- the indexes the sidebar search relies on
- the `get_customers_with_preview()` function, which the app calls to fetch
  each customer along with their latest message in a single request

### Step 3 — Create and fill in `config/config.php`

`config/config.php` is **not in the repository** — it holds the Supabase
`service_role` secret key, and this repo is public. It is listed in
`.gitignore` so it can never be committed by accident. Create it from the
template that *is* tracked:

```bash
cp config/config.example.php config/config.php
```

Then open `config/config.php` and set the two Supabase values plus your n8n
webhook URL. Everything else already has a working default.

```php
const SUPABASE_URL         = 'https://your-project-ref.supabase.co';
const SUPABASE_SERVICE_KEY = 'your-service-role-secret-key';
```

Find both in the Supabase dashboard under **Project Settings → API**:
- **Project URL** → `SUPABASE_URL`
- **service_role** secret key → `SUPABASE_SERVICE_KEY`

Use the **service_role** key, not the public `anon` key — service_role is
what lets the CRM's backend read and write every customer's rows regardless
of Row Level Security. It's only ever used server-side in PHP and is never
sent to the browser.

`config/config.php` also holds the n8n webhook URL (`N8N_WEBHOOK_URL` —
see section 5), its timeout, and the sidebar page size.

That's the whole configuration. There is no `.env` file, no `secrets.php`,
and no environment variables or `SetEnv` directives to set — which is
exactly why this works the same on cPanel as anywhere else. The `config/`
folder is blocked from web access by `config/.htaccess`, so these values
are never reachable from a browser.

> **Upgrading later:** keep a copy of your `config/config.php` before
> overwriting the app with a newer version, then put it back.

### If something doesn't work

The app logs the real underlying error and shows only a generic message in
the browser. Check your PHP error log — on cPanel that's **Metrics → Errors**
or `public_html/error_log` — for lines starting with `[Supabase]`. Common
ones:

| Log line | Cause | Fix |
|---|---|---|
| `config/config.php is missing` | Never copied from the template | `cp config/config.example.php config/config.php`, then Step 3 |
| `config/config.php has not been filled in yet` | Placeholder key still in place | Step 3 above |
| `HTTP 401` / `Invalid API key` | Wrong key, or the `anon` key instead of `service_role` | Recopy the service_role key |
| `HTTP 404` + `Could not find the function` | Schema not run, or run before this function existed | Re-run `sql/schema.sql` (Step 2) |
| `HTTP 404` + `relation ... does not exist` | Tables missing | Re-run `sql/schema.sql` (Step 2) |
| `Could not resolve host` | Typo in `SUPABASE_URL` | Recopy the Project URL |

Note: cPanel's built-in **PostgreSQL Databases** feature manages a *local*
Postgres instance on the server. It is not used here — Supabase is external
and reached over HTTPS — so you can ignore it entirely.

## 3. Project structure

```
/config
    config.example.php  template — copy to config.php (tracked in git)
    config.php          ← THE ONLY FILE YOU EDIT (all settings; gitignored)
    load_config.php     loads config.php, with a clear error if it's missing
    database.php      Supabase REST client (curl-based)
    app.php            small request/response helpers
    db_functions.php   all data access lives here, one function per operation
/api
    customers.php     GET (list/search/one), POST (create), PUT (update)
    messages.php       GET (chat history) — read-only, n8n does the writing
    webhook.php         proxies the customer message to n8n
/assets
    css/style.css      mobile-first styles
    js/app.js           all frontend logic
/components
    sidebar.php, chat.php, customer_form.php
index.php
sql/schema.sql
```

## 4. How the pieces fit together

- **Sidebar** (`components/sidebar.php` + `app.js`) loads customers in pages
  of 30 via `GET /api/customers.php`, with a `LEFT JOIN LATERAL` in
  `getCustomers()` that pulls each customer's most recent chat message for
  the preview line. Search re-queries with `ILIKE` across name/username/phone/email.
- **Chat** loads `n8n_chat_history` rows for the selected `session_id`,
  parses the `message` JSONB column, and renders a bubble per row —
  white/left for `"type": "human"`, orange/right for `"type": "ai"`.
  Unrecognized rows (no `type`) are skipped instead of breaking the thread.
- **Generate Answer** shows the typed/pasted text as a local "pending"
  bubble (never written to Supabase), then:
  1. `POST /api/webhook.php` — forwards `{ session_id, message }` to n8n and
     waits for it to finish (up to 45s). n8n saves *both* the human message
     and the AI reply to `n8n_chat_history` itself.
  2. Re-fetches `/api/messages.php` and re-renders, replacing the pending
     bubble with the real persisted rows, so **Supabase is always the
     source of truth** — the CRM never trusts n8n's response body for the
     actual reply text, only for an optional "is it back yet" signal.
  If the webhook call fails, the pending bubble is removed and the text is
  put back in the composer for retry — nothing was ever persisted either way.
- **New Chat** creates a blank `livar_customer` row (`POST /api/customers.php`
  with no body), generates a `session_id` server-side, opens that
  conversation immediately, and pops the details panel open for editing.
- **Customer details** panel is a slide-over form; `PUT /api/customers.php`
  updates only the fields submitted.

## 5. n8n workflow — step by step

This is the workflow the **Generate Answer** button calls. Build it as a
separate n8n workflow behind the webhook URL configured in `N8N_WEBHOOK_URL`.

### Step 1 — Webhook node
- Node: **Webhook**
- HTTP Method: `POST`
- Path: the UUID from your URL — the same one you put in `N8N_WEBHOOK_URL`
- Respond: **Using "Respond to Webhook" node** (not immediately) — this lets
  you wait for the AI Agent step to finish before answering the CRM.
- Expected body:
  ```json
  { "session_id": "sess_xxx", "message": "customer's message text" }
  ```

### Step 2 — Validate / parse the body
- Node: **Set** (or **Code**) node named "Parse Input"
- Pull `session_id` and `message` out of `{{$json.body}}` into top-level
  fields so later nodes can reference them simply as `{{$json.session_id}}`
  and `{{$json.message}}`.
- Add an **IF** node right after: if either field is empty, branch to a
  small error path that goes straight to "Respond to Webhook" with a 400
  status and `{ "success": false, "error": "session_id and message are required" }`.
  This keeps a bad request from ever reaching the AI Agent.

### Step 3 — Insert the human message

The CRM does **not** write to `n8n_chat_history` itself — n8n is the single
writer, Supabase is purely a read source for the frontend. So this step is
required, not optional. Add a **Supabase** node here:
- Operation: **Insert**
- Table: `n8n_chat_history`
- Columns:
  - `session_id`: `{{$json.session_id}}`
  - `message`: `{{ JSON.stringify({ type: "human", content: $json.message }) }}`

### Step 4 — AI Agent
- Node: **AI Agent** (LangChain node), or **Basic LLM Chain** if you don't
  need tool use.
- Chat Model: connect your model of choice (e.g. Anthropic, OpenAI).
- Memory: **Postgres Chat Memory** node, pointed at the same
  `n8n_chat_history` table, keyed on `{{$json.session_id}}` — this is what
  gives the agent the full conversation context without you having to
  assemble it manually.
- System Prompt: LiVAR Packaging Solutions' support/sales persona (tone,
  product knowledge, escalation rules, etc.).
- User Message: `{{$json.message}}`

### Step 5 — Insert the AI reply into `n8n_chat_history`
- Node: **Supabase** (Insert) or a raw **Postgres** node
- Table: `n8n_chat_history`
- Columns:
  - `session_id`: `{{$('Parse Input').item.json.session_id}}`
  - `message`: `{{ JSON.stringify({ type: "ai", content: $json.output }) }}`
    (use whichever field name the AI Agent node outputs — commonly `output`
    or `text`; check the node's output panel).

If you're using the **Postgres Chat Memory** node from Step 4, it may
already auto-save both turns to `n8n_chat_history` for you — check its
output before adding a duplicate insert here.

### Step 6 — Respond to Webhook
- Node: **Respond to Webhook**
- Response Code: `200`
- Response Body (JSON):
  ```json
  { "success": true, "reply": "{{$json.message}}" }
  ```
  The CRM treats this `reply` field as a best-effort preview only — it
  always re-reads `n8n_chat_history` from Supabase afterward, so this step
  can't get the CRM's UI "out of sync" even if the field name differs.

### Step 7 — Error handling
- Set **On Error** = "Continue Using Error Output" on the AI Agent and both
  Supabase nodes, and route each error output to a small "Respond with
  error" branch:
  - Node: **Respond to Webhook**
  - Response Code: `500`
  - Body: `{ "success": false, "error": "AI generation failed. Please try again." }`
- Add a **Workflow Timeout** (Settings → Timeout Workflow) of ~40s so a
  hung model call can't leave the CRM's `fetch()` waiting past its own
  45s timeout without an answer.
- Optional but recommended: an **Error Trigger** workflow that posts
  failures to a Slack/email channel so support ops notice silently
  failing conversations.

### Resulting flow

```
Webhook (POST) 
   -> Parse Input (Set/Code) 
   -> IF valid? --no--> Respond to Webhook (400)
        |yes
   -> Insert human message into n8n_chat_history
   -> AI Agent (+ Postgres Chat Memory on session_id)
   -> Insert AI message into n8n_chat_history
   -> Respond to Webhook (200, { success: true, reply })

Any node error -> Respond to Webhook (500, { success: false, error })
```

With this in place, the CRM's **Generate Answer** button will: show the
typed text as a local (not-yet-saved) bubble, call the webhook, wait for
n8n to save the human turn, run the agent, and save the AI reply, then
re-read `n8n_chat_history` from Supabase and highlight the new bubble —
the CRM itself never writes to the chat history table.
