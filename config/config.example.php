<?php
/**
 * config/config.example.php
 *
 * ============================================================
 *  TEMPLATE. Copy this to config/config.php and edit that copy:
 *
 *      cp config/config.example.php config/config.php
 *
 *  config/config.php is the ONLY file you need to edit, and it is
 *  gitignored so your secret key never reaches this public repository.
 *  This template holds placeholders only -- keep it that way.
 * ============================================================
 *
 * All application settings live here -- there is no .env, no
 * secrets.php, and no environment variables to configure anywhere else.
 *
 * This folder is blocked from web access by config/.htaccess, so these
 * values are never reachable from a browser. Keep a copy of your filled-in
 * config/config.php before overwriting the app with a newer version.
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// 1. Supabase  --  dashboard -> Project Settings -> API
// ------------------------------------------------------------------

/**
 * Your project's "Project URL", e.g. https://abcdefgh.supabase.co
 * No trailing slash, no /rest/v1 suffix.
 */
const SUPABASE_URL = 'https://your-project-ref.supabase.co';

/**
 * The "service_role" SECRET key -- NOT the public "anon" key.
 *
 * service_role is required so the CRM's backend can read and write every
 * customer's rows regardless of Row Level Security. It is only ever used
 * server-side (PHP) and is never sent to the browser.
 */
const SUPABASE_SERVICE_KEY = 'REPLACE_WITH_YOUR_SERVICE_ROLE_KEY';

// ------------------------------------------------------------------
// 2. n8n
// ------------------------------------------------------------------

/**
 * The webhook that runs the AI agent.
 *
 * n8n is a STATELESS DRAFT GENERATOR: the CRM posts the conversation
 * history to it, n8n runs the agent and returns { "draft": "..." }.
 * It must not write to n8n_chat_history -- the CRM owns that table.
 * See README section 5 for the workflow.
 */
const N8N_WEBHOOK_URL = 'https://your-n8n-host.example.com/webhook/your-webhook-uuid';

/**
 * How long to wait for n8n to generate an answer, in seconds.
 * Raise this if your AI agent is slow and requests time out.
 */
const N8N_TIMEOUT_SECONDS = 45;

// ------------------------------------------------------------------
// 3. Sign-in
// ------------------------------------------------------------------

/**
 * The one shared password for the CRM, stored as a password_hash() string
 * -- NEVER the plaintext password. Generate it on any machine with PHP:
 *
 *     php -r "echo password_hash('your-password-here', PASSWORD_DEFAULT), PHP_EOL;"
 *
 * Paste the whole "$2y$..." output below. Everyone who signs in shares
 * this one password; there is no user table.
 */
const CRM_PASSWORD_HASH = 'REPLACE_WITH_A_PASSWORD_HASH';

// ------------------------------------------------------------------
// 4. WhatsApp (360dialog Cloud API)
// ------------------------------------------------------------------

/**
 * Your 360dialog API key (Partner Hub / Client Hub -> your WABA number).
 * Sent as the D360-API-KEY header on every call.
 */
const D360_API_KEY = 'REPLACE_WITH_YOUR_360DIALOG_API_KEY';

/**
 * 360dialog's Cloud-API-compatible base URL. Only change this if
 * 360dialog tells you to.
 */
const D360_BASE_URL = 'https://waba-v2.360dialog.io';

/**
 * A long random string that authenticates the inbound webhook.
 *
 * 360dialog does not sign its webhook calls, so the URL you register with
 * them is the credential:
 *
 *     https://your-crm.example.com/api/whatsapp_webhook.php?token=<this>
 *
 * Generate one with:
 *     php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
 */
const WHATSAPP_WEBHOOK_TOKEN = 'REPLACE_WITH_A_LONG_RANDOM_TOKEN';

/**
 * Largest media file the CRM will download from, or upload to, WhatsApp.
 * 16 MB matches WhatsApp's own limit for video/audio/documents.
 */
const WHATSAPP_MAX_MEDIA_BYTES = 16 * 1024 * 1024;

/**
 * WhatsApp only allows free-form replies for 24 hours after the
 * customer's last inbound message. Outside that window a business must
 * use an approved template, which this CRM does not send -- so it blocks
 * sending instead. Leave at 24 unless Meta changes the rule.
 */
const WHATSAPP_WINDOW_HOURS = 24;

// ------------------------------------------------------------------
// 5. Interface
// ------------------------------------------------------------------

/** How many customers to load per page in the sidebar (infinite scroll). */
const CUSTOMERS_PAGE_SIZE = 30;
