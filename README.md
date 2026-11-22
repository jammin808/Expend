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
  tax payments, never expenses.
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
- **Assistant**: ask anything about your money, jobs, dates and documents, in the app or on Telegram. It
  looks things up with read-only tools, cites records, labels suggestions and estimates, and every money
  figure it quotes is checked against your data; anything it can't match is flagged. It can suggest
  decisions for your Inbox but never confirms anything. AI suggestions for a whole tax year are one click.
- **Spreadsheet export** of any year (return figures, every transaction, mileage), for filing or records.
- **Security**: one account only (registration closes after you sign up), two-factor sign-in with
  recovery codes, strict security headers and content security policy, locked tax years, encrypted
  calendar feed address, encrypted backups the server itself can't read.

Not built yet (see the plan): night-by-night hotel-stay and journey matching, direct Gmail search (use
forwarding for now), recharges, Making Tax Digital quarterly export, passkeys.

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
php artisan test        # 86 tests: money, tax years, imports, dedupe, ledger rules, tax, mileage, FX,
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
10. **First visit.** Open the site, create your account (sign-up then closes for good), complete Setup,
   and turn on two-factor sign-in under Settings › Security.

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

Then load `database.sql` into an empty database. Try this once before you rely on it.

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
| Screens | `resources/js/pages/` |

Tax figures are a cross-check. HMRC's own calculation and your online account are the authority.
