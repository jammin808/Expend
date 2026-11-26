# Expend intelligence cycle: the plan

Written against commit `cbe54d7`. Every file, table and column named below exists there unless it is
marked **(new)**. The base design is *unify in place*, which the judges scored highest for fit with the code (9, 9)
and incremental delivery (9, 9). Ideas are grafted in from *controlled autonomy* (control and safety: 9, 9) and
*evidence graph* (traceability: 9, 9). The judges' main correction is followed: the read-only money discoveries
come first, not last.

M1 to M7 are built. Where the build differs from the first draft (names, dates, contracts, settings), the sections
below say what was built, and each milestone's **As built** notes what was left or done differently. Law 4 (section
5.1) was amended on 24 November 2026 by the owner's mail and calendar decision (docs/mail-and-calendar.md).

**Income v3 and clients, jobs and invoices (built 25 Nov 2026)** change these parts, each marked where it is:
payer aliases are worked out, not stored (3.2); invoice matching is `Income\InvoiceMatcher`, with two new methods, a
tie rule and a match on issue (2.4, 4.2); `relate.sweep`, "Not this job" and the Verifier read income links (4.2,
4.3, 4.6); six new to-do keys and their finding codes, and `income.unlinked` version 2 (4.4); the new to-do actions,
the toast's Undo and the daily invoice nudge (4.5); charge statuses and draft invoices that follow the job (4.7);
`income.invoice` never acts (5.3, 5.4, M10); and the schedule entries outside the cycle (6.1).

---

## 1. The cycle in one picture

```
                 +----------------------- PRESERVE ------------------------+
                 | audit chain, decisions, "not this one", acts + undo,    |
                 | year snapshots, run seals, measured outcomes            |
                 +---------------------------^-----------------------------+
                                             |
 ACQUIRE --> RELATE --> VERIFY --> DETECT --> RECOMMEND --> owner decides (web, Telegram)
 bank CSV,   pairs,     grounding, findings   ranked to-do,   or policy acts, only for a
 Starling,   routes,    checks +   with refs, morning report, kind the owner switched on
 Gmail,      categories independence, money,  alerts,         and the record supports
 mailbox,    job links  reconcile  coverage   why-panels      |
 Telegram,   (proposals)            lifecycle                 v
 ICS, docs,      ^                                       PRESERVE --> back to RELATE
 HMRC rates      |                                       (vetoes stop repeats, outcomes
                 +---------------------------------------  measure every method)
```

Expend already does every stage somewhere. Imports, Starling, Gmail, the mailbox, Telegram and the calendar
acquire evidence. TransferPairer, Suggester, Categoriser, RuleLearner, JobFinder, JobGatherer and TravelMatcher
relate it. Grounding, Verifier, BalanceCheck, Reconciler and `AuditLog::verify` check it. TodoList and
FindingsRegistry find problems. TodoActions and Telegram recommend actions. AllocationWriter and the audit chain
keep decisions. What's missing is a shared language and a shared rhythm, so stages can't hand work to each other
and nothing can be measured. This plan keeps every table and service and adds four things:

- **One vocabulary:** evidence refs, statuses, grades and method names.
- **Findings that remember:** what was found, the evidence, the money, the direction, and why each one closed.
- **One resumable nightly run on the existing cron,** with a run log and a morning report.
- **A decision memory:** provenance, "not this one", acts that can be undone, and measured outcomes.

With these, discovery runs every night without the owner. Nothing changes the ledger except an owner tap or a
policy the owner switched on, and a policy acts only when the measured record supports it and every act can be
undone.

---

## 2. Shared vocabulary

Everything below lives in `app/Domain/Intel/` **(new)**. It is backed by PHP enums or constants, with a `Legacy`
map for every value already stored. A test fails if any stored value has no mapping.

### 2.1 Evidence references

One string format is used for storage, UI chips, Telegram, the assistant and the run log:

```
ref     = kind ":" id [ "#" locator ] [ "@" pin ]        at most 191 characters, so it can be indexed
locator = p3 (page) | l12 (line) | c120-188 (span of extracted text) | row41 | f=po_number
pin     = 8 hex characters: a prefix of the content hash the claim relied on
```

| kind | id | pin | Notes |
|---|---|---|---|
| `tx` | `bank_transactions.id` | none (bank lines are immutable) | `#row41` once `source_line` exists (M8) |
| `exp` | `manual_expenses.id` | sha1 of date, amount and description | editable, so pinned |
| `batch` | `import_batches.id` | `file_sha256` | |
| `acct`, `sbal` | `accounts.id`, `statement_balances.id` | none | |
| `doc` | `documents.id` | `documents.sha256` | facts are cited as `doc:55#f=rate@ab12cd34`, never by `document_facts.id` (rows are re-created on re-read) |
| `gmail` | `gmail_messages.message_id` | none | |
| `cal` | `calendar_events.event_key` | `calendar_event_versions.content_sha1` (M8) | `event_key` survives the hourly reload; row ids don't |
| `travel`, `job`, `jday`, `cand`, `jfind`, `trip`, `rule` | the row id | none | |
| `quick` | `quick_entries.id` | none | an expense or receipt from your phone (PLAN.md §6.12): "Your receipt from your phone · £12.50 parking · 25 Nov 2026", opening `/quick/entries/{id}` |
| `inv` | `invoices.id` | `pdf_sha256` once issued | |
| `check` | `verifications.subject_type:subject_id` | none | |
| `audit` | `audit_events.id` | `audit_events.hash` | self-verifying; **the way to cite a decision** |
| `fx` | `CUR:YYYY-MM:source`, e.g. `EUR:2025-04:hmrc_monthly` | none | `exchange_rates` |
| `law`, `taxp` | `resources/tax/law.json` id; `2025-26:personal_allowance` | `researched_on` | |
| `year` | `tax_years.label` | none | |
| `run`, `fnd`, `ask` | `cycle_runs.id`, `findings.id`, `assistant_logs.id` | none | |
| `set` | a settings key | none | never a key in `Setting::ENCRYPTED` |

Rules:

- **Never cite an unstable id.** `AllocationWriter::decide` deletes and re-creates allocations on every decision,
  so a decision is cited as `tx:381` plus `audit:9120@…`, never by allocation id. Document facts are cited by
  locator. Calendar entries are cited by `event_key`.
- A pin that no longer matches resolves as `source_changed` ("changed since this was relied on"). A missing
  target resolves as `source_missing`. Neither is an error.
- `RefResolver::resolveMany(array $refs)` makes one query per kind. For each ref it returns the label, link,
  whether the target exists, whether the pin still holds, and the latest check result.
- A ref list is stored as `text` cast to an array, at most 20 refs.

### 2.2 Statuses

| Name | Values | Legacy mapping |
|---|---|---|
| **Actor** | `you`, `rule`, `matcher`, `ai`, `policy`, `checker`, `system` | `rules` (JobFinder, CalendarSync, JobFinder links) becomes `matcher`; `server` becomes `system`. `decided_by` keeps its four values. |
| **Channel** | `web`, `telegram`, `schedule`, `queue`, `cli` | |
| **Proposal state** | `proposed`, `accepted`, `changed`, `rejected`, `superseded`, `withdrawn` | Allocations: `confirmed_at` null means proposed. Calendar links: `linked_by` rules/ai means an automatic link; `rejected[]` and `not_job` mean rejected. Documents: `job_linked_by`, `job_rejected`. Job finds: `possible`, `linked`, `rejected`. Candidates: `open`, `accepted`/`merged`, `dismissed`. Rule offers: `rule_offers_declined` means rejected. |
| **Check result** | `passed`, `partial`, `failed`, `not_checkable`, `unchecked`, plus **independence** 0-3 | `verified` is passed (independence 1 for `source_text`; 2 for `gmail_refetch`, `records`, `calendar`). `second_read_agreed` is passed with independence 0, because the same model saw the same text and the claims. `partly` is partial. `mismatch` and `second_read_disagreed` are failed. `unverifiable` is not_checkable. `source_missing` is failed (source missing). `document_facts.verified` true is passed (1), false is unchecked. `import_batches.balance_check` passed/failed/not_available map to passed (2)/failed/not_checkable. Reconciler method `none` is not_checkable. |
| **Independence** | 0 self (same model, same text), 1 same source re-read, 2 independent source, 3 the owner | |
| **Finding state** | `open`, `snoozed`, `dismissed`, `resolved`, `unseen` | Null means legacy: read from `resolved_at` and `resolution`. |
| **Finding events** | `detected`, `changed`, `snoozed`, `snooze_ended`, `dismissed`, `reopened`, `resolved`, `unseen`, `detector_failed`, `rekeyed` | |
| **Coverage** (per detector run) | `complete`, `capped`, `failed` | |
| **Money direction** | `owed_to_you`, `owed_by_you`, `unrecorded`, `at_risk`, `claimable`, `none` | Totals are never added across directions or currencies. A duplicate's amount is (n − 1) × the charge. |
| **Severity** | `critical`, `important`, `info` | PLAN.md §8.1's three alert levels; mapped to to-do priority 1, 2, 3 |
| **Run status / step status** | `running`, `ok`, `partial`, `failed`, `abandoned` / `pending`, `running`, `ok`, `more`, `budget`, `failed`, `skipped` | |
| **Autonomy level** | 0 off, 1 watch, 2 suggest, 3 act and tell, 4 act quietly | Section 5 |

### 2.3 Confidence: graded from evidence, measured from outcomes

Expend does not invent probabilities. There are two kinds of confidence and both are shown as a **grade**:
`certain`, `strong`, `likely`, `possible`, `weak` or `unmeasured`. The basis is always shown next to the grade.

**(a) Evidence grade, for findings and for links that rest on evidence.** Rule-based, deterministic and
unit-tested in `Intel\Grades`. The grade is that of the weakest evidence the claim cites.

| Grade | When |
|---|---|
| certain | Only confirmed records and arithmetic: bank lines, owner-decided or confirmed allocations, issued invoices, filed figures |
| strong | Also relies on an owner-confirmed link, or an AI-read fact whose check passed with independence of at least 1 |
| likely | Relies on an unconfirmed automatic link, or an estimate (postcode distance × `JobTrips::ROAD_FACTOR`) |
| possible | Relies on an unchecked AI value, or an ambiguous match whose runner-up is within the method's margin |
| weak | Relies on failed, contradicted or missing evidence. Shown only in the `checks` and `health` groups |

**No laundering.** A claim can never grade higher than any unconfirmed machine proposal it cites. An unconfirmed
machine conclusion is never evidence for an automatic act.

**(b) Measured confidence, for machine methods (suggestions and acts).** This applies per method, per method
version and per score band (for example `transfer.pair` with strength 6 or more). It is the **one-sided 95%
Wilson lower bound** (z = 1.645) of correct ÷ (correct + wrong) over the last 365 days.

- When there are at least 10 blind outcomes, the measured figure is the lower of the bound over all outcomes and
  the bound over blind outcomes. Blind outcomes counter anchoring: an owner tends to accept what is shown.
- **Correct:** accepted unchanged; an act kept for 60 days.
- **Wrong:** changed, rejected, overridden, undone, or reversed within 180 days.
- **Neutral:** superseded, withdrawn, or expired (not looked at in 90 days).
- **Blind outcomes** come from two sources:
  - backtests over the owner's catch-up decisions, for methods whose inputs carry timestamps;
  - hold-backs, where 1 in 10 proposals of a kind under consideration is computed but not shown (M10).
- **Grades from the bound:** certain ≥ 98%, strong ≥ 95%, likely ≥ 85%, possible ≥ 60%, otherwise weak.
  Under 30 outcomes the grade is `unmeasured`.
- **Worked figures** (with every outcome correct, the bound is n ÷ (n + 2.706)): 25 outcomes give 90.2%,
  30 give 91.7%, 52 give 95.0%, and 133 give 98.0%.
- **Outcomes come only from honest actors.** Audit events written before M2 ships (when `confirm()` and `clear()`
  logged automation as `you`) count as method version 0. They are shown as history and never used for gating.

**Calibration and ranking.**

- **Detectors** are calibrated from the owner's dismissal reasons (`Intel\DetectorLevels`, built in M4). "Not a
  problem", "Not mine" and "Both charges were real" count as false positives; the owner's own words ("Other") never
  do. The findings run (`FindingsRegistry::sync`) demotes a kind shown in full to `info`, below the fold:
  - a kind M4 added, once the owner has raised it: more than one false positive in ten, out of at least 10 findings
    seen since it was raised (or in the last 90 days, if shorter);
  - a kind shown in full before M4 (M1's three and `tx.duplicate`): at least 10 dismissals in 90 days, more than half
    of them false positives.

  Never on fewer than two false positives. Health, deadlines, fines and held statements are never demoted. A
  demotion is kept in `intel_detector_levels`, audited as `detector.demoted` by `system`, named once in the next
  morning report (`DetectorLevels::demotedSince`), and stays until the owner raises the kind again.
- **Ranking** uses fixed, documented weights per grade: certain 1.0, strong 0.9, likely 0.7, possible 0.4,
  weak 0.1, unmeasured 0.5.
- **No numeric probability** is shown or stored until it has been measured.

### 2.4 Methods

`Intel\Methods` is a registry of dotted names. Each entry has an actor, a basis (`exact`, `rule`, `heuristic`,
`ai_grounded`, `ai_ungrounded`, `owner`), the decision kind it serves (section 5), a version integer that is bumped
whenever its logic changes, the bands of its native score, and a **legacy recogniser** (`decided_by` plus a reason
prefix) for rows written before the method column existed.

| Method | Code today | Native score kept | Legacy recogniser |
|---|---|---|---|
| `transfer.pair` | `TransferPairer::pairAll` | `strength()` 0-10, tie flag | matcher + "Matches … moving between your accounts" |
| `transfer.one_sided` | `TransferPairer::suggestOneSided` | `OwnAccounts::reason` kind | matcher + OwnAccounts reasons |
| `transfer.partner` | `TransferPairer::afterYourDecision` → `confirm()` | n/a | `allocation.confirmed` right after the partner's owner decision |
| `revolut.exchange` | `RevolutImport` (exchange legs) | exchange key | matcher + its reason |
| `suggest.invoice` | `Suggester::suggestFor` (invoice branch). **As built (income v3):** version 2, written only by `Income\InvoiceMatcher`'s exact level: the only open invoice whose amount still to come is the payment ("Matches what's still to come on INV-0042 (£450)") | how many open invoices had that amount still to come | matcher + "Matches invoice " (v1) |
| `suggest.invoice_set` **(income v3)** | `InvoiceMatcher`'s set level: 2 to 4 of the payer's sole client's oldest 12 open pound invoices that add up to the payment, when exactly one combination does ("INV-0041 + INV-0043 add up to £1,350") | how many of the client's open invoices were searched | n/a |
| `suggest.invoice_net` **(income v3)** | `InvoiceMatcher`'s net level: one invoice, or 2 to 4, with a fee or commission taken off (the bank's own pound fee, `RevolutImport::poundFee`, or the payer's usual: 2 of its last 5 receipts you decided with a deduction agreeing within 1p or 10 basis points); never for a payslip-like payer | how many open invoices were tried | n/a |
| `suggest.hmrc`, `suggest.penalty`, `suggest.car_hire`, `suggest.travel` | `Suggester` branches | pattern | matcher + "Looks like a payment to HMRC" / "Looks like a fine" / "Looks like car hire" / travel reason |
| `categorise.merchant`, `.similar`, `.bank_learned`, `.hint`, `.bank_hint` | `Categoriser::propose` (`source` already returned) | n of m | matcher + `Categoriser::REASONS` (minus Suggester's) |
| `rule.learned`, `rule.owner` | `RuleLearner::accept`, `DecisionService::apply` | rule id | rule + `rule.learned` audit event |
| `travel.match` | `TravelMatcher::suggest` | night verdicts | matcher + travel reasons |
| `jobs.calendar_rules`, `jobs.calendar_ai`, `jobs.document`, `jobs.travel`, `jobs.invoice_po`, `jobs.gather` | `JobFinder::linkCalendar/linkDocument/linkTravel/followUp`, `JobGatherer` | `workScore`, margin | `linked_by` rules/ai; "Same PO number as the job" |
| `doc.read`, `travel.read` | `DocumentReader`, `TravelReader` | grounded or not | `documents.extraction_model` |
| `ai.advisor`, `ai.assistant` | `TransactionAdvisor`, `AssistantTools` `propose_decision` | n/a | ai + reason |
| `receipt.match` **(new, M4)** | extracted from `TransactionController` (lines 133-145) | amount, days apart | n/a |
| `receipt.quick` **(quick add)** | `ReceiptAttacher::attach`: your business-card receipt from the phone on its bank line, offered with your category, job and share (`AllocationWriter::PRECEDENCE` 7, above rules, below you at 8) | days between the receipt and the payment | matcher + "From your receipt photo" |
| `quick.maybe_card` **(quick add)** | a veto method only: "two payments" answered on a cash or personal-card cost against a card line | n/a | n/a |
| `detector.<code>` | section 4.4 | n/a | n/a |

---

## 3. Data model changes

### 3.1 Rules for every migration

- Additive only: new tables, or nullable columns with no default **appended without `->after()`**, so MariaDB adds
  them instantly. Old code runs on the new schema, and every milestone can be rolled back by redeploying the
  previous release. For the audit chain this needs one extra step: M6 writes audit events the old (v1) way until
  `settings.intel_audit_context` is on, because a release before M6 would read a v2 event as a broken chain. M7
  turns it on (section 3.3); from then on, M6 is the oldest release to roll back to.
- **No `json()` on existing tables.** On MariaDB, Laravel's `json()` becomes `JSON`, which is `LONGTEXT` plus a
  `json_valid` CHECK, and adding a CHECKed column can force a table copy. Lists are `text` cast to `array` in the
  model. New tables use `text` too, so hashed content is always canonical JSON produced by PHP.
- Hex and ref columns are ASCII and canonical case, so MariaDB's case-insensitive collation is harmless.
- **Backfills never run inside migrations.** Deploys run `migrate --force` from a temporary cron. Backfills are
  idempotent, chunked (500 rows) `ledger:intel-backfill {name}` commands, scheduled hourly until
  `settings.intel_backfills_done` lists them. They leave a value null when unsure.
- File names sort after `2026_12_03_000002_add_payment_plan_and_amendment_facts_to_tax_years.php`, and after every
  migration already shipped, so a fresh install runs them in the order they shipped. M5's migration is dated after
  M6's for that reason (section 3.2).

### 3.2 New tables

**`finding_events`** (M3, `2026_12_10_000001_findings_lifecycle.php`). Append-only: the model throws on update
and delete.

| Column | Type |
|---|---|
| id | bigint |
| finding_id | FK `findings`, restrictOnDelete |
| event | varchar(16) (section 2.2) |
| amount_pence | bigint null |
| currency | char(3) null |
| cause_ref | varchar(191) null (`batch:7`, `audit:123@…`, `run:44`, `fnd:12`) |
| run_id | bigint unsigned null |
| actor | varchar(10) |
| note | varchar(255) null |
| created_at | timestamp |

Index: (finding_id, id).

**`cycle_runs`** (M5, `2026_12_16_000001_create_cycle_runs.php`: M5 shipped after M6, so it is dated after M6's
migrations). Index: (status, id).

| Column | Type |
|---|---|
| id | bigint |
| run_key | varchar(40), unique (`nightly:2026-12-14`, `morning:…`, `import:batch:91`, `manual:<ulid>`) |
| kind | varchar(10): `nightly`, `morning`, `import`, `manual` |
| mode | varchar(8): `on`, `shadow` |
| status | varchar(10) |
| started_at; heartbeat_at; deadline_at; finished_at | timestamps (finished_at null) |
| ai_budget_micro; ai_spent_micro | bigint unsigned default 0 |
| counts | text null (array) |
| report | text null (array: the rendered morning report data) |
| reported_at | timestamp null |
| digest | char(64) null: sha256 of the open findings at the seal |
| seal_audit_id | bigint unsigned null |
| error | varchar(1000) null |
| created_at, updated_at | timestamps |

**`cycle_run_steps`** (M5, same file):

| Column | Type |
|---|---|
| id | bigint |
| cycle_run_id | FK, restrictOnDelete |
| step | varchar(40) (`relate.transfers`) |
| stage | varchar(10) |
| seq | tinyint unsigned |
| status | varchar(10) |
| attempts | tinyint unsigned default 0 |
| cursor; counts; scope | text null (arrays) |
| coverage | varchar(10) null |
| detector_version | smallint unsigned null |
| started_at, finished_at | timestamp null |
| error | varchar(1000) null |
| created_at, updated_at | timestamps |

Unique: (cycle_run_id, step).

**`vetoes`** (M7, `2026_12_16_000002_create_vetoes.php`, numbered after M5's migration of the same date). "Not this
one" for links that have no rejection store today.

| Column | Type |
|---|---|
| id | bigint |
| veto_key | char(64), unique: sha256 of subject, relation, object, shape and method (portable, unlike NULLs in a unique index) |
| subject_ref | varchar(191), indexed |
| relation | varchar(24): `allocation_shape`, `transfer_pair`, `invoice_payment`, `travel_payment`, `invoice_job`, `mileage_day`, `receipt_payment` |
| object_ref | varchar(191) null |
| shape | char(16) null: hash of kind, category, business % and job |
| method | varchar(40) null; null blocks every automatic method |
| effect | varchar(6): `block` (never propose) or `ask` (propose, never act) |
| reason_code | varchar(16) null |
| reason | varchar(255) null |
| audit_event_id | bigint unsigned null |
| lifted_at | timestamp null |
| created_at, updated_at | timestamps |

The existing rejection stores stay authoritative for their links: `calendar_job_links.rejected`/`not_job`,
`documents.job_rejected`, `job_finds.status = rejected`, `job_candidates` dismissed, and
`settings.rule_offers_declined`. `Vetoes::allows()` reads them through adapters. Nothing is copied.

**`link_provenance`** (M6, `2026_12_14_000002_create_link_provenance.php`). The "why" of non-allocation links.
Written in the same transaction as the native link. It is **ignored unless its `object_ref` equals the native
column**, so it can never become a second source of truth.

| Column | Type |
|---|---|
| id | bigint |
| link | varchar(16): `calendar_job`, `document_job`, `travel_job`, `travel_payment`, `invoice_job`, `receipt_payment` |
| subject_ref | varchar(191) |
| object_ref | varchar(191) null |
| method | varchar(40) |
| method_version | smallint unsigned |
| basis | varchar(16) (`ai_ungrounded` is 13 characters) |
| score | smallint null |
| evidence | text null |
| alternatives | text null (array of {ref, score}: the runner-ups) |
| run_id | bigint unsigned null |
| correlation | char(26) null |
| created_at, updated_at | timestamps |

Unique: (link, subject_ref).

**`calendar_event_versions`** (M8, `2026_12_18_000001_acquisition_evidence.php`). `CalendarSync::load` deletes
and reloads `calendar_events` every hour and keeps only the summary and location, so a cancelled or moved job is
invisible today.

| Column | Type |
|---|---|
| id | bigint |
| event_key | char(40), indexed |
| uid | varchar(255) |
| sequence | int null |
| status | varchar(12) null (`CONFIRMED`, `TENTATIVE`, `CANCELLED`) |
| summary | varchar(250) |
| location | varchar(250) null |
| description | text null (first 4,000 characters) |
| starts_at, ends_at | datetime (ends_at null) |
| content_sha1 | char(40) |
| first_seen_at, last_seen_at | timestamp |
| missing_since | timestamp null |
| ai_verdict | text null: JobFinder's AI verdict, today only in `Cache::put(..., 90 days)` |
| created_at, updated_at | timestamps |

Unique: (event_key, content_sha1).

**`document_arrivals`** (M8, same file). Every arrival is recorded, even when `DocumentIntake` finds the bytes
already stored (today the second arrival of a PO loses its sender and date).

| Column | Type |
|---|---|
| id | bigint |
| document_id | FK, cascadeOnDelete |
| channel | varchar(10): `upload`, `email`, `gmail`, `telegram` |
| external_id | varchar(191) null (Message-ID, Gmail id, `tg:chat:message`) |
| sender | varchar(300) null |
| subject | varchar(500) null |
| occurred_at | timestamp null: the source's own date |
| observed_at | timestamp |
| created_at | timestamp |

Unique: (channel, external_id).

**`autonomous_acts`** (M9, `2026_12_20_000001_create_autonomous_acts.php`):

| Column | Type |
|---|---|
| id | bigint |
| kind | varchar(40), indexed |
| method | varchar(40) |
| level | tinyint unsigned |
| subject_ref | varchar(191), indexed |
| object_ref | varchar(191) null |
| before, after | text (canonical snapshots) |
| evidence | text null |
| gates | text null: each gate and its result |
| amount_pence | bigint null |
| run_id | bigint unsigned null, indexed |
| correlation | char(26) null |
| audit_event_id | bigint unsigned null |
| recheck | varchar(12) null: last sampled re-check result |
| reviewed_at, overridden_at, undone_at | timestamp null |
| undone_by | varchar(10) null |
| undo_reason | varchar(255) null |
| created_at, updated_at | timestamps |

Unique: (kind, subject_ref, run_id). The `run_id` of an act is never null: acts happen only inside cycle runs.

**`proposal_outcomes`** (M10, `2026_12_22_000001_create_proposal_outcomes.php`). Written **only for the methods
under consideration for autonomy**: `transfer.pair`, `suggest.hmrc` and `suggest.invoice` at first. This is not a
mirror of every machine write.

| Column | Type |
|---|---|
| id | bigint |
| method | varchar(40) |
| method_version | smallint unsigned |
| band | varchar(16) |
| subject_ref | varchar(191) |
| shape | char(16) |
| visibility | varchar(8): `shown`, `blind`, `backtest` |
| run_id | bigint unsigned null |
| proposed_at | timestamp |
| outcome | varchar(8) null: `correct`, `wrong`, `neutral` |
| outcome_cause | varchar(16) null |
| outcome_ref | varchar(191) null |
| decided_at | timestamp null |

Unique: (method, subject_ref, shape, visibility). Index: (method, method_version, band, outcome).

**`payer_aliases`** (M10, same file). Learned **only** from owner-confirmed income.

| Column | Type |
|---|---|
| id | bigint |
| counterparty_key | varchar(120) |
| client_id | FK, cascadeOnDelete |
| confirmations | smallint unsigned |
| last_tx_id | bigint unsigned |
| created_at, updated_at | timestamps |

Unique: (counterparty_key, client_id).

**As built (income v3, 25 Nov 2026): not a table.** `Income\PayerAliases` works out who pays for whom from your
decisions each time it's asked, in one grouped query for a page or a run (`PayerAliases::forKeys`): confirmed
business income on active statements, counted by payer (the line's `merchant_key`) and the line's worked-out client
(`Income\IncomeClient`: its invoice's client, else its job's, else the line's own `allocations.client_id`), each
payment once. A line counts when you made it, or when you accepted it and its client comes from its invoice or job;
never a client that automation filled in from these very counts and you merely accepted. Rule-confirmed lines, waiting
suggestions, money paid back, voided lines and "UNKNOWN" payers don't count. A payer who has paid for more than one
client is shared, and `sole()` names no client for it. Nothing is migrated or backfilled, and a merge or a corrected
decision shows at once. The client page lists them read only ("Pays you as ACME PAYROLL (5 payments)",
`PayerAliases::keysFor`).

### 3.3 Columns added to existing tables

| Table | Columns (all nullable, appended) | Milestone |
|---|---|---|
| `findings` | `code` varchar(40) idx; `detector_version` smallint unsigned; `subject_ref` varchar(191) idx; `scope_ref` varchar(191) idx (`job:14`, `acct:3`, `year:2024-25`); `evidence` text; `direction` varchar(12); `grade` varchar(10); `severity` varchar(10); `due_on` date; `state` varchar(10); `note` varchar(255) (the owner's reason); `resolution_ref` varchar(191); `last_run_id` bigint unsigned | M3 |
| `allocations` | `method` varchar(40); `method_version` smallint unsigned; `basis` varchar(16); `score` smallint; `evidence` text; `alternatives` text; `run_id` bigint unsigned idx; `confirmed_by` varchar(10) (`you`, `rule`, `policy`: tells accepting a suggestion apart from making one) | M6 (`2026_12_14_000001_provenance_and_audit_context.php`) |
| `audit_events` | `channel` varchar(10); `correlation` char(26) idx (a ULID shared by an owner action and every knock-on write); `run_id` bigint unsigned | M6, same file |
| `bank_transactions` | `source_line` int unsigned (`NormalizedRow::$line`, discarded today) | M8 |
| `statement_balances` | `source` varchar(10) (null = you, `api`, `document`); `currency` char(3); `document_id` FK nullOnDelete | M8 |
| `verifications` | `independence` tinyint unsigned | M8 |

**Audit hash v2.** **Only when one of channel, correlation or run id is non-null**, `AuditLog::hashFor` hashes
`'v2|'.channel.'|'.correlation.'|'.run_id.'|'` followed by the v1 fields, with `before` and `after` re-encoded
canonically (keys in order at every level, PHP's encoding), so every existing event hashes exactly as before. The
context comes first, not last: appended after the free-text reason, it could be emptied and its text added to the
reason, and the event would hash to the same value. v2 is written only once `intel_audit_context` is on (below), and
always on a database whose JSON type reorders keys (MySQL 8). The `cycle.completed` seal, the year-lock snapshot and
act records live in the audit `after` payload, so no further audit columns are needed.

**Settings keys** (no migration):

| Key | Default | Meaning |
|---|---|---|
| `intel_cycle` | `off` | `off`, `shadow` or `on`. Switched on the server with `php artisan ledger:cycle mode off\|shadow\|on` (audited); there is no web switch |
| `intel_autonomy` | start levels | per kind: {level, suspended_until, reason} |
| `intel_autonomy_paused` | false | master pause |
| `intel_ai_night_usd` | 1.50 | nightly AI ceiling |
| `intel_ai_reserve_pct` | 30 | share of the monthly cap the cycle never uses |
| `intel_alerts_per_day` | 3 | Telegram alert limit |
| `intel_alerts_sent` | `[]` | findings already alerted (or taken as known on the first run), so none is alerted twice. Not in `Setting::DEFAULTS` |
| `intel_receipt_threshold_pence` | 10000 | cost.no_receipt threshold |
| `intel_detector_levels` | `[]` | per kind: {level: `raised`, `lowered` or `demoted`, at, reason}. The owner's raise and lower, and demotions (section 2.3) |
| `intel_report_time` | `07:30` | morning report time |
| `intel_report_detail` | `full` | `full` or `counts` |
| `mailbox_last_result` | null | {ok, at, error}: the forwarding mailbox's last change of state, written by `ledger:fetch-email` and read by `health.mailbox`. The error is never shown |
| `intel_backfills_done` | `[]` | finished backfills |
| `invoice_nudges` | `[]` | **(clients, jobs and invoices)** what `ledger:invoice-nudges` told you, `{key: {at, n}}`, kept 400 days. Not the audit log: a message isn't a change. Not in `Setting::DEFAULTS` |
| `invoice_emails_blind` | null | **(same)** "Expend can't see Gmail": `{since, why, told}`, kept while checks can't read Gmail and cleared by the next one that works (`InvoiceEmails::BLIND_SETTING`). Not in `Setting::DEFAULTS` |
| `job_finished_told` | `[]` | **(same, rekeyed)** the jobs `ledger:job-finished` asked about, `{"{job}@{finish}": day sent}`, plus `{job}@{finish}#{day}` for the ask after a "Not finished yet" snooze ends; kept 400 days. Rows kept by job id alone count as asked for any finish up to that day |
| `intel_audit_context` | true (from M7) | audit events record channel, correlation and run id (hash v2). Off in M6, so M6 can still be rolled back; on by default from M7 (a stored `false` still turns it off). From M7, roll back no further than M6 |

`ai_usage_YYYY_MM` gains `reserved` and `by_purpose` fields (M2, M5).

### 3.4 How existing tables map in (all kept as they are)

| Stage | Table or column | Read as |
|---|---|---|
| Acquire | `import_batches`, `bank_transactions`, `manual_expenses`, `documents`, `document_facts`, `gmail_messages`, `calendar_events`, `travel_items`, `exchange_rates`, `statement_balances`; settings `starling_last_result`, `gmail_last_result`, `calendar_synced_at`, `job_finder_last_run`; `failed_jobs` | Refs `batch:`, `tx:`, `exp:`, `doc:`, `gmail:`, `cal:`, `travel:`, `fx:`, `sbal:`, `set:`. `acquire.health` reads freshness from these. |
| Relate | `allocations` (+ `invoice_id`, `merchant_rule_id`, `decided_by`, `confirmed_at`, `reason`); `bank_transactions.transfer_pair_id`; `calendar_job_links` (`linked_by`, `rejected`, `not_job`, `reason`); `documents.work_job_id`/`job_linked_by`/`job_rejected`; `travel_items.work_job_id`/`job_linked_by`/`bank_transaction_id`/`suggestion`/`decision`; `invoices.work_job_id`; `job_candidates`; `job_finds`; `job_days`; `merchant_rules`; `gmail_labels` | Proposals and links in the vocabulary of section 2.2. They are still written only by their existing writers, which gain a `?Provenance` argument. |
| Verify | `verifications`, `document_facts.verified`, `import_batches.balance_check`, `audit_heads` | Check results (section 2.2) |
| Detect | `findings`, `todo_dismissals` (whose `note` column is finally written) | Findings and their lifecycle |
| Recommend | No new tables: the Ranker reads findings | |
| Preserve | `audit_events` (+ context), `tax_years.locked_at`/`filed_*`, `allocations.voided_at`, `settings.rule_offers_declined`, plus `vetoes`, `autonomous_acts` and `proposal_outcomes` | |

### 3.5 Backfills

| Backfill | Milestone | What it does |
|---|---|---|
| `findings` | M3 | Sets `code`, `subject_ref`, `scope_ref` and `direction` from the key prefix (the table in 4.4, the same parsing as migration `2026_11_29_000901`). `state` comes from `resolved_at`/`resolution`. Writes a `detected` event at `first_seen_at` and a closing event at `resolved_at`. Unparsable keys stay null. |
| `allocations.confirmed_by` | M6 | Set where `decided_by = you` (`you`), or `decided_by = rule` confirmed when it was made (`confirmed_at = created_at`, with no later `allocation.confirmed` event) (`rule`). A rule's suggestion confirmed later stays null: the tap may have been yours. Everything else stays null. `method` is not backfilled: the `Methods` legacy recogniser reads the reason at query time. |
| `proposal_outcomes` backtests | M10 | Replays `transfer.pair`, `suggest.hmrc` and `suggest.invoice` over the owner's catch-up decisions (`allocation.decided` with an empty `before`, actor `you`), using only inputs created before the decision. |

---

## 4. Stage contracts

Two contracts and one value object. `Detector` is in `app/Domain/Intel/Contracts/`; `CycleStep` (with `Stage`,
`StepOutcome` and `CycleContext`) is in `app/Domain/Intel/Cycle/`, beside the runner that uses it:

```php
interface CycleStep {
    public function key(): string;                       // 'relate.transfers'
    public function stage(): Stage;
    public function writes(): bool;                      // changes anything outside the run log: skipped in shadow
    public function run(CycleContext $ctx): StepOutcome;  // idempotent; may return 'more' with a cursor
}

interface Detector {
    public function code(): string;                      // 'job.closeout'
    public function version(): int;
    public function detect(CycleContext $ctx): iterable; // FindingDraft; read-only (tested with DB::listen)
    public function coverage(): Coverage;                // complete | capped | failed
}

final class Provenance {
    // method, method_version, basis, score, evidence refs, alternatives, run_id
}
```

- `CycleContext` carries the run, a deadline (`hasTime()`), an AI allowance (`ai->allow($estimate, $purpose)`),
  the cursor, the run's kind and scope, `$changed` (refs acquired or changed in this run), `$proposed` and `$acted`
  (`handOff()`, at most 500 refs each, kept with the run), and a shadow flag. Steps hand off through these. Every
  constructor argument has a default, so `new CycleContext` (a page view or a command run by hand) and
  `new CycleContext(runId:, deadline:, shadow:)` keep working.
- A detector that makes several codes implements `Contracts\CoversCodes` and reports coverage per code (the to-do
  list's groups, `health.*`), so one failing check never resolves another's findings.
- `FindingDraft` holds: key, code, subject_ref, scope_ref, title, detail, evidence[], amount_pence, currency,
  direction, grade, due_on and actions[].

### 4.1 Acquire evidence

| | |
|---|---|
| Input | Channel settings and cursors (Starling `synced_to`, Gmail scan state, `calendar_ics_url`); the failed-read backlog (documents with `status = failed` on the AI cap, `gmail_messages` failed) |
| Output | Rows in the existing source tables, unchanged; new `tx:` and `doc:` refs added to `$changed`; `health.*` findings |
| Steps | `acquire.health` (read-only freshness and failure checks: runs the `Health` detector alone); `acquire.retry_reads` (re-dispatches `ReadDocument` for documents marked `waiting_budget`, or failed on the cap before M5, and `RetryTravelRead` for booking emails failed on the cap, within the run's AI allowance: each read counts about $0.15 against it when queued, at most 25 a night) |
| Runs | Calendar (hourly), mailbox (every 5 minutes), Starling (06:41 London) and the Gmail rescan (Sunday 05:23) keep their own schedules |
| Hand-off | `Importer::afterImport` (already called by `StarlingSync` line 240 and `RevolutImport` line 190) still relates new lines at once; an `import` run then updates findings for that account |

Code that changes:

- `app/Domain/Calendar/CalendarSync.php` (M8): before the delete-and-insert in `load()`, upsert
  `calendar_event_versions`, keeping `STATUS`, `SEQUENCE` and `DESCRIPTION`, and set `missing_since` on entries
  that disappear.
- `app/Domain/Mail/MailboxFetcher.php` (M8): a per-message `try/catch` in the `fetch()` loop. Today one bad message
  stops every later one, because `setFlag('Seen')` is never reached. Also writes `document_arrivals`.
  `settings.mailbox_last_result` is already written (with M4/M5) by `ledger:fetch-email` in `routes/console.php`, only
  when the result changes (read, or not), so the to-do list's cache isn't emptied every five minutes.
- `app/Domain/Documents/DocumentIntake.php` (M8):
  - writes `document_arrivals` on every `store()`, including duplicates;
  - one `autoRead(channel)` gate in `app/Domain/Documents/AiConfig.php`. Today `Telegram.php` line 200 dispatches
    `ReadDocument` when `ai_extraction !== 'off'`, and `DocumentIntake` line 65 also dispatches it when the setting
    is `'on'`, so a Telegram file is read twice.
- `app/Jobs/ReadDocument.php` (M5): reads under the `reading` AI purpose. While `intel_cycle` is `on`, a refusal for
  want of AI budget (the cap, or reading's share of it) sets `status = waiting_budget` instead of `failed`, so
  `acquire.retry_reads` picks it up when there's room. While the cycle is off or in shadow nothing reads it again on
  its own, so it stays `failed`, as before; switching away from `on` turns waiting documents back to `failed`. Read
  with AI pressed on the document page (`DocumentController::read`, your own request) still marks a refusal
  `failed`, so you see why at once; `acquire.retry_reads` picks up documents failed on the cap too. The documents
  pages show `waiting_budget` as "Waiting for AI budget", with a filter.
- `app/Jobs/ReadTravelEmail.php` (M5): reads under the `reading` purpose too. `gmail_messages` has no
  `waiting_budget` status: an email refused at the cap stays `failed` with the cap in its error, which
  `acquire.retry_reads` and the `health.ai_cap` item both count as waiting.
- `app/Domain/Import/Importer.php` and `RevolutImport.php` (M8): store `source_line`.
- `app/Domain/Banks/StarlingSync.php` (M8): a daily balance snapshot into `statement_balances` (`source = api`,
  with currency). It never overwrites a balance the owner typed in for the same day. If the token lacks the balance
  scope, it degrades to an info health item.

### 4.2 Discover relationships

| | |
|---|---|
| Input | `$changed`, waiting lines, vetoes, the autonomy levels |
| Output | Proposals written through the existing writers, each carrying a `Provenance`; added to `$proposed` |
| Steps | `relate.transfers`; `relate.receipts` (quick add); `relate.route` (M8); `relate.categorise`; `relate.jobs`; `relate.sweep` (M7) |
| Rules every writer obeys | 1. AllocationWriter's guard: owner-made or confirmed lines and locked years are untouchable. 2. `Vetoes::allows()`. 3. No laundering (section 2.3). 4. One precedence order, `Methods::outranks`: you > rule > matcher (invoice > HMRC > transfer > pattern > categoriser) > ai. **As built (M7):** the order is kept with the writer that enforces it, `AllocationWriter::PRECEDENCE` (`rank()`, `outranks()`); `Methods::outranks` delegates to it. The assistant's `ai.assistant` (your own words in the chat) ranks with the categoriser, and the background `ai.advisor` below it. An automatic writer never replaces an open suggestion that outranks its own; one of the same rank or lower is replaced as before. |
| Hand-off | `$proposed` goes to verify; owner decisions go back through preserve |

The steps:

- **`relate.transfers`** runs `TransferPairer::pairAll` and `suggestOneSided`. From M2 it audits the pair link and
  refuses ties: today `$candidates->first()` silently picks one of two equal candidates.
- **`relate.receipts`** (quick add, PLAN.md §6.12; `Cycle\Steps\RelateReceipts`, it writes) runs
  `ReceiptAttacher::sweep` in chunks with a cursor, open years only: a business-card receipt from the phone still
  waiting goes on its bank line when exactly one line fits and no other waiting receipt fits that line (never a line
  that is your own money moving, in a locked year, taken by another receipt or one you said no to), and a `receipt.quick`
  suggestion whose receipt is no longer on its line is taken back (`receipt_gone`). The command `ledger:receipts`
  (04:09 London, skipped when the cycle does the night's work) does the same and re-reads photos refused at the AI cap.
  `Importer::afterImport` also runs `ReceiptAttacher::forLines` for each import's new lines, after `pairAll`.
- **`relate.route`** (M8) runs `Suggester::suggestFor` over waiting lines nightly, so a rule made today, an invoice
  issued after its payment arrived, and the HMRC, penalty, car-hire and travel patterns reach lines that are already
  waiting. Today `ledger:categorise` runs only `Categoriser`. The invoice branch also changes:
  - it refuses when two open invoices have the same total;
  - it requires a payer resembling the client (`payer_aliases` in M10).

  **As built (income v3, 25 Nov 2026).** `relate.route` itself is still M8, but the invoice branch is rebuilt for
  every path that runs the Suggester (a new statement, and `ledger:categorise` and `relate.categorise`, which run the
  whole chain):
  - It is `Income\InvoiceMatcher`, the only writer of `suggest.invoice`, `suggest.invoice_set` and
    `suggest.invoice_net` (section 2.4), one level per pass: exact, else set, else net. An invoice is open when it is
    issued with money still to come: what's outstanding (`InvoicePayments::received`), less what other payments
    waiting in the Inbox name for it, less what this run has already proposed for it (the tally), so two payments
    never take the same amount.
  - **The tie rule.** Tied exact candidates keep those of the payer's sole client; still tied, nothing is proposed,
    and the Inbox shows the invoices as chips (`Income\IncomeTargets`). A set or a net fit counts only when exactly one
    combination fits. The matcher never writes over an invoice suggestion, whatever made it; an open one is replaced
    only after `relate.sweep` takes it back.
  - **The payer.** A payer known to pay for one client from 2 or more of your payments is matched only to that
    client's invoices (or client-less ones billed to that name). A payment must come no earlier than 3 days before the
    invoice's date (`InvoiceMatcher::SLACK_DAYS`), or 60 for a deposit from its known payer (`DEPOSIT_DAYS`).
    Invoices you said a payment isn't for (and the invoices that replaced them), and the invoices of a job you said
    it isn't for, are dropped before any search.
  - **The on-issue match.** `InvoiceService::issue` calls `InvoiceMatcher::forInvoice` once the issue commits: up to
    200 waiting payments (`FOR_INVOICE_LIMIT`) that could be for the new invoice are tried, oldest first, with one
    shared tally. A payment counts when it has no lines, or an open suggestion that isn't an invoice suggestion and
    ranks below the matcher's 5 (`AllocationWriter::PRECEDENCE`), which it then replaces; a rule's open suggestion
    (rank 6) stays. It never fails the issue, and the issue's message says "1 waiting payment looks like it's for
    it".
  - **Client enrichment.** `PayerAliases::enrich` gives the job-less, invoice-less business-income lines of a rule or
    categoriser suggestion (and of `RuleLearner::accept`) the payer's sole client, adds " · for Acme Events (from your
    payments)" to the reason and the payment it was learned from as `tx:` evidence. Nothing is enriched when two
    clients are plausible.
  - **Never confirmed.** Every automatic income link is a suggestion (the owner's answer of 24 Nov 2026):
    `AllocationWriter::decide` stores any automatic write of money in unconfirmed when a line names an invoice, a job or
    a client, and `confirm()` refuses an automatic actor ("Only you can accept an income link."). So a turnover rule
    that confirms on its own leaves a payment from a payer with a sole client as a suggestion (`decided_by` rule,
    `client_id` set). A plain turnover rule with no link still confirms as before.
  - The background AI (`TransactionAdvisor`) gets no jobs around the date for money in: it proposes the kind and
    category only.
- **`relate.categorise`** runs `Categoriser::suggestFor` in chunks of 300 with a cursor, as `routes/console.php`
  does today. M2 fixes `Categoriser::isOurs`: `'Looks like '` in `REASONS` matches Suggester's "Looks like a
  payment to HMRC/a fine/car hire", so `learnFrom` can overwrite those suggestions. From M6 the check uses the
  `method` column.
- **`relate.jobs`** runs `JobFinder::run`: all rules, then the AI calendar pass, at most 15 entries per step,
  returning `more`. Today it takes 120 in one go (`JobFinder.php` line 862). Then it calls `followUp` for jobs
  whose links changed. `Cache::lock('intel:jobfinder')` is shared with `app/Jobs/FindJobs.php`, so the command and
  the queued job can't run at once. From M7, `followUp`'s "Same PO number" invoice link (line 466) respects
  `job_finds` rejections and vetoes.
- **`relate.sweep`** (M7) withdraws unconfirmed machine suggestions whose basis is gone: the job was cancelled, the
  invoice was voided or paid, the rule was deleted, or a veto was added. **As built (income v3):** its parts are
  `void_invoice`, `paid_invoice`, `cancelled_job`, `deleted_rule` and `vetoed` (`RelateSweep::PARTS`). `paid_invoice`
  goes by what is still to come, not by the total: a match is taken back when its invoice has nothing outstanding
  (paid by payments you confirmed, or closed), or less than the match puts on it (in pence for a pound invoice; in its
  own currency for another when the payment came in it), with a reason naming what's still to come.
  `cancelled_job` leaves alone a job cancelled with something owed (`cancel_charge` `fee` or `costs`: its draft
  carries them), and a match to an invoice of it that is still issued and unpaid.
- **Learning from accepted suggestions** (M7): `Categoriser` and `RuleLearner::offers` count accepted suggestions
  (`confirmed_by = you` on a machine `method`) at half weight. They never learn from unreviewed rule or policy acts.

### 4.3 Verify findings

| | |
|---|---|
| Input | `$proposed` and `$acted`; the re-check backlog; accounts; the audit chain |
| Output | `verifications` rows (unchanged table, plus `independence`); reconcile results; chain status |
| Steps | `verify.audit_chain`, `verify.new`, `verify.backlog`, `verify.reconcile` |
| Hand-off | Check results go to the detectors (grades) and to the policy gates |

The steps:

- **`verify.audit_chain`** runs `AuditLog::verify()`. A failure becomes a critical `health.audit_chain` finding, an
  immediate Telegram alert, and a health block: no automatic act that night. Today `ledger:verify-audit` only calls
  `Log::critical`.
- **`verify.new`** (M8) checks this run's proposals and acts.
- **`verify.backlog`** runs `Verifier::recheck`, made fair in M8: never-checked first, then items carrying money,
  then the oldest `checked_at`. Today `$due` uses `orderBy('id')->limit(200)` (`Verifier.php` line 522), so high
  ids starve. Rule-made and policy lines are included, and 20% (at least one per kind) of last night's acts are
  sampled.
- **`verify.reconcile`** runs `Reconciler::all()`. Method `none` becomes an `acct.unchecked` info finding. From M8,
  a foreign pocket is reconciled against statement balances in its own currency.

Code that changes:

- `app/Domain/Documents/Grounding.php` (M2): `check()` compares amounts in the raw document span, separators
  included. Today `normalise()` strips punctuation, so the quote "£12.50" is found in "Crew fee £1,250", and
  `amountInQuote` then checks the amount only against the quote. From M8, `valueInSpan` also checks dates, times
  and PO numbers.
- `app/Domain/Verification/Verifier.php`:
  - M2: `save()` audits with the real model subject and id instead of a type string with a null id.
  - M8: a **blind second read** by a different model, which is not shown the claims (today the second read prompt
    lists them, line 158). It runs only for money-bearing scans, under the `verify` AI purpose.
  - **As built (income v3):** `Verifier::transaction` reads a negative business-expense line on money in that also has
    an income line as a deduction (a fee or commission taken off: its sign is right, and it gets the income's date
    window, from 30 days before the job starts to 120 after it ends). A line naming an invoice is checked against that
    invoice rather than the job's dates. A job cancelled with a fee or its costs to charge still fits. Money in from a
    payer whose sole client you've charged costs to (on an issued invoice, or another way), suggested as something
    other than turnover (personal, a transfer, a loan, money put in, a refund or other income), gets a failed 'Who
    paid' check: "Looks like Acme Events paying you: that's turnover (box 15), not a refund or other income". Only
    open automatic suggestions are checked: a repayment you confirmed yourself is not warned about.

### 4.4 Identify discrepancies

| | |
|---|---|
| Input | The ledger, links and check results |
| Output | `FindingDraft`s plus a coverage value per detector run |
| Steps | `detect.todo` (the `TodoGroupsDetector` adapter); `detect.<code>`; `detect.sync` |
| Runs | In the nightly and morning runs; `/todo` still builds `TodoList` live |
| Hand-off | Open findings go to recommend |

**`detect.todo`** wraps `TodoList::compute` and maps each key prefix:

| To-do key today | code | subject | scope | direction |
|---|---|---|---|---|
| `gmail` | health.gmail | `set:gmail_last_result` | none | none |
| `import:{b}` | import.held | `batch:b` | acct | unrecorded |
| `invoice_overdue:{i}` | invoice.overdue | `inv:i` | job | owed_to_you |
| `underbilled:{i}`, `recharge:{t}` | invoice.underbilled, travel.recharge | `inv:i`, `travel:t` | job | owed_to_you |
| `check:{type}:{id}` | check.failed | that subject | none | none |
| `deadline:{y}`, `deadline:{y}:amend`, `penalty:{y}` | tax.deadline, tax.penalty | `year:y` | year | none, owed_by_you |
| `invoice:{j}`, `unsent:{i}` | job.uninvoiced, invoice.unsent | `job:j`, `inv:i` | job | owed_to_you |
| `candidate:{c}` | job.candidate | `cand:c` | none | none |
| `dupe:{t}` | tx.duplicate | `tx:t` | acct | at_risk, (n − 1) × charge |
| `no_po:{j}` | job.no_po | `job:j` | job | none |
| `gap:{a}:{from}:{n}` → **`gap:{a}:{from}`** | acct.gap | `acct:a` | acct | unrecorded |
| `stale:{a}:{line}` → **`stale:{a}`** | acct.stale | `acct:a` | acct | none |
| `missing:{t}` | travel.payment_missing | `travel:t` | job | unrecorded |
| `doc:{d}` | doc.held | `doc:d` | none | none |
| `invoice_overpaid:{i}`, `invoice_void_paid:{i}` **(income v3)** | invoice.overpaid, invoice.void_paid | `inv:i` | job | none |
| `job_done:{j}` **(clients, jobs and invoices)** | job.done_due | `job:j` | job | owed_to_you |
| `draft:{i}` **(same)** | invoice.draft | `inv:i` | job | owed_to_you |
| `claimed:{cost}`, `charge:{cost}` **(same)**; the cost's button id, `CostRef::toId` (`tx:381:j45`, `travel:7`, `trip:3`) | cost.claimed, cost.late | `tx:`, `exp:`, `travel:` or `trip:` | job (from `j45`) | owed_to_you |

**As built (25 Nov 2026), the new keys** (`TodoGroupsDetector::TABLE`, with grades in `Intel\Grades` and names in
`DetectorLevels`, all graded certain from your own records):

- `invoice_overpaid:{i}`: "Acme Events paid £100 more than INV-0042", with [Keep £100 as Acme Events, no job]
  (`inv_over_keep`, pound invoices with a client) and "I paid it back" (a link to that payer's money out).
- `invoice_void_paid:{i}`: "INV-0041 was voided after £500 was paid against it" (`InvoicePayments::stranded`), with
  [INV-0043 replaced it] (`inv_replace`, `{void}:{replacement}`) when exactly one issued invoice of the same client, dated
  that day or later, is still owed at least that much. Reminders to that client wait until you say.
- `job_done:{j}`: "Is Arena load-in done?" for a booked job whose last day finished in the last 60 days
  (`JobStage::DONE_WINDOW_DAYS`), with Done, Not yet and Cancelled… (`job_done`, `job_not_yet`, `job_cancel`). It
  replaces "Invoice <job>" (`invoice:{j}`) while shown; older finished jobs keep "Invoice <job>". Its snooze reasons
  include "Not finished yet" (`TodoActions::NOT_FINISHED_NOTE`, 3 days, `NOT_YET_DAYS`).
- `draft:{i}`: a follow-up draft (late costs of a job already invoiced) or a client's draft with no job, "Draft · 3
  lines · 2 changes since you looked".
- `claimed:{cost}`: "Was Uber 12 Mar paid back?" for a cost charged another way or claimed on the client's form 30 days
  ago or more with nothing said since (`TodoList::CLAIMED_DAYS`), with [Paid back] and [Not paid back]
  (`cost_paid_back`, `cost_not_paid`).
- `charge:{cost}`: "Charge Taxi 13 Mar to Acme Events" for a cost linked to a job after its invoice was sent
  (ChargeStatus `owed_late`, invoices issued in the last 400 days: `TodoList::LATE_COST_DAYS`), with [Invoice it now]
  (`cost_invoice_now`), [Not charged] (`cost_no_charge`) and [It was on INV-0042] (`cost_invoiced`). The job's latest
  issued invoice shows the same costs once it was sent, by the same rule, so a cost decided in one place goes from both.
  `JobCloseout` no longer counts these late costs, so they aren't asked about twice.

Also changed: `invoice_overdue:{i}` counts only once an invoice was sent, from the later of its due date and the day
it was sent plus its terms (`ChaseLadder::daysOverdue`); an issued invoice never sent stays `unsent:{i}`. A part-paid
invoice gains a ghost "Close it: £350 isn't coming" (`inv_close`, `{id}:sp`), and one paid in another currency "It's
paid (in another currency)" (`{id}:fx`). `invoice:{j}` is written by `JobBilling` (a job billed by income alone, or by
another job's invoice, isn't asked about), and for a client who pays without an invoice from you it reads "Acme Events
hasn't paid for Arena load-in yet" once 45 days have gone by with nothing in (`TodoList::NO_INVOICE_DAYS`).

The routine groups (`payments`, `rules`, `transfers`, `stays`: `FindingsRegistry::ROUTINE`) remain proposals, not
findings. The two re-keyed entries use rekeyed events (M3), so a partly filled gap becomes a `changed` event on the
same finding instead of a new finding.

**`detect.sync`** (`FindingsRegistry::sync`, made coverage-aware in M3):

- A finding missing from this run is `resolved` **only** if its detector ran `ok` with coverage `complete`.
- A capped detector (the `limit(300)` on finished jobs, the `limit(50)` on duplicate groups in `TodoList`) marks
  missing findings `unseen`.
- A failed detector (the `catch (Throwable)` around `PenaltyEstimator::all()`) adds a `detector_failed` event and
  keeps the finding open.
- A changed amount becomes a `changed` event.
- Snoozes are read with `TodoDismissal::activeKeys()`. Today `sync()` reads every row, so an expired snooze still
  counts as snoozed.

**The new detectors.** All are deterministic and read-only, and act only through existing writers.

| Code | Milestone | What it compares | Direction | Grade |
|---|---|---|---|---|
| `tax.hmrc_unallocated` | M1 | Money-out lines matching `Suggester::HMRC_PATTERN` (made public) with no allocation, or allocations other than `tax_payment`. `PenaltyEstimator` (line 313) counts only `tax_payment` allocations, so it overstates penalties and interest. | none (fixes owed_by_you) | certain |
| `travel.unclaimed` | M1 | `JobTrips::proposals($year)` for each open year: finished jobs with a postcode, no mileage, no rail, flight or coach. Deduction at `TaxParameters::for($year)['mileage']` from `config/tax.php` (45p; 55p from 2026-27). | claimable | likely (distance estimated) |
| `year.filed_drift` | M1 | `ReturnSummary::for($label)` against `tax_years.filed_turnover/expenses/profit_pence` for filed or amending years; the amend-by date is `FilingDates::deadline` plus one year | owed_by_you or claimable | certain |
| `job.closeout` | M4 | Per finished job: `invoices.po_total_minor` and PO facts (`doc:#f=…`), invoice total and lines, `InvoicePayments::received`, recharge-decided `travel_items` against `invoice_lines`, costs and job days. One verdict per job. | owed_to_you | strong or likely (from the PO fact's check) |
| `travel.double_claim` | M4 | Mileage from home on a morning after a hotel night near the venue; mileage plus a confirmed train for the same job day; confirmed fuel while every vehicle uses simplified mileage | at_risk | certain |
| `income.unlinked` | M4; **version 2** (income v3) | Confirmed `business_income` with no invoice and no job. **As built:** per line, and a line counts as assigned once its worked-out client (`IncomeClient`), job or invoice is set: client-only income is done (the owner's answer). A payment is flagged for the sum of its unassigned turnover lines, titled "Income with no client, job or invoice" (`IncomeUnlinked::VERSION` 2) | none | certain |
| `receipt.unmatched`, `cost.no_receipt` | M4 | `receipt.match`: receipts with no payment (often a card that isn't imported); confirmed business costs over `intel_receipt_threshold_pence` with no document | unrecorded, none | likely |
| `dupe.cross_source` | M4 | A cash `manual_expenses` row and a card line with the same amount, a similar merchant, within a day. An expense added from the phone is left to `quick.maybe_card` | at_risk | likely |
| `rule.drift` | M4 | Rules the owner overrode at least twice in 90 days (from honest audit events since M2) | none | certain |
| `tax.saving` | M4 | `TaxSavings::review` items with status action or check and a `saving_pence` | claimable | as reported |
| `acct.unchecked`, `check.unverifiable` | M4 | Reconciler method `none`; verification `unverifiable` on money | none | n/a (info) |
| `health.*` | M2, M4, M5 | Audit chain; `starling_last_result` failing; `calendar_synced_at` over 2 hours; mailbox blocked (`mailbox_last_result`); reads `waiting_budget` or failed on the cap; `failed_jobs` (`Detectors\Health`, M4, one detector covering six codes). A cycle step failing (`health.cycle_step`, `Detectors\CycleStepFailing`, M5) | none | n/a |
| `year.locked_drift` | M4 | The lock snapshot's figures against `ReturnSummary::for` now | none | certain |
| `calendar.job_changed` | M8 | A calendar version moved or set `STATUS:CANCELLED` while the job still has a booked stay, costs or no cancellation invoice | owed_to_you or at_risk | strong |
| `decision.evidence_changed` | M8 | An owner decision whose cited evidence pin changed (a booking amended after a stay was decided; a document re-read) | none | strong |
| `receipt.waiting`, `receipt.which`, `receipt.second_copy`, `quick.photo_differs`, `quick.line_differs`, `quick.held`, `quick.maybe_card`, `quick.maybe_same`, `quick.photo_waiting` | quick add | `QuickEntryChecks` (one detector, group "From your phone", normal level): a business-card receipt with no bank line 7 days after a statement covers its day or after 21 days, with near misses (±15%, ±7 days) first; a receipt that fits more than one line, or a line that already has one; the photo's read against what you typed; a receipt whose figures changed after it went on a line you decided; an entry kept, not added (locked year, no rate, details missing, the same photo); a cash or personal-card cost against a card payment of the same amount within two days; the same amount twice within a day (skipped when two merchant names read from the photos differ); a photo still on the phone after 24 hours. It only reads, and `forEntry()` gives the phone's reply its warnings | unrecorded or at_risk (none for a photo still on the phone) | certain for your own entries' states; likely for `receipt.which` and `receipt.second_copy`; possible for `quick.photo_differs`, `quick.maybe_card` and `quick.maybe_same` |

### 4.5 Recommend actions

| | |
|---|---|
| Input | Open findings, open proposals, deadlines, autonomy levels |
| Output | Ranked to-do items with severity, grade, direction, `why[]` refs and actions bound to state `{code, id, fp}`; Telegram alerts; the morning report |
| Steps | `recommend.report`, `recommend.alerts` (morning run) |
| Hand-off | Owner taps go to preserve |

Interfaces:

- **`Intel\Ranker::score`:** |amount at stake, in one direction| × grade weight × urgency. Urgency is 3 when due
  within 7 days, 2 within 30, 1.5 within 60, and 1 otherwise. Today's fixed group priorities in `TodoList::compute`
  stay as floors.
- **Severity:**
  - `critical`: audit chain broken, or the nightly audit chain check failing; a deadline or penalty step within 7
    days; a health failure two nights running (the finding's meta `nights` is 2 or more); or a score of £500 or
    more.
  - `important`: a score of £50 or more, due within 60 days, or failed checks on money.
  - `info`: everything else. **New detectors start at `info`**: visible below the fold and kept off Telegram
    (`Intel\DetectorLevels`, M4). The owner raises a kind to show it in full (`det_raise`) or lowers it again
    (`det_lower`), from the to-do page; both are audited. A kind demoted by its false positives (section 2.3) is
    `info` too. Severity is stored on the finding when it is recorded, with what only its detector knew (meta).
- **`TodoActions::run($code, $id, $offeredAt, $fp)`.** New codes:
  - `tx_tax` (M1): decide as `tax_payment`, as you;
  - `fnd_snooze`, `fnd_dismiss` (M3), with preset reasons;
  - `trips_add` (M4): `JobTrips::add` for one simplified-mileage vehicle, otherwise a link to `/mileage`;
  - `det_raise`, `det_lower` (M4): show a kind of finding in full, or below the fold again (id: the code with `:`
    for `.`, e.g. `job:closeout`);
  - `veto_lift` (M7);
  - `cand_merge` (mail and calendar batch 1): "Part of <job>" on a possible job, id `{candidate}:{job}`, through
    `JobFinder::merge`, which then searches the job again (`GatherForJob`);
  - `act_undo`, `run_undo` (M9).
  - **As built (income v3 and clients, jobs and invoices, 25 Nov 2026)**, every one bound by its fingerprint:
    - income: `tx_invoice`, `tx_client` (an Inbox chip as one press), `tx_fee` (the payer's usual fee taken off),
      `tx_job` ("It was for this job" on an `invoice:{j}` item), `inv_close` (`{id}:sp`, `{id}:fx`), `inv_over_keep`,
      `inv_replace` (`{void}:{replacement}`);
    - jobs: `job_done`, `job_not_yet` (snoozes `job_done:{id}` for 3 days, "Not finished yet"), `job_cancel` (a bare
      job id only asks; `{id}:none` and `{id}:costs` cancel it; a fee is given on the job's page, `/jobs/{id}#cancel`),
      `job_reopen` and `job_uncancel` (the Undos), `todo_undo` (Undo of a snooze a button made: Not yet, They've
      paid, Remind me Monday);
    - invoices: `inv_draft_later` ("Remind me Monday"), `inv_to_gmail`, `inv_gmail_send`, `inv_sent_self` (today; the
      item adds a "Pick a date" link to `/invoices/{id}#sent`), `inv_chase` (Make a reminder: `ChaseLadder::make` with
      once true; with no usable Gmail it emails the reminder through `InvoiceMailer`), `inv_chase_send`,
      `inv_chase_later` ("Not yet", 7 days), `inv_paid_wait` ("They've paid");
    - costs, by the cost's button id (`CostRef::toId`): `cost_charge`, `cost_no_charge`, `cost_elsewhere`,
      `cost_claimed`, `cost_invoiced` (`{cost}:{invoice}`), `cost_paid_back`, `cost_not_paid`, `cost_clear`,
      `cost_invoice_now` (all through `Charges\CostCharges`, as you);
    - `link`: an action with an `href` and no fingerprint, which decides nothing (it opens a page).
  - **What follows a press** (`TodoActions::followUp`): a link to what the press made or opened, and Undo where it
    can be undone, worked out after the press so each is bound to what it left. On Telegram they replace the
    pressed message's buttons; on the web `TodoController::act` flashes `undo` (`{code, id, label, fp}`) and
    `follow_up` (buttons `{code, id, label, fp}`, links `{label, href}`), which the toaster shows as buttons
    (`resources/js/components/toaster.tsx`, `resources/js/lib/todo.ts`): 10 seconds with buttons, 9 for an error, 4.5
    otherwise, held while touched, and kept until answered when it asks. On the to-do page, Cancelled…'s question
    ("Do they owe you anything?") is asked in a bottom sheet instead, so it can't time out.
- **Fingerprints (M7, `Todo\Fingerprints`).** Every deciding action is bound to the state it was offered on: 6 hex
  characters of a hash of what it would act on (a payment's lines, an invoice's status and total, a finding's key and
  code), never of fields a night's run rewrites (a finding's title or amount, an opportunity's last-seen time or
  evidence wording). The web sends it as `fp` and refuses a deciding press without one; Telegram carries it as the
  fourth part of the callback. A press on changed state is refused ("This changed since"), and one on something
  already settled says "Already dealt with". Only `recheck`, and "Not relevant…" by a finding's id alone (it only asks
  why), decide nothing.
- **`ledger:invoice-nudges`** (`routes/console.php`): M2 makes it skip `invoice_overdue:{id}` keys in
  `TodoDismissal::activeKeys()`. M5 makes it read the `invoice.overdue` findings (brought up to date first with
  `FindingsRegistry::refresh()`), so the to-do list and the nudges share one logic. **As built (25 Nov 2026):** it is a
  command class, `app/Console/Commands/InvoiceNudges.php`, under the same name, daily at 09:13 London (it was
  weekdays): drafts waiting, invoices not sent, a deleted Gmail draft and overdue invoices, each at its own pace, with
  quiet hours (21:00 to 08:00), a wait on job days (at most 2 days) and at most one message per invoice a day; what it
  told is kept in `settings.invoice_nudges`. It only tells: every button is yours, and nothing it does writes a
  reminder draft or sends an email (docs/mail-and-calendar.md, "The money end of batch 4").

### 4.6 Preserve decisions

| | |
|---|---|
| Input | Owner actions (web, Telegram), acts, the end of each run |
| Output | Allocations with provenance and `confirmed_by`; `link_provenance`; vetoes; finding events; audit events with channel, correlation and run id; acts; the year-lock snapshot; outcomes; the `cycle.completed` seal |

Interfaces and code that changes:

- **`Intel\AuditContext::with(actor, fn, channel, correlation, runId)`** (built in M2 with the work second; pass
  the rest by name). It is set by:
  - a web middleware (`app/Http/Middleware/AuditChannel.php` **(new)**, registered in `bootstrap/app.php`);
  - `Telegram::handleButton`;
  - the cycle runner;
  - a queue job middleware (`app/Jobs/Middleware/AuditQueueContext.php` **(new)**).

  `AuditLog::record` takes its default actor from the context. M2 introduces the context in memory only, so actors
  become honest at once; M6 persists channel, correlation and run id once `intel_audit_context` is on (M7).
- **`app/Domain/Ledger/AllocationWriter.php`:**
  - **M2:**
    - `lockForUpdate` on the source row and its allocations inside the transaction (today two writers can both pass
      the guard);
    - a no-op when the new lines equal the existing ones, with no audit event (today every nightly pass
      re-audits identical suggestions);
    - `confirm()` and `clear()` take their actor from the context. Today the partner confirm in
      `TransferPairer::afterYourDecision`, the clear in `JobGatherer` (line 232, inside `GatherFromGmail`) and the
      clear in `TravelMatcher` (line 131) are all logged as `you`.
  - **M6:**
    - a trailing `?Provenance`;
    - `confirm()` sets `confirmed_by`;
    - `snapshot()` includes `invoice_id`, `merchant_rule_id`, `method` and `reason`, so outcomes can be rebuilt
      from the audit trail.
- **`app/Domain/Gmail/GmailLabeller.php`** (M2): records `gmail.labelled` with actor `system` (today it defaults
  to `you`). It does not call `confirm()` or `clear()`.
- **`Intel\Vetoes::record`** (M7) is called when the owner:
  - clears or changes a machine suggestion (`DecisionController`);
  - unpairs a transfer;
  - unlinks a payment from a booking (`TravelMatcher::decide` with `client_booked`);
  - rejects an invoice-to-job link (`JobGatherer::reject`);
  - says "Not this job" on a payment (`JobGatherer::unlinkPayment`, income v3): when its lines were automatic and named
    the job's invoice, the invoice is dropped too and an `invoice_payment` veto is recorded (reason code
    `not_this_job`), so the matcher doesn't offer it again. The matcher's job-only "not this job" memory is read from
    the job's rejected `job_finds` rows, not a veto row (it isn't in the vetoes list; deleting the rejected find, or
    the job's own undo, lifts it);
  - says "didn't drive" for a mileage day;
  - undoes a rule (`RuleLearner::undo` also adds the merchant to `rule_offers_declined`).

  Lifting a veto is audited. **As built (M7):** a link that already has a rejection store (calendar, documents, job
  finds, candidates, rule offers) gets a veto row too when you reject it there, so it can be seen and lifted in one
  place; the older store is still what `Vetoes::allows()` reads, through its adapter. `mileage_day` vetoes use subject
  `job:J` with object null for the whole job, or `jday:<job_days id>` for one day (the page offers whole jobs only). A
  veto never stops your own transfer from pairing. The Inbox's own Undo records no veto.
- **`TaxYearController::lock`** (M4, `YearChecks::lockSnapshot`): the `tax_year.locked` event's `after` payload
  carries
  - the `ReturnSummary::for` figures;
  - the sha256 of the `YearExporter::write` file made at the lock. The file is kept in
    `storage/app/private/locks` (inside the backed-up `private/` folder), because an export made later can't match
    it byte for byte (the xlsx carries zip and document timestamps). The years page checks the kept file against the
    event ("Check it": `lock_check`) and downloads it as it was (`GET years/{label}/export?as_locked=1`);
  - the open-findings digest (`FindingsRegistry::openDigest()`: sha256 of the canonical JSON of each open finding's
    key, code, amount and currency, sorted by key; the same digest as the `cycle.completed` seal);
  - the `YearChecks::checks` statuses;
  - from M9, the number of unreviewed acts.

  Locking with a failing check or unreviewed acts needs a reason, kept as the event's reason. Today the event is
  bare. `year.locked_drift` compares the snapshot's figures with `ReturnSummary::for` now.
- **The seal:** one `cycle.completed` audit event per run, actor `system`, carrying the run id, its counts and the
  findings digest, written inside the transaction that ends the run. A shadow run keeps its digest on the run
  (`cycle_runs.digest`) and writes no audit event.
- **`deploy/restore-check.sh`** (M2): with `--load`, also checks the audit chain of the loaded copy, in SQL, the
  way `AuditLog::verify()` does. It never runs `ledger:verify-audit` there: that command writes a setting and sends
  the Telegram alarm, and a restored copy holds the owner's Telegram token.

### 4.7 Charges and draft invoices (built 25 Nov 2026, outside the cycle's steps)

Clients, jobs and invoices (docs/PLAN.md §6.13) add work that the cycle reads but never does: no cycle step changes a
draft, a charge decision or an invoice email.

**Charge statuses are worked out, not stored** (`Charges\ChargeStatus::for`, read only, a few queries per batch).
Each cost (a payment's line on a job, a cash expense, a booking, a mileage trip, or part of the job's work) is one of
`ChargeStatus::STATUSES`, in this order:

| Status | Means |
|---|---|
| `covered` | The job was billed before Expend tracked where lines come from, or another way (an issued invoice with no sync, income with no Expend invoice through `JobBilling`, or "Already sorted" on its `invoice:{job}` item). Its costs are never ticked or pulled onto a draft: `JobCloseout` asks about them instead |
| `on_invoice` | Charged on an issued (or legacy paid) invoice, or you said it was. A voided invoice is followed to the one that replaced it (up to 5 steps); voiding frees a cost |
| `elsewhere` | Charged another way, or claimed on the client's expense form |
| `to_claim` | The arrangement says their form, and it isn't claimed yet |
| `not_charged` | You said so; or it was left off an issued invoice (unless your recharge decision is newer); or the arrangement doesn't charge it; or its job was cancelled with nothing owed |
| `on_draft` | Ticked on a draft |
| `owed`, `owed_late` | Something left that's expected to be charged (you said recharge, its kind is charged at cost or per mile, or it's work); `owed_late` once the job has a tracked issued invoice: it goes on the client's next draft, "From earlier jobs", and is the to-do list's `charge:{cost}` |

The client page's chips group them (`ChargeStatus::CHIPS`: To charge, On a draft, Charged, To claim, Not charged,
Charged another way). What's charged is the ticked lines on issued invoices whose ref is the cost's, so a ticket charged
once has nothing left when it's later split between jobs; a refund is ticked only within what was charged for its
kind on the job. Two records that may be one cost (`CostUnits` flags the one made second) aren't both charged: once
one is charged, the other is unticked too, until you say they're the same or different (`pair`).

**Your decisions** about a cost are rows in `cost_charges` (`CostCharge::CHARGES`: `recharge`, `no_charge`,
`elsewhere`, `invoiced`; `pair` `same` or `distinct` with its `pair_ref`; `outcome` `paid_back` or `not_paid`), written
only by `Charges\CostCharges` as you and audited: `cost.charge_set`, `cost.claimed`, `cost.charge_cleared`,
`cost.outcome_set`, `cost.paired`. A client's or a job's usual arrangement is `charge_terms` rows (one per kind of cost,
`ChargeTerm::CHARGES`: `per_mile`, `at_cost`, `per_job`, `per_day`, `day_rate`, `their_form`, `none`), written by
`ChargeTermsWriter` from your presets (`ChargeTermsWriter::PRESETS`: `at_cost`, `mileage`, `in_rate`, `travel_fee`) or
your rows, tied to the rows you were shown, and audited `charge_terms.updated`. Expend never learns an arrangement by
itself: only your presets, your rows, or "Save these ticks as Acme's usual" at issue.

**Draft invoices follow the job** (`Invoices\InvoiceDraftSync::sync`, as `policy`, on drafts only; an issued invoice
never changes). A draft gets its job's work (`InvoiceDraftSync::WORK_SOURCES`: `po`, `fee`, `days`, `hours`, `extra`,
`tdays`, `tfee`, `meals`, `cancel`), every cost of the job (ticked where the client usually pays it), its mileage trips,
and "From earlier jobs" (late costs of the same paying client's other jobs, plus client costs with no job you marked to
recharge; each on one draft only). A line follows its cost unless you changed its wording (`own_text`), its amount
(`own_amount`) or its tick (`ticked_by` `you`). A line whose cost has gone is deleted when untouched, and otherwise
stays flagged `gone`, which blocks issuing (`InvoiceDraftSync::BLOCKING`: `gone`, `no_amount`, `check`). A cost in
another currency is listed unpriced and unticked. On a draft made before line sources, the job's work takes over a
typed line only when exactly one has exactly its amount; otherwise work lines aren't added at all, recorded once as
`invoice.legacy_draft`. With nothing changed it takes no lock and writes nothing; otherwise it takes the locks in the
usual order (audit head, then the paying client or the job, then the invoice and its lines) and audits
`invoice.line_added`, `invoice.line_changed`, `invoice.line_removed`, `invoice.line_flagged` and `invoice.filled`. It
runs when a draft is made, saved or issued, when a job's status or client changes, when the check screen opens
(`InvoiceController::show`), and nightly in `ledger:sync-drafts` (03:37, section 6.1); a cost linked to a job in
between reaches its draft on the next of these.

`ledger:sync-drafts --before-rollback`, run before going back to the release before this one, deletes every unticked
line of every draft, typed ones too (that release bills every line), and turns the lines that stay into plain typed
lines (`invoice.rollback_prepared`, as `policy`). Issued invoices aren't touched.

**Issuing** stays your press (`InvoiceService::issue`). It refuses while anything changed since the lines you were
shown (`lines_version`, `seen_up_to`: a stale press gets 409 with the sheet again), and confirms ticked costs whose
payments weren't confirmed only from the issue sheet, each bound to its payment's whole split (`SourceState`), never
in a locked year.

---

## 5. The autonomy policy

### 5.1 Laws (constants in code, not settings)

1. The owner's decision is final. No machine write replaces an owner-made or owner-confirmed line or link, or
   repeats an outcome the owner vetoed.
2. Bank lines are never changed.
3. Nothing irreversible is automatic: a kind without an undo handler has a ceiling of 2.
4. Expend writes freely only to what it owns, and every such write is audited: its own Gmail labels (today
   "✓ Invoiced"), its own Google calendar and its own Gmail drafts. Sending anything to a client (invoices,
   reminders, replies), changing the owner's own calendar entries and moving money always need the owner's press.
   *Amended 24 November 2026 by the owner's mail and calendar decision (docs/mail-and-calendar.md); before that,
   nothing left the app except the "✓ Invoiced" label.* Access is not autonomy: the Gmail and calendar permissions
   held allow more, and this law is what keeps it to these.
5. AI is never the sole basis for an act (PLAN.md §6.4 and §6.8). Hotel stays never act (PLAN.md §6.4).
6. Every act is audited with actor `policy` (or `rule` for a rule), with its run and correlation, and never as
   `you`.
7. **Expend may lower its own autonomy, never raise it.** Only the owner promotes a kind.
8. Locked, filed and amending years are frozen to automation, both for acts and for undo.

### 5.2 Levels

| Level | Meaning |
|---|---|
| 0 off | No proposals |
| 1 watch | Computed and recorded for measurement, not shown. Used only for hold-backs of a kind under consideration, never for detectors. |
| 2 suggest | Today's default: an unconfirmed suggestion the owner accepts, changes or rejects |
| 3 act and tell | Applied overnight, itemised in the morning report with undo, sampled for re-check, listed for sign-off before lock |
| 4 act quietly | Housekeeping only; counted in the report |

### 5.3 Decision kinds, ceilings and this owner's start levels

The start levels equal **today's behaviour**, so bringing existing automation under the policy (M9) changes
nothing the owner sees except that it becomes visible and reversible.

| Kind | Methods | Ceiling | Start | Today |
|---|---|---|---|---|
| `transfer.partner` | transfer.partner | 3 | **3** | Your decision on one side confirms the other |
| `transfer.pair` | transfer.pair | 3 | 2 | Suggested |
| `transfer.exchange` | revolut.exchange | 3 | 2 | Suggested |
| `transfer.one_sided` | transfer.one_sided | 2 | 2 | Suggested |
| `income.invoice` | suggest.invoice, suggest.invoice_set, suggest.invoice_net | **2** | 2 | Suggested. **Not to be raised (the owner's answer, 24 Nov 2026):** no income link (an invoice, a set, net of a fee, or a client from a payer) is ever confirmed by automation; `AllocationWriter` enforces it whatever a level says |
| `tax.hmrc_payment` | suggest.hmrc | 3 | 2 | Suggested |
| `category.rule` | rule.learned, rule.owner | 3 | **3** for rules with `auto_confirm = true` (every rule made from "Always do this": `RuleLearner::accept` line 166); 2 otherwise | Same |
| `category.learned`, `category.pattern` | categorise.*, suggest.penalty/car_hire/travel | 2 | 2 | Suggested |
| `category.ai` | ai.advisor, ai.assistant | 2 | 2 | Suggested |
| `travel.stay` | travel.match | 2 | 2 | Never automatic (PLAN.md §6.4) |
| `mileage.trip`, `job.cost`, `job.create` | JobTrips, job lines, candidates | 2 | 2 | Suggested |
| `link.calendar_job`, `link.document_job`, `link.travel_job` | jobs.* | 3 | **3** | JobFinder links with `linked_by` rules or ai |
| `link.invoice_job` | jobs.invoice_po | 3 | **3** | "Same PO number as the job" |
| `link.receipt_payment` | receipt.match | 3 | 2 | Shown live on the transaction page |
| `category.receipt` | receipt.quick | 2 | 2 | Your receipt's category, job and share offered on its line for one tap; never confirmed by itself (the owner's answer, 25 Nov 2026) |
| `external.gmail_label` | GmailLabeller | 3 | **3** | Hourly `SyncGmailLabels` |
| `evidence.retry`, `evidence.balance_snapshot` | acquire steps | 4 | 4 | n/a |
| `suggestion.withdraw` | relate.sweep | 4 | 3 | n/a |
| `finding.close` | detect.sync | 4 | 4 | Same |

**Never a kind, whatever the settings:**

- lock, unlock or file a year;
- accept a held statement or document, or release a held sender;
- delete or withdraw evidence;
- create, change or retire a rule;
- raise an autonomy level;
- dismiss a finding;
- send anything to a client;
- change the owner's own calendar entries;
- move money.

Writes to Expend's own labels, calendar and drafts (law 4) are kinds like any other: audited, and recorded as acts
from M9.

### 5.4 Gates for acting (level 3 or 4)

All gates run in one transaction with `lockForUpdate` on the subject, so an owner decision made at the same moment
wins. A proposal that fails any gate stays a suggestion, and the failed gates are stored and shown ("Would have been
confirmed on its own, but there are two invoices of £850").

| Gate | Test |
|---|---|
| G1 | The level is at least 3; not paused; no health block tonight (audit chain broken, or the verify step failed) |
| G2 | The subject hasn't been touched by the owner (AllocationWriter's guard); no `ask` veto (an "always ask me" pin) on the subject, merchant, account, job or client |
| G3 | No `block` veto, and no rejection in the existing stores |
| G4 | An identity rule holds (same-file exchange legs; a standing owner rule), or at least two **independent evidence families** agree (bank, a second bank, owner records, a grounded document, email metadata, calendar, reference data). No family disagrees. AI counts at most once and never alone. No laundering. |
| G5 | Unambiguous: a single candidate, or the method's required margin over the runner-up |
| G6 | The measured lower bound (section 2.3) is at least the bar: **95% for ledger kinds, 90% for links**, from at least 30 outcomes including at least 10 blind ones, with no wrong outcome in the last 30 days |
| G7 | Within caps: £2,000 per act (£500 for `category.*`), 25 per kind per night, 60 in total per night |
| G8 | The tax year is open: not locked, filed or amending |
| G9 | An undo handler exists and the before snapshot is captured |
| G10 | Fresh: the subject's and evidence's hashes are unchanged since the proposal was evaluated |

**Kinds that start at level 3** (behaviour preserved) run with G1-G3 and G7-G10 enforced. G4-G6 are measured and
shown on the Autonomy page but not enforced until the owner reviews the kind there. Demotion applies to them in
full.

**The first kinds offered for promotion** (never enabled automatically):

- `transfer.pair`: both sides imported, exact amount, single candidate, strength of 6 or more. An incoming line
  that names a client or a payer alias is never paired (the existing −2 in `TransferPairer::strength`, made
  absolute).
- `tax.hmrc_payment`: the payee matches `HMRC_PATTERN`, **and** either the amount equals an obligation from
  `PenaltyEstimator::forYear` or `TaxSavings`' payments on account, or the reference contains the year's
  `hmrc_reference`.
- ~~`income.invoice`~~: **not offered** (the owner's answer of 24 Nov 2026: income links are never confirmed by
  automation, so its ceiling is 2; see 5.3). The design was: exactly one open GBP invoice with that total, nothing
  received yet, and the payer known from at least two owner-confirmed payments by that client.

### 5.5 Reversibility, verification and demotion

**Undo.**

- **One act:** restores `before` through the same writer, as `you`. For links it also records a rejection. The
  outcome counts as wrong.
- **A whole run:** each act is restored, the outcomes are neutral, and every kind involved is suspended until the
  owner reviews it.
- Undo never overwrites a subject the owner has touched since. A locked year blocks undo as it blocks acts.

**Verification.**

- `verify.new` re-checks every act in the same run. If the result is failed, the act is undone at once (audited)
  and an important finding is raised.
- The next night, 20% of acts (at least one per kind) are re-checked by a different checker. A disagreement undoes
  the act and suspends its band.

**Demotion** (automatic and audited):

| Trigger | Effect |
|---|---|
| 1 owner override | That band is suspended for 14 days, with a finding |
| 2 overrides in 90 days | The kind drops to level 2 |
| A sampled re-check disagrees | The act is undone and the band suspended |
| The lower bound falls below the bar | The band is suspended |
| A rule overridden twice | `auto_confirm` goes off, and an important "Retire this rule?" finding offers `RuleLearner::undo` |
| Audit chain broken, or verify failed | Everything behaves as level 2 that night |

**Promotion:**

- Offered only when G6 is met.
- The offer comes with a **30-day rehearsal**: "At level 3 it would have confirmed 41; you later changed 0."
- Accepted only by the owner. The first promotion of any kind to level 3 must be confirmed on the web, not
  Telegram.
- Audited as `settings.autonomy`, with the owner's reason.

### 5.6 The owner's controls

- **Master pause:** `intel_autonomy_paused`, also `/pause` and `/resume` in Telegram, from the paired chat only.
- **Per kind:** level up to the ceiling, a stricter bar and caps, and a view of what the kind did.
- **Pins:** "Always ask me about this merchant, account, job or client" (an `ask` veto).
- **"Not this one"** with reason chips: wrong category, not business, wrong job, personal this time, "this
  merchant varies" (which also adds a pin). Every veto is visible and can be lifted.
- **Undo** an act or a run from the report, the why-panel or Telegram.
- **Sign-off before lock:** `/autonomy/sign-off` lists rule and policy confirmations not yet reviewed, by kind with
  totals, with "These look right". `YearChecks::checks` gains "N automatic decisions not reviewed". This builds
  PLAN.md §6.4's sign-off list.
- **Detector settings:** each kind of finding can be raised (shown in full) or lowered (below the fold) from the
  to-do page (M4, `Intel\DetectorLevels`); switching a detector off is still to come.

---

## 6. The cycle run on shared hosting

### 6.1 Schedule

Only the existing cron (`schedule:run` every minute) and the existing every-minute
`queue:work --queue=default,reads --stop-when-empty --max-time=240 --tries=1` run (the `reads` queue, read after
`default`, holds the AI reads of receipt photos from the phone). No daemon is added, and no long process runs in the
foreground of `schedule:run`: Laravel runs due events in sequence, and nothing in `routes/console.php` uses
`runInBackground()`, so a long foreground tick would delay `fetch-email`, `queue:work` and the heartbeat.

- **`ledger:cycle tick`** **(new, M5)** is scheduled every minute and defined **before** `queue:work`. It reads one
  indexed row. If a run is due it creates the run; if a step is runnable it dispatches `App\Jobs\RunCycleStep`
  **(new**, `ShouldBeUnique` per run**)**. It returns in under a second and never runs a step itself. It is skipped
  when `intel_cycle` is `off`.
- **The work runs in the existing queue worker.** `RunCycleStep` works until its step deadline, saves its cursor,
  returns `more`, and the next tick dispatches it again. This is the pattern `ScanGmail` already uses. Its job
  timeout is 150 s, a backstop: a step that overruns is stopped by the worker and noticed by the next tick.
- **Timezone:** every nightly entry is pinned to Europe/London. Today `verify-audit`, `transfers`, `categorise`,
  `find-jobs`, `recheck` and `findings` run in UTC (`APP_TIMEZONE=UTC`) and move by an hour in summer.

| Run | Due | Steps |
|---|---|---|
| nightly | from 03:05 London; hard deadline 06:30 | verify.audit_chain, acquire.health, acquire.retry_reads, relate.transfers, relate.receipts, relate.route (M8), relate.categorise, relate.jobs, relate.sweep (M7), verify.new (M8), verify.backlog, verify.reconcile, decide.policy (M9), detect.todo, detect.*, detect.sync, learn.outcomes (M10), preserve.seal |
| morning | from 07:05, after Starling's 06:41 import | acquire.health, detect.*, detect.sync, recommend.report (at `intel_report_time`), recommend.alerts, preserve.seal |
| import | queued after an owner upload or a Starling import | detect.todo, detect.sync, preserve.seal. **As built (M5)** this is not yet limited to the account: the batch and account refs are kept as the steps' scope, and detect.sync runs with complete coverage, so nothing is resolved wrongly. Scoping needs `FindingsRegistry` support |
| manual | "Check now" on `/intel` (`POST /intel/check`, at most 6 a minute) | detect.*, detect.sync, preserve.seal |

**Outside the cycle, whatever its mode** (clients, jobs and invoices, 25 Nov 2026; `routes/console.php`). No cycle
step touches drafts or invoice emails, so these run on their own schedule even when the cycle is `on`:

| Entry | When (London) | What |
|---|---|---|
| `ledger:sync-drafts` | 03:37 daily, `withoutOverlapping` | Every open draft brought up to date with what's linked to its job (`InvoiceDraftSync`, as `policy`); section 4.7 |
| `invoice-emails-check` (`App\Jobs\CheckInvoiceEmails`) | hourly at :23, `withoutOverlapping` | The drafts Expend put in your Gmail: sent from Gmail (recorded), deleted unsent, edited; and Expend's own unedited reminder drafts taken out once they aren't needed (`ChaseLadder::tidy`). It never writes a draft |
| `ledger:invoice-nudges` | 09:13 daily (it was weekdays) | Telegram about drafts waiting, invoices not sent and overdue invoices; buttons only (section 4.5) |
| `ledger:job-finished` | 08:37 daily, as before | Telegram: "All done?" the morning after a job's last day (section 7.6) |

Reminders are never scheduled: `ChaseLadder::make` runs only on your press.

`detect.*` is one `detect.<code>` step per registered detector (`Intel\Detectors\Registry`), `detect.todo` first, so
a detector added to the registry joins the runs without other wiring. An import or "Check now" asked for while a run
of the same mode is still to record its findings is covered by that run instead of starting another.

**The rollout flag `intel_cycle`:**

- `off`: today's schedule. The tick is skipped, and no run starts.
- `shadow`: every step that would change a record (`CycleStep::writes()`) is skipped; the others run and record what
  they *would* open, close or mark unseen in `cycle_run_steps.counts` (detect.sync works it all out inside a
  transaction it rolls back). Nothing else in the ledger is written, the legacy schedule keeps running, and no
  Telegram message is sent. One week of runs is compared before switching. A shadow run writes only its own
  `cycle_*` rows: it keeps its findings digest on the run but writes no `cycle.completed` audit event. On the
  database cache store, locks and cache entries are still taken and let go; these hold no ledger data.
- `on`: the legacy nightly entries (`ledger:transfers`, `categorise`, `find-jobs`, `recheck`, `findings`,
  `verify-audit`) stay defined with `->skip(fn () => CycleRunner::doesNightlyWork())`, so they remain manual entry
  points. They are skipped only when tonight's nightly run is a real one (the flag is on **and** tonight's run
  started in `on`), so switching during the night neither drops nor doubles the night's work: switched on after a
  shadow run started tonight, the legacy entries still do tonight's work and the cycle takes over the next night. A
  step that changes records runs only while the flag is on now, whatever its run started as.
- The flag is switched on the server with `php artisan ledger:cycle mode off|shadow|on` (audited as
  `settings.intel_cycle`); there is no web switch. Switched off, the runs still going are ended and sealed; switched
  away from `on`, documents waiting for AI budget go back to `failed`, where Read with AI offers them.
- `ledger:cycle status` shows the mode and the last five runs with their steps; `ledger:cycle run [nightly|morning|
  manual] [--force]` runs one now in the foreground (a trial while the flag is off).

### 6.2 Budgets

**Time.**

- A step job has a budget of 90 s at night and 30 s by day, so Telegram presses queued behind it stay quick. Even
  if the worker starts a step at its 240 s mark, the process ends within about 330 s.
- Steps call `$ctx->hasTime()` between chunks of 300 rows and save `{last_id}` cursors.
- At 06:30 (12:00 for the morning run, 30 minutes after it starts for an import or "Check now"), unfinished steps
  are marked `skipped` and the run ends `partial`; a detector that didn't finish never resolves findings.

**AI.**

- **Envelopes:** the monthly cap (`ai_monthly_cap_usd`, default $40) is split into purpose shares in
  `ai_usage_YYYY_MM.by_purpose`: assistant 30% (the reserve, never used by the cycle), reading 40%,
  verify 10%, discovery 20%. After the 20th of the month (from day 21), a purpose may borrow unused share, never
  the assistant's. **The shares apply only while `intel_cycle` is `on`** (`AiUsage::sharesApply()`); off or in
  shadow, every call is held only to the whole cap, as before M5. Spend is still recorded by purpose in every mode.
  A call made outside any purpose (the assistant, or Read with AI pressed on a page) counts as the owner's own:
  `assistant`. Spend from before purposes were recorded counts against the cycle's part.
- **Purposes as built:** `reading` for `ReadDocument` and `ReadTravelEmail` (and `RetryTravelRead`), `verify` for
  `ledger:recheck` and `verify.backlog`, `discovery` for the job finder's AI pass.
- **Nightly allowance:** min(`intel_ai_night_usd`, (cap × 70% − cycle spend this month) ÷ days left).
- **Calls** go through `AiUsage::guard`, which reserves the call's maximum cost, and `AiUsage::record`, which
  settles the actual cost, both under `DB::transaction` with `lockForUpdate` on the settings row (M2). Today
  `record` is a read-modify-write and `guard` only reads.
- **Running out:** a step ends with status `budget`, which is not a failure; the work carries over.
- **Redaction:** discovery prompts (AI calendar verdicts, blind second reads) pass through `Intel\Redactor`, which
  removes the owner's names, account and card digits, addresses and references (M8).

### 6.3 Idempotence and locks

- `run_key` is unique, so there is one nightly run per date. Steps are unique per run and resume from their
  cursors.
- Every step is safe to run twice: pairing, categorising, upserting findings by key, re-checks, and acts (unique
  per kind, subject and run).
- With M2's no-op, AllocationWriter writes nothing for an unchanged suggestion.
- `Cache::lock('intel:cycle', 300)` (`CACHE_STORE=database` supports atomic locks) prevents two step jobs at once.
  `Cache::lock('intel:jobfinder')` is shared by `relate.jobs`, `ledger:find-jobs` and `FindJobs`.
- `ledger:cycle run --force` re-runs a finished run under a new key. It is for the developer and never scheduled.

### 6.4 Run log, seal and failure handling

- `cycle_runs` and `cycle_run_steps` record, per step: status, counts, coverage, scope, cursor, attempts, error and
  times. Per run, they record the AI budget and spend, the findings digest, the report and the seal. That is about
  20 rows a night, kept indefinitely.
- **Shown** at `/intel/runs` **(new)** and read by `ledger:check` (`app/Console/Commands/CheckIntegrations.php`).
- **Failures:**
  - A step that fails is retried up to 3 times. Its dependents are skipped, its independent successors still run,
    and the run ends `partial`.
  - A failed step raises a `health.cycle_step` finding (key `health:step:<key>`, from
    `Intel\Detectors\CycleStepFailing`, M5), with the number of runs running it has failed in its meta
    (`nights`). It is important, or critical when it fails two nights (or mornings) running or the step is
    `verify.audit_chain` (critical from its first failure: `Intel\Ranker`). The error stays in the run log, never in
    the finding.
  - A run whose `heartbeat_at` is more than 15 minutes old (the worker was stopped mid-step) has its current step
    resumed from its cursor, at most twice. The third stall counts the step as failed, like a step that threw three
    times, and the rest of the run carries on. A run that stalls 6 times in all is `abandoned`.
  - `ledger:check` shows the mode, the latest run and each step that failed in it, with its error cleaned of keys;
    a failed step, or a run that failed or was abandoned, counts as a problem.
- **The seal:** one `cycle.completed` audit event per run anchors the run in the hash chain.

---

## 7. Owner surfaces

### 7.1 The morning report: "What Expend found and did overnight"

It is sent to Telegram at `intel_report_time` (07:30) when anything happened, and kept at `/intel` **(new**,
`resources/js/pages/intel/index.tsx`**)**. A card on `resources/js/pages/dashboard.tsx` shows its first lines. Each
section is left out when it's empty; on a quiet night the report is one line.

1. **Found:** new findings ranked by the Ranker, with money by direction and never one mixed total. Example: "Arena
   load-in: PO £1,200.00, invoiced £1,050.00, hotel recharge £180.00 not on the invoice."
2. **Did on its own:** acts by kind, with counts, money, per-act Undo and "Undo everything from last night".
   Before M9, this section lists what the existing automation did, from audit events with non-`you` actors, with no
   Undo buttons.
3. **Spot-check these 3:** sampled automatic confirmations, once there are any (always empty until M9 records
   acts).
4. **Dealt with:** findings resolved since yesterday, with their cause (an import batch, your action, data
   changes).
5. **Needs you:** counts by group.
6. **Health:** source freshness, failed steps, AI spent against tonight's allowance, and any kind of finding
   demoted by its false positives since the last report (`DetectorLevels::demotedSince`, named once).

Findings of a kind still below the fold are listed apart on `/intel`, and kept off Telegram and out of the money
totals. Notes (the AI spent, routine double-checks, reads waiting for budget, a demotion) never make a night worth a
message on their own.

Telegram carries merchant names and amounts, as the Monday digest does today, but no account numbers, references
or addresses. `intel_report_detail = counts` reduces it to counts and a link.

### 7.2 The to-do list (same page and buttons)

Each item gains:

- a grade chip;
- wording from its direction: "you may be owed", "you may owe", "missing from your records", "could claim";
- a **Why** expander.

Within each priority, items are ranked by the Ranker. New groups:

- `health` (priority 1);
- `tax` (HMRC payments, filed drift);
- `claims` (unclaimed mileage, savings);
- `closeout`;
- `phone`, "From your phone" (quick add's nine checks; their buttons are the `qx` action, `<entry>:<verb>[:arg]`, bound
  to what was shown by its fingerprint like every other to-do button).

Snooze and Not relevant take a reason, kept in `todo_dismissals.note` and `findings.note`. Undoing a dismissal is
audited (today `TodoController::dismiss` audits a dismissal but not its undo, and never writes the note).

The footer says what was checked, so discovery is visible even when nothing is wrong: "Checked overnight: 312 HMRC
payments, 41 finished jobs, 2 filed years."

### 7.3 Proposals with evidence

Machine suggestions appear in the Inbox (`resources/js/pages/inbox.tsx`), and link proposals at `/intel/proposals`
**(new**, M7**)**. Each card shows:

- the claim in one sentence;
- its grade and basis, for example "Unmeasured: matched hotel name and amount" or "Strong: 58 of 58 right, 31 decided blind";
- evidence chips, one per resolved ref, each opening the source with the quote highlighted, for example "Starling
  statement · 3 Mar · row 41" or "Email from Premier Inn · 1 Mar · 'Total £186.00'";
- the checks and how independent each was;
- **the alternatives considered**, for example "Also possible: job 41, 2 points behind";
- the impact;
- buttons: Accept; **Not this** (reason chips); Later.

**As built (M7):** the Inbox's "Also possible" lists the alternatives stored with each allocation (M6 provenance), so
suggestions made before M6 show none. `/intel/proposals` lists open automatic links with Not this; a stay's matched
payment has no Accept there (it links to Stays & travel, where the stay is decided), and only draft invoices are listed
for an invoice-to-job link (an issued invoice is never changed).

### 7.4 Why-panels

`resources/js/components/why-panel.tsx` **(new)** is fed by `GET /why?ref=` (`app/Http/Controllers/WhyController.php`
**(new)**). It appears on the transaction drawer (`item-drawer.tsx`), Inbox suggestions, findings, job pages
(`jobs/show.tsx`), travel items and documents. It shows:

- the link's state and actor in plain words ("Suggested by matching: your rule for TRAINLINE");
- the evidence refs, with a warning when a pinned source has changed since ("The booking now says 3 nights; you
  decided on 2");
- the grade and its basis;
- the audit history, including knock-on changes that share a correlation;
- the act, its gates and its undo, for an act;
- actions: Accept, Not this one, Lift veto, Undo, and "Always ask me about …".

### 7.5 Other pages

- **Findings history** (`todo-history.tsx`): amount history, the cause of each resolution, unseen versus resolved,
  the owner's reasons, and filters by job, account or year (`scope_ref`). Money is totalled by direction and
  currency.
- **Settings › Autonomy** (`resources/js/pages/settings/autonomy.tsx` **(new)**): per kind, its level and ceiling,
  what it did, its measured record (counts beside every percentage, blind beside shown), suspensions and their
  reasons, promotion offers with their rehearsal, the master pause, pins and vetoes. Learned rules appear here with
  their override counts.
- **Settings › Rules** (`settings/rules.tsx`): the wording "Auto-confirm is off unless you turn it on" is corrected
  in M2. Rules made from "Always do this" do confirm on their own.
- **`/intel/runs`:** the step log.

### 7.6 Telegram

- The morning report; the Monday digest (`ledger:telegram-digest`), ranked and opening with "Since last week: N
  new, M dealt with, £X recovered".
- **Alerts** (`recommend.alerts`, in the morning run): critical findings are sent at once, at most
  `intel_alerts_per_day` (3) a day and deduplicated by key (kept in `intel_alerts_sent`; a finding dealt with and
  back is alerted again). Examples: Starling failing two days running, a penalty step within 7 days, a cycle step
  failing two nights running. The order is due within 7 days first (soonest first), then the newest. The first
  time alerts are sent for real, critical findings open for over a day and not due within 7 days are taken as known
  (they're on the to-do list and in the Monday digest), so switching the cycle on doesn't flood the chat. Not
  alerted here: a broken audit chain (its own alarm, sent by `verify.audit_chain` and `ledger:verify-audit`),
  `invoice.overdue` (left to the invoice nudge, daily since 25 Nov 2026, which carries its Make a reminder button;
  `RecommendAlerts::NOT_ALERTED`), and kinds below the fold.
  A shadow run only counts what it would send.
- **Buttons:** Snooze a week and Not relevant with preset reasons ("Both charges were real", "Refunded", "Not
  mine", "Other") from M3; Undo an act or the run from M9; Allow / Not yet on promotion offers (the first promotion
  of a kind needs the web); `/pause` and `/resume`.
- **Callback data** becomes `t|code|id|fp`, where fp is a 6-character fingerprint of the state shown, within the
  64-byte limit (M7). `handleButton` splits into 4 parts and still accepts old 3-part buttons for codes that don't
  decide anything. A press on changed state answers "This changed since: open it in Expend". **As built:** a button
  whose data wouldn't fit is left out, never cut; "Snooze a week" decides something, so an old 3-part snooze button is
  refused; a press on something already settled says "Already dealt with".
- Only the paired chat is accepted (as today). The channel is recorded in the audit context.
- **As built (clients, jobs and invoices, 25 Nov 2026):** `ledger:job-finished` asks "Arena load-in: last day was
  yesterday. All done?" with [Done] [Not yet] / [Cancelled] [Open the job], once per job and finish
  (`settings.job_finished_told`), and once more the morning a "Not finished yet" snooze ends; three or more jobs on one
  morning go as one message. Only the jobs' names (cleaned by `MorningReport::clean`) and links are sent. After a press,
  `Telegram::handleButton` edits the message with `TodoActions::followUp`'s buttons (links and Undo). Invoice messages
  (`ledger:invoice-nudges`) and their quiet rules are in docs/mail-and-calendar.md.

### 7.7 The assistant

Two read-only tools are added to `app/Domain/Ai/AssistantTools.php` (M4):

- `findings`: open discrepancies for a year, job or account;
- `evidence_for(ref)`: resolved labels and check results only.

Neither sends document text beyond what `read_document` already returns. Proposals made by `propose_decision` carry
`ask:{assistant_logs.id}` as evidence.

---

## 8. Milestones

Each milestone ships and rolls back on its own. Each passes the full suite on **SQLite** (CI today) and
**MariaDB** (the CI job added in M1, `DB_CONNECTION=mysql` against a `mariadb` service at the live server's major
version; confirm the version from the live database first, and use 10.11 otherwise). Locally,
`DB_CONNECTION=mysql php artisan test` runs the same suite, as `BackupRestoreTest` already does.

**Ordering constraint.** The work in progress on penalties and the law library (task 50, `wip/law`) touches
`TodoList`, `TaxYearController`, `PenaltyEstimator`, `AiUsage` and `Telegram`. M1 starts after that merge, and each
later milestone rebases on whatever has merged. Task 52 (job opportunities, PO chase) touches `JobFinder` and
`DocumentReader`: coordinate before M7 and M8.

### M1. First discoveries: three money findings, no migrations

**Scope.**

- Three read-only detectors as new `TodoList` groups, recorded by `FindingsRegistry` like any other finding:
  - **`hmrc:{tx}`** (group `tax`, priority 2): a money-out line matching `Suggester::HMRC_PATTERN` (made `public`).
    It is found by an SQL `LIKE` prefilter on description and counterparty, then the regex, and qualifies when the
    line has no allocation or an allocation other than `tax_payment`. The title is "Payment to HMRC not recorded as
    tax: £X on date". Action `tx_tax` (decide as `tax_payment`, as you). In a locked year there is no action, only
    a link.
  - **`mileage:{year}`** (group `claims`, priority 2): one item per open year from `JobTrips::proposals($year)`.
    Example: "6 finished jobs with no mileage: about 1,240 miles, about £558 to claim". The deduction uses
    `TaxParameters::for($year)['mileage']` (`car_van_first` up to `threshold_miles`, then `car_van_after`), counting
    miles already in `MileageCalculator::forYear($year)` towards the 10,000-mile threshold. The link goes to `/mileage`, where
    `MileageController::fromJobs` already adds them. Without `home_postcode` it shows one setup item instead.
  - **`drift:{year}`** (group `tax`, priority 1 when the amend-by date is within 60 days, else 2): for
    `tax_years` with status `filed` or `amending` and `filed_*` set, compares `ReturnSummary::for($label)`.
    Example: "2024-25 profit is £640 higher than the return you filed: amend by 31 January 2027." The amend-by date
    is `FilingDates::deadline` plus one year.
- `TodoList::WATCHED` gains `mileage_trips`, `job_days` and `vehicles`, so the kept list refreshes when they change.
- Two registry fixes that keep these findings' history honest:
  - `FindingsRegistry::sync` reads `TodoDismissal::activeKeys()`;
  - `TodoList` records `$failures` (the `PenaltyEstimator` catch) and `$capped` (the two `limit()`s). `sync` leaves
    findings from a failed or capped group open instead of resolving them.
- The to-do footer line "Checked: N HMRC payments, M jobs, K filed years".
- The MariaDB CI job.

**Files.**

- `app/Domain/Todo/TodoList.php`, `FindingsRegistry.php`, `TodoActions.php`
- `app/Domain/Matching/Suggester.php`
- `app/Http/Controllers/TodoController.php`
- `resources/js/pages/todo.tsx`
- `.github/workflows/tests.yml`
- `tests/Feature/Intel/FirstDiscoveriesTest.php` **(new)**
- `tests/Feature/FindingsTest.php`

**Migrations.** None.

**Tests.**

- A payment to "HMRC SELF ASSESSMENT" decided as personal raises `hmrc:{id}`; one decided as `tax_payment` doesn't;
  `tx_tax` decides it and the item goes.
- A finished job 40 miles away with no trips appears in `mileage:{year}` with the right miles (straight line ×
  1.25) and the year's rate; a job reached by train doesn't.
- A filed year whose ReturnSummary differs by £1 or more raises `drift:{year}`, with the amend-by date.
- An expired snooze on a vanished finding closes as `resolved`, not `snoozed`.
- A thrown `PenaltyEstimator` leaves `penalty:{y}` open.
- The `TodoList` query count stays within a set bound.
- All of the above on SQLite and MariaDB in CI.

**Acceptance.**

- On a restored copy of the live database, `/todo` shows each of the three groups wherever the data has them, and
  the footer shows the counts checked.
- The Monday digest includes them.
- `/todo/history` records them with first-seen dates.
- Both CI jobs are green.

**Risks.**

- Noise: the items are priority 2 and can be snoozed.
- `JobTrips::proposals` cost on each build: bounded to open years; the list is already kept for 30 minutes.

### M2. Honest records: correctness and trust fixes, no migrations

**Scope.**

- **Grounding:** amounts are compared in the raw span, with separators (section 4.3).
- **AI usage:** atomic `AiUsage::record` and `guard` with reserve-then-settle (section 6.2).
- **AuditContext in memory:**
  - `AllocationWriter::confirm` and `clear` take their actor from it;
  - TransferPairer's partner confirm logs `matcher`; JobGatherer and TravelMatcher clears log `matcher`;
    GmailLabeller logs `system`;
  - `Verifier::save` audits with its real subject.
- **AllocationWriter:** `lockForUpdate` and the no-op for unchanged lines.
- **TransferPairer:**
  - `pairAll` writes a `transfer.paired` audit event;
  - it refuses ties (equal top strength and distance) and leaves both lines for the owner.
- **Categoriser:** `isOurs` excludes Suggester's three "Looks like …" reasons.
- **Nudges:** `ledger:invoice-nudges` respects snoozes.
- **Health:**
  - `ledger:verify-audit` failure raises a `health` to-do (priority 1) and sends a Telegram message at once;
  - TodoList gains a `health` group: chain broken, `starling_last_result` failed, `calendar_synced_at` over
    2 hours, `failed_jobs` present, documents failed on the AI cap.
- **Timezones:** nightly schedules pinned to Europe/London.
- **Restore check:** `deploy/restore-check.sh --load` checks the audit chain of the loaded copy in SQL (never
  `ledger:verify-audit`, which writes and sends the Telegram alarm).
- **Rules page:** `settings/rules.tsx` wording corrected.

**Files.**

- `app/Domain/Documents/Grounding.php`
- `app/Domain/Ai/AiUsage.php`, `app/Domain/Ai/AnthropicAiClient.php`
- `app/Domain/Intel/AuditContext.php` **(new)**, `app/Domain/Audit/AuditLog.php`
- `app/Domain/Ledger/AllocationWriter.php`
- `app/Domain/Matching/TransferPairer.php`, `app/Domain/Matching/Categoriser.php`
- `app/Domain/Jobs/JobGatherer.php`, `app/Domain/Travel/TravelMatcher.php`
- `app/Domain/Gmail/GmailLabeller.php`, `app/Domain/Verification/Verifier.php`
- `routes/console.php`, `app/Domain/Todo/TodoList.php`, `app/Domain/Telegram/Telegram.php`
- `deploy/restore-check.sh`, `resources/js/pages/settings/rules.tsx`
- Tests: `tests/Feature/Intel/{GroundingAmountsTest,HonestActorsTest,AllocationWriterLockTest,AiBudgetTest,HealthTest}.php` **(new)**

**Migrations.** None.

**Tests.**

- Text "Crew fee £1,250", quote "£12.50", amount 1250 → not verified; the correct quote → verified.
- Two usage records add up exactly. A reserve that would pass the cap is refused.
- A partner confirm, a clear inside `GatherFromGmail`, and a Gmail label each carry the right actor; a web
  decision carries `you`.
- A second identical `decide()` writes no audit event.
- Two £100 candidates with equal strength → no pair.
- `Categoriser::learnFrom` leaves an HMRC suggestion alone.
- A snoozed overdue invoice gets no nudge.
- A tampered event raises the health item and one Telegram call.
- `Schedule::events()` shows Europe/London on the nightly entries.

**Acceptance.**

- After deploy, the nightly audit volume drops, because unchanged suggestions are no longer re-audited.
- New automatic events carry `matcher`, `system` or `checker`.
- A forced chain break on a restored copy produces the alarm.
- The restore check verifies the chain.

**Risks.**

- Stricter grounding turns some facts unverified at their next re-check, so the failed-checks list grows. This is
  correct; say so in the release note.
- Lock contention is negligible for one owner.

### M3. Findings that remember

**Scope.**

- The `findings` columns and `finding_events` (section 3).
- `Intel\Ref`, `RefResolver` (the kinds findings use), `Grades`, the `Detector` contract and registry, and
  `TodoGroupsDetector` (the 4.4 table).
- M1's three detectors become `Detector` classes.
- Coverage-aware `sync`: resolved, unseen or `detector_failed`.
- Stable keys `gap:{a}:{from}` and `stale:{a}`, with `rekeyed` events that keep `first_seen_at`.
- Amount history as `changed` events.
- A duplicate's amount counts only the extra charges.
- Direction, grade and severity; the `Ranker` orders items within each priority.
- Dismissal reasons are written; undo is audited.
- Telegram Snooze a week and Not relevant buttons with preset reasons (`fnd_snooze`, `fnd_dismiss`; id
  `{finding}:{reason}`).
- The history page shows events, causes and scope filters.
- Backfill `findings`.

**Files.**

- `database/migrations/2026_12_10_000001_findings_lifecycle.php` **(new)**
- `app/Models/Finding.php`, `app/Models/FindingEvent.php` **(new)**
- `app/Domain/Intel/{Ref,RefResolver,Grades,Ranker}.php` **(new)**
- `app/Domain/Intel/Contracts/Detector.php` **(new)**
- `app/Domain/Intel/Detectors/{TodoGroupsDetector,HmrcUnallocated,TravelUnclaimed,YearFiledDrift}.php` **(new)**
- `app/Domain/Todo/{FindingsRegistry,TodoList,TodoActions}.php`
- `app/Http/Controllers/TodoController.php`
- `app/Domain/Telegram/Telegram.php`
- `app/Console/Commands/IntelBackfill.php` **(new)**
- `resources/js/pages/{todo,todo-history}.tsx`

**Migrations.** One.

**Tests.**

- A capped group produces `unseen`, never `resolved`.
- A `PenaltyEstimator` exception produces `detector_failed`, and the finding stays open.
- An expired snooze produces `snooze_ended`.
- A partly filled gap produces `changed` on the same finding.
- A rekey keeps `first_seen_at`.
- Totals stay separate by direction and currency.
- The backfill is idempotent on fixture rows, on both databases.
- A Telegram "Not relevant · Refunded" stores the note and an audit event.
- Every evidence ref resolves.
- Detectors never write (`DB::listen`).

**Acceptance.**

- On the restored live copy, after one `ledger:findings`, no open finding is falsely resolved.
- Every finding has a code, and those with a parsable key have a subject and direction.
- The history page shows why each finding closed.

**Risks.**

- Backfill parsing misses some keys: those fields stay null and the finding still works.

### M4. Money discoveries, batch 2, and the year-lock snapshot

**Scope.**

- **Detectors** (section 4.4): `job.closeout`, `travel.double_claim`, `income.unlinked`, `receipt.unmatched`,
  `cost.no_receipt`, `dupe.cross_source`, `rule.drift`, `tax.saving` (the `TaxSavings::review` adapter; group
  `claims`), `acct.unchecked`, `check.unverifiable`, and the `health.*` set.
- **`Intel\ReceiptMatch`:** the same-amount match extracted from `TransactionController`, so the page and the
  detectors agree.
- **Actions:** `trips_add`, plus the existing `job_invoice` on close-out items.
- **The `TaxYearController::lock` snapshot** and reason rule (section 4.6), and `year.locked_drift`.
- **Assistant tools** `findings` and `evidence_for`.
- **New detectors start at `info`.** The owner can raise one, and the report says when one is demoted by its false
  positives.

**Files.**

- `app/Domain/Intel/Detectors/*.php` (`Health` and eleven money and check detectors), `app/Domain/Intel/ReceiptMatch.php`,
  `app/Domain/Intel/DetectorLevels.php` **(new)**
- `app/Domain/Intel/{Grades,Ranker,RefResolver}.php`, `app/Domain/Intel/Detectors/{Registry,TodoGroupsDetector,TravelUnclaimed}.php`
- `app/Http/Controllers/TransactionController.php`, `app/Http/Controllers/TaxYearController.php`, `app/Http/Controllers/TodoController.php`
- `app/Domain/Todo/{TodoList,FindingsRegistry,TodoActions,Dismissals}.php`, `app/Domain/Ai/AssistantTools.php`, `app/Domain/Tax/YearChecks.php`
- `resources/js/pages/{todo,todo-history}.tsx`, `resources/js/pages/years/show.tsx`, `resources/js/components/year-lock-panel.tsx` **(new)**
- `tests/Feature/Intel/Detectors/*Test.php`, `tests/Feature/Intel/{YearLockSnapshot,AssistantFindings}Test.php` **(new)**

**As built.**

- Demotion follows section 2.3 as rewritten there: M4's kinds by "more than one in ten", the long-standing kinds by
  "10 dismissals and over half wrong". The findings run demotes; the morning report (M5) names it.
- The lock keeps the exported file instead of exporting again (section 4.6).
- A close-out finding on a finished job subsumes its `underbilled:` and `recharge:` items: their findings are
  resolved as superseded, citing it (`fnd:`), and an item you hid stays hidden. A recharge on a line that only names
  the provider counts as not yet billed.
- `dupe.cross_source` flags a generic cash description ("Parking") beside a named card merchant, graded `possible`.
- `rule.drift` counts `allocation.cleared` events only from `RuleDrift::HONEST_FROM` (2026-11-24, when M2 made
  actors honest); owner decisions count from any date.
- `health.mailbox` reads `settings.mailbox_last_result`, written by `ledger:fetch-email` since the M4/M5
  integration.
- Run `php artisan ledger:findings` once after deploying (or leave it to the nightly run), so the new kinds are
  recorded before the history page is opened; the history page queues a findings run rather than waiting for it, so
  the queue worker must be running.
- Live-data acceptance (each detector's time bound on a restored live copy, and the hand review of a sample) is done
  before release; in development a 4,000-line fixture keeps every detector under 10 s and the list under 200 queries.

**Migrations.** None.

**Tests.**

- A fixture per detector checks the key, subject, refs, integer-pence amount, direction and grade.
- The Arena example end to end: PO £1,200, invoiced £1,050, a £180 recharge not on the invoice lines → one
  close-out finding of £330 owed to you.
- Mileage plus a train on the same job day → `travel.double_claim`.
- The lock event carries the figures and the export sha; locking with a failing check needs a reason.
- The assistant tools return no document text.
- Detectors never write.

**Acceptance.**

- On the restored live copy, each detector runs within its time bound.
- A sample of findings is reviewed by hand; any detector with more than one false positive in ten is demoted
  before release.
- The lock event can be shown and verified.

**Risks.**

- False positives: `info` first, measured by dismissals.
- The close-out double-counts what the `underbilled` and `recharge` items already show: a close-out finding
  subsumes the job's `underbilled:` and `recharge:` items, and they are linked in its evidence.

### M5. The nightly cycle and the morning report

**Scope.**

- The `cycle_runs` and `cycle_run_steps` tables.
- `Intel\Cycle\CycleRunner`, `CycleContext`, `AiAllowance`, the `ledger:cycle tick|run|status` command, and the
  `RunCycleStep` job.
- Steps wrapping today's commands with unchanged behaviour: audit_chain, health, retry_reads, transfers,
  categorise, jobs (with its 15-entry AI limit and the lock shared with `FindJobs`), backlog, reconcile, todo,
  detectors, sync and seal.
- The `intel_cycle` flag with `->skip()` on the legacy entries.
- AI purpose shares; `waiting_budget` reads retried automatically.
- Import-triggered and "Check now" runs.
- The morning report (Telegram, `/intel`, dashboard card), critical alerts, and `/intel/runs`.
- `ledger:invoice-nudges` reads `invoice.overdue` findings.
- Rollout: deploy with `off`, run `shadow` for a week and compare counts with the legacy findings, then `on`
  (`php artisan ledger:cycle mode shadow`, then `on`).

**As built.**

- No web switch for `intel_cycle`: `ledger:cycle mode` on the server, audited.
- Import runs are not limited to the imported account yet (section 6.1).
- `followUp` after automatic job links is left to M8, as listed there.
- Second reads made while `ReadDocument` reads a new document count as `reading`; only the nightly re-check
  (`verify.backlog`, `ledger:recheck`) uses `verify`.
- `relate.transfers` and `detect.sync` are single passes (pairing needs every line at once; the sync needs every
  detector to know coverage). A step that overruns the 150 s job timeout is noticed, and after three stalls counts
  as failed.
- `acquire.retry_reads` counts each re-queued read at about $0.15 against the night's allowance; what the read
  really costs is held to reading's share when it is made, but not added back to the run's `ai_spent_micro`.

**Files.**

- `database/migrations/2026_12_16_000001_create_cycle_runs.php` **(new**; dated after M6's migrations, section 3.2**)**
- `app/Models/{CycleRun,CycleRunStep}.php` **(new)**
- `app/Domain/Intel/Cycle/{CycleRunner,CycleStep,Stage,AiAllowance,StepOutcome,MorningReport}.php` **(new)**,
  `app/Domain/Intel/Cycle/CycleContext.php` (M3's, extended with defaulted arguments)
- `app/Domain/Intel/Cycle/Steps/*.php` **(new)**: `VerifyAuditChain`, `AcquireHealth`, `AcquireRetryReads`,
  `RelateTransfers`, `RelateCategorise`, `RelateJobs`, `VerifyBacklog`, `VerifyReconcile`, `RunDetector`
  (`detect.<code>`), `DetectSync`, `RecommendReport`, `RecommendAlerts`, `PreserveSeal`
- `app/Domain/Intel/Detectors/CycleStepFailing.php` **(new**, `health.cycle_step`, registered in `Registry`**)**
- `app/Jobs/{RunCycleStep,RetryTravelRead}.php` **(new)**, `app/Jobs/{FindJobs,ReadDocument,ReadTravelEmail}.php`
- `app/Domain/Jobs/JobFinder.php`, `app/Domain/Ai/AiUsage.php`, `app/Models/Setting.php`
- `routes/console.php`, `routes/web.php`, `app/Console/Commands/CheckIntegrations.php`
- `app/Http/Controllers/{IntelController,DashboardController,ImportController}.php` (`IntelController` new)
- `resources/js/pages/intel/{index,runs}.tsx`, `resources/js/components/intel-run.tsx` **(new)**,
  `resources/js/pages/dashboard.tsx`, `resources/js/components/app-sidebar.tsx`
- `resources/js/pages/documents/{index,show}.tsx`, `resources/js/components/doc-line.tsx` ("Waiting for AI budget")
- `app/Domain/Telegram/Telegram.php`

**Migrations.** One.

**Tests.**

- `Schedule::events()` for each flag value: nothing runs twice.
- The tick never runs a step inline (query count and time bound).
- A second run is idempotent and changes nothing.
- A deadline hit mid-step resumes from the cursor on the next tick (fake clock).
- A failing step doesn't stop independent ones; the run ends `partial` and raises a health finding.
- An abandoned run resumes.
- The seal is written once and the chain verifies.
- Shadow mode writes nothing outside `cycle_*`.
- The assistant's AI share is never used by the cycle.
- The report omits empty sections and carries no account numbers.

**Acceptance.**

- On live, in shadow, seven consecutive nightly runs end `ok` or `partial`, each with every step logged.
- The shadow-versus-legacy differences in opened and resolved findings are explained.
- In `on`, the morning report arrives by 07:35 on six of seven days.
- The host's process count stays at one worker plus the scheduler.

**Risks.**

- Steps running twice during switchover: the three-state flag, `skip()`, the schedule test and a shadow week.
- The host killing long processes: 90 s steps.
- Report fatigue: one line on quiet nights.

### M6. Provenance and why-panels

**Scope.**

- **Schema:** the `allocations` and `audit_events` columns, and `link_provenance`.
- **AuditContext persisted** (channel, correlation, run id), set by web middleware, Telegram, the runner and queue
  middleware; hash v2.
- **`AllocationWriter` gains `?Provenance`.** Every automatic writer passes one: TransferPairer (with strength and
  runner-up), Suggester (branch), Categoriser (`source`, n of m, example tx refs), RuleLearner, TravelMatcher,
  JobGatherer, TransactionAdvisor, `AssistantTools::propose_decision` and RevolutImport.
- **JobFinder's link methods and `followUp`** write `link_provenance` (score, margin and runner-up from
  `workScore`).
- **`Categoriser::isOurs`** reads `method` first.
- **Learners** count accepted suggestions at half weight.
- **The why-panel and `GET /why`.**
- **Backfill** `allocations.confirmed_by`.

**Files.**

- `database/migrations/2026_12_14_000001_provenance_and_audit_context.php` **(new)**
- `database/migrations/2026_12_14_000002_create_link_provenance.php` **(new)**
- `app/Domain/Audit/AuditLog.php`, `app/Domain/Intel/{AuditContext,Provenance,Methods}.php`
- `app/Http/Middleware/AuditChannel.php` **(new)**, `app/Jobs/Middleware/AuditQueueContext.php` **(new)**,
  `bootstrap/app.php`
- `app/Domain/Ledger/{AllocationWriter,RuleLearner}.php`
- `app/Domain/Matching/{TransferPairer,Suggester,Categoriser}.php`
- `app/Domain/Travel/TravelMatcher.php`, `app/Domain/Jobs/{JobFinder,JobGatherer}.php`
- `app/Domain/Ai/{TransactionAdvisor,AssistantTools}.php`, `app/Domain/Import/RevolutImport.php`
- `app/Http/Controllers/WhyController.php` **(new)**
- `resources/js/components/{why-panel,item-drawer}.tsx`, `resources/js/types/intel.ts` **(new)**

**Migrations.** Two.

**Tests.**

- A fixture chain built before the migration still verifies, on MariaDB too. Mixed old and v2 chains verify.
  Tampering with channel or correlation is caught.
- Every automatic writer stores a registered method.
- `confirm()` sets `confirmed_by`.
- A correlation links an Inbox decision to its partner confirm and the `learnFrom` rewrites.
- `link_provenance` is ignored when the native link changed.
- The existing "automation never overwrites" tests still pass.
- The read model never writes.

**Acceptance.**

- On the restored live copy, `ledger:verify-audit` passes before and after migrating.
- The drawer's why-panel explains a rule line, a matcher line and a JobFinder calendar link, each with method,
  evidence and history.

**Risks.**

- **Audit hash compatibility is the highest risk in the plan.**
  - The v2 prefix is added only when the fields are set.
  - M6 ships writing v1 only (`intel_audit_context` off), so redeploying the release before it stays safe. Until it
    is on, the why-panel history shows no channel or knock-ons, and events carry no run id.
  - Mixed-chain tests run on both databases.
  - The chain is verified on a restored live copy before deploy.
  - Refuse MySQL 8's native JSON, which reorders keys, until hashing re-encodes canonically.

### M7. Rejection memory, precedence and actions bound to what was shown

**Scope.**

- **The `vetoes` table and `Intel\Vetoes`** with adapters over the existing rejection stores. AllocationWriter and
  the link writers refuse a non-owner write that matches a veto.
- **Recording points** (section 4.6).
- **`relate.sweep`.**
- **Precedence** enforced over open suggestions.
- **JobFinder `followUp`** respects `job_finds` rejections and vetoes. Today an invoice rejected on a job can be
  re-linked by "Same PO number".
- **Fingerprints** on web and Telegram actions (`t|code|id|fp`).
- **Reason chips** and the alternatives considered in the Inbox; `/intel/proposals` with Not this one.
- **Suggester** applies a rule's `work_job_id` only within that job's dates. Today `DecisionService::apply` can
  save a job on a rule, and every future payment then gets it.
- **Audit context on.** `intel_audit_context` defaults to on, so events record channel,
  correlation and run id (hash v2). From this release, roll back no further than M6, the first release that reads
  v2. Verify the chain on a restored live copy after turning it on.

**As built.**

- The vetoes migration is `2026_12_16_000002_create_vetoes.php`, numbered after M5's `2026_12_16_000001` of the same
  date, as section 3.2 says; the table is exactly as listed there.
- Precedence lives in `AllocationWriter::PRECEDENCE` (`rank()`, `outranks()`, and `$refused` with `REFUSED_WORDS`:
  `yours`, `vetoed`, `outranked`); `Methods::outranks` delegates to it. `ai.assistant` ranks with the categoriser and
  `ai.advisor` below it. The assistant's `propose_decision` tells the owner which of the three stopped it.
- Vetoes are written for links that already had a rejection store too, so every "Not this" can be seen and lifted;
  the older stores stay authoritative and are read through adapters. Vetoes never stop your own transfer from
  pairing. `mileage_day` is subject `job:J`, object null for the whole job or `jday:<id>` for one day (the page offers
  whole jobs only), and `TravelUnclaimed` leaves those jobs out of the "may have mileage" count.
- The Inbox sends the ids of the lines it showed ("shown"): Not this on a payment that says something else by now is
  refused, and deciding a payment differently remembers a veto only for the suggestion you were shown.
- Fingerprints (section 4.5) leave out volatile fields; settled presses say "Already dealt with". Snooze a week counts
  as deciding, so old 3-part snooze buttons are refused. The web refuses every deciding press without a fingerprint,
  the connections map's possible-job buttons included.
- `/intel/proposals` has no Accept for a stay's matched payment, and lists only draft invoices (section 7.3).
- The audit context is on by default (`Setting::DEFAULTS`); a stored `false` still turns it off. Web events are v2 and
  stored with their keys in order (`AuditLog::canonical`).

**Files.**

- `database/migrations/2026_12_16_000002_create_vetoes.php` **(new)**
- `app/Models/Veto.php` **(new)**, `app/Domain/Intel/Vetoes.php` **(new)**
- `app/Domain/Ledger/{AllocationWriter,DecisionService,RuleLearner}.php`
- `app/Http/Controllers/{DecisionController,TodoController}.php`
- `app/Domain/Matching/{TransferPairer,Suggester}.php`, `app/Domain/Travel/TravelMatcher.php`
- `app/Domain/Jobs/{JobFinder,JobGatherer}.php`, `app/Domain/Mileage/JobTrips.php`
- `app/Domain/Intel/Cycle/Steps/RelateSweep.php` **(new)**
- `app/Domain/Todo/TodoActions.php`, `app/Domain/Telegram/Telegram.php`
- `app/Models/Setting.php`, `app/Domain/Audit/AuditLog.php` (`recordsContext()` reads the default)
- `resources/js/pages/{inbox.tsx,intel/proposals.tsx}`, `resources/js/components/why-panel.tsx`
- As built, also: `app/Domain/Todo/{Fingerprints,ChangedSince}.php` **(new)**, `app/Domain/Intel/Methods.php`,
  `app/Domain/Intel/Detectors/TravelUnclaimed.php`, `app/Domain/Matching/Categoriser.php`,
  `app/Domain/Ai/{TransactionAdvisor,AssistantTools}.php`, `app/Domain/Intel/Cycle/CycleRunner.php`,
  `app/Http/Controllers/{InboxController,IntelController,WhyController,MileageController,ConnectionsController}.php`,
  `routes/web.php`, `resources/js/pages/{todo,mileage/index,intel/index,connections}.tsx`,
  `resources/js/components/item-drawer.tsx`, `resources/js/types/intel.ts`
- Tests: `tests/Feature/Intel/{Veto,VetoReview,Fingerprint,FingerprintReview,RelateSweep,AuditContextOn,RejectionMemoryIntegration}Test.php` **(new)**

**Migrations.** One.

**Tests.**

- A cleared "food or drink" suggestion on a Pret payment is not re-proposed by the categoriser, transfers or the
  AI advisor the next night, but the owner can still choose it.
- The Inbox's own Undo creates no veto.
- An invoice rejected on a job stays unlinked after a gather and a `followUp`.
- A voided invoice's match is swept.
- A fingerprint mismatch is refused with a message.
- Old 3-part callbacks still work for codes that don't decide anything.
- Lifting a veto is audited.
- Callback data stays within 64 bytes.

**Acceptance.**

- In a week of live use, no cleared suggestion reappears.
- A Telegram press on an item changed since it was sent is refused.

**Risks.**

- Over-blocking: vetoes are narrow (method and shape), visible and liftable, and the Inbox Undo stays neutral.

### M8. Sharper evidence: acquisition, relating and verifying

**Scope.**

- **Schema:** `calendar_event_versions`, `document_arrivals`, `bank_transactions.source_line`, the
  `statement_balances` columns, `verifications.independence`.
- **Acquisition (section 4.1):**
  - CalendarSync versions, with JobFinder's AI verdicts moved out of the cache into `ai_verdict`;
  - mailbox per-message `try/catch`;
  - arrivals recorded;
  - one `autoRead` gate;
  - Starling balance snapshots.
- **Relating:** `relate.route` (Suggester nightly, with ambiguity and payer checks); `followUp` after automatic
  links.
- **Verifying:**
  - fair `Verifier::recheck`;
  - Grounding `valueInSpan` for dates, times and PO numbers;
  - independence on every check;
  - a blind second read by a different model;
  - Reconciler in the pocket's own currency.
- **Detectors:** `calendar.job_changed` and `decision.evidence_changed`.
- **Privacy:** `Intel\Redactor` on discovery prompts.

**Files.**

- `database/migrations/2026_12_18_000001_acquisition_evidence.php` **(new)**
- `app/Models/{CalendarEventVersion,DocumentArrival}.php` **(new)**
- `app/Domain/Calendar/CalendarSync.php`, `app/Domain/Jobs/JobFinder.php`, `app/Domain/Mail/MailboxFetcher.php`
- `app/Domain/Documents/{DocumentIntake,AiConfig,Grounding}.php`, `app/Domain/Telegram/Telegram.php`
- `app/Domain/Import/{Importer,RevolutImport}.php`, `app/Domain/Banks/StarlingSync.php`
- `app/Domain/Matching/Suggester.php`
- `app/Domain/Verification/Verifier.php`, `app/Domain/Ledger/Reconciler.php`
- `app/Domain/Intel/Redactor.php` **(new)**, `app/Domain/Intel/Detectors/{CalendarJobChanged,DecisionEvidenceChanged}.php` **(new)**

**Migrations.** One.

**Tests.**

- An edited calendar entry gives version 2; a deleted one gets `missing_since`; a cancelled entry keeps its STATUS.
- A cancelled entry whose job still has a booked hotel raises `calendar.job_changed`.
- The same PO by Gmail and by forward gives two arrivals and one document.
- One malformed email doesn't block the rest.
- A Telegram file is read once with `ai_extraction = on`.
- A rule made today applies to waiting lines overnight.
- Two invoices with the same total are never matched.
- Ids above 200 are re-checked within 7 nights.
- A "£450/day" fact quoting "Rate: £350 per day" fails.
- The blind read's prompt contains no claims (fake `AiClient`).
- A EUR pocket reconciles in EUR.
- Redacted prompts contain no account digits.

**Acceptance.**

- On live: the calendar version count grows only on real changes.
- The re-check backlog's oldest `checked_at` is under 14 days.
- More overnight suggestions appear, all unconfirmed.

**Risks.**

- More suggestions overnight: they remain proposals.
- The Starling balance endpoint may need a token scope the owner hasn't granted: it degrades to an info item.

### M9. Existing autonomy under one policy

**Scope.**

- **`autonomous_acts`**, `Intel\Autonomy\{DecisionKinds,Policy,Gates,Acts,Undo}`, and the `decide.policy` step.
- **The automatic paths that already exist** move under the policy at their start levels and are recorded as acts:
  - learned-rule auto-confirm (`Suggester` rule branch, `RuleLearner::accept`);
  - JobFinder calendar, document and travel links;
  - `followUp`'s invoice link;
  - the transfer partner confirm;
  - the Gmail label.
- **Undo** per act and per run; demotion; rule health.
- **The sign-off list** and the YearChecks line; the lock reason rule extended to unreviewed acts.
- **The Autonomy page:** levels up to the ceiling (lowering only for kinds without measurements), pins, pause.
- **Telegram:** `/pause`, `/resume`, and Undo buttons with fingerprints.

**Files.**

- `database/migrations/2026_12_20_000001_create_autonomous_acts.php` **(new)**
- `app/Models/AutonomousAct.php` **(new)**, `app/Domain/Intel/Autonomy/*.php` **(new)**
- `app/Domain/Matching/{Suggester,TransferPairer}.php`, `app/Domain/Ledger/RuleLearner.php`
- `app/Domain/Jobs/JobFinder.php`, `app/Domain/Gmail/GmailLabeller.php`
- `app/Domain/Tax/YearChecks.php`, `app/Http/Controllers/{TaxYearController,AutonomyController}.php` (`AutonomyController` new)
- `resources/js/pages/settings/autonomy.tsx`, `resources/js/pages/autonomy/sign-off.tsx` **(new)**
- `app/Domain/Telegram/Telegram.php`

**Migrations.** One.

**Tests.**

- Snapshot tests: import, job-finder and label results are identical before and after, at the start levels.
- Lowering `category.rule` to 2 turns rule confirms into suggestions.
- Undo restores `before` exactly, and refuses when the owner changed the subject since.
- Undoing a run suspends its kinds.
- An owner decision racing `decide.policy` wins (lock test on MariaDB, two connections).
- A locked year blocks acts and undo.
- Two overrides of a rule switch `auto_confirm` off and raise the finding.
- Each gate refuses on its own fixture.

**Acceptance.**

- The morning report itemises every automatic act of the night with a working undo.
- The sign-off list matches the acts not yet reviewed.
- No behaviour change is visible on the restored live copy.

**Risks.**

- A regression in automation that works today: mitigated by the behaviour-preserved snapshot tests and by start
  levels equal to today's.

### M10. Earned autonomy for three kinds

**Changed by income v3 (25 Nov 2026).** `payer_aliases` and `PayerAlias` are no longer needed: who pays for whom is
worked out by `Income\PayerAliases` (section 3.2), which a `PayerAliasCheck` can read. `income.invoice` is not one of
the kinds offered: the owner chose that income links are never confirmed by automation (section 5.3), so outcome
capture and promotion would cover `transfer.pair` and `suggest.hmrc` only.

**Scope.**

- **Schema:** `proposal_outcomes` and `payer_aliases`.
- **Outcome capture** for `transfer.pair`, `suggest.hmrc` and `suggest.invoice`: shown outcomes, hold-backs (1 in
  10, opt-in per kind) and backtests.
- **`Intel\Autonomy\Calibration`:** Wilson bounds per band, blind and shown separately; the `learn.outcomes` step.
- **Second-family checks:** `StatementPairCheck`, `ObligationCheck` (`PenaltyEstimator` obligations as data) and
  `PayerAliasCheck`.
- **G4-G6** enforced for these kinds.
- **Sampled next-night re-checks** with automatic undo.
- **Promotion offers** with the 30-day rehearsal. Nothing is promoted without the owner's tap.

**Files.**

- `database/migrations/2026_12_22_000001_create_proposal_outcomes.php` **(new)**
- `app/Models/{ProposalOutcome,PayerAlias}.php` **(new)**
- `app/Domain/Intel/Autonomy/{Calibration,Backtest,Checks/StatementPairCheck,Checks/ObligationCheck,Checks/PayerAliasCheck}.php` **(new)**
- `app/Domain/Matching/{TransferPairer,Suggester}.php`, `app/Domain/Tax/PenaltyEstimator.php`
- `app/Domain/Intel/Cycle/Steps/{LearnOutcomes,VerifyActs}.php` **(new)**
- `resources/js/pages/settings/autonomy.tsx`

**Migrations.** One.

**Tests.**

- Wilson: 52 of 52 gives at least 95%; fewer than 30 outcomes is `unmeasured`; the lower of the blind and shown
  bounds is used.
- Backtests use only inputs created before each decision.
- Two £100 top-ups in one week stay suggestions (G5).
- An incoming line naming a client is never auto-paired.
- Aliases are learned only from confirmed income.
- A sampled disagreement undoes the act and suspends the band.
- An offer appears only above the bar with at least 10 blind outcomes.
- An invoice paid by an act stops its overdue nudges.
- Outcome rebuilds are identical on both databases.

**Acceptance.**

- The Autonomy page shows a measured record for the three kinds from the owner's own history.
- No kind acts until the owner accepts an offer.
- After a promotion, a week of acts all have a passed same-run check and appear in the report.

**Risks.**

- **Wrong acts reach the tax figures.** Mitigations:
  - off until opted in;
  - blind-inclusive lower bounds;
  - two independent families;
  - caps;
  - same-run and sampled next-night checks;
  - undo per act and per run;
  - demotion on the first override;
  - sign-off before lock.
- **One owner's volume:** many kinds will never qualify, and that is intended.

---

## 9. What not to do

1. **Don't leave the money-finding detectors to the end.** They are read-only and low-risk, and they are the
   discovery the owner asked for. They ship in M1 and M4, before any runner or autonomy machinery.
2. **Don't build a second source of truth.** No evidence row per bank line (`bank_transactions` is already
   immutable, with fingerprint, batch and raw), no assertion mirror of allocations and links, no graph-drift job.
   `link_provenance` is metadata that is ignored whenever it disagrees with the native column.
3. **Don't couple intake to new writes in the same transaction.** A bug in the intelligence layer must never block
   a bank import, a Starling sync or a mailbox fetch.
4. **Don't reroute working writers through a new gate, or make TodoList read stored recommendations.** Adapters
   wrap TodoList, Verifier and the matchers; `/todo` still builds live.
5. **Don't invent precision.** No confidence numbers that weren't measured. Grades come from evidence rules or
   Wilson bounds; anything else is `unmeasured`.
6. **Don't treat the owner's acceptance of a shown suggestion as unbiased.** Gate on the lower of the blind and
   shown bounds.
7. **Don't measure anything from audit events written before actors were honest (M2).** They are version 0, shown
   as history only.
8. **Don't call JobFinder's `rules`/`ai` links or the "Same PO number" invoice link mere proposals.** They act
   today. Name them as acts, record them and bring them under the policy (M9).
9. **Don't build calibration machinery for every method.** Measure only the kinds offered for promotion, and leave
   Brier scores and reliability charts out.
10. **Don't run long work in the foreground of `schedule:run`.** The tick only dispatches; steps run in the
    existing worker with 90 s budgets.
11. **Don't add `json()` columns to existing tables,** and don't hash JSON whose key order depends on the engine.
    Use text cast to array, and canonical JSON from PHP.
12. **Don't change the audit hash before both databases are in CI** and a restored live copy verifies. Don't
    install on MySQL 8 until hashing re-encodes canonically.
13. **Don't cite allocation ids.** Cite `tx:` plus `audit:`.
14. **Don't switch the cycle on after one live night.** Run a shadow week and compare it with the legacy schedule.
15. **Don't hide new detectors in a "would have raised" shadow level.** Show them as `info` below the fold, off
    Telegram, and let dismissals tune them.
16. **Don't send Telegram decisions without fingerprints,** and don't add Telegram undo before them (M7 before
    M9).
17. **Don't let AI be the sole basis for any act,** and don't let an unconfirmed machine conclusion count as
    evidence for another automatic act.
18. **Don't reorder Starling ahead of the nightly matchers to fix staleness.** `StarlingSync` already calls
    `Importer::afterImport` (pairing and Suggester). The morning run after 06:41 is what brings its lines into the
    report.
19. **Don't send more to outside services.** No new data goes to Claude or Telegram beyond today's. Discovery
    prompts are redacted, and encrypted settings can never be cited.
20. **Don't delete or rewrite anything to adopt the vocabulary.** Legacy values are mapped in code, and every
    migration is additive.
