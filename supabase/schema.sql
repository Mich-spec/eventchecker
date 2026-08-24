-- Run this in the Supabase SQL editor (Project > SQL Editor > New query).

create table if not exists access_codes (
  id bigint generated always as identity primary key,
  code text not null unique,
  used boolean not null default false,
  used_at timestamptz,
  event_name text,
  created_at timestamptz not null default now()
);

-- Speeds up the exact-match lookups the scanner does on every scan.
create index if not exists access_codes_code_idx on access_codes (code);

-- Keep Row Level Security ON. The PHP backend authenticates with the
-- service_role key, which bypasses RLS by design, so no policies are
-- needed here as long as the anon/public key is never used to touch this
-- table directly from a browser.
alter table access_codes enable row level security;

-- Example seed data - replace with your real event codes, or bulk-insert
-- via the Table Editor / CSV import instead.
insert into access_codes (code, event_name) values
  ('EVENT-2026-0001', 'Sample Event'),
  ('EVENT-2026-0002', 'Sample Event'),
  ('EVENT-2026-0003', 'Sample Event')
on conflict (code) do nothing;

-- Handy query to reset a code back to "unused" while testing:
-- update access_codes set used = false, used_at = null where code = 'EVENT-2026-0003';
