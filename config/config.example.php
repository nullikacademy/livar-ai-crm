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
 *  gitignored so your secrets never reach this public repository.
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
// 2. CRM login
// ------------------------------------------------------------------

/**
 * Bcrypt password_hash() output, never a plaintext password.
 * Generate one with:
 *   php -r "echo password_hash('choose-a-strong-password', PASSWORD_BCRYPT), PHP_EOL;"
 */
const CRM_PASSWORD_HASH = 'REPLACE_WITH_A_BCRYPT_PASSWORD_HASH';

// ------------------------------------------------------------------
// 3. n8n draft generator
// ------------------------------------------------------------------

/**
 * The webhook that runs the stateless AI drafting workflow. The CRM posts
 * explicit history and customer context; n8n returns {"draft":"..."} and
 * must not write to n8n_chat_history.
 */
const N8N_WEBHOOK_URL = 'https://your-n8n-host.example.com/webhook/your-webhook-uuid';

/**
 * How long to wait for n8n to generate an answer, in seconds.
 * Raise this if your AI agent is slow and requests time out.
 */
const N8N_TIMEOUT_SECONDS = 45;

// ------------------------------------------------------------------
// 4. WhatsApp via 360dialog
// ------------------------------------------------------------------

/** 360dialog channel API key. Sent only from server-side PHP. */
const D360_API_KEY = 'REPLACE_WITH_YOUR_360DIALOG_API_KEY';

/**
 * Long random token embedded in the registered webhook URL. 360dialog does
 * not sign callbacks, so keep this unguessable and secret.
 */
const WHATSAPP_WEBHOOK_TOKEN = 'REPLACE_WITH_A_LONG_RANDOM_WEBHOOK_TOKEN';

/** Maximum accepted inbound or outbound media payload (16 MiB by default). */
const WHATSAPP_MAX_MEDIA_BYTES = 16 * 1024 * 1024;

// ------------------------------------------------------------------
// 5. Interface
// ------------------------------------------------------------------

/** How many customers to load per page in the sidebar (infinite scroll). */
const CUSTOMERS_PAGE_SIZE = 30;
