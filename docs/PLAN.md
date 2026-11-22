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
| Client-booked travel and recharges | Not covered | Client-booked flights and hotels carry no cost but anchor matching. Costs you pay can be marked rechargeable and tracked to reimbursement, with a claim pack | Clients book some travel, and some costs are claimed back |
| Control | "You approve uncertain classifications" | You have the final say on everything. Review every item, allocate to jobs by hand, get rule/matching/AI suggestions, and override any decision at any time; automation never overwrites your edits | The owner's requirement: a final manual override in every case |
| Navigation | 8 sections | 5 sections: Inbox, Money, Jobs, Invoices, Tax (plus Settings) | Email and Calendar are sources of data, not places you go |
| Phasing | Reporting in phase 6 | Catch-up first: past tax years' Self Assessment figures in phase 1, ready by mid-January 2027 | No returns filed yet; the 2025-26 return and payment are due 31 Jan 2027 |
| Security | HTTPS, 2FA, encrypted credentials | Adds passkeys, a threat model for a shared hosting account, backups encrypted to an offline key, CSV-injection-safe exports, re-auth for sensitive actions | |
| Reliability | Not specified | Hazard log, risk classes, invariants, golden-file tests, recompute-and-verify, atomic deploys with rollback, heartbeat monitoring | Borrowed from medical-device and games engineering (§8) |

---

## 1. Principles and measurable targets

When goals conflict, priority is: **correct money > security > ease of workflow > speed.**

- **You always have the final say.** Rules, matching and AI only *suggest*. You can review every item,
  allocate anything to a job by hand, and edit or override any decision at any time, whoever made it.
  Automation never overwrites something you set yourself.
- **Never silently guess.** Anything uncertain lands in the Inbox with a suggested answer. Nothing becomes
  "business expense" or "taxable income" without your confirmation, or a rule you have explicitly set to
  auto-confirm. Reports show unconfirmed amounts separately as "pending".
- **Every pound accounted for.** Each bank transaction must be fully allocated (business, personal,
  transfer, etc.) before its period can close.
- **Originals are sacred.** Imported files and rows are never edited or deleted. Corrections are new
  records with a reason.
- **Everything is undoable.** Before a period is locked, edits are direct. After it's locked you can still
  unlock it (with a reason, after re-authenticating) or post an adjustment.
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

**Framework options considered**

| Option | Verdict |
|---|---|
| **Laravel + Inertia/React** | **Chosen.** Native to PHP shared hosting; login, 2FA, queues, scheduler, encryption, migrations and testing built in; libraries exist for everything this plan needs (PDF, spreadsheets, money, iCal, Gmail, Claude). React gives the review screens an instant, app-like feel |
| Laravel + Livewire | Simpler (one language), but every click is a round trip to a shared server. That adds up over hundreds of review decisions per session |
| Laravel + Filament | The fastest way to build admin-style tables and forms, but looks like an admin panel and is weaker for custom mobile flows (swipe cards, trip timelines). Possible for Settings screens only, if time gets tight |
| Symfony, Slim or plain PHP | No advantage for one user; more to build by hand, especially security features |
| Node.js (Next.js and similar) | Runs on this plan, but as a long-lived process with shared limits, a 120 s request cap and one of the plan's Node slots already used by `rohleder.tech` |
| Django or Rails | Strong frameworks, but not supported as web apps on this shared hosting; they would need a VPS |

---

## 3. Architecture

### 3.1 Stack

| Concern | Choice | Notes |
|---|---|---|
| Framework | Laravel (current release) on PHP 8.4 | Fortify for login, TOTP and recovery codes; scheduler; queues; encryption; migrations |
| UI | Inertia + React + TypeScript + Tailwind + shadcn/ui (Laravel's official React starter kit) | App-like review screens: decisions apply instantly and sync in the background; keyboard shortcuts, swipe, large filterable tables. Built in CI with no server-side rendering, so there's no Node process on the server |
| Database | Hostinger MySQL/MariaDB, InnoDB, utf8mb4, strict mode | |
| Money | Integer pence (`BIGINT`) in the DB; `brick/money` in PHP | Never floats |
| Spreadsheets | OpenSpout for large reads/writes; PhpSpreadsheet only where formatting matters | Memory |
| PDF output | dompdf | Pure PHP |
| PDF input (phase 9) | smalot/pdfparser | Pure PHP |
| Calendar | sabre/vobject | ICS, including recurring events |
| Email | Gmail API via google/apiclient; PHP imap extension for the forwarding-mailbox alternative | Read-only |
| Geocoding | postcodes.io, or a local copy of the ONS Postcode Directory | UK postcodes to coordinates, for hotel/venue/home distances |
| AI (opt-in) | Claude via the official PHP SDK (`anthropic-ai/sdk`) | Queued jobs only; structured outputs; Message Batches for overnight runs (§6.8) |
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
| `allocations` | Explains a transaction: type (`business_expense`, `business_income`, `personal`, `transfer`, `drawings`, `capital_introduced`, `refund`, `loan`, `tax_payment`), HMRC category, job, `amount_pence`, business-use %, `vat_pence`, status (`suggested`/`confirmed`), decided by (`you`, `rule`, `matcher`, `ai`), `manual` flag (you set or changed it), confirmed at, reason, rule |
| `suggestions` | Every suggestion ever made, from any source (rule, matcher, AI), with its confidence and reasons, and whether you accepted, changed or rejected it. Feeds the accuracy figures and rule learning |
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
| `settings` | Home postcode, overnight-stay distance bands (default 12 and 40 miles), early-start and late-finish times, hotel and travel sender lists |
| `recharges` | Expense or trip items being claimed back from a client: client, job, status (`to_claim`, `claimed`, `reimbursed`, `not_reimbursed`), claim method (invoice or client expense form), linked reimbursement payment |
| `invoices`, `invoice_lines`, `payments`, `credit_notes` | `payments` links an invoice to bank transactions; partial payments allowed |
| `vehicles`, `mileage_trips` | Vehicle claim method (simplified mileage or actual costs, fixed per vehicle); trips with date, from/to, miles, job and a snapshot of the rate used |
| `periods` | Tax years and quarters. Tax-year status: `outstanding`, `in_progress`, `ready_to_file`, `filed` (date and HMRC reference), `amending`. Quarter status: `open`, `closed`, `submitted`. Lock metadata |
| `rules` | Supplier/description pattern mapped to category, job and business-use %; created from your confirmations |
| `audit_events` | Append-only: when, actor, action, entity, before/after JSON, reason, `prev_hash`, `hash` |
| `tax_parameters` | Rates, thresholds and rules per tax year (for example, whether cash basis is the default and whether Class 2 NIC applies), each with a source URL and effective dates |
| `guidance` | Plain-English notes for each judgement call (mileage, travel, use of home, recharges…), each with a GOV.UK link and a "last checked" date |
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
- **Automation never writes over a manual decision.** Any field you set or change is flagged `manual`.
  Rule changes, re-matching and AI runs skip it; they can only add a new suggestion alongside it.
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

### 5.1 Catching up: any outstanding tax year

The owner has been self-employed since the early 2000s, has a UTR and has filed before, and there is no
accountant. The app's first job is to clear the backlog efficiently; after that, it keeps the books up to
date as you go.

**Any year, with that year's own rules**

- **Catch-up works on any tax year,** past or current. Mark a year as outstanding and it gets its own
  catch-up flow. Several years can be in progress at once. The app suggests working oldest first, because
  the oldest year's penalties grow fastest.
- **Each transaction belongs to exactly one tax year by its date,** so a CSV that spans 5 April is split
  across the two years automatically.
- **Each year uses its own rules,** taken from `tax_parameters`, not today's rules. For example, cash
  basis became the default only from 2024-25 (earlier years used accruals unless you elected cash basis);
  Class 2 National Insurance applied up to 2023-24; and 2023-24 was the basis-period transition year
  (relevant only if your accounting year doesn't end between 31 March and 5 April). If a year's rates
  aren't loaded, the app still produces its return figures but won't estimate the tax until they are.
- **A filed year can be reopened to prepare an amendment.** HMRC allows amendments up to 12 months after
  the 31 January deadline. The original figures are kept alongside the amended ones.
- **Year status:** outstanding, then in progress, ready to file, filed (with date and HMRC reference), and
  amending if reopened.

**Your outstanding years now** (deadlines for online returns)

| Tax year | Return and payment due | Position on 22 Nov 2026 |
|---|---|---|
| 2024-25 (6 Apr 2024 – 5 Apr 2025) | 31 Jan 2026 | Overdue. Penalties so far: £100 straight away; £10 a day from 1 May 2026, up to £900; then the greater of 5% of the tax due or £300 from the end of July 2026. Another 5%-or-£300 penalty is added after 31 Jan 2027, so file this one first. Interest and late-payment penalties also run on unpaid tax |
| 2025-26 (6 Apr 2025 – 5 Apr 2026) | 31 Jan 2027 | Due in about 10 weeks |

Check the exact amounts with HMRC's penalty estimator. HMRC can cancel penalties if you had a reasonable
excuse. If HMRC has sent an estimated bill (a "determination") for 2024-25, filing the return replaces it.

**Before the app is ready**

- **Fallback.** If the app isn't producing figures by about 10 January 2027, export what has been
  categorised to a spreadsheet and file from that. The app must never put the deadline at risk.

**What the app produces for each tax year**

- A **return summary**: the figures to type into HMRC's online Self Assessment, box by box (turnover,
  other business income, allowable expenses, net profit). With turnover under £90,000, the short
  self-employment pages accept a single expenses total, but the app still keeps the categories behind it.
- A **tax estimate**: income tax and Class 4 National Insurance.
- A **bill preview**: the year's tax, minus payments on account you've already made for that year, plus
  the payments on account HMRC will ask for towards the next year (half of the year's bill each on 31 Jan
  and 31 Jul, if the bill is over £1,000 and less than 80% was collected at source). Payments to HMRC are
  found in your bank data and recorded against their tax year; you can also enter them by hand. Your HMRC
  online account shows the authoritative balance.
- **Checks before you file:** every account has data for every month (no gaps), transfers and card-bill
  payments are paired, nothing is undecided, and the figures are compared with the previous year (from
  the app, or that year's filed figures entered once), with big changes flagged.

HMRC's own online calculation is the authority; the app's estimate is a cross-check. The app doesn't file returns.

**Without an accountant**

- Every judgement call (mileage, use of home, travel and subsistence, frequent venues, recharges, capital
  items) has a short plain-English note in the app and a link to the relevant GOV.UK page. These are
  stored in a `guidance` table with a "last checked" date.
- Wherever this plan says something needs checking, the app flags it for *you*, with that note and link.
- Consider a one-off review by an accountant or tax adviser for these two returns, especially the late 2024-25 one.
- The **records pack** export (all transactions, decisions and receipts) is kept ready for any HMRC enquiry.

### 5.2 Making Tax Digital for Income Tax

- **Your position.** Turnover is under £50,000, so MTD doesn't apply for 2026-27. It may apply soon after:
  - from **6 April 2027** if 2025-26 *turnover* (gross income before expenses, not profit) was over £30,000;
  - from **6 April 2028** if 2026-27 turnover is over £20,000.

  The 2025-26 figures from phase 1 settle the first question. The dashboard shows each tax year's
  turnover against these thresholds and says which MTD start date applies; HMRC also writes to confirm.
  If it's April 2027, the first quarterly update (6 Apr – 5 Jul 2027) is due 7 Aug 2027, so phase 2's
  quarterly summary must be ready by then.
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
    as CSV into HMRC-recognised bridging or MTD software that you operate, which submits them.
  - **B. Later, if worthwhile:** call HMRC's MTD API directly. That needs HMRC Developer Hub registration,
    OAuth with HMRC, mandatory fraud-prevention headers and HMRC production approval, which is a lot of
    work for a single user.
- **Penalties.** HMRC will not apply penalty points for late quarterly updates in 2026-27. Penalties for
  the final declaration and for late payment still apply.

### 5.3 Categories

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

### 5.4 Other rules

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
  the app flags these for you to check.
- **Credit cards:** a purchase on a credit card counts as paid on the card transaction date (one of the
  dates HMRC accepts under the cash basis), not when the card bill is paid. Paying the card bill from the
  bank is a transfer between your own accounts, never an expense. Card interest and fees are allowable
  only for business borrowing, in proportion on a card used for both.
- **VAT:** you aren't VAT-registered. The dashboard tracks taxable turnover over the last 12 months
  (recharges included) against the registration threshold (£90,000) and warns at 80%. If you register
  later, VAT tracking switches on and MTD for VAT applies, with its own submission route.
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

**Your accounts** (these may change, so adding or swapping an account must need no code change):

| Account | Import | Checks and quirks |
|---|---|---|
| Starling business account | CSV export, which usually includes a running balance | Balance check on every row. Starling's own spending categories are kept as a matching hint. Starling also offers a developer API with personal access tokens (read-only scopes such as `transaction:read`); confirm it covers business accounts before relying on it, and consider it for automatic daily import later |
| Amex credit card | CSV export | No running balance: check against each statement's closing balance, entered once a month. Amex shows charges as positive and payments as negative, so the profile flips the sign |
| Capital One credit card | CSV export (check which formats are offered) | Same statement-balance check; sign convention set in its profile |

Card-bill payments from Starling to Amex or Capital One are paired automatically with the "payment
received" line on the card, as a transfer. A new bank or card is added with a column-mapping wizard: map
the columns once, check a preview, save the profile.

- **Per-bank import profiles** record the column mapping, date format, sign convention and encoding. A
  profile is saved after the first import from a bank and auto-detected on later files.
- **Order of support:** CSV, then OFX/QIF, then XLSX, then PDF.
- **Balance check:** where the file has balances, opening balance plus the sum of amounts must equal the
  closing balance and every running balance. Otherwise the import is held for review. This makes parsing
  errors visible instead of silent.
- Overlapping statements are expected and safe (see the dedupe fingerprint in §4).
- **PDF statements (phase 9):** per-bank parsers in pure PHP, or AI extraction (§6.8). Both must pass the
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

### 6.4 Suggestions: rules, matching and AI

Three layers propose an answer for each item, and you make the final decision.

1. **Your rules,** for example "Adobe → Software, 100% business". A rule pre-fills the answer and marks it
   *suggested by rule*.
2. **Matching** (deterministic and explainable) scores candidate jobs on:
   - transaction date within the job's date range plus its travel window;
   - a location in the description that matches the event location;
   - a booking reference in an email;
   - an amount that matches a known booking.
3. **AI suggestions** (opt-in, §6.8) handle items the first two can't settle: free-text descriptions,
   unfamiliar merchants, and items that could belong to more than one job.

Each suggestion shows its source, confidence and reasons, for example "Premier Inn · during Job #42 ·
booking email 3 Oct · matching (high)". When sources disagree, both are shown. Below the confidence
threshold there is no suggestion at all, only the Inbox item.

**What you can always do**

- Accept a suggestion in one tap, change any part of it, or reject it and decide yourself.
- Allocate anything to a job by hand, with or without a suggestion, including items automation never touched.
- Edit or override any decision later, whoever made it. Your edit always wins, and the change is logged
  with its reason.
- Re-run matching or AI on demand for one item, a selection or a whole period. A re-run only adds fresh
  suggestions; it never changes what you've set.

**Auto-confirm is off by default.** You can switch an individual rule to auto-confirm (a monthly phone
bill, say). Those items are still marked *auto-confirmed by rule*, stay visible in the review table, and
appear on the quarter-close sign-off list. Hotel stays and AI suggestions can never auto-confirm.

**Learning from your decisions.** When you change a suggestion, the app records what you chose. After
you've made the same choice three times, it offers to create a rule. Settings shows the acceptance rate for
each source (rules, matching, AI), so you can judge how far to trust each one.

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

1. **Job overlap.** A night between two working days (of the same job or of nearby jobs) is job-linked.
2. **Night before or after: distance from home plus the job's times.** Whether you stay over depends on
   how far away the venue is and when you start or finish (traffic included), so the rule combines both:

   | Venue distance from home | Night before the first day | Night after the last day |
   |---|---|---|
   | Under 12 miles | Needs review: a stay this close is unusual to claim | Needs review |
   | 12–40 miles | Business if the job starts early (default: before 08:00); otherwise needs review | Business if it finishes late (default: after 22:00); otherwise needs review |
   | Over 40 miles | Business | Business |

   In the 12–40 mile band the app never proposes *personal* just because the times don't fit, because
   traffic can justify a stay; it asks instead. When you confirm, a reason chip ("early start", "late
   finish", "traffic", "client asked") is saved with the decision for the audit trail. Distances are
   straight-line, the bands and times are settings, and start and finish times come from the job or its
   calendar event.
3. **Hotel location.** A hotel within 30 miles of the venue (default) supports business.
4. **Other signals.** These are shown but never decide a night on their own: a weekend or bank-holiday
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
  depends on the trip; the app flags it for you to check, with the HMRC guidance, and keeps the facts
  (nights, job, purpose) to support your decision.
- **Who paid.** You, or the client.
  - Some clients book hotels for you directly and you don't pay them back. Those stays carry no expense and
    nothing to declare; they're kept on the job record only.
  - Anything you pay yourself during a client-booked stay (parking, an evening meal, an upgrade) is still
    your expense. It's linked to the stay and reviewed like anything else.
  - If you pay and then claim the cost back from the client, it's a recharge (§6.9).
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
| TfL: Tube, bus, Overground, Elizabeth line | `TFL TRAVEL CH`: one charge per day, or several days combined | None | Paid by contactless debit card, with no TfL account. Matched by day (see "TfL without an account" below) |
| Coach | National Express, Megabus | Ticket email | |
| Parking, tolls, congestion and ULEZ charges | NCP, JustPark, RingGo, PayByPhone, Dartford Crossing, TfL road charges | Sometimes | Usually claimable alongside simplified mileage (the app links the HMRC guidance) |
| Parking fines and penalty charges | Council or operator name, "PCN" | Sometimes | **Never allowable.** Always classed as disallowable, with the reason shown |
| Fuel | Fuel-station names | None | Only for a vehicle on actual costs. With simplified mileage, fuel is personal and the app offers to log the mileage trip instead |
| Flights, car hire | Airline or hire company | Booking or itinerary email | Flights are usually booked by the client, so they carry no cost (§6.9). Flight times anchor taxi and train matching |

The bank descriptions are examples. Seed `merchant_patterns` with them, then refine from your real statements.

**TfL without an account.** You pay by contactless debit card, so statements show TfL charges but not the
journeys behind them.
- Each charge is assigned to its travel date: the card transaction date if your bank's export includes it,
  otherwise the day before it was posted.
- A charge on a job day, when the job or its travel is in London, is proposed as business. A charge on a
  day with no job is proposed as personal.
- Two cases go to review: a charge much larger than your usual daily TfL spend (it may cover several
  days), and a job day where you may also have made personal journeys. You can split the amount.
- Optional: registering the same debit card on the TfL website lets you download journey history. The app
  can import it to show each journey behind a charge.

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
   ordinary commuting rather than business travel. The app flags frequently visited venues for you to
   decide, with the HMRC guidance.
4. **Who paid.** As with hotels, travel a client books for you carries no expense. Costs you pay yourself
   may be rechargeable to the client (§6.9).

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

### 6.8 AI assistance (opt-in)

AI has two jobs:
- **Suggesting** how to allocate items: job, HMRC category, business or personal, split.
- **Extracting** fields from receipt photos, booking emails and PDF statements.

It never confirms anything and never edits a decision. It only adds suggestions, which you accept, change
or reject like any other.

**Provider and setup**

- Claude via the official PHP SDK (`anthropic-ai/sdk`, installed with Composer). Calls run in queued jobs,
  never in page requests, so a slow or failed call can't block a screen.
- Model: Claude Opus 5.5 (`claude-opus-5-5`), with effort set explicitly (start at `low` for routine
  suggestions, and keep it there only if the test set below shows quality holds).
- Structured outputs (a JSON schema in `outputConfig`). Every response must name a job from the candidate
  list (or "none"), a valid category, a business/personal/split decision, a confidence and short reasons.
  Anything that fails validation is discarded, and the item stays manual.
- Overnight runs use the Message Batches API, at about half the cost. "Suggest now" on a single item uses a
  normal request.
- Check for the `refusal` stop reason and enable the API's server-side fallback. On any error the item just
  has no AI suggestion; the manual flow is unaffected.
- A monthly spending cap in Settings, with usage shown on screen.

**What it sees (data minimisation)**

- Sent for a suggestion: date, amount, bank description, and the candidate jobs (dates, town, client name).
- Sent for extraction: only the document being read.
- Never sent: account numbers, your whole ledger, or emails not linked to the item.
- Every request is logged (what was sent, when, for which items) and viewable in Settings, next to Gmail's
  "what the app read" log.
- Suggestions and extraction have separate switches, and both stay off until you turn them on. Check the
  API account's data-retention settings before switching on.

**Checking it's helping**

- Every AI suggestion is stored with the decision you finally made, so Settings shows its acceptance rate,
  overall and by item type.
- A fixed test set (anonymised past items with your confirmed answers) is re-run before any prompt or model
  change. A drop in accuracy blocks the change.
- If the acceptance rate falls below a threshold (default 70%), the app tells you and switches AI to
  "show only": its suggestions appear but nothing is pre-filled.

Extraction results also pass the validation gates in §6.1 and §6.5 (balance check, amount match).

### 6.9 Client-booked travel and recharges

**Client-booked travel.** Clients sometimes book transport for you, mostly flights (and some hotels, §6.5).
- Itineraries arrive by email from the client or their travel agent. The app reads them (schema.org
  `FlightReservation` markup first, then per-sender parsers) and adds them to the job's trip at no cost.
- Flight times then anchor the trip. A taxi to the airport before departure, or a train after landing, is
  matched to the job with the flight as its reason ("flight departs 07:10").
- Because the client paid, the flight carries no expense and nothing to declare.

**Recharging costs to a client.** You can sometimes claim back costs you paid yourself, such as a taxi to
the airport.
- Any expense, or a whole trip, can be marked **rechargeable to the client**. This is never assumed. The
  app only suggests it when that client has reimbursed similar items before, or booked the job's flights.
- Each recharge moves through *to claim*, then *claimed* (added to an invoice, or sent through the
  client's own expense process), then either *reimbursed* (the incoming payment is matched) or *not
  reimbursed* (it stays an ordinary business expense).
- **Claim pack:** for a client or job, a list of dates, amounts and reasons, plus the receipt images as one
  PDF, ready for the client's expense form.
- Outstanding recharges are listed per client with their age, and go to the Inbox once older than a set
  number of days (default: 30).
- **Tax treatment.** By default the cost is a business expense and the reimbursement is business income,
  so profit doesn't change. The reimbursement still counts towards turnover, which is what the MTD and VAT
  thresholds measure. Some items can be treated differently (for example, as disbursements); the app
  links the HMRC guidance so you can check.

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
  | Regular trips to one venue claimed as business travel | Frequent-venue flag, with HMRC guidance | 30 trips to one venue in a year |
  | Card-bill payment counted as an expense on top of the card purchases | Automatic transfer pairing between bank and card | Starling payment to Amex plus Amex purchases |
  | Card purchase put in the year the bill was paid | Card transaction date used | Purchase on 3 Apr, bill paid 20 Apr |
  | Amex amounts imported with the wrong sign | Sign convention in the import profile; statement-balance check | Amex CSV fixture |
  | Tax bill larger than expected | Bill preview, including payments on account already made and due | Year with a bill over £1,000 and part-paid payments on account |
  | Payment to HMRC counted as a business expense | HMRC payment pattern maps to `tax_payment`, recorded against its tax year | Starling payment to HMRC Self Assessment |
  | Missing month of statements in a catch-up year | Coverage grid; balance continuity between statements | Year with one month missing |
  | Older year worked out with today's rules | Year-specific rules in `tax_parameters`; no tax estimate without them | 2022-23 fixture |
  | Transaction counted in two tax years | Tax year derived from the posting date only | CSV spanning 5–6 April |
  | Driven job with no mileage claimed | "How did you get there?" gap check | Far-away job with no travel items |
  | Automation overwrites a decision you made | `manual` flag; re-runs only add suggestions | Re-run rules and AI over hand-edited items: nothing changes |
  | AI names a job or category that doesn't exist | Schema validation against the candidate list; invalid output discarded | Malformed and out-of-list responses |
  | AI unavailable or over budget | Suggestions are optional; the manual flow is unaffected | API errors; spending cap reached |
  | Auto-confirmed items never looked at | Auto-confirm off by default; quarter sign-off lists them | Quarter close with rule-confirmed items |
  | Reimbursed recharge missing from income | Reimbursement matched to its recharge items and recorded as income | Recharge claimed, then paid |
  | Recharge never claimed | Outstanding-recharges list and Inbox reminder | Recharge older than 30 days |
  | Multi-day TfL charge put on one day | Unusually large charges go to review | Charge covering three days |

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
2. **Money:** accounts, imports, and **Review all**: every transaction and its allocation in one table.
3. **Jobs:** clients, jobs, job P&L, mileage, a trip view for each job, and a year timeline of jobs and travel.
4. **Invoices:** create, send, track, credit notes.
5. **Tax:** quarters, tax-year summary, estimate and set-aside, exports.

**Settings** holds connections (bank profiles, receipts mailbox, calendar feed), categories, rules,
security and backups. A floating **+ Capture** button offers: snap receipt, add mileage, add cash expense,
new invoice.

### 9.2 Key flows

- **Catch-up mode (any outstanding tax year; oldest first is suggested).** A guided flow with a progress bar per year
  ("2024-25: 82% of value decided, 140 items left"):
  1. **Import.** Drop in every CSV for the year. A coverage grid shows each account month by month: green
     when the balance carries over, red where a statement is missing.
  2. **Big groups first.** Merchants are listed by total value. One decision covers every transaction from
     a merchant ("all Adobe: Software, business"; "all Tesco: personal"), and you pick out any exceptions.
     A few dozen decisions usually settle most of the year's value.
  3. **The rest, one by one.** Each item shows that day's calendar entries (and, once connected, matching
     emails and the AI suggestion), with keyboard and swipe shortcuts.
  4. **Checks.** Coverage complete, transfers paired, nothing undecided, comparison with the previous year.
  5. **Return figures.** The box-by-box summary and bill preview; then lock the year.
- **Monthly review (target under 10 minutes).** Import a statement. The Inbox shows each item as a card
  with the best suggestion pre-filled. Confirm it (tap or Enter), change it (category chips or number
  keys), split it, or mark it personal. An undo toast stays for 10 seconds.
  - Keyboard: J/K to move, Enter to confirm, P for personal, S to split, 1–9 for categories.
  - iPhone: swipe right to confirm, swipe left for personal.
- **Review everything.** The Review all table lists every transaction and its allocation for any period.
  - Filter by job, category, status (confirmed, suggested, unreviewed), who decided (you, rule, matching,
    AI), amount or merchant.
  - Each row shows the current decision and where it came from, with any differing suggestion alongside.
  - Edit inline, or select many rows and assign them to a job, a category or business/personal in one go.
  - Opening a row shows its full history: every suggestion and every change, with reasons.
- **Allocate to a job by hand.** Start from a transaction, from the review table, or from the job page
  ("Add expenses to this job"). The job page lists unallocated items around the job's dates first, then
  lets you search everything. You can split one transaction across several jobs, or between a job and
  personal, with live totals that must balance before you can save.
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
  5. You've looked over the sign-off list: everything confirmed by an auto-confirm rule or in bulk, plus
     totals by job and category. Anything on it can still be changed.

  Then lock the period and export the MTD summary and the records pack.
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
| 0. Decide and verify (days) | Confirm the setup defaults in §11; verify the remaining hosting items; create the site and database; CI/CD with an atomic deploy of a hello-world app; backups and heartbeat; hazard log v1 | CI deploys the hello page; a restore drill passes |
| 1. Catch-up returns (figures ready by mid-January 2027) | Login (passkey + TOTP); audit log; accounts; CSV import for Starling, Amex and Capital One with dedupe, balance or statement checks and automatic card-bill transfer pairing; Inbox and Review all with filters and bulk edit, including "decide for all transactions from this merchant"; HMRC categories; business, personal and transfer types; calendar context on each item (read-only iCal feed showing what was on that day); catch-up mode with coverage grid and big-groups-first review; HMRC payments recorded per tax year; return summary, tax estimate, bill preview and pre-filing checks per tax year; spreadsheet export. Stretch goal if time allows (otherwise phase 5): AI suggestions for category and business/personal | Any outstanding year's figures can be typed into HMRC online straight from the app, starting with 2024-25 and 2025-26 |
| 2. Ledger depth | Per-item history; splits and business-use %; rules (auto-confirm off by default); duplicates UI; period close and lock; quarterly MTD summary and export; column-mapping wizard for new accounts; OFX/XLSX; camera receipts; documents | A whole quarter closes at 100% reconciliation, ready for MTD if it applies from April 2027 |
| 3. Jobs | Clients; jobs; calendar sync; manual job allocation (from an item, the review table or the job page); job-matching suggestions; mileage and vehicles; job P&L | Expenses are suggested to jobs, with reasons |
| 4. Travel | Read-only Gmail connection; booking and travel email extraction for your providers; hotel stays night by night; journeys; client-booked flight itineraries; rechargeable items and claim packs; TfL charges matched by day (journey-history import optional); trip view and per-trip confirmation; gap checks | Every hotel and travel payment is linked to a job's trip or proposed as personal, with reasons |
| 5. AI assistance (opt-in) | AI suggestions for items rules and matching can't settle; AI extraction for receipts, booking emails and PDFs; request log, spending cap, acceptance tracking and test set | AI suggestions appear with reasons, never auto-confirm, and their acceptance rate is visible |
| 6. Invoicing | Numbering; templates; PDF; recharges as invoice lines; credit notes; payment matching including partial payments; ageing; reminders | Payments reconcile against invoices automatically |
| 7. Tax and reports | Full SA103 mapping; trading-allowance comparison; use of home; set-aside for tax; VAT-threshold monitor; P&L; records pack (Excel/CSV/PDF plus receipts zip) | Every figure on a return can be traced back to its transactions and receipts |
| 8. Other email receipts | Receipts and invoices from known senders via the Gmail connection (or the forwarding mailbox); attachment extraction; matching | Emailed receipts attach themselves |
| 9. Automation and extras | Recurring expenses; smarter rules; PDF statements; optional Open Banking; optional direct MTD API | No fixed exit criterion |

---

## 11. Setup choices and recorded decisions

The owner will import and enter their own data when running the app. No open question blocks the build.
The remaining choices are made in a **setup wizard** on first run; each has a default until changed, and
all can be changed later in Settings.

| Setting | Default until you change it |
|---|---|
| Amex and Capital One: business or mixed-use cards | Mixed use: each transaction decided individually; interest and fees go to review |
| Gmail connection | Read-only Gmail API (forwarding mailbox available instead) |
| Scottish taxpayer | No |
| Vehicles and mileage method | Added in setup; simplified mileage unless you choose actual costs |
| Home postcode | Entered in setup (stored in settings only) |
| Outstanding tax years | Chosen in setup (currently 2024-25 and 2025-26); more can be added at any time |
| Last filed year's figures | Entered in setup for the year before the oldest outstanding one, for the year-on-year check |
| AI suggestions | On (owner agreed) |
| AI extraction | On demand: a "Read with AI" button on each document |
| Hosting | Same hosting account as the other sites, with the §7 mitigations; can move later |

**Recorded decisions (22 Nov 2026)**

- Self-employed since the early 2000s, with a UTR. Only the 2024-25 and 2025-26 returns are outstanding.
- Goal: a slick app that clears the backlog efficiently, then keeps things up to date going forward.
- Catch-up must work on any outstanding year, not only the current two.
- VAT: not registered.
- MTD: turnover is under £50,000, so not in scope for 2026-27. It could apply from April 2027 (if 2025-26
  turnover is over £30,000) or April 2028 (over £20,000); the app checks this from the filed figures.
- Accounts: Starling business account; Amex and Capital One credit cards. These may change.
- Accountant: none.
- Typical hotel stay: one night per job.
- Booking providers: Booking.com, Hotels.com, Premier Inn, Travelodge, IHG.
- Some clients book hotels directly, and the owner doesn't pay them back, so those stays carry no expense.
- Travel to cover: Uber, Trainline, taxis, TfL and similar, compared against job dates.
- Overnight stays: considered for jobs about 12–40 miles away depending on start time and traffic; normal
  beyond 40 miles.
- Clients occasionally book transport, mostly flights. Other transport the owner pays for is sometimes
  recharged to the client.
- TfL: paid by contactless debit card with no TfL account, so statements show TfL charges only.
- AI suggestions: the owner agrees to minimised item details (date, amount, bank description, candidate
  jobs) being sent to the Claude API. Switch suggestions on during setup; the request log, spending cap
  and "never auto-confirm" rule still apply.
- Control: the owner must be able to review everything, allocate expenses to jobs by hand, and get AI
  suggestions, with a final manual override in every case.

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
