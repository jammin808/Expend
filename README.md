# Expense Ledger

A private, single-user bookkeeping app for a UK sole trader: import bank and card statements, decide
what each transaction is (with you always having the final say), link costs to jobs, log mileage, and
get the figures for any tax year's Self Assessment return.

Built with Laravel 12 and React (Inertia), for Hostinger shared hosting. The full design and roadmap is
in [`docs/PLAN.md`](docs/PLAN.md).

## What works in this version

- **Catch-up mode for any tax year** (`Tax years › 2024-25`): a month-by-month coverage grid, the biggest
  merchants first ("decide all 12 from Adobe in one go"), pre-filing checks, and the figures for your
  return box by box, with a tax and National Insurance estimate and a bill preview including payments on
  account. Each year uses its own rules (rates, Class 2, cash basis default).
- **Inbox**: one item at a time with the best suggestion, that day's calendar entries and jobs around the
  date. Keyboard (`?` shows shortcuts) and swipe on a phone.
- **Full manual control**: review every transaction with filters, bulk-edit, split one payment across
  jobs or business/personal, and override any decision. Rules and matching only suggest; anything you set
  is never overwritten by automation. Every change is in a tamper-evident history.
- **Imports**: Starling, Amex and Capital One CSVs recognised automatically; any other bank by mapping its
  columns once. Re-importing or overlapping statements never duplicates. Running balances are checked.
  Card-bill payments are paired as transfers so nothing is counted twice; HMRC payments are suggested as
  tax payments, never expenses. A statement whose running balance doesn't add up is **held for review**: its
  rows are kept but count nowhere (no Inbox, totals or tax) until you accept it with a reason or discard it.
  Undoing an import never deletes anything: its rows and your decisions are kept, marked reversed, and the
  import can be restored (card-bill transfers are paired again). Invoice lines use exact decimal arithmetic (no floating point), and every history
  entry is chained through a locked head so simultaneous writes can't fork it and a removed entry is caught.
- **Jobs** with income, costs, mileage and profit; purchases such as equipment need no job.
- **Mileage** at each year's HMRC rate (45p a mile before 6 April 2026, 55p from 2026-27, 25p after
  10,000 miles), counted as a travel expense for the job.
- **Other currencies**: cash or receipts in USD, EUR and others convert using the document's sterling
  figure if it has one, otherwise HMRC's monthly exchange rate for that date (or your own rate).
- **Documents** (POs, job invites, production packs, call sheets, bookings, receipts) by upload, email
  forwarding or a private Telegram bot. Claude reads them into a **job sheet**: call and load-in times, venue,
  travel, accommodation and who booked it, contacts, PO number, rates and invoicing details. Every detail
  keeps the exact words it came from and is checked against the document's text; anything that can't be
  checked is marked for you.
- **Invoices from POs**: PO lines prefill a draft; the total is checked against the PO; issuing gives the next
  number and a PDF, after which it can't be edited (void and re-issue instead). Incoming payments of the
  invoice amount are suggested against it.
- **Tax help everywhere**: a "?" next to every payment (Inbox, Transactions, job pages, the map) and next to the HMRC
  category explains what it is, whether it's normally allowable and on what conditions, the evidence to keep, and notes
  from its own context (a job that day or not, your vehicle's mileage method, a non-allowable category, a split). The
  rules come from a guide researched from GOV.UK and HMRC's manuals, every rule with its source, dated, and points that
  need an accountant marked as such. **Tax guide** (from Tax years): 18 legal ways to pay less tax, the rules that matter
  (travel, mileage, use of home, capital allowances, cash basis, payments on account, pensions, VAT, Making Tax Digital)
  and every expense category. The assistant looks rules up in the same guide and cites its links; any link it gives
  that didn't come from the guide or your records is flagged.
- **Assistant**: ask anything about your money, jobs, dates and documents, in the app or on Telegram. It
  looks things up with read-only tools, cites records, labels suggestions and estimates, and every money
  figure it quotes is checked against your data; anything it can't match is flagged. It can suggest
  decisions for your Inbox but never confirms anything. AI suggestions for a whole tax year are one click.
- **Gmail and Stays & travel**: narrow, editable Gmail searches per tax year find hotel bookings
  (Booking.com, Hotels.com, Premier Inn, Travelodge, IHG and others), travel receipts and tickets (Uber, Trainline,
  rail operators, airlines, car hire, parking) and POs. Every fetched email is listed in a "what the app read" log.
  Bookings are read from the structured booking data booking sites embed (no AI needed), otherwise by Claude with
  every date, amount and reference checked against the email. Each stay is lined up with your jobs night by night:
  between working days is business; the night before or after depends on distance from home (12 and 40 miles by
  default, via postcodes.io) and the job's start and finish times; no job is personal; a stay with no payment, or
  paid by someone else, is proposed as booked by the client. The payment is found in your statements and gets the
  same suggestion. TfL, taxis, trains and parking that only appear on statements are suggested from the same job
  days. You decide each one (business, part business by nights, personal, client-booked or recharge), and that
  decision is applied to the payment.
- **PO tracking**: a job's PO comes from a PO document linked to it (read from email, Gmail, Telegram or upload), a
  PO number typed on the job, or an invoice. Jobs without one are highlighted, with a "No PO" filter and a count on
  the Overview, unless marked "No PO needed". Once a job's invoice is issued, the PO email and its attachment get a
  green tick with the invoice number ("Paid" once paid). With Gmail connected, the PO email in Gmail also gets a
  green **✓ Invoiced** label, taken off again if the invoice is voided. The app finds it by the Gmail message it
  fetched, by a forwarded email's Message-ID, or by the PO number when every match is in one conversation; it
  never guesses between conversations.
- **To do** (top of the menu): one list of everything that needs you, most urgent first, with one-tap actions:
  unpaid invoices (send a reminder), statements held, failed checks, a filing deadline, invoices to draft or send,
  possible jobs (add, or not a job), payments to decide (accept the suggestion, or personal), merchants ready for a
  rule, stays to decide, jobs with no PO, paid bookings missing from your statements and documents held for approval.
  It also finds money you may be owed: an invoice for less than its PO, part-paid invoices past due, stays you
  decided to recharge to the client, and the same amount charged twice in a day. Any item can be snoozed for a week or
  marked not relevant (with undo). The top items show on the Overview. The same list comes to **Telegram every Monday** with buttons that act in the
  chat (only from your paired chat); send `/todo` any time. Overdue invoices get a Telegram nudge with a "Send a
  reminder" button.
- **Learns from your decisions**: once you've decided a merchant the same way twice, it offers "Always do this". The
  rule then applies automatically to what's waiting and to new statements, as a decision by your rule, and can be
  undone in one go (Settings › Rules). The job is never part of a rule.
- **Invoices by email**: email an issued invoice (PDF attached) to the client, and send reminders when it's overdue,
  from your own mailbox if set up (replies go to your business email). The invoice records when it was sent.
- **Mileage from jobs**: one click per year adds a return trip from home to each venue you drove to (estimated from
  the postcodes, straight line × 1.25, clearly marked so you can correct it); a trip each day for multi-day jobs without
  a stay; jobs reached by train, plane or coach are left out.
- **Starling automatic import**: with your own read-only personal access token (Settings › Integrations), Starling's
  CSV statement is downloaded every morning for the days since the last import (a week's overlap) and imported exactly
  like an upload: balance-checked, held if it doesn't add up, never duplicated. "Import from a date" catches up. Amex and
  Capital One still need their CSVs (they'd need an open-banking provider).
- **Gmail auto-forwarding** (Settings › Integrations › Forward by email): step-by-step Gmail filters, with the searches
  ready to copy, that forward POs, call sheets and bookings to your forwarding mailbox, so documents keep arriving without
  Google's 7-day reconnect.
- **Search everything** from the top bar (press `/`): payments, jobs, documents, invoices and stays, by name, amount,
  PO number or reference.
- **Job finder** (`Jobs › Find jobs`): looks through your calendar, the POs, job invites, production packs and call
  sheets read from email (bodies and attachments), and your stays and journeys. What clearly belongs to one of your jobs
  is linked: the same PO number, or the job's dates plus its client, venue, postcode or title words. Close calls are left
  for you. The rest is grouped by date and shared words into possible jobs (a calendar entry, the job invite and the
  hotel stay become one), which you add as a job (with your edits), add to a job you have (only its blank details are
  filled in), or dismiss for good. Calendar entries the rules can't call are asked about with AI; an answer only counts
  if the words it quotes are in the entry. Links you make are never changed automatically; automatic ones can be
  confirmed or undone. Adding a job lines up stays around its dates again and links an invoice that quotes its PO.
  It runs nightly and after new documents, bookings or calendar entries arrive.
- **Connections** map: jobs in the middle of a timeline, joined to calendar entries, documents and POs, stays,
  costs, income, invoices and mileage. Solid links are yours, dashed are automatic, dotted are possible (a cost on a
  job's day: is it that job's?). "Needs attention" flags jobs with no PO, calendar entries that look like jobs but
  aren't set up ("This is a job: add it", or "Not a job"), costs and undecided payments on job days, stays with no job,
  jobs not invoiced and AI findings that failed their check. Select anything to link, unlink or decide it.
- **Every AI finding is double-checked** against its source, separately from the read that produced it: the stored file
  is checked against its fingerprint and its text extracted again, then every quoted detail, PO line, total, receipt,
  proposed job and job link is matched again; an AI job link only counts if the document shows the job's dates with its
  client, venue or title, or its PO number. Scans and photos get an independent second read. Bookings are fetched again
  from Gmail (still there? unchanged?) and re-checked. AI suggestions on payments are checked against the payment and
  the job's dates. Each finding shows its result with a Re-check button; everything is re-checked nightly and the
  Gmail searches re-run weekly.
- **Sending email** (password reset and test emails only): from the web server with no setup, or from your own
  mailbox (recommended, more reliable), set in Settings › Integrations with a test button. Locked out with no email?
  `php artisan ledger:reset-link` on the server prints a one-time reset link.
- **Spreadsheet export** of any year (return figures, every transaction, mileage), for filing or records.
- **Security**: one account only (registration closes after you sign up), two-factor sign-in with
  recovery codes, strict security headers and content security policy, locked tax years, encrypted
  calendar feed address, encrypted backups the server itself can't read.

Not built yet (see the plan): Gmail searches triggered automatically when a job is added, the Calendar API
(the iCal feed is used), recharge invoicing, Making Tax Digital quarterly export, passkeys.

## Run it on your computer

Needs PHP 8.2+ (8.4 recommended), Composer and Node 22.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm run build
php artisan serve
```

Open http://127.0.0.1:8000 and create your account. To try it with fictional sample data instead:

```bash
php artisan migrate:fresh --seed --seeder=DemoSeeder   # sign in as demo@example.com / demo-password-123
```

The demo seeder refuses to run in production.

## Tests

```bash
php artisan test        # 161 tests: money, tax years, imports, dedupe, ledger rules, tax, mileage, FX,
                        # documents and grounding, invoices, assistant, Telegram, 2FA, every page
npx tsc --noEmit        # type-check the front end
npm run lint
```

## Deploy to Hostinger

One-time setup:

1. **Create the site.** In hPanel add `expenses.rohleder.tech` as its own website (not a folder of the
   Node.js site on `rohleder.tech`). Create a MySQL database and user for it.
2. **PHP.** Choose PHP 8.4 for the site. Turn on the `sodium` extension and turn off `expose_php`.
3. **SSH.** Enable SSH in hPanel and add your public key.
4. **Settings file.** On the server, create `~/domains/expenses.rohleder.tech/app/shared/.env` from
   `.env.example` with:
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://expenses.rohleder.tech`,
   `APP_KEY=` (from `php artisan key:generate --show`), `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`,
   `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`, `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=strict`,
   `SETUP_CODE=` (a long random code; the sign-up page only opens with it, at `/register?code=…`),
   and optionally `HEARTBEAT_URL`. Then `chmod 600` it. Never commit it.
5. **Backup key.** On *your own computer*:
   ```bash
   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out ledger-backup-private.pem
   openssl pkey -in ledger-backup-private.pem -pubout -out backup_public.pem
   ```
   Upload only `backup_public.pem` to `~/domains/expenses.rohleder.tech/app/shared/`. Keep the private
   key in your password manager. The server can then make backups but never read them.
6. **First deploy** (from your computer, in this repository):
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   DEPLOY_HOST=u000000000@your.server.ip DEPLOY_PORT=65002 DEPLOY_DOMAIN=expenses.rohleder.tech ./deploy/deploy.sh
   ```
   Each deploy goes into a new release folder, backs up the database, runs migrations, then switches over
   and checks `/up`. If the check fails it switches back.

   **Without SSH** (for example through Hostinger's file upload API): upload into one new folder inside
   `public_html` a `.htaccess` containing `Require all denied` (first, and check the folder answers 403),
   then `RELEASE` (the release name), `release.tar.gz` (the built app), `deploy/install-uploaded.sh` and, on
   the first install only, the `.env` as `env`. Run the script once with a temporary cron job
   (`/bin/bash ~/domains/expenses.rohleder.tech/public_html/<folder>/install-uploaded.sh`), read the cron
   output, then delete the cron job. It does the same steps as `deploy.sh` and also sets up the document
   root below.
7. **Document root.** So that only `public/` is reachable:
   ```bash
   cd ~/domains/expenses.rohleder.tech
   mv public_html public_html.original && ln -s app/current/public public_html
   ```
   The deploy script then warns if `/.env` or `/composer.json` are ever reachable.
8. **Cron jobs** in hPanel:
   ```
   * * * * *  /opt/alt/php84/usr/bin/php ~/domains/expenses.rohleder.tech/app/current/artisan schedule:run
   17 2 * * * bash ~/domains/expenses.rohleder.tech/app/current/deploy/backup.sh
   ```
   Copy the encrypted backups in `~/ledger-backups` off the server regularly.
9. **AI, email and Telegram** (Settings › Integrations): paste a Claude API key (or set `ANTHROPIC_API_KEY` in
   `.env`), choose the model (Claude Opus 5.5 by default) and a monthly spending cap. For email, create a
   mailbox in hPanel (for example `ledger@rohleder.tech`), enter its IMAP details and your own sending
   addresses, and optionally add a Gmail filter that forwards POs, call sheets and production packs to it.
   For Telegram, create a bot with @BotFather, paste its token, then send the bot the pairing code shown.
   For Gmail, follow the steps shown in that section: create your own Google Cloud project with the Gmail API, an
   OAuth client of type Web application with the redirect URI shown. Keep the consent screen in Testing and add
   the Gmail address you'll connect as a test user (Google blocks Gmail access for published apps that haven't had its
   paid security review). In Testing, Google ends the access after 7 days: the app says so on Telegram and you press
   Connect Gmail again. Then Connect Gmail. The gmail.modify scope is requested so the app can add its ✓ Invoiced label; the
   code only ever reads, and adds or removes that one label (it never sends, moves or deletes). Then use
   **Stays & travel › Search Gmail** for each tax year. Add your home postcode (Settings › Ledger) and postcodes on
   your jobs so distances can be used.
10. **First visit.** Open `https://expenses.rohleder.tech/register?code=<SETUP_CODE>`, create your account
   (sign-up then closes for good), complete Setup, and turn on two-factor sign-in under Settings › Security.

## Getting your statements

Export CSV files for each account and import them under **Import**. Menu names change, but roughly:

- **Starling**: Statements › choose dates › CSV.
- **Amex**: Statements & activity › Download › CSV (choose "include all additional transaction details").
- **Capital One**: Transactions › Download › CSV.

Overlapping date ranges are fine. Import whole tax years at a time, then use the coverage grid on the tax
year page to spot missing months.

## Restoring a backup

```bash
mkdir restore && tar -xf ledger-<stamp>.tar -C restore && cd restore
openssl pkeyutl -decrypt -inkey ledger-backup-private.pem -pkeyopt rsa_padding_mode:oaep -in key.enc -out key.bin
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in payload.enc -out payload.tar.gz -pass file:key.bin
tar -xzf payload.tar.gz   # database.sql and private/ (uploaded statements)
```

Then load `database.sql` into an empty database.

To check a backup really restores, on your own computer (where the private key is), download a backup and its `.sha256` and run:

```bash
bash deploy/restore-check.sh ledger-<stamp>.tar ledger-backup-private.pem
# and, with MySQL or MariaDB installed locally, load it into a new empty database too:
RESTORE_DB_USER=root MYSQL_PWD=... bash deploy/restore-check.sh ledger-<stamp>.tar ledger-backup-private.pem --load ledger_restore_check
```

It checks the checksum, decrypts into a temporary folder, checks the dump is complete and has every main table, counts the
uploaded files, optionally loads it and counts rows, then deletes the decrypted copy. `tests/Feature/BackupRestoreTest.php`
runs `backup.sh` and this check end to end on MariaDB (`DB_CONNECTION=mysql php artisan test --filter=BackupRestore`).

## Where things live

| Area | Code |
|---|---|
| Money, tax years | `app/Support/Money.php`, `app/Support/UkTaxYear.php` |
| Tax rates and rules per year | `config/tax.php` |
| Import, dedupe, balance check | `app/Domain/Import/` |
| Decisions and their rules | `app/Domain/Ledger/AllocationWriter.php` |
| Suggestions, transfers, merchants | `app/Domain/Matching/` |
| Return figures, estimate, bill, checks, mileage | `app/Domain/Tax/` |
| Exchange rates | `app/Domain/Fx/ExchangeRates.php` |
| Audit log | `app/Domain/Audit/AuditLog.php` |
| Documents, text extraction, grounding checks | `app/Domain/Documents/` |
| Claude: document reading, assistant and tools, suggestions | `app/Domain/Ai/` |
| Invoices and PDF | `app/Domain/Invoices/`, `resources/views/pdf/invoice.blade.php` |
| Email and Telegram intake | `app/Domain/Mail/`, `app/Domain/Telegram/` |
| Gmail: searches, read log, ✓ Invoiced label | `app/Domain/Gmail/` |
| PO and invoiced status | `app/Domain/Jobs/PoStatus.php` |
| Stays and journeys: reading, night-by-night rules, payment matching | `app/Domain/Travel/` |
| Health check (Claude, Telegram, Gmail, queue, errors) | `php artisan ledger:check` |
| Screens | `resources/js/pages/` |

Tax figures are a cross-check. HMRC's own calculation and your online account are the authority.
