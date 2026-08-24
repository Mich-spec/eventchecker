# Event QR Check-In

A PHP + Supabase QR code check-in system for an event. Scans an existing
QR code (e.g. `EVENT-2026-0003`), checks whether it's a valid code and
whether it's already been used, and marks it used on first valid scan.

## Important things to know before you deploy

- **Vercel doesn't officially support PHP.** This project uses
  [`vercel-community/php`](https://github.com/vercel-community/php), a
  well-maintained third-party runtime, configured in `vercel.json`. It
  works well for a project this size, but it's not an official Vercel
  runtime the way Node.js/Python/Go are — if Vercel ever changes how
  community runtimes work, this could need an update.
- **Supabase has no official PHP SDK.** That's fine here — Supabase is
  Postgres behind a REST API (PostgREST), and this project talks to it
  directly over HTTP with cURL (see `api/lib/supabase.php`). No SDK
  needed.
- **The `service_role` key is powerful** — it bypasses Row Level
  Security entirely. It's only ever read server-side inside
  `api/*.php` via environment variables. It is never sent to the
  browser. Do not put it in `index.html` or any client-side code.

## How the check-in logic works

1. The camera (or manual input) reads a code, e.g. `EVENT-2026-0003`.
2. The browser POSTs it to `/api/scan.php`.
3. The server tries to **atomically** flip that code from `used = false`
   to `used = true` in a single database update, filtered on
   `used = eq.false`.
   - If that update affects a row → the code existed and was unused →
     **success**, it's now marked used.
   - If it affects zero rows → either the code doesn't exist, or it was
     already used. A follow-up lookup tells those two cases apart:
     - Code not found at all → **"This QR code does not exist in the
       system."**
     - Code found but already used → **"This access card has already
       been used."**

Doing the check-and-update as one atomic request (rather than "check if
used, then update") avoids a race condition where two scanners hitting
the same code at nearly the same instant could both pass a separate
"is it used?" check before either one writes back.

## 1. Set up Supabase

1. Create a project at [supabase.com](https://supabase.com).
2. Open **SQL Editor** and run the contents of `supabase/schema.sql`.
   This creates an `access_codes` table with columns:
   `id, code, used, used_at, event_name, created_at`, and inserts 3
   sample codes.
3. Load your real event codes into the table — either edit the `insert`
   statement, or use the **Table Editor**'s CSV import for a big batch
   (just needs a `code` column; `used` defaults to `false`).
4. Go to **Project Settings > API** and copy:
   - **Project URL** → this is `SUPABASE_URL`
   - **service_role** secret key → this is `SUPABASE_SERVICE_ROLE_KEY`
     (not the `anon` key — that one respects RLS and won't be able to
     write)

## 2. Deploy to Vercel

1. Push this project to a GitHub repo.
2. In Vercel: **Add New > Project**, import the repo.
3. Before (or right after) the first deploy, go to **Project Settings
   > Environment Variables** and add:
   - `SUPABASE_URL`
   - `SUPABASE_SERVICE_ROLE_KEY`

   Apply both to Production, Preview, and Development.
4. Deploy. Vercel will read `vercel.json`, see `api/*.php`, and build
   those files with the `vercel-php` runtime automatically.
5. Once deployed, visit `https://your-project.vercel.app/api/health.php`
   to confirm the environment variables are set and Supabase is
   reachable before testing the scanner itself.

## 3. Use it

Open `https://your-project.vercel.app/` on a phone or tablet:

- Grant camera access when prompted — it scans automatically and shows
  a green **"Access granted"**, amber **"already used"**, or red
  **"does not exist"** result.
- If the camera doesn't work (permissions, no camera, HTTP instead of
  HTTPS, etc.), there's a manual text field underneath to type the code
  in directly — it hits the same `/api/scan.php` endpoint.

Note: camera access in the browser requires HTTPS (Vercel deployments
are HTTPS by default, so this isn't an issue in production — only
matters if you're testing over plain HTTP locally).

## Testing / resetting codes

To reset a code back to unused while testing, run in the Supabase SQL
editor:

```sql
update access_codes set used = false, used_at = null where code = 'EVENT-2026-0003';
```

## Project structure

```
vercel.json              # Configures the PHP runtime for api/*.php
index.html                # Scanner UI (camera + manual entry)
api/
  scan.php                 # POST endpoint: validates + checks in a code
  health.php                # GET endpoint: verifies env vars/connectivity
  lib/
    supabase.php             # cURL helpers for Supabase's REST API
supabase/
  schema.sql                # Table definition + sample seed data
.env.example               # Documents the two required env vars
```

## Local development

```bash
npm i -g vercel
vercel login
vercel link
vercel env pull .env.local
vercel dev
```

`vercel dev` runs the PHP runtime locally too, so `/api/scan.php` and
`/api/health.php` behave the same as in production.
