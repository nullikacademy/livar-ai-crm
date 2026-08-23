-- ============================================================================
-- LiVAR Packaging CRM — reference schema (Supabase / PostgreSQL)
-- Run in the Supabase SQL editor. Safe to re-run (IF NOT EXISTS guards).
-- ============================================================================

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

create index if not exists idx_livar_customer_session_id on public.livar_customer (session_id);
create index if not exists idx_livar_customer_created_at on public.livar_customer (created_at desc);
-- Speeds up the sidebar search (first/last name, username, phone, email).
create index if not exists idx_livar_customer_search
    on public.livar_customer using gin (
        (coalesce(first_name,'') || ' ' || coalesce(last_name,'') || ' ' ||
         coalesce(username,'')   || ' ' || coalesce(phone,'')     || ' ' ||
         coalesce(email,'')) gin_trgm_ops
    );

-- Chat history (already produced by the n8n LangChain memory node) -------
create table if not exists public.n8n_chat_history (
    id          bigint generated always as identity primary key,
    session_id  text not null,
    message     jsonb not null
);

create index if not exists idx_n8n_chat_history_session_id on public.n8n_chat_history (session_id);
create index if not exists idx_n8n_chat_history_session_id_id on public.n8n_chat_history (session_id, id);

-- Requires the pg_trgm extension for the GIN search index above.
create extension if not exists pg_trgm;

-- ============================================================================
-- RPC function used by the CRM's REST-based data layer (config/db_functions.php)
-- Combines the customer list + each one's most recent chat message in a
-- single call, since PostgREST alone can't express the LATERAL join the
-- sidebar preview needs. Exposed automatically at:
--   POST {SUPABASE_URL}/rest/v1/rpc/get_customers_with_preview
-- ============================================================================
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
    last_message       text,
    last_message_type  text,
    last_activity_id   bigint,
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
            c.email      ilike '%' || p_search || '%'
        )
    ),
    counted as (
        select count(*) as total from filtered
    )
    select
        f.id, f.created_at, f.session_id, f.first_name, f.last_name, f.username,
        f.phone, f.country, f.email, f.city, f.address, f.tax_id, f.details,
        lm.content as last_message,
        lm.type    as last_message_type,
        lm.id      as last_activity_id,
        counted.total
    from filtered f
    left join lateral (
        select h.message->>'content' as content, h.message->>'type' as type, h.id
        from public.n8n_chat_history h
        where h.session_id = f.session_id
        order by h.id desc
        limit 1
    ) lm on true
    cross join counted
    order by coalesce(lm.id, 0) desc, f.created_at desc
    limit p_limit offset p_offset;
$$;

-- service_role already bypasses grants/RLS, but grant explicitly too in
-- case you also want the anon/authenticated roles to be able to call it.
grant execute on function public.get_customers_with_preview(text, int, int)
    to anon, authenticated, service_role;
