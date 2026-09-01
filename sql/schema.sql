-- ============================================================================
-- LiVAR Packaging CRM — reference schema (Supabase / PostgreSQL)
-- Run in the Supabase SQL editor. Safe to re-run (IF NOT EXISTS guards).
-- ============================================================================

-- Needed by the trigram GIN index on livar_customer below, so it has to be
-- created before it, not after.
create extension if not exists pg_trgm;

-- Customer directory -----------------------------------------------------
create table if not exists public.livar_customer (
    id          bigint generated always as identity primary key,
    created_at  timestamptz not null default now(),
    session_id  text not null unique,
    details     text,
    first_name  text,
    last_name   text,
    username    text,
    phone       text,
    country     text,
    email       text,
    city        text,
    address     text,
    tax_id      text
);

-- WhatsApp identity. wa_id is the customer's number in international
-- format, digits only, exactly as 360dialog reports it (e.g. 34600111222).
alter table public.livar_customer
    add column if not exists wa_id           text,
    add column if not exists wa_profile_name text,
    add column if not exists last_inbound_at timestamptz,
    -- Profile photo, as a path inside storage/media. WhatsApp does not
    -- hand out a contact's own picture, so this is one an agent set;
    -- api/avatar.php is the only writer, and the only reader.
    add column if not exists avatar_path     text,
    -- 'new' | 'old' | null. Written as 'new' when the inbound webhook
    -- first meets a number, and changed by hand after that. See
    -- CUSTOMER_LABELS in config/db_functions.php.
    add column if not exists label           text,
    -- The name the BUSINESS has saved for this number in the WhatsApp
    -- Business app's address book, mirrored by the smb_app_state_sync
    -- coexistence webhook. Distinct from wa_profile_name, which is what
    -- the customer calls themselves, and from first/last_name, which an
    -- agent typed here. Never overwrites those.
    add column if not exists wa_contact_name text;

-- When an agent last opened this conversation. Anything inbound after
-- it is unread, and drives the badge in the sidebar.
--
-- Added inside a DO block rather than with `add column if not exists`
-- because of the backfill: without it every existing conversation would
-- light up unread the moment this ships, which is noise, not news. The
-- guard makes the backfill run exactly once -- on the install that
-- creates the column -- and never again on a re-run.
do $$
begin
    if not exists (
        select 1 from information_schema.columns
        where table_schema = 'public'
          and table_name   = 'livar_customer'
          and column_name  = 'last_read_at'
    ) then
        alter table public.livar_customer add column last_read_at timestamptz;
        update public.livar_customer set last_read_at = now();
    end if;
end $$;

create index if not exists idx_livar_customer_session_id on public.livar_customer (session_id);
create index if not exists idx_livar_customer_created_at on public.livar_customer (created_at desc);
-- Speeds up the sidebar search (first/last name, username, phone, email).
create index if not exists idx_livar_customer_search
    on public.livar_customer using gin (
        (coalesce(first_name,'') || ' ' || coalesce(last_name,'') || ' ' ||
         coalesce(username,'')   || ' ' || coalesce(phone,'')     || ' ' ||
         coalesce(email,'')) gin_trgm_ops
    );

-- The search index above is on a concatenated EXPRESSION, so it cannot
-- serve a `wa_id = '...'` lookup -- the webhook's hottest query. This
-- dedicated index does, and being unique it is also the race guard: two
-- webhook deliveries for a brand-new number converge on one row instead
-- of both inserting.
--
-- Deliberately NOT a partial `where wa_id is not null` index. Postgres
-- already treats NULLs as distinct in a unique index, so the predicate
-- would buy nothing but would make the index invisible to
-- `on conflict (wa_id)` inference -- which is exactly how the CRM's
-- get-or-create upsert avoids the race. The drop makes this re-runnable
-- against a database that got the partial version first.
drop index if exists public.idx_livar_customer_wa_id;
create unique index if not exists idx_livar_customer_wa_id
    on public.livar_customer (wa_id);

-- Chat history -----------------------------------------------------------
-- Originally written only by n8n's LangChain memory node; the CRM is now
-- the single writer (see CLAUDE.md). The WhatsApp columns are added to
-- THIS table rather than a sibling one so there is one ordering, one
-- preview query, and one place to read a conversation from.
create table if not exists public.n8n_chat_history (
    id          bigint generated always as identity primary key,
    session_id  text not null,
    message     jsonb not null
);

-- `message` stays the canonical LangChain { type, content } payload so
-- anything already reading it keeps working. Everything operational is a
-- real column because dedup and status lookups need indexes. All of them
-- are nullable, so a plain { session_id, message } insert still succeeds.
alter table public.n8n_chat_history
    add column if not exists created_at    timestamptz not null default now(),
    add column if not exists direction     text,          -- 'in' | 'out'
    add column if not exists wa_message_id text,
    add column if not exists wa_status     text,          -- sent|delivered|read|failed
    add column if not exists wa_error      text,
    -- text|image|video|audio|document|location|sticker|template|
    -- buttons|reply|unsupported. 'buttons' is a question the CRM asked
    -- with tappable options; 'reply' is the customer tapping one.
    add column if not exists msg_type      text,
    -- The option labels of a 'buttons' question, as a JSON array. One
    -- column rather than a side table: three short strings that are only
    -- ever read with their own row do not earn a join.
    add column if not exists wa_buttons    text,
    -- Which approved template a 'template' row was sent from. The row's
    -- content is the template already filled in, which is what the
    -- customer saw; this records what it was built from.
    add column if not exists wa_template   text,
    -- Where an outbound message was written: 'crm' from this app, 'app'
    -- from the WhatsApp Business app on somebody's phone, mirrored back
    -- by the smb_message_echoes coexistence webhook. Null on inbound
    -- rows and on anything written before this column existed.
    add column if not exists wa_source     text,
    -- The provider's media id, kept so api/media.php can re-download a
    -- file the webhook never managed to fetch. Meta expires media after
    -- roughly 30 days, after which this is only a record of what was.
    add column if not exists wa_media_id   text,
    -- One-line description of a photo, written by the vision model when
    -- the file lands. Used for the sidebar preview and for older photos
    -- that fall outside the window where the real image is attached to a
    -- draft. Null means "not captioned" -- the AI may be unconfigured.
    add column if not exists ai_caption    text,
    add column if not exists media_path    text,
    add column if not exists media_mime    text,
    add column if not exists media_size    bigint,
    add column if not exists media_name    text,
    add column if not exists latitude      double precision,
    add column if not exists longitude     double precision,
    add column if not exists place_name    text,
    add column if not exists place_address text;

create index if not exists idx_n8n_chat_history_session_id on public.n8n_chat_history (session_id);
create index if not exists idx_n8n_chat_history_session_id_id on public.n8n_chat_history (session_id, id);

-- Dedup guard for 360dialog webhook retries: the same wa_message_id can
-- only ever land once. Legacy rows and drafts have none, and unlimited
-- NULLs are fine here -- Postgres treats them as distinct. As with
-- idx_livar_customer_wa_id this must NOT be partial, or
-- `on conflict (wa_message_id)` cannot infer it and the webhook's
-- insert-or-ignore turns back into a 409.
drop index if exists public.idx_chat_wa_message_id;
create unique index if not exists idx_chat_wa_message_id
    on public.n8n_chat_history (wa_message_id);
create index if not exists idx_chat_created_at
    on public.n8n_chat_history (session_id, created_at desc);

-- Phone address book -----------------------------------------------------
-- What the smb_app_state_sync coexistence webhook mirrors: the contacts
-- saved in the WhatsApp Business app. Its own table rather than rows in
-- livar_customer, because onboarding replays the WHOLE address book --
-- hundreds of numbers, most of which have never messaged the business.
-- Turning each into a customer would bury the real conversations in the
-- sidebar. A contact in a phone is not a conversation; it is a name
-- waiting for one, which getOrCreateCustomerByWaId() collects when that
-- number first writes in.
create table if not exists public.livar_wa_contact (
    wa_id      text primary key,
    full_name  text,
    first_name text,
    updated_at timestamptz not null default now()
);

-- Editable application settings ------------------------------------------
-- The AI system prompt and model live here rather than in
-- config/config.php, because the settings page has to be able to change
-- them and config.php is a secrets file the app must never write to.
-- Everything here is non-secret by design: API keys stay in config.php.
create table if not exists public.livar_settings (
    key        text primary key,
    value      text,
    updated_at timestamptz not null default now()
);

-- Lock the tables to the service_role -------------------------------------
-- Everything above is reachable through PostgREST, which means anyone
-- holding the project's ANON key could otherwise read and write it --
-- and between them these tables hold every customer's name, number and
-- entire conversation.
--
-- No policies are created, deliberately. The CRM authenticates with the
-- service_role key, which bypasses RLS completely, so this closes the
-- tables to the outside world without restricting the app at all. If you
-- later want a Supabase client library to read these with the anon key,
-- that is the point at which to write a policy.
--
-- Safe to re-run: enabling RLS on a table that already has it is a no-op.
alter table public.livar_customer   enable row level security;
alter table public.n8n_chat_history enable row level security;
alter table public.livar_wa_contact enable row level security;
alter table public.livar_settings   enable row level security;

-- ============================================================================
-- RPC function used by the CRM's REST-based data layer (config/db_functions.php)
-- Combines the customer list + each one's most recent chat message in a
-- single call, since PostgREST alone can't express the LATERAL join the
-- sidebar preview needs. Exposed automatically at:
--   POST {SUPABASE_URL}/rest/v1/rpc/get_customers_with_preview
-- ============================================================================

-- The returned column list changed (wa_id, last_inbound_at), and Postgres
-- refuses to `create or replace` a function whose `returns table` differs.
-- Dropping first is what keeps this file re-runnable against a database
-- that already has the older version.
drop function if exists public.get_customers_with_preview(text, int, int);

create or replace function public.get_customers_with_preview(
    p_search text default '',
    p_limit  int  default 30,
    p_offset int  default 0
)
returns table (
    id                 bigint,
    created_at         timestamptz,
    session_id         text,
    first_name         text,
    last_name          text,
    username           text,
    phone              text,
    country            text,
    email              text,
    city               text,
    address            text,
    tax_id             text,
    details            text,
    wa_id              text,
    -- The name the customer uses on WhatsApp. Without this the sidebar
    -- falls back to the bare phone number for anyone an agent has not
    -- typed a name for, which is most of a real inbox.
    wa_profile_name    text,
    last_inbound_at    timestamptz,
    -- Needed by the sidebar, which shows the photo and the label on
    -- every row. Fetching them per customer afterwards would undo the
    -- whole point of this function.
    avatar_path        text,
    label              text,
    wa_contact_name    text,
    -- Inbound messages the agent has not seen. Counted here rather than
    -- fetched per row afterwards, for the same reason the preview is.
    unread_count       bigint,
    last_message       text,
    last_message_type  text,
    last_activity_id   bigint,
    last_activity_at   timestamptz,
    total_count        bigint
)
language sql
stable
as $$
    with filtered as (
        select c.*
        from public.livar_customer c
        where p_search = '' or (
            c.first_name ilike '%' || p_search || '%' or
            c.last_name  ilike '%' || p_search || '%' or
            c.username   ilike '%' || p_search || '%' or
            c.phone      ilike '%' || p_search || '%' or
            c.email      ilike '%' || p_search || '%' or
            c.wa_id      ilike '%' || p_search || '%'
        )
    ),
    counted as (
        select count(*) as total from filtered
    )
    select
        f.id, f.created_at, f.session_id, f.first_name, f.last_name, f.username,
        f.phone, f.country, f.email, f.city, f.address, f.tax_id, f.details,
        f.wa_id, f.wa_profile_name, f.last_inbound_at, f.avatar_path, f.label, f.wa_contact_name,
        -- Only INBOUND rows count: a reply we sent is not something to
        -- catch up on. A conversation never opened has last_read_at null,
        -- which the schema backfills on install so only genuinely new
        -- traffic badges.
        (select count(*)
           from public.n8n_chat_history u
          where u.session_id = f.session_id
            and u.direction  = 'in'
            and (f.last_read_at is null or u.created_at > f.last_read_at)
        )                                          as unread_count,
        -- A photo must not read as "No messages yet" in the sidebar, so
        -- media rows get a short label instead of their (empty) content.
        case lm.msg_type
            -- Once the vision model has labelled a photo, the label is a
            -- far better preview than the word "Photo".
            when 'image'    then '📷 ' || coalesce(nullif(lm.ai_caption, ''), 'Photo')
            when 'video'    then '🎥 Video'
            when 'audio'    then '🎤 Voice message'
            when 'document' then '📄 ' || coalesce(lm.media_name, 'Document')
            when 'location' then '📍 Location'
            when 'sticker'  then '🙂 Sticker'
            -- A question we asked, and the answer that came back. Both
            -- read better with their own marker than as bare text.
            when 'buttons'  then '❓ ' || coalesce(nullif(lm.content, ''), 'Question')
            when 'reply'    then '↩ '  || coalesce(nullif(lm.content, ''), 'Answered')
            -- Something arrived that the CRM cannot render. Still needs a
            -- preview, or the sidebar claims there are no messages at all.
            when 'unsupported' then '📎 Attachment'
            else coalesce(nullif(lm.content, ''), '')
        end                                        as last_message,
        lm.type                                    as last_message_type,
        lm.id                                      as last_activity_id,
        coalesce(lm.created_at, f.created_at)      as last_activity_at,
        counted.total
    from filtered f
    left join lateral (
        select
            h.message->>'content' as content,
            h.message->>'type'    as type,
            h.id,
            h.created_at,
            h.msg_type,
            h.media_name,
            h.ai_caption
        from public.n8n_chat_history h
        where h.session_id = f.session_id
        order by h.id desc
        limit 1
    ) lm on true
    cross join counted
    -- Now that chat rows carry a real timestamp, order by it rather than
    -- by the message id, and fall back to the customer's own created_at
    -- for conversations that have no messages yet.
    order by coalesce(lm.created_at, f.created_at) desc, f.id desc
    limit p_limit offset p_offset;
$$;

-- service_role already bypasses grants/RLS, but grant explicitly too in
-- case you also want the anon/authenticated roles to be able to call it.
grant execute on function public.get_customers_with_preview(text, int, int)
    to anon, authenticated, service_role;
