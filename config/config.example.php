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
 * The webhook that runs the AI agent. This n8n workflow is responsible
 * for saving BOTH the human message and the AI reply into
 * n8n_chat_history -- the CRM only reads that table.
 */
const N8N_WEBHOOK_URL = 'https://your-n8n-host.example.com/webhook/your-webhook-uuid';

/**
 * How long to wait for n8n to generate an answer, in seconds.
 * Raise this if your AI agent is slow and requests time out.
 */
const N8N_TIMEOUT_SECONDS = 45;

// ------------------------------------------------------------------
// 3. Interface
// ------------------------------------------------------------------

/** How many customers to load per page in the sidebar (infinite scroll). */
const CUSTOMERS_PAGE_SIZE = 30;
