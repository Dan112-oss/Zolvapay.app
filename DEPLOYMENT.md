# ZolvaPay — Railway Deployment Guide

You already have migrations running successfully, so the app and MySQL are
talking to each other. This covers the three things that still need doing:
confirming your services, adding a queue worker, and attaching persistent
storage for KYC documents.

## 1. Confirm what's actually provisioned

In the Railway dashboard, open your project. You should see a canvas of
service boxes. Check:

- **A MySQL box** — since migrations ran, this exists. Click it → "Variables"
  tab → confirm it exposes `MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_DATABASE`,
  `MYSQL_USER`, `MYSQL_PASSWORD` (names vary slightly by Railway's plugin
  version). You'll map these to `DB_*` in step 4.
- **A Redis box** — you likely do NOT have one, and that's fine. This
  project's queue now defaults to the `database` driver (see the
  `config/queue.php` change from this session), so Redis is optional, not
  required. Skip adding it unless you specifically want it later.
- **Your app/web service** — the one running the Laravel code itself.

If you only see one box (the web service) and no MySQL box at all, that
would contradict "migrations ran successfully" — in that case, tell me and
we'll re-check rather than assume.

## 2. Run the new migration

This session added one new migration (`create_jobs_table` — required by the
`database` queue driver, see step 3). Redeploy after pulling these changes,
or manually run:
```
php artisan migrate --force
```
against the Railway service (via Railway's shell/CLI, or it'll run
automatically if your deploy already has a migrate step in its start
command).

## 3. Add the queue worker service

Jobs (currently just `SendTransferNotification`, dispatched on every P2P
transfer) are **enqueued** by the web process but only **sent** by a worker
process actually polling the queue. Without this, transfer notification
emails silently never go out — the transfer itself still succeeds, only the
notification is missing.

1. In the Railway project canvas, click **"+ New"** → **"Empty Service"** (or
   "GitHub Repo" pointing at the same repo you already deployed).
2. Name it something like `zolvapay-worker`.
3. Under its **Settings → Deploy**, set the **Custom Start Command** to:
   ```
   php artisan queue:work database --tries=3 --sleep=3 --timeout=90
   ```
   (This matches the `worker:` line in the `Procfile` added this session —
   if your Railway setup auto-detects Procfiles, you may see this offered
   as a selectable process type instead of needing to paste it manually.)
4. Under its **Variables** tab, either copy every variable from your web
   service, or (cleaner) use Railway's **"Reference Variable"** feature to
   point this service at the same MySQL/env values so you're not maintaining
   two copies. Both services must share the same `DB_*` and `APP_KEY`.
5. Deploy it. Check its logs — you should see it sitting idle, polling,
   with no errors. Do a test transfer from the app and confirm a log line
   appears (or an email if `MAIL_MAILER` is set to something real).

**Do not skip this if you want transfer notifications to work at all.**

## 4. Persistent Volume for KYC documents

This is the one you were stuck on. `KycController::submit()` writes
uploaded documents to the `local` filesystem disk, which resolves to
`storage/app/private` inside the app. Railway's filesystem is **ephemeral**
— every redeploy wipes it — so without a Volume, every approved/pending KYC
document disappears the next time you push code or Railway restarts the
container.

Steps (dashboard, not mobile-specific — if the option is genuinely missing
on mobile, switch to a desktop browser for this one step, then continue
managing the rest from mobile):

1. Click on your **web service** (not the worker — only the process that
   actually receives KYC uploads needs this Volume).
2. Go to the **"Settings"** tab of that service.
3. Scroll to **"Volumes"** (sometimes shown as a distinct section, not
   nested under another tab — if you don't see it under Settings, check for
   a separate "Volume" icon/tab at the service level, or the "+ New" menu
   at the project canvas level, which also lets you attach a Volume to an
   existing service).
4. Click **"Add Volume"** (or **"+ New Volume"**).
5. Set the **Mount Path** to exactly:
   ```
   /app/storage/app/private
   ```
   (Railway's Nixpacks PHP builder deploys the app to `/app` by default. If
   your deploy uses a different working directory, the mount path must
   match wherever `storage_path('app/private')` resolves to at runtime —
   when in doubt, deploy once, open a Railway shell/log, and run
   `php artisan tinker --execute="echo storage_path('app/private');"` to
   confirm the exact absolute path before setting the mount.)
6. Save. Railway will redeploy the service with the Volume attached.
7. **Verify it survives a redeploy** (the actual point of doing this):
   - Submit a test KYC document through the app.
   - Trigger a redeploy (push any small commit, or use "Redeploy" in the
     dashboard).
   - Confirm the document is still retrievable via the admin KYC queue
     (`admin/kyc-queue.html` → document view) after the redeploy completes.
     If it's gone, the mount path is wrong — recheck step 5.

Volumes are single-service in Railway (they don't sync across the web and
worker services), which is fine here since only the web service handles
uploads/reads for KYC docs.

## 5. Set environment variables

Use `.env.example` (added this session) as your checklist. In each
service's **Variables** tab:

- Map Railway's MySQL-provided vars to this app's expected `DB_*` names
  (Railway doesn't auto-match these for a custom Laravel setup):
  `DB_HOST=${{MySQL.MYSQL_HOST}}`, `DB_PORT=${{MySQL.MYSQL_PORT}}`, etc. —
  Railway's variable reference syntax (`${{ServiceName.VAR}}`) lets you
  link them live instead of copy-pasting values that can drift.
- Set `APP_KEY` — generate one locally with `php artisan key:generate --show`
  and paste the result (don't leave this blank in production).
- Set `APP_URL` to your actual Railway-issued domain.
- Leave every provider var (`FX_PROVIDER`, `KYC_PROVIDER`,
  `PAYMENT_RAIL_PROVIDER`, `BILLER_PROVIDER`, `CARD_PROCESSOR`) as `mock`
  for now — that's what makes the app fully testable end-to-end before any
  real vendor integration is verified (see `SECURITY_AUDIT.md` item 5).
- `QUEUE_CONNECTION=database` — matches the worker command from step 3.

## 6. After this is all live

Run through the full flow once for real: signup → KYC submit → admin
approve (check the doc is viewable) → fund wallet → convert currency →
P2P transfer (check a log line/email from the worker) → issue a card. If
every step works against the live Railway deploy, this phase is done.
