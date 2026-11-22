# Expense Ledger — Plan v2

Private, single-user bookkeeping for a UK sole trader: bank imports, receipts, jobs, invoices and
tax-year reporting, hosted on Hostinger Business shared hosting at `expenses.rohleder.tech`.

Status: draft for the code architect. Revised 22 Nov 2026 from the owner's v1 plan.

---

## 0. What changed from v1

| Area | v1 | v2 | Why |
|---|---|---|---|
| Tax compliance | "Exportable records for an accountant or HMRC-compatible software" | Making Tax Digital for Income Tax (MTD ITSA) is a first-class requirement: HMRC categories, quarterly periods, quarter close, digital-link export | MTD ITSA has been live since 6 Apr 2026 for qualifying income over £50k; £30k from Apr 2027; £20k from Apr 2028 |
| Data model | Originals kept separately | Three layers (immutable source, allocations, disposable projections); integer pence; splits must sum exactly; hash-chained audit log; period locks | Makes wrong figures structurally hard to produce |
| Email | Gmail API | Gmail API, read-only, with narrow searches (bookings, receipts) and an access log. Forwarding mailbox as the privacy-first alternative | Hotel-stay checks need on-demand searches around job dates. Access stays read-only and narrow, and the OAuth token-expiry trap is avoided |
| Calendar | Calendar API | Default: Google Calendar's secret iCal feed (read-only). API optional | No OAuth to maintain |
| Hotel stays | Not covered | Each stay is assembled from Gmail bookings, calendar and bank payments, then classified night by night against job dates and venue distance. Never auto-confirmed | Hotels are the classic mixed business/personal expense |
| Travel journeys | Not covered | Uber, Bolt, taxis, Trainline and train operators, TfL, coaches, parking, tolls and fuel are matched to each job's travel window, grouped into one trip per job and confirmed per trip | Travel is mixed business/personal too, and a job with no recorded travel often means a missed claim |
| Receipts | From email only | Phone camera capture is a primary input | Most receipts are paper or on-screen at the moment of spend |
| Bank formats | CSV, XLSX, PDF from the start | CSV/OFX first with saved per-bank profiles; XLSX next; PDF last, gated by a balance check | PDF parsing is the least reliable part, and the host cannot run `pdftotext` |
| PDF generation | Unspecified | dompdf (pure PHP) | `exec`/`shell_exec` are disabled on the host |
| Navigation | 8 sections | 5 sections: Inbox, Money, Jobs, Invoices, Tax (plus Settings) | Email and Calendar are sources of data, not places you go |
| Phasing | Reporting in phase 6 | Walking skeleton (import, review, quarterly summary) in phase 1 | Tax deadlines drive the value; next MTD deadline is 7 Feb 2027 |
| Security | HTTPS, 2FA, encrypted credentials | Adds passkeys, a threat model for a shared hosting account, backups encrypted to an offline key, CSV-injection-safe exports, re-auth for sensitive actions | |
| Reliability | Not specified | Hazard log, risk classes, invariants, golden-file tests, recompute-and-verify, atomic deploys with rollback, heartbeat monitoring | Borrowed from medical-device and games engineering (§8) |

---

## 1. Principles and measurable targets

When goals conflict, priority is: **correct money > security > ease of workflow > speed.**

- **Never silently guess.** Anything uncertain lands in the Inbox with a suggested answer. Nothing becomes
  "business expense" or "taxable income" without your confirmation or a rule you created.
- **Every pound accounted for.** Each bank transaction must be fully allocated (business, personal,
  transfer, etc.) before its period can close.
- **Originals are sacred.** Imported files and rows are never edited or deleted. Corrections are new
  records with a reason.
- **Everything is undoable** until a period is locked; after that, changes are explicit adjustments.
- **Boring technology.** Few dependencies, mainstream packages, no runtime dependence on Node or external servers.

Targets:

- Review a typical month (about 60 transactions) in under 10 minutes on an iPhone.
- Interaction feedback under 100 ms (optimistic UI); page load under 1 s on 4G; 1,000-row CSV import under 10 s.
- Zero unexplained differences between the app's balance and the bank statement's closing balance.
- Off-site restore tested every quarter. At most 24 h of data lost (RPO), back up within 4 h (RTO).

---

## 2. Hosting: verified facts and consequences

Checked read-only against the Hostinger account on 22 Nov 2026.

| Fact | Consequence |
|---|---|
| Business shared hosting plan, active | SSH, Git, cron and Node.js websites are available on this tier |
| PHP 8.3 currently selected; 8.2–8.5 available | Current Laravel runs. Use 8.4 for this app and pin the same version in CI |
| `memory_limit` up to 2048M; `max_execution_time` max 360 s | Jobs must be chunked to finish well under 300 s |
| Enabled: bcmath, gmp, intl, mbstring, imap, gd, imagick, zip, fileinfo, pdo_mysql, opcache | Money maths, IMAP polling, image handling and XLSX are all available |
| `sodium` disabled | Enable it (used for sealed-box backup encryption) |
| Disabled functions include `exec`, `shell_exec`, `system`, `passthru`, `popen`, `symlink` | PHP cannot shell out: no wkhtmltopdf/Chromium PDFs, no `pdftotext`, no `mysqldump` from PHP, no `artisan storage:link`. Use pure-PHP libraries and run shell tasks from cron/SSH directly |
| `expose_php` On; PHP `log_errors` Off | Turn `expose_php` off; log through Laravel to a private path |
| PHP session-cookie defaults are weak (Secure/HttpOnly off, SameSite None) | Laravel sets its own cookies: enforce `Secure`, `HttpOnly`, `SameSite=Lax` or `Strict` in config and cover it with a test |
| `rohleder.tech` currently runs as a Node.js website; no subdomains exist yet | Create `expenses.rohleder.tech` as its own website with its own document root, not as a folder under the Node app, and confirm requests are not routed to the Node process |
| Other websites share the same hosting account and system user | A compromise of any of them can read this app's files and `.env` (see §7) |

Still to verify before building:

- MySQL/MariaDB version. CHECK constraints need MySQL 8.0.16+ or MariaDB 10.2+.
- Composer available over SSH.
- Cron can run every minute.
- Shell `ln -sfn` works over SSH and the web server follows a symlinked document root (needed for atomic deploys).
- Whether the database user may create triggers.
- Hostinger's backup frequency and retention on this plan.

**Why PHP/Laravel and not Node, even though this plan supports Node:** PHP runs per request, so there is
no long-lived process to crash, leak memory or hit the shared process limit. Hostinger's Node websites
also stop any request after 120 s. For a low-traffic, correctness-first app, per-request PHP is the more
stable choice on shared hosting.

---

## 3. Architecture

### 3.1 Stack

| Concern | Choice | Notes |
|---|---|---|
| Framework | Laravel (current release) on PHP 8.4 | Fortify for login, TOTP and recovery codes; scheduler; queues; encryption; migrations |
| UI | Blade + Livewire + Alpine + Tailwind | Server-driven; no SPA complexity. Assets compiled in CI, so the server needs no Node |
| Database | Hostinger MySQL/MariaDB, InnoDB, utf8mb4, strict mode | |
| Money | Integer pence (`BIGINT`) in the DB; `brick/money` in PHP | Never floats |
| Spreadsheets | OpenSpout for large reads/writes; PhpSpreadsheet only where formatting matters | Memory |
| PDF output | dompdf | Pure PHP |
| PDF input (phase 7) | smalot/pdfparser | Pure PHP |
| Calendar | sabre/vobject | ICS, including recurring events |
| Email | Gmail API via google/apiclient; PHP imap extension for the forwarding-mailbox alternative | Read-only |
| Geocoding | postcodes.io, or a local copy of the ONS Postcode Directory | UK postcodes to coordinates, for hotel/venue/home distances |
| Passkeys | A maintained WebAuthn package (or Laravel's own, if available at build time) | |
| Quality | Pest, Larastan at a strict level, Infection (mutation testing) on high-risk modules | |
| CI/CD | GitHub Actions, then deploy over SSH | |

### 3.2 Layers (the core idea)

1. **Source layer (immutable).** Uploaded files (stored with a SHA-256 hash), parsed raw rows, emails,
   calendar events and receipt images. Insert-only.
2. **Ledger layer (editable until locked).** Allocations that explain each bank transaction: what it was,
   which job, which HMRC category, business-use %, VAT. Every change is written to the audit log.
3. **Projection layer (disposable).** Dashboard totals, quarterly summaries, P&L, job profitability and the
   tax estimate. These can always be rebuilt from layer 2. A nightly job rebuilds them, compares the result
   with the cached values and raises an alert on any difference.

### 3.3 Background work without daemons

- One cron entry runs every minute: `php artisan schedule:run`.
- The scheduler runs `queue:work --stop-when-empty --max-time=240`, so queued jobs (imports, IMAP polls,
  ICS sync, PDF generation) run without a long-lived worker.
- Every job is **idempotent** (safe to run twice) and chunked to finish in under 240 s. Long work resumes
  from a stored cursor.
- Each scheduler run pings a heartbeat URL. If the pings stop you get an alert, because cron that dies
  silently is the most common shared-hosting failure.

### 3.4 Server layout

```
~/domains/expenses.rohleder.tech/
  app/
    releases/<git-sha>/          # one directory per deploy
    current -> releases/<sha>    # switched atomically
    shared/
      .env                       # mode 0600, never in Git
      storage/                   # logs, cache, private files (receipts, statements)
  public_html -> app/current/public   # only public/ is reachable over HTTP
```

If the host will not serve a symlinked `public_html`, deploy the contents of `public/` into `public_html`
and point `index.php` at the app directory. Either way, nothing outside `public/` may be reachable. A
post-deploy smoke test requests `/.env`, `/composer.json`, `/storage/` and `/vendor/` and expects 403 or 404.

### 3.5 Deploy pipeline

1. CI: lint, static analysis, tests (including golden files), build assets,
   `composer install --no-dev --optimize-autoloader`.
2. Upload to `releases/<sha>` over SSH with rsync.
3. Back up the database, then run migrations. Migrations must stay backwards-compatible (expand, then contract).
4. Cache config, routes and views; switch the `current` symlink; health-check `/up`.
5. If the health check fails, switch back and raise an alert. Keep the last five releases.

---

## 4. Data model essentials

| Table | Purpose |
|---|---|
| `accounts` | Bank and card accounts: type, last four digits only |
| `import_batches` | File hash, import profile, period covered, opening/closing balance, balance-check result, status. "Undo this import" reverses a whole batch |
| `bank_transactions` | Raw, immutable rows: account, batch, posting date, `amount_pence`, raw description, balance after, fingerprint, occurrence |
| `allocations` | Explains a transaction: type (`business_expense`, `business_income`, `personal`, `transfer`, `drawings`, `capital_introduced`, `refund`, `loan`), HMRC category, job, `amount_pence`, business-use %, `vat_pence`, status (`suggested`/`confirmed`), reason, rule |
| `documents` | Receipts, statements, invoice PDFs: SHA-256, MIME type, size, private path; linked to transactions, allocations or jobs |
| `clients`, `jobs` | A job has a client, date range, location, status and agreed fee |
| `calendar_events` | ICS UID, start/end, location, linked job |
| `stays` | One per booking: provider, booking reference, hotel, address, postcode, check-in/out, guests, total and nightly amounts, currency, status (`booked`, `amended`, `cancelled`, `completed`), paid by (`me`, `client`, `rechargeable`), linked job |
| `stay_nights` | One row per night: date, `amount_pence`, linked job, classification (`business`, `personal`, `review`), reasons, confirmed |
| `stay_sources` | Links a stay to Gmail message IDs, calendar event UIDs and bank transactions; keeps every version of the booking |
| `places` | Postcode-to-coordinates cache |
| `journeys` | One per journey or charge: mode (`rideshare`, `taxi`, `rail`, `tfl`, `coach`, `parking`, `toll`, `road_charge`, `fuel`, `flight`, `car_hire`), provider, travel date and time, from/to places, booking reference, purchase date, `amount_pence`, status (`booked`, `travelled`, `cancelled`, `refunded`), paid by, linked job, classification, reasons, confirmed |
| `journey_sources` | Links a journey to Gmail message IDs, bank transactions, TfL journey-history rows and calendar events |
| `merchant_patterns` | Bank-description pattern mapped to provider and type (hotel, ride, rail, TfL, food delivery, parking, penalty charge…). Seeded, editable, refined from your statements |
| `settings` | Home postcode, travel thresholds (far from home, early start, late finish), hotel and travel sender lists |
| `invoices`, `invoice_lines`, `payments`, `credit_notes` | `payments` links an invoice to bank transactions; partial payments allowed |
| `vehicles`, `mileage_trips` | Vehicle claim method (simplified mileage or actual costs, fixed per vehicle); trips with date, from/to, miles, job and a snapshot of the rate used |
| `periods` | Tax years and quarters; status `open`/`closed`/`submitted`; lock metadata |
| `rules` | Supplier/description pattern mapped to category, job and business-use %; created from your confirmations |
| `audit_events` | Append-only: when, actor, action, entity, before/after JSON, reason, `prev_hash`, `hash` |
| `tax_parameters` | Rates and thresholds per tax year, each with a source URL and effective dates |
| `secrets` | Encrypted credentials: IMAP password, ICS URL, OAuth tokens |

Invariants, enforced in both code and database:

- `amount_pence` is a non-zero integer. Currency is GBP in v1; the column exists for later.
- A transaction is **reconciled** only when its confirmed allocations sum exactly to its amount.
- Apportionment uses integer maths, and the remainder penny goes to the personal portion, so parts always
  sum exactly. Covered by a property-based test.
- Unique key on `(account_id, fingerprint, occurrence)`, so re-importing the same or an overlapping
  statement never duplicates rows.
- A stay's nights sum exactly to its total. Allocations on the linked bank transactions are generated from
  the confirmed nights, so the stay and the ledger always agree.
- Where TfL journey history is imported, each TfL bank charge equals the sum of the fares behind it.
- No `ON DELETE CASCADE` on financial tables. Void or reverse instead of deleting.
- Rows in a locked period cannot change. Guard this in the domain layer and cover it with a test; also use
  a database trigger if the host allows it.
- Bank posting dates are stored as `DATE` (no timezone). Timestamps are stored in UTC and shown in
  Europe/London time. Tax-year assignment uses the posting date only.

**Dedupe fingerprint:** account + posting date + amount + normalised description (+ running balance when
the bank supplies one). `occurrence` is the nth identical row within the file. Two genuine £3.20 coffees
on the same day both survive, while the same coffee appearing in two overlapping statements does not.

---

## 5. UK accounting and tax rules

### 5.1 Making Tax Digital for Income Tax

- **Who is in scope.** From 6 Apr 2026 if *qualifying income* (gross self-employment plus property
  turnover, not profit) on the 2024-25 return was over £50,000. From 6 Apr 2027 at £30,000, based on
  2025-26. From 6 Apr 2028 at £20,000.
- **Quarterly updates** are year-to-date summaries of income and expenses by category for each business.
  Standard periods run 6 Apr–5 Jul, 6 Jul–5 Oct, 6 Oct–5 Jan and 6 Jan–5 Apr, with deadlines on 7 Aug,
  7 Nov, 7 Feb and 7 May. Calendar quarters can be elected instead, so support both. The final
  declaration is due by 31 Jan.
- **Digital records and digital links.** Records must be kept digitally and moved between tools by digital
  means. Exporting and importing a file counts; retyping figures does not.
- **Submission route (owner decision):**
  - **A. Recommended for v1:** this app is the record-keeping system. It exports quarterly category totals
    as CSV into HMRC-recognised bridging or MTD software (or the accountant's software), which submits them.
  - **B. Later, if worthwhile:** call HMRC's MTD API directly. That needs HMRC Developer Hub registration,
    OAuth with HMRC, mandatory fraud-prevention headers and HMRC production approval, which is a lot of
    work for a single user.
- **Penalties.** HMRC will not apply penalty points for late quarterly updates in 2026-27. Penalties for
  the final declaration and for late payment still apply.

### 5.2 Categories

Use HMRC's self-employment expense categories as the reporting backbone, because they match SA103F and the
MTD quarterly update:

1. Cost of goods bought for resale or goods used
2. Construction industry: payments to subcontractors
3. Wages, salaries and other staff costs
4. Car, van and travel expenses
5. Rent, rates, power and insurance costs
6. Repairs and maintenance of property and equipment
7. Phone, fax, stationery and other office costs
8. Advertising and business entertainment costs (entertainment is disallowable)
9. Interest on bank and other loans
10. Bank, credit card and other financial charges
11. Irrecoverable debts written off
12. Accountancy, legal and other professional fees
13. Depreciation and loss/profit on sale of assets (disallowable)
14. Other business expenses

Each allocation can be split into allowable and disallowable parts. Friendly sub-categories (for example
"Hotels" or "Rail") map onto these. Below the VAT threshold, MTD allows a single consolidated expenses
figure, but categorise anyway: the detail is needed for SA103F and for your own insight.

### 5.3 Other rules

- **Cash basis** is the default since 2024-25; accruals is an option. Store invoice dates and payment dates
  separately so either view can be produced.
- **Trading allowance** (£1,000) versus actual expenses: show which is better in the tax summary.
- **Mileage:** simplified rates are 45p a mile for the first 10,000 business miles and 25p after that
  (cars and vans), and 24p for motorcycles. Each vehicle uses simplified mileage *or* actual costs, never
  both. Warn if fuel for a mileage-method vehicle is categorised as business.
- **Use of home:** simplified flat rate by hours worked per month (£10 for 25–50 hours, £18 for 51–100,
  £26 for 101 or more), or apportioned actual costs.
- **Mixed-use expenses:** business-use % per allocation or per rule (for example, phone at 60%).
- **Capital items:** flag large purchases. Under cash basis most equipment is deductible but cars are not;
  the accountant confirms.
- **VAT:** off by default. If registered (threshold £90,000), track VAT per allocation and invoice, keep it
  separate from income tax figures, and note that MTD for VAT has its own submission route.
- **Invoices:** sequential numbers that are never reused or deleted. Issued invoices are immutable (PDF
  stored with its hash); corrections go through credit notes. Partial payments and ageing are tracked.
- **Tax estimate:** income tax plus Class 4 NIC, payments on account (31 Jan and 31 Jul), and a "set aside
  for tax" figure on the dashboard. Rates come only from `tax_parameters`, never from code. Support
  Scottish income-tax bands if they apply.
- **Retention:** keep records for at least 5 years after the 31 January deadline for each tax year. The app
  never deletes records automatically; plan for at least 6 years.
- **Don't assume:** incoming payments default to "needs review", not income, and outgoing payments default
  to "needs review", not expenses. Transfers between your own accounts are detected as matching pairs.

---

## 6. Integrations

### 6.1 Bank statements

- **Per-bank import profiles** record the column mapping, date format, sign convention and encoding. A
  profile is saved after the first import from a bank and auto-detected on later files.
- **Order of support:** CSV, then OFX/QIF, then XLSX, then PDF.
- **Balance check:** where the file has balances, opening balance plus the sum of amounts must equal the
  closing balance and every running balance. Otherwise the import is held for review. This makes parsing
  errors visible instead of silent.
- Overlapping statements are expected and safe (see the dedupe fingerprint in §4).
- **PDF statements (phase 7):** per-bank parsers in pure PHP or optional AI extraction. Both must pass the
  balance check.
- **Open Banking aggregators:** later, if a provider with acceptable cost and terms exists. Consents need
  periodic renewal.

### 6.2 Email (Gmail) and receipts

- **Phone camera capture (PWA):** a "+" button opens the camera. The photo is compressed, uploaded and
  linked to a transaction straight away, or matched later by date and amount.
- **Gmail API, read-only (recommended).** Hotel-stay checks (§6.5) need to search Gmail on demand around
  job dates, which justifies a direct connection. The same connection also finds receipts and invoices from
  known senders.
  - Request the `gmail.readonly` scope only. Google classes it as "restricted".
  - For a personal app, keep the OAuth client to yourself and set the consent screen to **In production**,
    accepting the "unverified app" warning once. In **Testing** status, refresh tokens expire after 7 days,
    which would silently break sync.
  - Run targeted searches only and never sync the whole mailbox. Store only matching messages, and list
    every fetched message in a "what the app read" log.
- **Forwarding mailbox (privacy-first alternative).** A Gmail filter using the same searches auto-forwards
  matching emails to a dedicated `receipts@` mailbox on Hostinger, which the app polls over IMAP every
  5–15 minutes. History is loaded once from a Google Takeout export of that filter's label. The server
  never holds Gmail access, but the app can't search Gmail when a new job is added.

### 6.3 Calendar

- **Default:** Google Calendar's "Secret address in iCal format" (read-only). It is stored encrypted,
  synced every 30–60 minutes, and can be reset in Google if it leaks.
- **Optional:** the Calendar API with `calendar.readonly`. It is a "sensitive" scope, which is less onerous
  than Gmail's "restricted" one.
- If Gmail's "Events from Gmail" setting is on, hotel reservations may already show up as calendar events.
  Treat them as one more source for stays (§6.5) and deduplicate them against the booking email.

### 6.4 Matching engine

Matching uses deterministic, explainable scoring, not a black box:

1. **User rules** (exact supplier mapped to category or job) apply automatically, stay visible, and can be undone.
2. **Candidate jobs** score on: transaction date within the job's date range plus a travel window; a
   location in the description that matches the event location; a booking reference in an email; an amount
   that matches a known booking.
3. **The top suggestion is shown with its reasons,** for example "Premier Inn · during Job #42 · booking
   email 3 Oct". One tap confirms. After you confirm the same supplier three times, the app offers to
   create a rule.
4. **Below the confidence threshold** there is no suggestion, only the Inbox item.

Hotel stays don't use this general scoring. They follow the night-by-night logic in §6.5.

### 6.5 Hotel stays: business or personal?

Hotel stays are the classic mixed-use expense. The same hotel can be a work trip one week and a weekend
away the next, and a single stay can be both. The app builds one record per stay from every source, lines
it up against your jobs night by night, and proposes a split for you to confirm.

**Sources, merged into one stay record**

| Source | What it gives | How it's read |
|---|---|---|
| Gmail booking emails | Hotel, address and postcode, check-in and check-out, guests, booking reference, price, amendments and cancellations | Narrow read-only searches (below). Parsers are tried in order: the schema.org `LodgingReservation` markup that most booking sites and hotel chains embed (the same markup Gmail uses for its booking cards); per-sender parsers for the providers you actually use; optional AI extraction (§6.8); manual entry as a last resort |
| Google Calendar | Job dates, times and venue. Also hotel reservations, if "Events from Gmail" is on | ICS feed or Calendar API. Gmail-created events have the type `fromGmail` in the API; check whether they also appear in the iCal feed |
| Bank transactions | What was actually paid, when, and in pounds | Merchant patterns (hotel chains, booking sites); amount matched against the booking; date matched against the booking date (prepaid) or check-out ± 3 days (pay at hotel) |
| Jobs | Date range, venue postcode, client | Jobs section |

**Gmail searches** run on a schedule, and again whenever a job is added or its dates change (searching
around those dates). Starting queries, editable in Settings:

```
from:(booking.com OR hotels.com OR premierinn.com OR travelodge.co.uk OR ihg.com)
subject:(booking OR reservation OR confirmation OR receipt) (hotel OR "check-in" OR "check in" OR stay)
```

The first parsers cover the five providers you use: **Booking.com, Hotels.com, Premier Inn, Travelodge
and IHG**. Confirm the exact sender addresses against your real emails during the build. When you confirm
a stay from a new sender, that sender is added to the list. Only matching messages are fetched, and every
one appears in the "what the app read" log.

**How these providers usually appear on bank statements** (seed `merchant_patterns` with these, then
refine them from your real statements):

- **Booking.com:** prepaid stays usually show as Booking.com; pay-at-property stays show under the hotel's own name.
- **Hotels.com:** may show as Hotels.com or Expedia.
- **IHG:** usually shows under the hotel brand, such as Holiday Inn, Holiday Inn Express or Crowne Plaza.
- **Premier Inn and Travelodge:** show under their own names.

**One stay per booking.** Stays are keyed by provider and booking reference. Later emails (amendment,
reminder, receipt, cancellation) update the same stay and keep its history. A stay without a reference is
matched on hotel and dates.

**Night-by-night classification.** For each night the app checks:

1. **Job overlap.**
   - A night between two working days (of the same job or of nearby jobs) is job-linked.
   - The night *before* the first day counts if the job starts early (default: before 10:00) or the venue
     is far from home (default: over 50 miles).
   - The night *after* the last day counts if the job finishes late (default: after 21:00) or the venue is
     far from home.
   - All thresholds are settings.
2. **Distance.** Straight-line distance from hotel to venue (within 30 miles by default supports
   business) and from hotel to home (within 15 miles is flagged as unusual to claim).
3. **Other signals.** These are shown but never decide a night on their own: a weekend or bank-holiday
   night with no job, more than one guest (the extra-person cost may be personal), payment by a personal card.

Each night gets *business*, *personal* or *needs review*, with its reasons. The stay is then all business,
all personal, or mixed with a proposed split.

Your usual pattern is **one night per job**. Any night the job doesn't explain is proposed as personal,
and the stay goes to review.

**Splitting the cost.**
- Use nightly rates from the booking where given; otherwise split evenly per night.
- Taxes and fees follow pro rata, and the remainder penny goes to the personal part.
- If several payments belong to one stay (deposit and balance), the split applies pro rata across them.
- Foreign-currency stays use the pound amount from the bank. The booking currency is only used for matching.

**Rules**

- Stays are **never auto-confirmed**, even when every night matches. They always get a one-tap review,
  because HMRC looks closely at these.
- Extra personal nights are personal. Whether travel to and from a mainly-business trip is fully allowable
  is the accountant's call; the app keeps the facts (nights, job, purpose) to support it.
- **Who paid.** You, or the client.
  - Some clients book hotels for you directly and you don't pay them back. Those stays carry no expense and
    nothing to declare; they're kept on the job record only.
  - Anything you pay yourself during a client-booked stay (parking, an evening meal, an upgrade) is still
    your expense. It's linked to the stay and reviewed like anything else.
  - A third option, "you paid, recharged to the client", exists but is off by default.
- **Timing.** The business/personal decision follows the *stay* dates; the tax year and quarter follow the
  *payment* date (cash basis). A May stay paid in March counts in March's tax year.
- **Cancellations.** A cancelled stay expects a refund. If none arrives within 30 days, or a cancellation
  charge appears, it goes to the Inbox.
- **Category.** Business nights go to HMRC category 4, "Car, van and travel expenses" (sub-category
  Accommodation). Card spend on meals in the same town during a business stay is suggested as Subsistence
  for that job, and still reviewed.

**Gap checks (both directions)**

- Hotel payment but no booking email: ask for details or a receipt.
- Booking email but no payment:
  - Until 7 days after check-out, it's treated as a pay-at-hotel charge still to come.
  - After that, the app proposes *client-booked*, more strongly if the email names a company or says the
    stay is paid on a business account.
  - Once you confirm, later bookings that look the same (same booker or company name) are proposed as
    client-booked straight away, still for review.
- Job far from home with no stay found: ask "Did you stay overnight?" (likely a missing receipt).
- Stay with no job nearby: proposed as personal unless you say otherwise.

**Location data.** UK postcodes are geocoded with postcodes.io, which is free open data; only the postcode
is sent. For no external calls at all, use a local copy of the ONS Postcode Directory instead. Results are
cached in `places`.

### 6.6 Journeys: trains, taxis, TfL and other travel

Travel is mixed business and personal in the same way as hotels, because the same Uber or Trainline
account gets used for both. Each journey is checked against the job's travel window and proposed as
business or personal, with reasons.

**Job travel window.** This is the set of days a job's travel can belong to:
- the job's working days;
- the day before or after, when the night-before or night-after rules in §6.5 apply (early start, late
  finish, far from home);
- any day you stayed in a hotel for the job.

Stays and journeys use the same window.

**What's covered, and how each type shows up**

| Type | Example bank description | Email | Notes |
|---|---|---|---|
| Ride-hailing: Uber, Bolt, FREENOW | `UBER *TRIP`, `BOLT.EU` | Trip receipt with pick-up and drop-off address and time | `UBER *EATS` is food, not travel (see Meals below) |
| Taxis and minicabs | Firm name, or a card-terminal name that's hard to recognise | Sometimes | Unknown merchants on a job day near the venue are suggested as taxi, for review |
| Rail: Trainline and train operators | `TRAINLINE`, operator name | E-ticket or receipt: origin, destination, travel date and time, railcard | Tickets are often bought weeks ahead. Refunds and Delay Repay are matched back to the journey |
| TfL: Tube, bus, Overground, Elizabeth line | `TFL TRAVEL CH`: one charge per day, or several days combined | None | Optional: import the journey history from your TfL account to see each journey behind a charge |
| Coach | National Express, Megabus | Ticket email | |
| Parking, tolls, congestion and ULEZ charges | NCP, JustPark, RingGo, PayByPhone, Dartford Crossing, TfL road charges | Sometimes | Usually claimable alongside simplified mileage (accountant confirms) |
| Parking fines and penalty charges | Council or operator name, "PCN" | Sometimes | **Never allowable.** Always classed as disallowable, with the reason shown |
| Fuel | Fuel-station names | None | Only for a vehicle on actual costs. With simplified mileage, fuel is personal and the app offers to log the mileage trip instead |
| Flights, car hire | Airline or hire company | Booking email | Later; same matching |

The bank descriptions are examples. Seed `merchant_patterns` with them, then refine from your real statements.

**Gmail searches** add travel senders, for example:

```
from:(uber.com OR bolt.eu OR thetrainline.com OR nationalexpress.com OR megabus.com)
```

Add the train operators you book with directly, and confirm sender addresses against your real emails
during the build. Uber's ride and Eats receipts come from the same sender, so they're told apart by content.

**Classifying a journey**

1. **Date and time.** A journey inside a job travel window supports business. A late-night or weekend
   journey with no job is proposed as personal.
2. **Route.** Where the receipt or ticket gives places, journeys between home, a station or airport, your
   hotel and the venue on job days support business. A route with no link to the job (for example, across
   town to a restaurant on a non-job evening) goes to review.
3. **The same place again and again.** If you travel to one venue very often, HMRC may treat it as
   ordinary commuting rather than business travel. The app flags frequently visited venues for the
   accountant to decide.
4. **Who paid.** As with hotels, travel a client books for you carries no expense.

**Timing and refunds.** As with hotels, the business purpose follows the travel date and the tax year
follows the payment date. A ticket bought in March for an April trip counts in March's tax year. Refunds,
Delay Repay and cancellations are linked to the original journey and netted off.

**Meals.** `UBER *EATS`, Deliveroo and similar are food, not travel. During an overnight business trip
they're suggested as Subsistence for that job; otherwise they're proposed as personal.

**Category.** Business journeys go to HMRC category 4, "Car, van and travel expenses". Sub-categories:
Rail, Taxi, Local transport, Parking and tolls, Fuel (actual-cost vehicles only), Flights, Car hire.
Penalty charges are disallowable.

**Gap checks**

- Job far from home with no travel found (no train, taxi, fuel or mileage): the app asks "How did you get
  there?" and offers to log a mileage trip. A missed mileage claim is lost money.
- Train ticket for a day with no job: review.
- Ride receipt email with no bank charge: probably a different card or a missing statement.
- A TfL charge that doesn't equal the sum of its imported journeys: review.

### 6.7 Trips: one view per job

Each job has a trip: outbound travel, hotel stays, local journeys, meals and return travel, shown on one
timeline with the total travel cost. A trip isn't a separate record. It's the job plus everything linked
to it within its travel window. The trip total feeds job profitability.

Most travel is confirmed **per trip, not per item**: one tap confirms "7 travel items for Job #42", and
you open only the items you want to change. Hotel stays in the trip are shown expanded, night by night, so
confirming the trip confirms them knowingly. Items outside every job's travel window always get their own
review.

### 6.8 Optional AI extraction (later, opt-in)

An LLM API can extract fields from receipt photos and PDF statements reliably. It sends financial
documents to a third party, so it is off by default, needs consent per document, and every result must
pass a validation gate (balance check or amount match).

---

## 7. Security

### 7.1 Threat model

1. **Credential theft or phishing:** passkeys.
2. **Web-app vulnerabilities:** framework defaults, CSP, dependency auditing.
3. **Another website on the same hosting account is compromised.** It runs as the same system user, so it
   can read this app's files. Mitigations:
   - Keep every site on the account patched and minimal.
   - Consider moving this app to its own hosting account or a VPS if the other sites run third-party or legacy code.
   - Encrypt sensitive fields at app level. This raises the bar, although the key lives on the same account.
   - Encrypt backups to a public key whose private key is *not* on the server.
4. **Data loss** (host failure, mistakes, ransomware): off-site, append-only backups.
5. **Lost phone:** passkey, short sessions, remote session revoke.
6. **Gmail access token stolen.** `gmail.readonly` technically allows reading the whole mailbox, even
   though the app only searches for bookings and receipts. That makes the refresh token the most sensitive
   secret in the app. Mitigations:
   - Encrypt the token and never log it.
   - Request no other Gmail scope.
   - Use a dedicated Google Cloud project.
   - Offer a one-click disconnect that revokes the token at Google.

   If the shared-account risk in item 3 isn't acceptable, use the forwarding-mailbox alternative (§6.2),
   so the server never holds Gmail access.

### 7.2 Controls

- **Authentication:** single user, with no registration route. Passkey (Face ID / Touch ID) first, TOTP as
  fallback, printed recovery codes. Login throttling and lockout; email alert on a new-device login.
- **Sessions:** 30-minute idle timeout, 12-hour absolute limit. Re-authentication required for exports,
  period unlock, settings and credential changes.
- **Transport and headers:** HTTPS only, HSTS, CSP with nonces, `frame-ancestors 'none'`,
  `Referrer-Policy: same-origin`, and a `Permissions-Policy` that allows the camera for this site only.
  Cookies are `Secure`, `HttpOnly` and `SameSite`.
- **Files:** stored outside the web root under random names and served only through an authenticated
  controller. MIME type sniffed, size limited, images re-encoded to strip embedded payloads.
- **Secrets:** `.env` outside the web root with mode 0600; credentials use Laravel's encrypted casts;
  `APP_KEY` can be rotated using previous keys.
- **Exports:** block spreadsheet formula injection by prefixing with `'` any cell that starts with `=`,
  `+`, `-`, `@`, a tab or a carriage return.
- **Dependencies:** `composer audit` and Dependabot in CI; the build fails on known vulnerabilities.
- **Visibility:** `noindex`; no public pages except login.
- **Optional extra gate:** an identity-aware proxy such as Cloudflare Access in front of the subdomain, so
  even the login page isn't public.

### 7.3 Backups

- **Nightly:** dump the database, collect new files, encrypt both as a sealed box to a public key (the
  private key is kept offline or in a password manager), and upload them to S3-compatible storage with
  versioning or object lock. Use write-only credentials, so the server can add backups but never delete them.
- Keep Hostinger's own backups as a second layer.
- **Restore drill** every quarter into a test environment, plus a CI job that checks the latest backup
  decrypts and imports.
- **One-click full export** (CSV, a zip of documents, and JSON) for portability.

---

## 8. Reliability engineering

### 8.1 From medical-device software (IEC 62304, ISO 14971, human-factors practice)

- **Risk class per module.**
  - High: ledger, import, dedupe, allocation, tax-year assignment, tax calculation, invoice numbering.
  - Medium: matching and integrations.
  - Low: theming and UI chrome.

  High-class code needs at least 95% branch coverage, mutation testing and a review of every change, and
  no new dependency without a written justification.
- **Hazard log** in `docs/hazards.md`: each way the app could produce a wrong figure, with its cause,
  mitigation and the test that proves the mitigation. Starter list:

  | Hazard | Mitigation | Test |
  |---|---|---|
  | Duplicate import | Fingerprint + occurrence unique key | Overlapping-statement golden file |
  | Missed transactions | Balance check; gap detection between statements | Statement with a missing row |
  | Wrong tax year around 5/6 April | Date-only assignment | 5 Apr and 6 Apr fixtures |
  | Personal spend counted as business | "Needs review" default; period close requires 100% reconciliation | Close blocked with one unreviewed row |
  | Shared expense counted twice | Allocation-sum invariant | Property test |
  | Rounding drift | Integer pence and remainder rule | Property test |
  | Locked period edited | Lock guard plus audit | Attempted edit is rejected and logged |
  | Invoice number gap or reuse | Unique sequence; no delete | Concurrency test |
  | Silent sync failure | Heartbeat; "last synced" shown on screen | Disable cron in staging and expect an alert |
  | Personal hotel night claimed as business | Night-by-night classification; stays never auto-confirmed | 3-night stay around a 1-day job |
  | One stay counted twice (confirmation, reminder and receipt emails) | One stay per provider and booking reference | Three emails for one booking |
  | Cancelled booking counted as an expense | Cancellation status; refund matched and netted | Cancel-and-refund fixture |
  | Prepaid stay in the wrong tax year | Tax year from payment date; purpose from stay dates | March payment for a May stay |
  | Rechargeable hotel left off the invoice | Rechargeable flag prompts an invoice line | Job closed with an uninvoiced recharge |
  | Food delivery (e.g. Uber Eats) counted as travel | Separate merchant patterns for food and rides | `UBER *EATS` fixture |
  | Fuel claimed for a simplified-mileage vehicle | Vehicle claim method; fuel proposed as personal, mileage trip offered | Fuel on a job day for a mileage vehicle |
  | Parking fine claimed | Penalty-charge patterns are always disallowable | PCN fixture |
  | Advance train ticket in the wrong tax year | Tax year from payment date; purpose from travel date | March purchase for April travel |
  | Regular trips to one venue claimed as business travel | Frequent-venue flag for the accountant | 30 trips to one venue in a year |
  | Driven job with no mileage claimed | "How did you get there?" gap check | Far-away job with no travel items |

- **ALCOA+ audit trail** (attributable, legible, contemporaneous, original, accurate). Every change records
  who, when, what and why, in a hash chain so tampering can be detected. The chain is verified nightly.
- **Fail-safe defaults:** anything uncertain goes to review and is never assumed.
- **Avoid alarm fatigue** with three alert levels only:
  - Critical (backup failed, sync dead for over 24 h, deadline within 7 days): push or email immediately.
  - Important: shown in the Inbox.
  - Info: weekly digest.
- **Prevent use errors:** explicit labels ("Tax year 2026-27: 6 Apr 2026 – 5 Apr 2027"), undo instead of
  confirmation dialogs, and colour never used as the only signal.

### 8.2 From games engineering

- **Command pattern and event log:** every user action is a command object. That gives universal
  undo/redo, the audit trail, and replay for debugging.
- **Deterministic rebuild:** totals derive from ledger data, and the nightly rebuild-and-compare job catches
  drift, like desync detection in lockstep multiplayer.
- **Save-game integrity:** every multi-row change runs in one DB transaction. Each import is a checkpoint
  that can be rolled back as a unit. Drafts autosave.
- **Frame budget becomes performance budget:** the targets in §1, measured in CI against a seeded dataset
  (for example, 5 years and 20,000 transactions).
- **Core loop and feedback:** the review Inbox is the core loop. It responds instantly, shows progress
  ("Q3: 84% reconciled") and ends in a clear "done" state. No points or badges.
- **Crash reporting with breadcrumbs:** an error tracker that scrubs personal and financial data, or a daily
  emailed error digest.

### 8.3 Testing strategy

- **Golden files:** one anonymised CSV per bank profile, with the expected parsed output.
- **Property-based tests:** splits and apportionment always sum exactly; imports are idempotent; rebuilt
  totals equal cached totals.
- **Scenario test:** a full fictional tax year (imports, jobs, invoices, mileage) with hand-checked
  quarterly totals and P&L.
- **Browser tests** (Playwright or Dusk) for Inbox, Capture and Close Quarter at iPhone viewport size.
- **Static analysis** at a strict level, plus mutation testing on high-risk modules.

---

## 9. UX

### 9.1 Navigation (bottom bar on mobile, sidebar on desktop)

1. **Inbox** (the default screen): everything that needs a decision. That means unreviewed transactions,
   hotel stays and trips to confirm, missing receipts, possible duplicates, unmatched payments, overdue
   invoices and sync problems.
2. **Money:** accounts, transactions, imports.
3. **Jobs:** clients, jobs, job P&L, mileage, a trip view for each job, and a year timeline of jobs and travel.
4. **Invoices:** create, send, track, credit notes.
5. **Tax:** quarters, tax-year summary, estimate and set-aside, exports.

**Settings** holds connections (bank profiles, receipts mailbox, calendar feed), categories, rules,
security and backups. A floating **+ Capture** button offers: snap receipt, add mileage, add cash expense,
new invoice.

### 9.2 Key flows

- **Monthly review (target under 10 minutes).** Import a statement. The Inbox shows each item as a card
  with the best suggestion pre-filled. Confirm it (tap or Enter), change it (category chips or number
  keys), split it, or mark it personal. An undo toast stays for 10 seconds.
  - Keyboard: J/K to move, Enter to confirm, P for personal, S to split, 1–9 for categories.
  - iPhone: swipe right to confirm, swipe left for personal.
- **Capture.** Two taps from the home screen to the camera. The photo is saved on the device first and
  uploaded in the background. If offline, the upload is retried the next time the app opens.
- **Confirm a hotel stay.** The card shows a timeline: job days as bars, and hotel nights as blocks
  labelled business, personal or review. It also shows the distance to the venue and to home, and an icon
  for each source (email, calendar, bank). Tap "Accept split", or tap a night to change it.
- **Confirm a trip.** The card lists every travel item for the job in time order, for example: train out,
  taxi to the hotel, hotel night, taxi to the venue, train home. Each item shows its amount and why it was
  matched ("Uber £18.40 · Thu 23:42 · venue → hotel · during Job #42"). One tap confirms the lot, or tap
  an item to change it.
- **Close the quarter.** A checklist screen:
  1. All transactions are reconciled.
  2. Balances match the statements.
  3. Receipts are attached for items over a chosen amount.
  4. Mileage is logged.

  Then lock the period and export the MTD summary and the accountant pack.
- **Invoice.** Start from a job, with client and fee pre-filled. Generate the PDF, mark it sent, and the
  incoming payment is matched automatically.

### 9.3 Standards

- WCAG 2.2 AA; touch targets at least 44×44 pt; dark mode; respects larger text sizes.
- PWA: manifest, icons and an app-shell service worker, so it works as a home-screen app on iOS. Web push
  is available on iOS 16.4+ for installed web apps, if wanted.
- Show data freshness everywhere ("Calendar synced 12 min ago").
- Empty states that teach ("Import your first statement").
- Plain-English microcopy. Amounts always show £ and a sign; colour is never the only cue.

---

## 10. Delivery plan

| Phase | Scope | Done when |
|---|---|---|
| 0. Decide and verify (days) | Answer §11; verify the remaining hosting items; create the site and database; CI/CD with an atomic deploy of a hello-world app; backups and heartbeat; hazard log v1 | CI deploys the hello page; a restore drill passes |
| 1. Walking skeleton | Login (passkey + TOTP); audit log; accounts; CSV import for the main bank with balance check and dedupe; Inbox review; HMRC categories; business, personal and transfer types; quarterly summary with CSV export | The 6 Oct–5 Jan quarter can be done in the app (deadline 7 Feb 2027) |
| 2. Ledger depth | Splits and business-use %; rules; duplicates UI; period close and lock; more bank profiles; OFX/XLSX; camera receipts; documents | A whole quarter closes at 100% reconciliation |
| 3. Jobs | Clients; jobs; calendar sync; job-matching suggestions; mileage and vehicles; job P&L | Expenses are suggested to jobs, with reasons |
| 4. Travel | Read-only Gmail connection; booking and travel email extraction for your providers; hotel stays night by night; journeys; optional TfL journey-history import; trip view and per-trip confirmation; gap checks | Every hotel and travel payment is linked to a job's trip or proposed as personal, with reasons |
| 5. Invoicing | Numbering; templates; PDF; credit notes; payment matching including partial payments; ageing; reminders | Payments reconcile against invoices automatically |
| 6. Tax and reports | Tax-year summary; SA103 mapping; trading-allowance comparison; tax estimate and set-aside; P&L; accountant pack (Excel/CSV/PDF plus receipts zip) | The accountant can work from the export alone |
| 7. Other email receipts | Receipts and invoices from known senders via the Gmail connection (or the forwarding mailbox); attachment extraction; matching | Emailed receipts attach themselves |
| 8. Automation and extras | Recurring expenses; smarter rules; PDF statements; optional AI extraction; optional Open Banking; optional direct MTD API | No fixed exit criterion |

---

## 11. Open questions for the owner

1. **Qualifying income** (gross turnover) for 2024-25, and roughly for 2025-26? This decides whether MTD is
   already live for you, in which case the Q1 and Q2 2026-27 updates are outstanding, or whether it starts
   in April 2027 or 2028.
2. Are you **VAT-registered**, or likely to be?
3. Which **banks and cards** do you use, and which export formats do they offer?
4. **Gmail access.** Is it a personal account or Google Workspace? (Workspace allows an "Internal" OAuth
   app.) Are you happy for the app to hold read-only Gmail access, which is recommended for hotel checks,
   or would you rather use the forwarding mailbox?
5. Do you have an **accountant**, and which software do they use? This shapes the export format and the MTD route.
6. Are you a **Scottish taxpayer**? Scotland has different income-tax bands.
7. **Mileage:** do you use your own car, and do you claim simplified rates or actual costs?
8. Are you comfortable keeping this app on the **same hosting account** as your other sites, or should it get its own?
9. Your **home postcode**, and how far away a job usually is before you stay overnight (default: 50
   miles). This sets the "far from home" rule. The postcode goes into the app's settings, not this document.
10. Do clients also book **trains or taxis** for you, for example through a travel agent or a business Uber account?
11. Is your **TfL** travel on a contactless card registered to a TfL account? If so, its journey history can be imported.

**Answered (22 Nov 2026)**

- Typical hotel stay: one night per job.
- Booking providers: Booking.com, Hotels.com, Premier Inn, Travelodge, IHG.
- Some clients book hotels directly, and the owner doesn't pay them back, so those stays carry no expense.
- Travel to cover: Uber, Trainline, taxis, TfL and similar, compared against job dates.

---

## 12. Pre-go-live checklist

- [ ] `/.env`, `/storage`, `/vendor` and `/composer.json` are unreachable (automated test)
- [ ] HTTPS and HSTS on; security headers pass a header scanner
- [ ] Passkey, TOTP and recovery codes all tested, including recovery after a lost phone
- [ ] Backup-to-restore drill passes on a clean environment
- [ ] Heartbeat and uptime alerts fire (tested by disabling cron)
- [ ] Golden-file tests exist for every bank profile in use
- [ ] A full sample tax year reconciles to hand-checked figures
- [ ] `expose_php` off; `sodium` enabled; PHP version pinned
- [ ] No real financial data is loaded until every item above passes
