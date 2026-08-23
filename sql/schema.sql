-- ============================================================================
-- LiVAR Packaging CRM — reference schema (Supabase / PostgreSQL)
-- Run in the Supabase SQL editor. Safe to re-run.
-- ============================================================================

-- The customer search index below depends on pg_trgm, so the extension must
-- exist before any index that uses gin_trgm_ops is created.
create extension if not exists pg_trgm;

-- Customer directory ---------------------------------------------------------
create table if not exists public.livar_customer (
    id               bigint generated always as identity primary key,
    created_at       timestamptz not null default now(),
    session_id       text not null unique,
    details          text,
    first_name       text,
    last_name        text,
    username         text,
    phone            text,
    country          text,
    email            text,
    city             text,
    address          text,
    tax_id           text,
    wa_id            text,
    wa_profile_name  text,
    last_inbound_at  timestamptz
);

alter table public.livar_customer
    add column if not exists wa_id            text,
    add column if not exists wa_profile_name  text,
    add column if not exists last_inbound_at  timestamptz;

create index if not exists idx_livar_customer_session_id
    on public.livar_customer (session_id);
create index if not exists idx_livar_customer_created_at
    on public.livar_customer (created_at desc);
create unique index if not exists idx_livar_customer_wa_id
    on public.livar_customer (wa_id) where wa_id is not null;

-- PostgREST emits ON CONFLICT (wa_id) for ?on_conflict=wa_id, but cannot
-- include the predicate needed to infer the partial index above. A regular
-- unique index is therefore the upsert arbiter. PostgreSQL unique indexes
-- allow multiple NULL values, so customers without a WhatsApp ID still work.
create unique index if not exists idx_livar_customer_wa_id_upsert
    on public.livar_customer (wa_id);

-- Speeds up name/username/phone/email sidebar searches. WhatsApp IDs have a
-- dedicated btree index above because equality cannot use this expression GIN.
create index if not exists idx_livar_customer_search
    on public.livar_customer using gin (
        (coalesce(first_name,'') || ' ' || coalesce(last_name,'') || ' ' ||
         coalesce(username,'')   || ' ' || coalesce(phone,'')     || ' ' ||
         coalesce(email,'')) gin_trgm_ops
    );

-- Canonical conversation history --------------------------------------------
create table if not exists public.n8n_chat_history (
    id             bigint generated always as identity primary key,
    session_id     text not null,
    message        jsonb not null,
    created_at     timestamptz not null default now(),
    direction      text,
    wa_message_id  text,
    wa_status      text,
    wa_error       text,
    msg_type       text,
    wa_media_id    text,
    media_path     text,
    media_mime     text,
    media_size     bigint,
    media_name     text,
    latitude       double precision,
    longitude      double precision,
    place_name     text,
    place_address  text
);

-- ALTER guards upgrade databases that already have the original two-column
-- n8n memory table. Every operational column remains nullable so old n8n
-- inserts containing only session_id + message continue to succeed.
alter table public.n8n_chat_history
    add column if not exists created_at     timestamptz not null default now(),
    add column if not exists direction      text,
    add column if not exists wa_message_id  text,
    add column if not exists wa_status      text,
    add column if not exists wa_error       text,
    add column if not exists msg_type       text,
    add column if not exists wa_media_id    text,
    add column if not exists media_path     text,
    add column if not exists media_mime     text,
    add column if not exists media_size     bigint,
    add column if not exists media_name     text,
    add column if not exists latitude       double precision,
    add column if not exists longitude      double precision,
    add column if not exists place_name     text,
    add column if not exists place_address  text;

create index if not exists idx_n8n_chat_history_session_id
    on public.n8n_chat_history (session_id);
create index if not exists idx_n8n_chat_history_session_id_id
    on public.n8n_chat_history (session_id, id);
create unique index if not exists idx_chat_wa_message_id
    on public.n8n_chat_history (wa_message_id)
    where wa_message_id is not null;
create index if not exists idx_chat_created_at
    on public.n8n_chat_history (session_id, created_at desc);

-- RPC used by the customer sidebar ------------------------------------------
-- PostgreSQL cannot CREATE OR REPLACE a function when RETURNS TABLE changes.
drop function if exists public.get_customers_with_preview(text, int, int);

create function public.get_customers_with_preview(
    p_search text default '',
    p_limit  int  default 30,
    p_offset int  default 0
)
returns table (
    id                       bigint,
    created_at               timestamptz,
    session_id               text,
    first_name               text,
    last_name                text,
    username                 text,
    phone                    text,
    country                  text,
    email                    text,
    city                     text,
    address                  text,
    tax_id                   text,
    details                  text,
    wa_id                    text,
    wa_profile_name          text,
    last_inbound_at          timestamptz,
    last_message             text,
    last_message_type        text,
    last_message_created_at  timestamptz,
    last_activity_id         bigint,
    total_count              bigint
)
language sql
stable
as $$
    with filtered as (
        select c.*
        from public.livar_customer c
        where p_search = '' or (
            c.first_name       ilike '%' || p_search || '%' or
            c.last_name        ilike '%' || p_search || '%' or
            c.username         ilike '%' || p_search || '%' or
            c.phone            ilike '%' || p_search || '%' or
            c.email            ilike '%' || p_search || '%' or
            c.wa_id            ilike '%' || p_search || '%' or
            c.wa_profile_name  ilike '%' || p_search || '%'
        )
    ),
    counted as (
        select count(*) as total from filtered
    )
    select
        f.id,
        f.created_at,
        f.session_id,
        f.first_name,
        f.last_name,
        f.username,
        f.phone,
        f.country,
        f.email,
        f.city,
        f.address,
        f.tax_id,
        f.details,
        f.wa_id,
        f.wa_profile_name,
        f.last_inbound_at,
        case lm.msg_type
            when 'image'    then '📷 Photo'
            when 'video'    then '🎥 Video'
            when 'audio'    then '🎤 Voice message'
            when 'document' then '📄 ' || coalesce(lm.media_name, 'Document')
            when 'location' then '📍 Location'
            when 'sticker'  then '🙂 Sticker'
            else coalesce(nullif(lm.content, ''), '')
        end as last_message,
        lm.type as last_message_type,
        lm.created_at as last_message_created_at,
        lm.id as last_activity_id,
        counted.total
    from filtered f
    left join lateral (
        select
            h.message->>'content' as content,
            h.message->>'type' as type,
            h.id,
            h.created_at,
            h.msg_type,
            h.media_name
        from public.n8n_chat_history h
        where h.session_id = f.session_id
        order by h.created_at desc, h.id desc
        limit 1
    ) lm on true
    cross join counted
    order by coalesce(lm.created_at, f.created_at) desc, coalesce(lm.id, 0) desc
    limit p_limit offset p_offset;
$$;

grant execute on function public.get_customers_with_preview(text, int, int)
    to anon, authenticated, service_role;
