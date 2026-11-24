<?php

namespace App\Domain\Todo;

use App\Domain\Gmail\GmailClient;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\DetectorLevels;
use App\Domain\Intel\Detectors\Health;
use App\Domain\Intel\Detectors\HmrcUnallocated;
use App\Domain\Intel\Detectors\JobCloseout;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Intel\Detectors\TodoGroupsDetector;
use App\Domain\Intel\Detectors\TravelUnclaimed;
use App\Domain\Intel\Detectors\YearFiledDrift;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ranker;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\CandidateText;
use App\Domain\Jobs\Opportunities;
use App\Domain\Jobs\PoStatus;
use App\Domain\Ledger\Reconciler;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\TransferPairer;
use App\Domain\Mileage\JobTrips;
use App\Domain\Tax\FilingDates;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\Submissions;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\CalendarJobLink;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Everything that needs you, in one list, most urgent first: something in Expend not working (the audit log, Starling,
 * the calendar, background work), a reconnect, a held statement, unpaid invoices, failed checks, a filing deadline,
 * payments to HMRC not recorded as tax and filed returns your records no longer match, work to answer (an offer or
 * hold whose reply-by date is close), bookings with no job yet, invoices to send, mileage to claim, possible jobs,
 * payments and stays to decide, jobs with no PO (with who to ask), payments missing from your statements and documents
 * held for approval. Each item carries one-tap actions (TodoActions), used by the to-do page and by Telegram buttons.
 * Nothing here changes anything: the checks only read.
 *
 * Building it reads most of the ledger, so the last list is kept (see cached()) for the dashboard, and built afresh
 * as soon as anything it's made from changes: a change made by this process is seen as it's written (watch()), one
 * made elsewhere (the queue, the scheduler) by the data's fingerprint (stamp()). The menu badge on every other page
 * shows the last list's total for a couple of minutes after a change (see badge()), so deciding payment after payment
 * doesn't build the list again each time.
 */
final class TodoList
{
    public const GROUPS = [
        'health' => 'Something isn’t working', 'gmail' => 'Gmail', 'imports' => 'Statements held', 'income' => 'Unpaid invoices', 'revenue' => 'Money you may be owed', 'closeout' => 'Jobs to close out', 'checks' => 'Failed checks', 'deadline' => 'Tax return', 'penalties' => 'Fines and interest',
        'tax' => 'Tax records to check', 'offers' => 'Work to answer', 'bookings' => 'Booked with no job yet', 'invoice' => 'Invoices to send', 'claims' => 'Expenses you could claim', 'jobs' => 'Possible jobs', 'transfers' => 'Transfers between your accounts', 'payments' => 'Payments to decide', 'rules' => 'Make it automatic', 'duplicates' => 'Possible duplicate charges', 'stays' => 'Stays and travel to decide',
        'no_po' => 'Jobs with no PO', 'reconcile' => 'Accounts that don’t match the bank', 'missing' => 'Payments missing from statements', 'records' => 'Records to tidy', 'documents' => 'Documents held',
    ];

    /** A filed return that differs from your records by this much or more is listed (Intel\Detectors\YearFiledDrift). */
    public const DRIFT_PENCE = YearFiledDrift::DRIFT_PENCE;

    /** Background work that failed longer ago than this many days has been put right or no longer matters. */
    public const FAILED_JOBS_DAYS = 7;

    /**
     * Journeys that mean you didn't drive your own vehicle to a job: no mileage to claim (a hire car's cost is claimed
     * instead). The Mileage page's own list and rule (JobTrips::JOURNEYS, JobTrips::journeys()), so the two always agree.
     */
    public const NOT_DRIVEN = JobTrips::JOURNEYS;

    /**
     * A finished job you said had no mileage to claim ("Not relevant" on a mileage item): mileage_job:{job}:{the item's
     * set}. Kept with the to-do dismissals, so an Undo puts the jobs back (see TodoController::dismiss()).
     */
    public const MILEAGE_JOB = 'mileage_job:';

    /** The calendar refreshes every hour: one not refreshed for longer than this has stopped. */
    public const CALENDAR_STALE_HOURS = 2;

    /** Where the last list is kept. */
    public const CACHE_KEY = 'todo_list';

    /** The list's total. A to-do action forgets it (forget()), which also makes the kept list be built afresh. */
    public const TOTAL_KEY = 'todo_total';

    /** The last list's total, for the menu badge (see badge()). */
    public const BADGE_KEY = 'todo_badge';

    /** Where what was last found below the fold is kept (see belowFold()). */
    public const BELOW_KEY = 'todo_below';

    /** The longest a kept list is used for, even if nothing it's made from seems to have changed. */
    private const KEEP_SECONDS = 1800;

    /** How long the menu badge may show the last list's total after something it's made from changed. */
    private const BADGE_SECONDS = 120;

    /** At least this many of each kind are kept, so the dashboard, the menu badge and the to-do page share one list. */
    private const KEEP_PER_GROUP = 15;

    /**
     * The tables the list is made from (directly, or through the rule offers, PO status, bank checks, the mileage
     * found for finished jobs and the background work that failed).
     */
    private const WATCHED = [
        'accounts', 'allocations', 'bank_transactions', 'calendar_job_links', 'categories', 'clients', 'document_facts', 'documents', 'failed_jobs', 'gmail_messages', 'import_batches',
        'invoice_lines', 'invoices', 'job_candidates', 'job_days', 'manual_expenses', 'merchant_rules', 'mileage_trips', 'opportunities', 'places', 'statement_balances', 'tax_submissions', 'tax_years', 'travel_items',
        'vehicles', 'verifications', 'work_jobs',
    ];

    /** Watched tables without the usual columns: what stands in for the newest row and for the latest change. */
    private const STAMP_COLUMNS = ['failed_jobs' => ['id', 'failed_at'], 'places' => ['postcode', 'updated_at']];

    /**
     * Where the mileage found for finished jobs is kept. Working it out looks at each job's travel and postcodes, so
     * it's kept until something it's made from changes (MILEAGE_WATCHED), not worked out again with every list.
     */
    private const MILEAGE_KEY = 'todo_mileage';

    /** What the mileage found for finished jobs is made from. */
    private const MILEAGE_WATCHED = ['job_days', 'mileage_trips', 'places', 'tax_years', 'travel_items', 'vehicles', 'work_jobs', 'vetoes'];

    private const MILEAGE_SETTINGS = ['home_postcode', 'mileage_threshold_scope'];

    /** Where ledger:verify-audit keeps how the last check of the audit log went (see health()). */
    public const AUDIT_RESULT = 'audit_chain_result';

    /**
     * The settings it reads: the Gmail connection, merchants you said no to a rule for, your Making Tax Digital status
     * (penalties), your home postcode and mileage settings, how the nightly audit check, Starling, the calendar and
     * the forwarding mailbox last went, how prominent each kind of finding is, and whether the nightly cycle is on
     * (it decides whether reads refused at the AI cap are read again on their own).
     */
    private const WATCHED_SETTINGS = ['google_client_id', 'google_client_secret', 'gmail_refresh_token', 'gmail_last_result', 'rule_offers_declined', 'mtd_volunteer_from', 'mtd_exempt', 'mtd_exempt_from', 'mtd_exempt_never_required',
        'home_postcode', 'mileage_threshold_scope', self::AUDIT_RESULT, 'starling_token', 'starling_last_result', 'calendar_ics_url', 'calendar_synced_at',
        Health::MAILBOX_RESULT, 'imap_host', DetectorLevels::SETTING, 'intel_receipt_threshold_pence', 'intel_cycle'];

    /** This process has written to what the list is made from since it last built it (see watch()). */
    private static bool $changed = false;

    /** This process has written to what the mileage found for finished jobs is made from (see mileageFound()). */
    private static bool $mileageChanged = false;

    /**
     * The list, built now. Counts and the total cover everything (less what you snoozed or marked not relevant),
     * whatever $perGroup is; only the items shown are limited to the first $perGroup of each kind. Items of a kind shown
     * below the fold (Intel\DetectorLevels: a new detector, or one demoted by its false positives) are apart, in
     * 'below' with its own 'below_counts', and left out of the total, the dashboard and Telegram; the new detectors'
     * own findings are worked out apart again (belowFold()), so the list never waits for them.
     *
     * @return array{items:list<array>, below:list<array>, counts:array<string,int>, below_counts:array<string,int>, total:int}
     */
    public static function build(int $perGroup = 15): array
    {
        self::watch();
        $hidden = TodoDismissal::activeKeys();
        $stamp = self::stamp($hidden);
        $list = self::compute(max($perGroup, self::KEEP_PER_GROUP), $hidden);
        self::keep($list, $stamp);

        return self::cut($list, $perGroup);
    }

    /**
     * Every item, for the findings history (FindingsRegistry), which must tell an item that's gone from one that's
     * only hidden or past a limit: nothing is left out by the limits that keep the list quick (the newest 300 jobs,
     * 50 duplicate charges), and what you snoozed or marked not relevant is kept, with 'hidden' set and 'hidden_by'
     * the key it's hidden under. Not kept for the dashboard, which keeps its own list.
     *
     * @return list<array>
     */
    public static function everything(): array
    {
        return self::scan()['items'];
    }

    /**
     * Every item, as everything(), with the kinds of item that couldn't be worked out in full this time: 'failed'
     * (something went wrong working them out, such as the fines and interest estimate) and 'capped' (a limit left
     * some out), each as the start of their keys ("penalty:"). An item of those kinds that's missing may still be
     * there, so the findings history keeps it open rather than calling it dealt with.
     *
     * @param  bool  $withDetectors  false: without the kinds that have detectors of their own (payments to HMRC,
     *                               mileage, filed returns: TodoGroupsDetector::OWN_DETECTORS), which the findings
     *                               history runs itself (Intel\Detectors\Registry)
     * @return array{items:list<array>, failed:list<string>, capped:list<string>, checked:array{hmrc:?int, jobs:?int, years:?int}}
     */
    public static function scan(bool $withDetectors = true): array
    {
        $list = self::compute(self::KEEP_PER_GROUP, TodoDismissal::activeKeys(), true, $withDetectors, true);

        return ['items' => $list['items'], 'failed' => $list['failed'], 'capped' => $list['capped'], 'checked' => $list['checked']];
    }

    /**
     * A fingerprint of everything the list is made from right now (see stamp()): the same as last time means the
     * list would be the same. One query.
     */
    public static function fingerprint(): string
    {
        return self::stamp(TodoDismissal::activeKeys());
    }

    /**
     * The list as last built, when nothing it's made from has changed since (no payment, decision, invoice, job,
     * document, check, statement, rule or snooze added, changed or removed, the date is the same, and no to-do action
     * has run); otherwise built now. For the dashboard, and the menu badge once its last total is too old (badge()).
     *
     * @return array{items:list<array>, counts:array<string,int>, total:int}
     */
    public static function cached(int $perGroup = 15): array
    {
        self::watch();
        $hidden = TodoDismissal::activeKeys();
        $stamp = self::stamp($hidden);
        $kept = self::$changed ? null : Cache::get(self::CACHE_KEY);
        if (is_array($kept) && ($kept['stamp'] ?? null) === $stamp && ($kept['per_group'] ?? 0) >= $perGroup && Cache::get(self::TOTAL_KEY) === ($kept['total'] ?? null)) {
            return self::cut($kept, $perGroup);
        }
        $list = self::compute(max($perGroup, self::KEEP_PER_GROUP), $hidden);
        self::keep($list, $stamp);

        return self::cut($list, $perGroup);
    }

    /**
     * The total for the menu badge: the last list's, if it was built in the last couple of minutes (on this day),
     * whatever has changed since; otherwise the list's as it is now (cached()). The dashboard and the to-do page build
     * the list afresh when it's changed, so the badge on them always agrees with them; on other pages it catches up
     * within a couple of minutes, as it always has, rather than the list being built again after every decision.
     */
    public static function badge(): int
    {
        $last = Cache::get(self::BADGE_KEY);
        if (is_array($last) && ($last['day'] ?? null) === today()->format('Y-m-d') && is_int($last['total'] ?? null)) {
            return $last['total'];
        }

        return self::cached(1)['total'];
    }

    /** Make the next cached() build the list afresh. (The menu badge may show the last total a little longer.) */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::TOTAL_KEY);
        Cache::forget(self::BELOW_KEY);
    }

    /**
     * Notice this process's own writes to what the list is made from, however they're made (a decision saved, an
     * invoice emailed, a document approved), so the kept list isn't used after one, even within the same second
     * (which the fingerprint can't tell apart). Call settle() once the work is done, so other requests know too.
     */
    public static function watch(): void
    {
        if (app()->bound(self::class.'.watching')) {
            return;
        }
        app()->instance(self::class.'.watching', true);
        DB::listen(function (QueryExecuted $query) {
            if (! (self::$changed && self::$mileageChanged) && ($table = self::writesTo($query->sql, $query->bindings)) !== null) {
                self::$changed = true;
                self::$mileageChanged = self::$mileageChanged || in_array($table, self::MILEAGE_WATCHED, true)
                    || ($table === 'settings' && array_intersect(array_filter($query->bindings, 'is_string'), self::MILEAGE_SETTINGS) !== []);
            }
        });
    }

    /** After a request: if it changed what the list is made from, the kept list is forgotten. */
    public static function settle(): void
    {
        if (self::$mileageChanged) {
            self::$mileageChanged = false;
            Cache::forget(self::MILEAGE_KEY);
        }
        if (self::$changed) {
            self::$changed = false;
            self::forget();
        }
    }

    /**
     * To-dos that come back under the same key every time they apply again: Gmail needing reconnecting, a tax return
     * falling due or overdue, transfers to confirm, something in Expend not working. "Not relevant" hides a key for
     * good, so it would hide every later one too (the next disconnection, the overdue return). These can only be
     * snoozed. A new item whose key never changes belongs here too. (Background work that failed is kept by the newest
     * failure, so "Not relevant" sets aside the failures you've looked at and the next one brings it back; mileage and
     * filed returns that differ are kept by the jobs and the difference.)
     */
    public static function snoozeOnly(string $key): bool
    {
        if (in_array($key, ['gmail', 'transfers'], true)) {
            return true;
        }
        if (str_starts_with($key, 'health:failed_jobs:')) {
            return false;
        }
        foreach (['deadline:', 'penalty:', 'submission:', 'health:'] as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Work you're asked about is listed this many days before the date to reply by. */
    public const REPLY_DAYS = 3;

    /**
     * Work waiting for your answer: an availability check, a hold or an offer with no job yet, whose date to reply by
     * is within REPLY_DAYS days or has already passed (until the nightly check closes it as passed with no answer).
     */
    public static function toAnswer(Builder $query): Builder
    {
        return $query->whereIn('status', ['enquiry', 'held', 'offered'])->whereNull('work_job_id')
            ->whereNotNull('reply_by')->whereDate('reply_by', '<=', today()->addDays(self::REPLY_DAYS)->format('Y-m-d'));
    }

    public static function action(string $code, int|string $id, string $label, string $style = 'default'): array
    {
        return ['code' => $code, 'id' => (string) $id, 'label' => $label, 'style' => $style];
    }

    /**
     * What has come in for each invoice, in the invoice's own currency (see InvoicePayments::received()).
     *
     * @param  iterable<Invoice>  $invoices
     * @return array<int, array{paid:int, elsewhere:int, waiting:int}> by invoice id
     */
    public static function received(iterable $invoices): array
    {
        return InvoicePayments::received($invoices);
    }

    /**
     * What new detectors found that's shown below the fold (Intel\DetectorLevels: a new detector, or one demoted by
     * its false positives), apart from the list: the to-do page loads it after the list, so building the list (for the
     * page, the dashboard, the menu badge and Telegram) never waits for checks whose findings aren't in it. Ranked and
     * cut as the list is, less what you snoozed or marked not relevant. What it found is kept until anything it's
     * made from changes (the list's fingerprint, the audit trail and every setting: belowStamp()), or for KEEP_SECONDS
     * at most, so opening the to-do page again doesn't work it all out again.
     *
     * @return array{items:list<array>, counts:array<string,int>}
     */
    public static function belowFold(int $perGroup = 15): array
    {
        $hidden = TodoDismissal::activeKeys();
        $stamp = self::belowStamp($hidden);
        $kept = Cache::get(self::BELOW_KEY);
        if (is_array($kept) && ($kept['stamp'] ?? null) === $stamp && is_array($kept['items'] ?? null)) {
            $items = $kept['items'];
        } else {
            $items = self::workOutBelow($hidden);
            Cache::put(self::BELOW_KEY, ['stamp' => $stamp, 'items' => $items], self::KEEP_SECONDS);
        }
        $cut = self::cut(['items' => $items, 'counts' => [], 'total' => 0], $perGroup);

        return ['items' => $cut['below'], 'counts' => array_count_values(array_column($items, 'group'))];
    }

    /**
     * A fingerprint of what the findings below the fold are made from: the list's (stamp()), and the audit trail and
     * every setting, which some of the newer checks read too (rules you changed, a year's lock, the tax savings review).
     */
    private static function belowStamp(array $hidden): string
    {
        return md5(self::stamp($hidden).'|'.DB::table('audit_events')->max('id').'|'.DB::table('settings')->count().'|'.DB::table('settings')->max('updated_at'));
    }

    /** @param  array<string, true>  $hidden  @return list<array> every item below the fold, ranked */
    private static function workOutBelow(array $hidden): array
    {
        $levels = DetectorLevels::all();
        $items = [];
        $add = function (string $group, int $priority, string $key, string $title, ?string $detail, ?string $href, array $actions = [], array $extra = []) use (&$items) {
            $items[] = ['key' => $key, 'group' => $group, 'priority' => $priority, 'title' => $title, 'detail' => $detail, 'href' => $href, 'actions' => $actions,
                'snooze_only' => self::snoozeOnly($key)] + $extra;
        };
        foreach (self::otherDetectors() as $detector) {
            if (! DetectorLevels::belowFold($detector->code(), $levels)) {
                continue; // raised: it's in the list itself
            }
            try {
                foreach ($detector->detect(new CycleContext) as $draft) {
                    self::addDraft($add, $draft);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $items = array_values(array_filter($items, fn ($i) => collect([$i['key'], ...($i['hidden_under'] ?? [])])->first(fn ($k) => isset($hidden[$k])) === null));

        return Ranker::sort(array_map(fn ($i) => Ranker::annotate(self::described($i) + ['below_fold' => true]), $items), array_flip(array_keys(self::GROUPS)));
    }

    /**
     * @param  array<string, true>  $hidden  keys you snoozed or marked not relevant
     * @param  bool  $complete  for everything(): no query limits, and hidden items kept and marked
     * @param  bool  $withDetectors  false: leave out the kinds with detectors of their own (TodoGroupsDetector::OWN_DETECTORS)
     * @param  bool  $below  with the detectors shown below the fold (belowFold()): only for everything()
     * @return array{items:list<array>, counts:array<string,int>, total:int, per_group:int} every item, most urgent first
     */
    private static function compute(int $limit, array $hidden, bool $complete = false, bool $withDetectors = true, bool $below = false): array
    {
        // How prominent each kind is (Intel\DetectorLevels), worked out once.
        $levels = DetectorLevels::all();
        if (! $complete) {
            self::$changed = false; // what's been written so far is in this list (which is kept)
        }
        $capped = fn ($query, int $max) => $complete ? $query : $query->limit($max);
        $items = [];
        $add = function (string $group, int $priority, string $key, string $title, ?string $detail, ?string $href, array $actions = [], array $extra = []) use (&$items) {
            $items[] = ['key' => $key, 'group' => $group, 'priority' => $priority, 'title' => $title, 'detail' => $detail, 'href' => $href, 'actions' => $actions,
                'snooze_only' => self::snoozeOnly($key)] + $extra;
        };
        // Kinds of item not worked out in full this time (see scan()), by the start of their keys.
        $notWorkedOut = [];
        $cappedKeys = [];
        // What the new checks looked at, for the line under the list: so it's seen they ran, even when nothing's wrong
        // (null: the check went wrong this time).
        $checked = ['hmrc' => null, 'jobs' => null, 'years' => null, 'closeout' => null];
        // One check going wrong never stops the list: its items are left out this time, and the history keeps them open.
        $try = function (string $prefix, callable $check) use (&$notWorkedOut) {
            try {
                $check();
            } catch (Throwable $e) {
                report($e);
                $notWorkedOut[] = $prefix;
            }
        };

        // Expend itself: the audit log altered, Starling, the calendar or the mailbox not updating, background work
        // that failed, documents left unread at the AI spending cap (Intel\Detectors\Health, a detector of its own).
        if ($withDetectors) {
            $try('health:', fn () => self::fromDetector(new Health, $add));
        }

        // Gmail access ended (Google's 7-day limit in Testing).
        if (GmailClient::configured() && ! GmailClient::connected() && (Setting::get('gmail_last_result')['ok'] ?? true) === false) {
            $add('gmail', 1, 'gmail', 'Gmail needs reconnecting', 'Google ended the connection, so searches and the ✓ Invoiced label have stopped.', '/settings/integrations#gmail');
        }

        // Statements held because the balance didn't add up.
        $held = ImportBatch::query()->where('status', 'quarantined')->with('account')->get();
        foreach ($held as $b) {
            $add('imports', 1, 'import:'.$b->id, 'Statement held: '.$b->original_filename, ($b->account?->name ?? '').': the running balance doesn’t add up. Accept it with a reason or discard it.', '/imports');
        }

        // Issued invoices past their due date or never emailed, with what has come in for each (see received()).
        $open = Invoice::query()->where('status', 'issued')
            ->where(fn ($q) => $q->whereNull('sent_at')->orWhere(fn ($q) => $q->whereNotNull('due_date')->whereDate('due_date', '<', today())))
            ->orderBy('due_date')->orderBy('id')->get();
        $received = self::received($open);

        // Past their due date and not paid in full.
        // The one-tap reminder is only offered when nothing at all has come in: its email tells the client so.
        foreach ($open->filter(fn (Invoice $i) => $i->due_date && $i->due_date->lt(today())) as $i) {
            ['paid' => $paid, 'elsewhere' => $elsewhere, 'waiting' => $waiting] = $received[$i->id];
            if ($paid >= $i->total_minor) {
                continue;
            }
            $money = fn (int $minor) => Money::format($minor, $i->currency ?: 'GBP');
            $due = 'due '.$i->due_date->format('j M Y').' ('.(int) $i->due_date->diffInDays(today()).' days ago)';
            $client = $i->bill_to_name ?: 'the client';
            $inbox = $waiting > 0 ? ' '.Money::format($waiting).' that may be for it is waiting in the Inbox: decide it first.' : '';
            $extra = ['amount' => $i->total_minor - $paid, 'currency' => $i->currency ?: 'GBP', 'due_on' => $i->due_date->format('Y-m-d')];
            if ($elsewhere > 0) {
                $add('income', 1, 'invoice_overdue:'.$i->id, "Invoice {$i->number}: check it’s been paid",
                    $money($i->total_minor).' from '.$client.', '.$due.'. '.Money::format($elsewhere).' came in for this job in another currency, so Expend can’t tell how much of the invoice it covers. If it’s all paid, mark this not relevant.'.$inbox,
                    '/invoices/'.$i->id, [], $extra);
            } elseif ($paid > 0) {
                $add('income', 1, 'invoice_overdue:'.$i->id, "Invoice {$i->number} part-paid",
                    $money($paid).' of '.$money($i->total_minor).' received from '.$client.', '.$due.'; '.$money($i->total_minor - $paid).' still to come.'.$inbox, '/invoices/'.$i->id, [], $extra);
            } else {
                // A reminder that went in the last day isn't offered again (the button would only say so).
                $reminded = $i->reminded_at && $i->reminded_at->gt(now()->subHours(InvoiceMailer::REPEAT_HOURS));
                $add('income', 1, 'invoice_overdue:'.$i->id, "Invoice {$i->number} unpaid", $money($i->total_minor).' from '.$client.', '.$due.'.'
                    .($reminded ? ' Reminder sent '.$i->reminded_at->format('j M Y, H:i').'.' : '').$inbox,
                    $waiting > 0 ? '/inbox' : '/invoices/'.$i->id, array_values(array_filter([$i->bill_to_email && $waiting <= 0 && ! $reminded ? self::action('inv_chase', $i->id, 'Send a reminder') : null])), $extra);
            }
        }

        // Jobs to close out: for each finished job, what's short of its PO and the recharges not on its invoice, as one
        // amount (Intel\Detectors\JobCloseout). It says what the two kinds below said for a finished job, so they're
        // left to it there and nothing is counted twice; worked out even when its own findings are recorded apart, so
        // the two always agree. If it goes wrong, the two kinds below are listed for every job, as before.
        $subsumed = [];
        $try('closeout:', function () use ($add, $withDetectors, &$subsumed, &$checked) {
            $closeout = new JobCloseout;
            $drafts = $closeout->detect(new CycleContext);
            $subsumed = $closeout->subsumed();
            $checked['closeout'] = $closeout->checked();
            if ($withDetectors) {
                foreach ($drafts as $d) {
                    self::addDraft($add, $d);
                }
            }
        });

        // Money you may be owed: an invoice for less than its PO, and costs to recharge to the client (for a job
        // still under way: a finished one's are in its close-out).
        foreach (Invoice::query()->whereIn('status', ['issued', 'draft'])->whereNotNull('po_total_minor')->whereColumn('total_minor', '<', 'po_total_minor')->get() as $i) {
            if (array_key_exists('underbilled:'.$i->id, $subsumed)) {
                continue;
            }
            $money = fn (int $minor) => Money::format($minor, $i->currency ?: 'GBP');
            $add('revenue', 1, 'underbilled:'.$i->id, ($i->number ? "Invoice {$i->number}" : 'Draft invoice').' is '.$money($i->po_total_minor - $i->total_minor).' less than its PO',
                'The PO authorises '.$money($i->po_total_minor).' and the invoice totals '.$money($i->total_minor).'. Check nothing was left off'.($i->status === 'issued' ? ' (an issued invoice can be voided and re-issued).' : '.'),
                '/invoices/'.$i->id, [], ['amount' => $i->po_total_minor - $i->total_minor, 'currency' => $i->currency ?: 'GBP']);
        }
        $recharges = TravelItem::query()->where('decision', 'recharge')->whereNotNull('work_job_id')->with('job')->get();
        $onInvoice = Invoice::query()->whereIn('work_job_id', $recharges->pluck('work_job_id')->unique()->values())->whereIn('status', ['issued', 'paid'])
            ->orderByDesc('issue_date')->orderByDesc('id')->get()->unique('work_job_id')->keyBy('work_job_id');
        foreach ($recharges as $t) {
            if (array_key_exists('recharge:'.$t->id, $subsumed)) {
                continue;
            }
            $invoice = $onInvoice[$t->work_job_id] ?? null;
            $add('revenue', 1, 'recharge:'.$t->id, 'Recharge '.$t->provider.' to the client', (TravelItem::KINDS[$t->kind] ?? 'Booking').' '.$t->starts_on->format('j M Y').' for '.$t->job?->title
                .($invoice ? ": check it's on invoice {$invoice->number}." : ': add it to the invoice when you draft it.'), $invoice ? '/invoices/'.$invoice->id : '/jobs/'.$t->work_job_id, [],
                ['amount' => $t->amount_minor, 'currency' => $t->currency ?: 'GBP']);
        }

        // AI findings that didn't stand up to the double-check, about something that's still there.
        $failed = Verification::query()->whereIn('status', ['mismatch', 'partly', 'source_missing', 'second_read_disagreed'])->orderByDesc('checked_at')->get();
        $subjects = self::subjects($failed);
        foreach ($failed as $v) {
            [$label, $href] = $subjects[$v->subject_type][$v->subject_id] ?? [null, null];
            if ($label === null) {
                continue;
            }
            $add('checks', 1, 'check:'.$v->subject_type.':'.$v->subject_id, $label, $v->summary, $href, [self::action('recheck', $v->subject_type.':'.$v->subject_id, 'Re-check')]);
        }

        // Fines and interest (worked out once here: the return reminders below say what being late costs in points).
        try {
            $fines = PenaltyEstimator::all()['years'];
        } catch (Throwable $e) {
            report($e);
            $fines = [];
            // Its items (fines and interest, quarterly updates) are missing this time, not dealt with.
            array_push($notWorkedOut, 'penalty:', 'submission:');
        }
        $returnEffect = [];
        foreach ($fines as $y) {
            // Only where points are the law for the year: elsewhere being late costs the £100 penalty.
            $annual = collect($y['submissions'] ?? [])->firstWhere('kind', 'annual');
            if ($annual && $annual['effect'] && ! $annual['announced']) {
                $returnEffect[$y['tax_year']] = $annual['effect'];
            }
        }

        // A return due within 60 days. One you filed and unlocked to amend isn't late: it can be amended until 12 months
        // after its filing date (TMA 1970 s9ZA), so that's the date shown as it gets close.
        // Both dates are whole days: a return is on time until the end of its deadline day.
        foreach (TaxYear::query()->where('status', '!=', 'filed')->get() as $year) {
            if ($year->status === 'amending') {
                $by = FilingDates::amendBy($year->label, $year);
                if (today()->lte($by) && $by->lte(now()->addDays(60))) {
                    $add('deadline', 1, 'deadline:'.$year->label.':amend', "Tax return {$year->label}: amend by ".$by->format('j F Y'),
                        'You unlocked it to make changes. HMRC accepts an amended return online until then; lock the year again once it’s sent.', '/years/'.$year->label, [], ['due_on' => $by->format('Y-m-d')]);
                }

                continue;
            }
            // The filing date follows HMRC's notice to file (FilingDates); with no notice there's no return due yet.
            $dates = FilingDates::for($year->label, $year);
            $deadline = $dates['filing_date'];
            if ($deadline === null || $deadline->gt(now()->addDays(60))) {
                continue;
            }
            if (today()->gt($dates['own_return_last'])) {
                // Past the last day to file your own return (s34A), or to replace HMRC's determination (s28C(5)).
                $add('deadline', 1, 'deadline:'.$year->label, "Tax return {$year->label}: too late to file it yourself",
                    ($dates['own_return_basis'] === 'determination'
                        ? 'The time to replace HMRC’s determination of '.$dates['determination_on']->format('j F Y').' with your own return ended on '.$dates['own_return_last']->format('j F Y')
                            .', so it stands as the tax for the year. Only a claim for special relief can change it: get advice from an accountant.'
                        : 'The time limit for filing your own return ended on '.$dates['own_return_last']->format('j F Y').'. HMRC can still assess the tax: get advice from an accountant before you contact them.'),
                    '/years/'.$year->label, [], ['due_on' => $dates['own_return_last']->format('Y-m-d')]);

                continue;
            }
            $estimated = $dates['filing_basis'] === 'estimate';
            $add('deadline', 1, 'deadline:'.$year->label, "Tax return {$year->label}: ".(today()->gt($deadline) ? 'overdue since ' : 'due ').$deadline->format('j F Y').($estimated ? ' (estimated)' : ''),
                implode(' ', array_filter([
                    'Work through the year, then copy the figures into HMRC online.',
                    $estimated ? 'The date is estimated from your notice to file (its date plus 3 months and 7 days, as HMRC’s system works it out); the date printed on HMRC’s notice is the one that counts.' : null,
                    $dates['notice'] === 'assumed' ? 'Assumes HMRC sent you a notice to file by 31 July '.($year->period()->startYear + 1).'; if they didn’t, or sent it later, say so on the year page.' : null,
                    $returnEffect[$year->label] ?? null,
                ])), '/years/'.$year->label, [], ['due_on' => $deadline->format('Y-m-d')]);
        }

        // Fines and interest: penalties already due or still growing, or the next ones coming within 60 days. (Points
        // and £200s for submissions not yet due come up with the submissions, below and in the return reminders.)
        foreach ($fines as $y) {
            $owed = $y['penalties_pence'] + $y['interest_pence'];
            // Every late payment penalty on the next date (they can fall together), or another penalty coming soon.
            $nextLate = PenaltyEstimator::nextLatePayment($y['upcoming']);
            $within = now()->addDays(60)->format('Y-m-d');
            $soon = $nextLate && $nextLate['date'] <= $within
                ? ['label' => implode(' and ', $nextLate['labels']), 'date' => $nextLate['date'], 'amount_pence' => $nextLate['pence'], 'range' => null]
                : collect($y['upcoming'])->first(fn ($p) => $p['date'] <= $within && ! str_starts_with($p['code'], 'points') && ! str_starts_with($p['code'], 'sch24_'));
            // A year HMRC haven't asked a return for, though you needed to tell them: that comes first.
            $notify = (bool) ($y['notify_hmrc'] ?? false);
            // Penalties a broken payment plan held off that HMRC can charge now (by notice).
            $risk = (int) ($y['plan']['at_risk_pence'] ?? 0);
            if ($owed <= 0 && ! $soon && ! $notify && $risk <= 0) {
                continue;
            }
            // A penalty HMRC set within a range (telling HMRC late): its floor isn't what it costs if nothing changes.
            $ranged = collect([...$y['penalties'], ...$y['upcoming']])->filter(fn ($p) => ! empty($p['range']) || ! empty($p['amount_unknown']));
            $upTo = $ranged->sum(fn ($p) => $p['range']['max_pence'] ?? 0);
            $soonText = $soon ? ($soon['range'] ?? null
                ? 'Next: '.$soon['label'].' on '.CarbonImmutable::parse($soon['date'])->format('j F Y').' (up to '.Money::format($soon['range']['max_pence'], 'GBP', false).').'
                : 'Next: '.$soon['label'].' on '.CarbonImmutable::parse($soon['date'])->format('j F Y').(! empty($soon['amount_unknown']) ? ' (not worked out).' : ' ('.Money::format($soon['amount_pence'], 'GBP', false).').')) : null;
            $detail = implode(' ', array_filter([
                $owed > 0 ? Money::format($y['penalties_pence'], 'GBP', false).' in penalties and '.Money::format($y['interest_pence'], 'GBP', false).' interest so far'
                    .($y['per_day_pence'] > 0 ? ', growing by about '.Money::format($y['per_day_pence'], 'GBP', false).' a day' : '').'.' : null,
                $upTo > 0 ? 'The penalty for telling HMRC late can be up to '.Money::format($upTo, 'GBP', false).'.' : null,
                $risk > 0 ? Money::format($risk, 'GBP', false).' more that your broken payment plan held off can now be charged.' : null,
                $soonText,
                $y['actions'][0] ?? null,
                'An estimate from your records.',
            ]));
            // Items set within a range carry no amount: their floor would understate them.
            $amount = $owed > 0 ? $owed : ($soon && empty($soon['range']) && empty($soon['amount_unknown']) ? $soon['amount_pence'] : 0);
            $add('penalties', 1, 'penalty:'.$y['tax_year'], $owed > 0 ? "Fines and interest for {$y['tax_year']}: ".Money::format($owed, 'GBP', false)
                : ($notify ? "Tell HMRC you need to file for {$y['tax_year']}" : ($risk > 0 && ! $soon ? "Payment plan broken for {$y['tax_year']}" : "Avoid a fine for {$y['tax_year']}")),
                $detail, '/years/'.$y['tax_year'], [], ($amount > 0 ? ['amount' => -$amount] : []) + ($soon ? ['due_on' => $soon['date']] : []));
        }

        // Making Tax Digital quarterly updates due within 14 days, or late with no "sent on" date: what being late
        // costs in late submission points (or £200 at the threshold), and where to record the date.
        foreach ($fines as $y) {
            $points = $y['points'] ?? ($y['announced']['points'] ?? null);
            foreach ($y['submissions'] ?? [] as $s) {
                if ($s['kind'] !== 'quarterly' || $s['submitted_on'] !== null || ! in_array($s['status'], ['not_sent', 'not_due'], true)) {
                    continue;
                }
                $due = CarbonImmutable::parse($s['due_on']);
                $late = $s['status'] === 'not_sent';
                if (! $late && $due->gt(today()->addDays(14))) {
                    continue;
                }
                // Late only if it wasn't sent: Expend just has no "sent on" date for it.
                $add('penalties', 1, 'submission:'.$y['tax_year'].':q'.$s['quarter'], 'Quarterly update '.$y['tax_year'].' ('.Submissions::periodText($s).'): '
                    .($late ? 'no sent date recorded (due '.$due->format('j F Y').')' : 'due '.$due->format('j F Y')),
                    implode(' ', array_filter([
                        $late && $s['effect'] ? preg_replace('/^(Late|If it wasn’t sent on time): /u', 'If late: ', $s['effect']) : $s['effect'],
                        $points && ! $s['volunteer'] ? $points['text'].($points['announced'] && ! str_contains((string) $s['effect'], 'announced rules') ? ' (Under the announced rules, which aren’t law yet.)' : '') : null,
                        $late ? 'Already sent? Record the date on the year page.' : 'Once it’s sent, record the date on the year page.',
                    ])), '/years/'.$y['tax_year'], [], ($s['penalty_pence'] > 0 ? ['amount' => -$s['penalty_pence']] : []) + ['due_on' => $due->format('Y-m-d')]);
            }
        }

        // Tax records to check: payments to HMRC not recorded as tax, and filed returns your records no longer match
        // (each found by a detector of its own: Intel\Detectors\HmrcUnallocated and YearFiledDrift).
        if ($withDetectors) {
            $try('hmrc:', function () use ($add, &$checked) {
                $checked['hmrc'] = self::fromDetector(new HmrcUnallocated, $add);
            });
            $try('drift:', function () use ($add, &$checked) {
                $checked['years'] = self::fromDetector(new YearFiledDrift, $add);
            });
        }

        // Work to answer: an availability check, a hold or an offer whose date to reply by is close or has passed.
        // You reply to them yourself; the buttons only record what you decided. A new date to reply by is a new item.
        foreach (self::toAnswer(Opportunity::query()->with('client'))->orderBy('reply_by')->orderBy('id')->get() as $o) {
            $in = (int) today()->diffInDays($o->reply_by, false);
            $what = match ($o->status) {
                'offered' => 'Offer',
                'held' => $o->hold_rank ? self::ordinal($o->hold_rank).' option hold' : 'Hold',
                default => 'Are you free?',
            };
            $client = $o->client?->name ?? $o->client_name;
            $add('offers', 1, 'opp_reply:'.$o->id.':'.$o->reply_by->format('Y-m-d'), $what.': '.$o->title.($client ? ' for '.$client : ''),
                implode(' · ', array_filter([
                    // The date, not "in 2 days": worded the same each day, so the findings history only notes a real change.
                    ($in < 0 ? 'Reply was due ' : 'Reply by ').$o->reply_by->format('j M Y'),
                    self::opportunityDetail($o),
                ])).'. Reply to them yourself: Expend never replies for you.',
                '/opportunities/'.$o->id,
                array_values(array_filter([
                    $o->status === 'offered' && self::oneTap($o) ? self::action('opp_add', $o->id, 'I said yes: add the job') : null,
                    self::action('opp_decline', $o->id, 'I said no', 'ghost'),
                ])), ['due_on' => $o->reply_by->format('Y-m-d')]);
        }

        // Booked, with no job yet: one tap adds the job with the details read from the emails (checked against them).
        foreach (Opportunity::query()->needsJob()->with('client')->orderByRaw('CASE WHEN starts_on IS NULL THEN 1 ELSE 0 END')->orderBy('starts_on')->orderBy('id')->get() as $o) {
            $soon = $o->starts_on !== null && $o->starts_on->lte(today()->addDays(14));
            $client = $o->client?->name ?? $o->client_name;
            $add('bookings', $soon ? 1 : 2, 'opp_job:'.$o->id, 'Booked, no job yet: '.$o->title.($client ? ' for '.$client : ''),
                implode(' · ', array_filter([self::opportunityDetail($o)]))
                    .(self::oneTap($o) ? '. Add it as a job (its emails come with it), or say it isn’t work.' : '. Open it to check the details, then add it as a job.'),
                '/opportunities/'.$o->id,
                array_values(array_filter([
                    self::oneTap($o) ? self::action('opp_add', $o->id, 'Add the job') : null,
                    self::action('opp_dismiss', $o->id, 'Not work', 'ghost'),
                ])));
        }

        // Finished jobs with no invoice issued (as PoStatus counts it: an issued or paid invoice for the job).
        $finished = $capped(WorkJob::query()->with('client')->where('status', '!=', 'cancelled')->whereDate('ends_on', '<', today())->orderByDesc('ends_on'), 300)->get();
        if (! $complete && $finished->count() >= 300) {
            $cappedKeys[] = 'invoice:';
        }
        $invoiced = Invoice::query()->whereIn('work_job_id', $finished->pluck('id'))->whereIn('status', ['issued', 'paid'])->distinct()->pluck('work_job_id')->flip();
        $drafts = Invoice::query()->where('status', 'draft')->whereIn('work_job_id', $finished->pluck('id'))->pluck('id', 'work_job_id');
        foreach ($finished->filter(fn (WorkJob $j) => ! isset($invoiced[$j->id])) as $j) {
            $draft = $drafts[$j->id] ?? null;
            $add('invoice', 2, 'invoice:'.$j->id, 'Invoice '.$j->title, ($j->client?->name ? $j->client->name.' · ' : '').'finished '.$j->ends_on->format('j M Y').($draft ? ' · draft ready to check and issue' : ''),
                $draft ? '/invoices/'.$draft : '/jobs/'.$j->id, $draft ? [] : [self::action('job_invoice', $j->id, 'Draft the invoice')]);
        }

        // Issued but never emailed, and not paid in full (a client who's paid it all has had the invoice; one who's
        // paid a deposit still needs it). Left until you've decided a payment waiting in the Inbox that may be for it.
        $unsent = $open->whereNull('sent_at')->sortBy(fn (Invoice $i) => [$i->issue_date?->format('Y-m-d') ?? '', $i->id])
            ->filter(fn (Invoice $i) => $received[$i->id]['paid'] < $i->total_minor && $received[$i->id]['waiting'] <= 0);
        foreach ($unsent as $i) {
            ['paid' => $paid, 'elsewhere' => $elsewhere] = $received[$i->id];
            $money = fn (int $minor) => Money::format($minor, $i->currency ?: 'GBP');
            $add('invoice', 2, 'unsent:'.$i->id, "Send invoice {$i->number}", $money($i->total_minor).' to '.($i->bill_to_name ?: 'the client').($i->bill_to_email ? ' at '.$i->bill_to_email : ': add their email address on the invoice')
                .($paid > 0 ? ' · '.$money($paid).' already received' : '')
                .($elsewhere > 0 ? ' · '.Money::format($elsewhere).' came in for this job in another currency: if that paid it, mark this not relevant' : ''),
                '/invoices/'.$i->id, $i->bill_to_email ? [self::action('inv_send', $i->id, 'Email it')] : [], ['amount' => $i->total_minor - $paid, 'currency' => $i->currency ?: 'GBP']);
        }

        // Expenses you could claim: mileage for finished jobs you drove to, with none recorded (Intel\Detectors\TravelUnclaimed).
        if ($withDetectors) {
            $try('mileage:', function () use ($add, $hidden, &$checked) {
                $checked['jobs'] = self::unclaimedMileage($add, $hidden);
            });
        }

        // Possible jobs from the job finder, the next 14 days first (CandidateText). One found in your calendar says so
        // ("From your calendar: Tue 3 Dec, 08:00–18:00, Acme Arena"), opens that entry, and offers "Part of <job>" when
        // a job you have is near it with the same client or place; one whose entry has gone says so.
        $candidates = JobCandidate::query()->where('status', 'open')->orderBy('starts_on')->orderBy('id')->get();
        foreach (CandidateText::forAll($candidates) as $id => $t) {
            $add('jobs', 2, 'candidate:'.$id, $t['title'], $t['detail'], $t['href'], array_values(array_filter([
                self::action('cand_add', $id, 'Add as a job'),
                $t['part_of'] ? self::action('cand_merge', $id.':'.$t['part_of']['job_id'], $t['part_of']['label']) : null,
                self::action('cand_dismiss', $id, 'Not a job', 'ghost'),
            ])));
        }

        // Money moving between your own accounts, suggested by the matcher, that is safe to confirm in one go: both
        // sides of each pair, and money going to another account of yours (TransferPairer::confirmable). The button
        // carries the last suggestion shown, so one made after the list was built is never confirmed unseen. Money
        // paid in with no other side imported is left out: a client could have paid it, so it's listed with the
        // payments to decide, one by one.
        $moves = TransferPairer::confirmable();
        if ($moves['count'] > 0) {
            $sides = implode(' and ', array_filter([
                $moves['out_count'] ? Money::format($moves['out_pence'], 'GBP', false).' out' : null,
                $moves['in_count'] ? Money::format($moves['in_pence'], 'GBP', false).' in' : null,
            ]));
            $add('transfers', 2, 'transfers', 'Confirm '.$moves['count'].' '.($moves['count'] === 1 ? 'transfer' : 'transfers').' between your accounts',
                ucfirst($sides).' moving between your own accounts: both sides of each pair, and money going to another account of yours. Once confirmed, they’re left out of your income and spending.',
                '/transactions?kind=transfer&status=suggested', [self::action('transfers_ok', $moves['up_to'], 'Confirm all')]);
        }

        // Payments to decide: suggestions first (one tap to accept), then the rest. Ones you snoozed are left out
        // before the first few are picked, so the next ones show instead, and the count is of what's left. Suggested
        // transfers are confirmed together above, except money paid in with no other side imported, and a transfer
        // with something else suggested beside it (a currency exchange's fee, as a bank charge), which are here.
        $hiddenTx = array_map(fn ($k) => (int) substr($k, 3), array_values(array_filter(array_keys($hidden), fn ($k) => str_starts_with($k, 'tx:'))));
        $waiting = fn () => BankTransaction::query()->whereIn('review_status', ['suggested', 'unreviewed'])->whereNotIn('id', $hiddenTx)
            ->where(fn ($q) => $q->whereDoesntHave('allocations', fn ($a) => $a->where('kind', 'transfer')->whereNull('confirmed_at')->where('decided_by', 'matcher'))
                ->orWhereIn('id', $moves['left_ids'] ?: [0])
                ->orWhereHas('allocations', fn ($a) => $a->where('kind', '!=', 'transfer')->whereNull('confirmed_at')));
        $txs = $waiting()->with(['account', 'allocations.category', 'allocations.job'])
            ->orderByRaw("CASE WHEN review_status = 'suggested' THEN 0 ELSE 1 END")->orderByDesc('posted_on')->limit($limit)->get();
        foreach ($txs as $t) {
            $s = $t->allocations->first();
            // Every line suggested, so accepting never confirms a part you didn't see (an exchange's fee beside its transfer).
            $suggestion = $s && ! $s->confirmed_at ? $t->allocations->map(fn (Allocation $a) => (Allocation::KINDS[$a->kind] ?? $a->kind).($a->category ? ': '.$a->category->name : '').($a->job ? ' · '.$a->job->title : ''))
                ->unique()->implode(' + ') : null;
            $how = self::howPaid($t);
            $add('payments', 2, 'tx:'.$t->id, ($t->counterparty ?: $t->description), $t->effectiveDate()->format('j M Y').($how !== '' ? ' · '.$how : '').($suggestion ? ' · suggested: '.$suggestion : ''), '/inbox',
                array_values(array_filter([$suggestion ? self::action('tx_accept', $t->id, 'Accept') : null, self::action('tx_personal', $t->id, 'Personal', 'ghost')])), ['amount' => $t->amount_pence]);
        }
        $payments = $txs->count() < $limit ? $txs->count() : $waiting()->count();

        // The same amount to the same merchant twice in a day (£20 or more): charged twice?
        $dupes = $capped(OwnAccounts::scopeNotTransfers(BankTransaction::query())->where('amount_pence', '<=', -2000)->whereNull('transfer_pair_id')
            ->select('account_id', 'merchant_key', 'amount_pence', 'posted_on')->selectRaw('COUNT(*) as n, MIN(id) as first_id')
            ->groupBy('account_id', 'merchant_key', 'amount_pence', 'posted_on')->havingRaw('COUNT(*) > 1')->orderByDesc('posted_on'), 50)->get();
        if (! $complete && $dupes->count() >= 50) {
            $cappedKeys[] = 'dupe:';
        }
        $firsts = BankTransaction::query()->whereIn('id', $dupes->pluck('first_id'))->get()->keyBy('id');
        foreach ($dupes as $d) {
            if (! ($tx = $firsts[$d->first_id] ?? null)) {
                continue;
            }
            // What's at stake is the extra charges only: of n alike, one was meant.
            $add('duplicates', 2, 'dupe:'.$d->first_id, 'Charged twice? '.($tx->counterparty ?: $tx->description), $d->n.' payments of '.Money::format(abs($d->amount_pence)).' on '.$tx->posted_on->format('j M Y').' from the same account. If one was a mistake, ask for a refund; if both were real, snooze this.',
                '/transactions?year='.$tx->tax_year.'&q='.urlencode($tx->counterparty ?: $tx->description), [], ['amount' => $d->amount_pence * ($d->n - 1)]);
        }

        // Merchants you always decide the same way: make it automatic. All of them, so the count doesn't depend on
        // how many are shown, and the next ones show once you snooze the first.
        foreach (app(RuleLearner::class)->offers(PHP_INT_MAX) as $o) {
            $add('rules', 2, 'rule:'.$o['merchant_key'], 'Always do this for '.$o['name'].'?', $o['text'], '/settings/rules',
                [self::action('rule_make', $o['example_id'], 'Always do this'), self::action('rule_no', $o['example_id'], 'No', 'ghost')]);
        }

        // Stays and journeys to decide.
        $stays = TravelItem::query()->whereNull('decided_at')->where('status', '!=', 'cancelled')->whereNotNull('suggestion')->orderBy('starts_on')->get()
            ->filter(fn (TravelItem $i) => in_array($i->suggestion['decision'] ?? null, ['business', 'personal', 'mixed', 'client_booked', 'review'], true));
        foreach ($stays as $i) {
            $decision = $i->suggestion['decision'];
            $add('stays', 2, 'stay:'.$i->id, (TravelItem::KINDS[$i->kind] ?? $i->kind).': '.($i->kind === 'hotel' ? ($i->place ?: $i->provider) : $i->provider),
                $i->starts_on->format('j M Y').' · suggested: '.(TravelItem::DECISIONS[$decision] ?? 'check it'), '/travel?year='.UkTaxYear::fromDate($i->starts_on)->label(),
                array_values(array_filter([$decision !== 'review' ? self::action('stay_accept', $i->id, 'Accept') : null, self::action('stay_personal', $i->id, 'Personal', 'ghost')])),
                ['amount' => $i->amount_minor ? -$i->amount_minor : null, 'currency' => $i->currency ?: 'GBP']);
        }

        // Jobs with no PO. Those worth chasing now (starting within 60 days, under way, or done and not invoiced) come
        // first, with who to ask (Opportunities::poChase): the job's page has the request ready to send from your email.
        $jobs = $capped(WorkJob::query()->where('status', '!=', 'cancelled')->orderByDesc('starts_on'), 300)->get();
        if (! $complete && $jobs->count() >= 300) {
            $cappedKeys[] = 'no_po:';
        }
        $noPo = self::withoutPo($jobs);
        $chase = [];
        if ($noPo->isNotEmpty()) {
            try {
                foreach (app(Opportunities::class)->poChase(null, 60, $noPo->pluck('id')->all()) as $c) {
                    $chase[$c['job_id']] = $c;
                }
            } catch (Throwable $e) {
                report($e); // listed without who to ask
            }
        }
        foreach ($noPo->sortBy(fn (WorkJob $j) => isset($chase[$j->id]) ? 0 : 1)->values() as $j) {
            $c = $chase[$j->id] ?? null;
            $who = $c['contact'] ?? null;
            // Worded the same every day (no "starts in 5 days"), so the findings history only notes a real change.
            $add('no_po', 2, 'no_po:'.$j->id, 'No PO: '.$j->title, $c
                ? $j->starts_on->format('j M Y').'. '.($c['days_until'] < 0 && $j->ends_on->lt(today()) ? 'Done and not invoiced. ' : '').($who
                    ? 'Ask '.trim(($who['name'] ? $who['name'].' ' : '').'<'.$who['email'].'>').' for it: the job’s page has the request ready to send from your own email.'
                    : 'No one found to ask: link the PO email or attachment, add the number, or mark it not needed.')
                : $j->starts_on->format('j M Y').'. Link the PO email or attachment, add the number, or mark it not needed.', '/jobs/'.$j->id,
                [self::action('job_no_po', $j->id, 'No PO needed', 'ghost')]);
        }

        // Accounts that don't match the bank's own figures, and statements not imported for a while.
        foreach (Account::query()->where('is_active', true)->get() as $acc) {
            $r = Reconciler::account($acc);
            $currency = $r['currency'] ?? 'GBP';
            foreach ($r['gaps'] as $g) {
                // Kept by the account and its first day (with its kind), so a gap partly filled is the same finding
                // with less money. A snooze or "not relevant" is kept by its dates and amount (dismiss_key,
                // dismissKeyFor()), as you saw it: one that grows (or shrinks) comes back. A snooze kept the way
                // before that (gap:{account}:{from}:{amount}) still counts.
                $kind = $g['kind'] ?? 'missing';
                $suffix = $kind === 'extra' ? ':twice' : ($kind === 'misdated' ? ':date' : '');
                $key = self::gapKey($acc->id, $g);
                $version = 'gap:'.$acc->id.':'.$g['from'].':'.$g['to'].':'.$g['missing_pence'].$suffix;
                $legacy = 'gap:'.$acc->id.':'.$g['from'].':'.$g['missing_pence'];
                $kept = ['dismiss_key' => $version, 'hidden_under' => $kind === 'missing' ? [$version, $legacy] : [$version], 'range' => ['from' => $g['from'], 'to' => $g['to']]];
                // A gap is in the account's own minor units (¥1,500 is 1500); the item's amount, like every to-do's,
                // in hundredths of its currency, as the list, the history and Telegram show it.
                $amount = Money::formatMinor(abs($g['missing_pence']), $currency);
                $meta = fn (int $minor) => ['amount' => Money::toHundredths($minor, $currency), 'currency' => $currency] + $kept;
                // The account's lines of that amount, where a payment in twice (or dated differently) is easy to see.
                $lines = '/transactions?account='.$acc->id.($currency === 'GBP' ? '&min='.($a = number_format(abs($g['missing_pence']) / 100, 2, '.', '')).'&max='.$a : '');
                match ($kind) {
                    'extra' => $add('reconcile', 2, $key, $acc->name.': '.$amount.' may be in twice', $g['text'].' Check whether the payment is on the ledger twice (for example from a second export) before undoing anything: undoing an import takes out every line it brought in. If it’s only there once, look for a gap of the same amount nearby; the bank may have it on another date.',
                        $lines, [], $meta(-$g['missing_pence'])), // the lines in twice
                    'misdated' => $add('reconcile', 2, $key, $acc->name.': check the date of a '.$amount.' line', $g['text'].' If it’s the same payment, nothing is missing: mark this not relevant. If not, import the statement for those dates (or the missing rows).',
                        $lines, [], $meta($g['missing_pence'])),
                    default => $add('reconcile', 2, $key, $acc->name.': '.$amount.' missing', $g['text'].' Import the statement for those dates (or the missing rows); if they’re already in another account, check the right account was chosen.',
                        '/imports', [], $meta($g['missing_pence'])),
                };
            }
            if ($r['stale']) {
                // Kept by the account; "not relevant" by its last line too (dismissKeyFor()), so it comes back when the
                // account has new lines.
                $since = CarbonImmutable::parse($r['last_import'] ?? $r['last_line'])->format('j M Y');
                $version = 'stale:'.$acc->id.':'.$r['last_line'];
                $add('reconcile', 3, 'stale:'.$acc->id, $acc->name.': nothing imported since '.$since,
                    $acc->parent_account_id
                        ? 'A statement for one currency doesn’t show another’s. If you used this pocket after '.$since.', import its statement; if not, mark this not relevant (it comes back when the pocket has new lines).'
                        : 'Import its latest statement, or choose “Hide from checks” on Accounts if it’s closed.', '/imports', [], ['dismiss_key' => $version, 'hidden_under' => [$version]]);
            }
        }

        // Paid bookings with no payment in your statements.
        $unpaid = TravelItem::query()->whereNull('bank_transaction_id')->whereNotNull('amount_minor')->where('status', '!=', 'cancelled')
            ->whereIn('payment', ['paid', 'pay_at_property'])->where(fn ($q) => $q->whereNull('decision')->orWhereNotIn('decision', ['client_booked', 'personal']))
            ->whereDate('starts_on', '<', now()->subDays(10))->orderByDesc('starts_on')->get();
        foreach ($unpaid as $i) {
            $add('missing', 3, 'missing:'.$i->id, 'No payment found: '.$i->provider, $i->starts_on->format('j M Y').'. The booking says it was paid; import the card or account it was paid with, or add it as a manual expense.',
                '/travel?year='.UkTaxYear::fromDate($i->starts_on)->label(), [], ['amount' => -$i->amount_minor, 'currency' => $i->currency ?: 'GBP']);
        }

        // Documents held because the sender isn't on your list.
        $docs = Document::query()->where('status', 'held')->whereNull('parent_id')->latest()->get();
        foreach ($docs as $d) {
            $add('documents', 3, 'doc:'.$d->id, 'Held: '.($d->email_subject ?: $d->original_name), 'From '.($d->email_from ?? 'an unknown sender').'. Approve it to read it.', '/documents/'.$d->id,
                [self::action('doc_approve', $d->id, 'Approve')]);
        }

        // Every other detector registered (M4's money discoveries, and any added since): each one going wrong never
        // stops the list. The ones above have their own places in it. One shown below the fold is left to belowFold(),
        // except for everything().
        if ($withDetectors) {
            foreach (self::otherDetectors() as $detector) {
                if (! $below && DetectorLevels::belowFold($detector->code(), $levels)) {
                    continue;
                }
                $try($detector->code(), function () use ($detector, $add) {
                    foreach ($detector->detect(new CycleContext) as $draft) {
                        self::addDraft($add, $draft);
                    }
                });
            }
        }

        // Hide what you snoozed or marked not relevant (or, for everything(), mark it), under its key or another it
        // may be kept under (a gap's dates and amount), count what's left, and rank it: within each priority, most at
        // stake first (Intel\Ranker), each with what it's about in the shared vocabulary. A kind shown below the fold
        // (a new detector, or one demoted by its false positives: Intel\DetectorLevels) is info, counted apart; a
        // close-out that says what items above the fold said before keeps their place.
        $by = fn (array $i) => collect([$i['key'], ...($i['hidden_under'] ?? [])])->first(fn ($k) => isset($hidden[$k]));
        $items = $complete
            ? array_map(fn ($i) => $i + ['hidden' => $by($i) !== null, 'hidden_by' => $by($i) ?? $i['key']], $items)
            : array_values(array_filter($items, fn ($i) => $by($i) === null));
        $items = array_map(function ($i) use ($levels) {
            $i = self::described($i);

            return $i + ['below_fold' => isset($i['code']) && empty($i['subsumes']) && DetectorLevels::belowFold($i['code'], $levels)];
        }, $items);
        $above = array_filter($items, fn ($i) => ! $i['below_fold']);
        $counts = array_count_values(array_column($above, 'group'));
        $counts['payments'] = $payments; // only the first few are listed
        $counts = array_filter($counts);
        $belowCounts = array_count_values(array_column(array_filter($items, fn ($i) => $i['below_fold']), 'group'));
        $items = Ranker::sort(array_map(fn ($i) => Ranker::annotate($i), $items), array_flip(array_keys(self::GROUPS)));

        return ['items' => $items, 'counts' => $counts, 'below_counts' => $belowCounts, 'total' => array_sum($counts), 'per_group' => $limit,
            'failed' => array_values(array_unique($notWorkedOut)), 'capped' => array_values(array_unique($cappedKeys)), 'checked' => $checked];
    }

    /**
     * A detector's findings as to-do items, exactly as they read; returns what it looked at (its checked()).
     *
     * @param  callable  $add  TodoList::compute()'s
     */
    private static function fromDetector(Detector $detector, callable $add): ?int
    {
        foreach ($detector->detect(new CycleContext) as $draft) {
            self::addDraft($add, $draft);
        }

        return method_exists($detector, 'checked') ? $detector->checked() : null;
    }

    /** The detectors in its places above (the to-do list's own groups, M1's three, health and the close-out). */
    private const OWN_PLACES = [TodoGroupsDetector::class, HmrcUnallocated::class, TravelUnclaimed::class, YearFiledDrift::class, Health::class, JobCloseout::class];

    /** @return list<Detector> every other detector registered (Intel\Detectors\Registry), as each list is built */
    private static function otherDetectors(): array
    {
        return array_values(array_map(fn (Detector $d) => clone $d, array_filter(app(Registry::class)->all(),
            fn (Detector $d) => ! in_array($d::class, self::OWN_PLACES, true))));
    }

    /** @param  callable  $add  TodoList::compute()'s */
    private static function addDraft(callable $add, FindingDraft $d): void
    {
        $add($d->group, $d->priority, $d->key, $d->title, $d->detail, $d->href, $d->actions, $d->itemExtra());
    }

    /**
     * An item with what it's about in the shared vocabulary (docs/intelligence-cycle.md section 2), for the ranking
     * and the page: its code, the record it's about, which way its money goes, its grade, and the evidence it rests
     * on. An item a detector made already has them; the rest are read from their keys (TodoGroupsDetector::classify()).
     * Routine items (payments, rules, transfers, stays) are proposals, not findings, and have none.
     */
    private static function described(array $item): array
    {
        if (isset($item['code'])) {
            return $item;
        }
        $c = TodoGroupsDetector::classify($item['key'], isset($item['amount']) ? (int) $item['amount'] : null);
        if ($c === null) {
            return $item;
        }
        [$grade] = Grades::forCode($c['code']);

        return $item + array_filter(['code' => $c['code'], 'subject_ref' => $c['subject_ref'], 'direction' => $c['direction'], 'grade' => $grade,
            'why' => $c['subject_ref'] ? [$c['subject_ref']] : null], fn ($v) => $v !== null);
    }

    /** The key a gap is kept by: its account and first day, with its kind (a line in twice, or dated differently). */
    private static function gapKey(int $account, array $gap): string
    {
        $kind = $gap['kind'] ?? 'missing';

        return 'gap:'.$account.':'.$gap['from'].($kind === 'extra' ? ':twice' : ($kind === 'misdated' ? ':date' : ''));
    }

    /**
     * The key a snooze or "not relevant" is kept under for the item with this key: for a gap with the bank, the gap
     * as it is now (its dates and amount), so one that grows or shrinks comes back; for an account with nothing
     * imported lately, its last line, so new lines bring it back; for a job's close-out, its parts as shown (what's
     * short of the PO, and each recharge: JobCloseout), so a new part brings it back. Every other item is kept under
     * its own key, as is a gap, account or close-out that isn't there now.
     */
    public static function dismissKeyFor(string $key): string
    {
        if (preg_match('/^closeout:(\d+)$/', $key, $m)) {
            $draft = collect((new JobCloseout([(int) $m[1]]))->detect(new CycleContext))->firstWhere('key', $key);

            return $draft?->dismissKey ?? $key;
        }
        if (! preg_match('/^(gap|stale):(\d+)(?::(\d{4}-\d{2}-\d{2})(:twice|:date)?)?$/', $key, $m) || ($m[1] === 'gap') !== isset($m[3])) {
            return $key;
        }
        $account = Account::query()->find((int) $m[2]);
        if (! $account) {
            return $key;
        }
        $r = Reconciler::account($account);
        if ($m[1] === 'stale') {
            return $r['stale'] ? 'stale:'.$account->id.':'.$r['last_line'] : $key;
        }
        foreach ($r['gaps'] as $g) {
            if (self::gapKey($account->id, $g) === $key) {
                $kind = $g['kind'] ?? 'missing';

                return 'gap:'.$account->id.':'.$g['from'].':'.$g['to'].':'.$g['missing_pence'].($kind === 'extra' ? ':twice' : ($kind === 'misdated' ? ':date' : ''));
            }
        }

        return $key;
    }

    /**
     * The finding a snooze or "not relevant" is about, by the key it's kept under: a gap's dates and amount (or the
     * way gaps were kept before) are the gap kept by its first day; an account's last line is the account; a
     * close-out's parts are the job's close-out. Any other key is its own.
     */
    public static function findingKey(string $key): string
    {
        if (preg_match('/^(closeout:\d+):.+$/', $key, $m)) {
            return $m[1];
        }
        $date = '\d{4}-\d{2}-\d{2}';
        if (preg_match('/^gap:(\d+):('.$date.'):'.$date.':-?\d+(:twice|:date)?$/', $key, $m)) {
            return 'gap:'.$m[1].':'.$m[2].($m[3] ?? '');
        }
        if (preg_match('/^gap:(\d+):('.$date.'):-?\d+$/', $key, $m)) {
            return 'gap:'.$m[1].':'.$m[2];
        }
        if (preg_match('/^stale:(\d+):.+$/', $key, $m)) {
            return 'stale:'.$m[1];
        }

        return $key;
    }

    /**
     * Mileage you could claim (Intel\Detectors\TravelUnclaimed), as items on the list: one for each open tax year's
     * finished jobs you drove to with no mileage recorded, or one asking for your home postcode.
     *
     * Each item is kept by its jobs (mileage:{year}:{set}), so "Not relevant" says those jobs have no mileage to claim
     * (they're left out from then on: TodoController::dismiss()), and a job that finishes later brings it back.
     *
     * @param  callable  $add  TodoList::compute()'s
     * @param  array<string, true>  $hidden  keys you snoozed or marked not relevant
     * @return int the finished jobs looked at
     */
    private static function unclaimedMileage(callable $add, array $hidden): int
    {
        $found = self::mileageFound($hidden);
        foreach ($found['items'] as $i) {
            self::addDraft($add, TravelUnclaimed::draft($i));
        }

        return $found['jobs'];
    }

    /**
     * The jobs of a mileage item still on the list under this key (mileage:{year}:{set}), for "Not relevant" to leave
     * them out from then on; null for any other key, or one whose jobs have changed since.
     *
     * @return list<int>|null
     */
    public static function mileageJobs(string $key): ?array
    {
        if (! preg_match('/^mileage:\d{4}-\d{2}:[0-9a-f]+$/', $key)) {
            return null;
        }
        $item = collect(self::mileageFound(TodoDismissal::activeKeys())['items'])->firstWhere('key', $key);

        return $item ? $item['job_ids'] : null;
    }

    /** The dismissal that leaves a job out of the mileage items, as part of the item with this key. */
    public static function mileageJobKey(int $job, string $key): string
    {
        return self::MILEAGE_JOB.$job.':'.substr($key, strrpos($key, ':') + 1);
    }

    /**
     * The mileage items and how many finished jobs were looked at: kept (MILEAGE_KEY) until something they're made
     * from changes, as seen by this process (watch()) or by their fingerprint, or the date changes, or after
     * KEEP_SECONDS. Working them out looks at each job's travel and postcodes (JobTrips::proposals), so they aren't
     * worked out again every time the list is built.
     *
     * @param  array<string, true>  $hidden
     * @return array{items:list<array{key:string, year:?string, title:string, detail:string, href:string, extra:array, job_ids:list<int>}>, jobs:int}
     */
    private static function mileageFound(array $hidden): array
    {
        $stamp = self::mileageStamp($hidden);
        if (($kept = self::mileageKept($hidden, $stamp)) !== null) {
            return $kept;
        }
        self::$mileageChanged = false;
        // Kept with no expiry of the store's own, and its own time limit inside: the database cache deletes an expired
        // entry when it's read, and mileageKept() must only read.
        $found = TravelUnclaimed::found(TravelUnclaimed::excluded($hidden)) + ['stamp' => $stamp, 'until' => now()->addSeconds(self::KEEP_SECONDS)->getTimestamp()];
        Cache::forever(self::MILEAGE_KEY, $found);

        return $found;
    }

    /**
     * The mileage items as last worked out, if nothing they're made from has changed since (and within KEEP_SECONDS);
     * otherwise null. Only reads, whatever the cache store (Intel\Detectors\TravelUnclaimed uses it, and never writes).
     *
     * @param  array<string, true>  $hidden
     * @return array{items:list<array>, jobs:int}|null
     */
    public static function mileageKept(array $hidden, ?string $stamp = null): ?array
    {
        if (self::$mileageChanged) {
            return null;
        }
        $kept = Cache::get(self::MILEAGE_KEY);

        return is_array($kept) && ($kept['stamp'] ?? null) === ($stamp ?? self::mileageStamp($hidden)) && ($kept['until'] ?? 0) >= now()->getTimestamp() ? $kept : null;
    }

    /** @param  array<string, true>  $hidden */
    private static function mileageStamp(array $hidden): string
    {
        $file = fn (string $class) => filemtime((new \ReflectionClass($class))->getFileName());

        return md5(json_encode([today()->format('Y-m-d'), array_keys(TravelUnclaimed::excluded($hidden)), $file(TravelUnclaimed::class), $file(JobTrips::class),
            self::tableStamps(self::MILEAGE_WATCHED, self::MILEAGE_SETTINGS)]));
    }

    /** An opportunity's dates, rate and who it's from, in a few words: "12–14 Mar 2026 · £350.00 a day · Alex Smith". */
    private static function opportunityDetail(Opportunity $o): string
    {
        $dates = null;
        if ($o->starts_on) {
            $end = $o->ends_on ?? $o->starts_on;
            $dates = $o->starts_on->isSameDay($end) ? $o->starts_on->format('j M Y')
                : ($o->starts_on->format('Y-m') === $end->format('Y-m') ? $o->starts_on->format('j') : $o->starts_on->format('j M')).'–'.$end->format('j M Y');
        }
        $unchecked = $o->unverifiedFields();

        return implode(' · ', array_filter([
            $dates ?? 'No dates yet',
            $o->venue,
            $o->rate_minor ? Money::formatMinor($o->rate_minor, $o->rate_currency ?? 'GBP', false).' '.(['day' => 'a day', 'hour' => 'an hour'][$o->rate_unit] ?? 'for the job') : null,
            $o->contact_name ?? $o->contact_email,
            $unchecked ? 'not found word for word in the email: '.implode(', ', $unchecked) : null,
            $o->mayBeOff() ? 'a later email may call it off' : null,
        ]));
    }

    /**
     * Whether the job can be added with one tap from the list (or Telegram): every one of its days, its title, client
     * and rate were found word for word in the email (Opportunity::oneTapReady). Otherwise it's opened and checked first.
     */
    private static function oneTap(Opportunity $o): bool
    {
        return $o->oneTapReady();
    }

    private static function ordinal(int $n): string
    {
        return $n.(in_array($n % 100, [11, 12, 13], true) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th'));
    }

    /**
     * The jobs among these that have no PO (see PoStatus). Only a job with no PO number typed and not marked "no PO
     * needed" can be missing one, so only those are looked up.
     *
     * @param  Collection<int, WorkJob>  $jobs
     * @return Collection<int, WorkJob>
     */
    public static function withoutPo(Collection $jobs): Collection
    {
        $candidates = $jobs->filter(fn (WorkJob $j) => ! $j->po_not_required && ! filled($j->po_number) && $j->status !== 'cancelled');
        $missing = array_filter(PoStatus::forJobs($candidates), fn ($s) => $s['missing']);

        return $candidates->filter(fn (WorkJob $j) => isset($missing[$j->id]))->values();
    }

    /**
     * How a payment was made, so it can be decided from the list (or the chat): the time, the account and card, the
     * payment type, the place, and what was paid in another currency, in that currency's own units ("20:15 ·
     * Revolut · card •••• 4821 · Card Payment · Lyon, FR · paid €23.00").
     */
    public static function howPaid(BankTransaction $tx): string
    {
        $foreign = $tx->original_currency && strtoupper($tx->original_currency) !== 'GBP' && $tx->original_amount_minor !== null;
        $merchant = $tx->merchant_currency && $tx->merchant_amount_minor !== null && strtoupper($tx->merchant_currency) !== 'GBP';

        return implode(' · ', array_filter([
            $tx->transacted_time ? substr((string) $tx->transacted_time, 0, 5) : null,
            $tx->account?->name,
            $tx->card_last4 ? 'card •••• '.$tx->card_last4 : null,
            $tx->payment_type,
            $tx->place,
            // The currency the shop charged ($90.00 from the euro account), else the line's own other currency.
            $merchant ? 'paid '.Money::formatMinor(abs((int) $tx->merchant_amount_minor), $tx->merchant_currency, false)
                .($foreign ? ' ('.Money::formatMinor(abs((int) $tx->original_amount_minor), $tx->original_currency, false).')' : '')
                : ($foreign ? 'paid '.Money::formatMinor(abs((int) $tx->original_amount_minor), $tx->original_currency, false) : null),
        ]));
    }

    /**
     * The first $perGroup items of each kind, above the fold and below it apart, with the counts and total of
     * everything.
     */
    private static function cut(array $list, int $perGroup): array
    {
        $first = function (array $items) use ($perGroup) {
            $seen = [];

            return array_values(array_filter($items, function ($i) use (&$seen, $perGroup) {
                $seen[$i['group']] = ($seen[$i['group']] ?? 0) + 1;

                return $seen[$i['group']] <= $perGroup;
            }));
        };
        $below = array_values(array_filter($list['items'], fn ($i) => $i['below_fold'] ?? false));

        return ['items' => $first(array_values(array_filter($list['items'], fn ($i) => ! ($i['below_fold'] ?? false)))), 'below' => $first($below),
            'counts' => $list['counts'], 'below_counts' => $list['below_counts'] ?? [], 'total' => $list['total'],
            'checked' => $list['checked'] ?? ['hmrc' => null, 'jobs' => null, 'years' => null, 'closeout' => null]];
    }

    private static function keep(array $list, string $stamp): void
    {
        Cache::put(self::CACHE_KEY, $list + ['stamp' => $stamp], self::KEEP_SECONDS);
        Cache::put(self::TOTAL_KEY, $list['total'], self::KEEP_SECONDS);
        Cache::put(self::BADGE_KEY, ['total' => $list['total'], 'day' => today()->format('Y-m-d')], self::BADGE_SECONDS);
    }

    /**
     * A fingerprint of everything the list is made from: today's date (items fall due, snoozes end), what's hidden
     * now, this code, and for each table how many rows it has, its newest row and its latest change. One query.
     *
     * @param  array<string, true>  $hidden
     */
    private static function stamp(array $hidden): string
    {
        // This code and the detectors and ranking it uses: a new release builds the list afresh.
        $classes = [self::class, Ranker::class, Grades::class, DetectorLevels::class, ReceiptMatch::class, FindingDraft::class, ...self::OWN_PLACES,
            ...array_map(fn (Detector $d) => $d::class, app(Registry::class)->all())];
        $code = array_map(fn (string $class) => filemtime((new \ReflectionClass($class))->getFileName()), array_values(array_unique($classes)));

        return md5(json_encode([today()->format('Y-m-d'), array_keys($hidden), $code, self::tableStamps(self::WATCHED, self::WATCHED_SETTINGS)]));
    }

    /**
     * For each table, how many rows it has, its newest row and its latest change, and the same for these settings.
     * One query.
     *
     * @param  list<string>  $tables
     * @param  list<string>  $settings
     */
    private static function tableStamps(array $tables, array $settings): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $parts = array_map(function (string $t) use ($grammar) {
            [$id, $changed] = self::STAMP_COLUMNS[$t] ?? ['id', 'updated_at'];

            return 'SELECT '.DB::getPdo()->quote($t).' AS t, COUNT(*) AS n, MAX('.$grammar->wrap($id).') AS i, MAX('.$grammar->wrap($changed).') AS u FROM '.$grammar->wrapTable($t);
        }, $tables);
        $parts[] = 'SELECT '.DB::getPdo()->quote('settings').', COUNT(*), NULL, MAX('.$grammar->wrap('updated_at').') FROM '.$grammar->wrapTable('settings')
            .' WHERE '.$grammar->wrap('key').' IN ('.implode(', ', array_fill(0, count($settings), '?')).')';

        return DB::select(implode(' UNION ALL ', $parts), $settings);
    }

    /** The table a query adds, changes or removes rows the list is made from in (null: none it's made from). */
    private static function writesTo(string $sql, array $bindings): ?string
    {
        if (! preg_match('/^\s*(?:insert(?:\s+or\s+\w+|\s+ignore)?\s+into|replace\s+into|update(?:\s+ignore)?|delete\s+from)\s+[`"\[]?(\w+)/i', $sql, $m)) {
            return null;
        }
        $prefix = DB::getTablePrefix();
        $table = $prefix !== '' && str_starts_with($m[1], $prefix) ? substr($m[1], strlen($prefix)) : $m[1];
        if ($table === 'settings') {
            return array_intersect(array_filter($bindings, 'is_string'), self::WATCHED_SETTINGS) !== [] ? $table : null;
        }

        return in_array($table, self::WATCHED, true) ? $table : null;
    }

    /**
     * Labels and links for what failed checks were about, loaded together; a check whose subject has gone (or, for
     * a calendar link, no longer links the entry to a job) has none, so it isn't listed.
     *
     * @param  Collection<int, Verification>  $checks
     * @return array<string, array<int, array{0:string, 1:string}>> by subject type and id
     */
    private static function subjects(Collection $checks): array
    {
        $out = [];
        $load = function (string $type, callable $query, callable $label) use ($checks, &$out) {
            $ids = $checks->where('subject_type', $type)->pluck('subject_id')->unique()->values();
            if ($ids->isNotEmpty()) {
                foreach ($query($ids) as $row) {
                    $out[$type][$row->id] = $label($row);
                }
            }
        };
        $load('document', fn ($ids) => Document::query()->whereIn('id', $ids)->get(['id', 'email_subject', 'original_name']),
            fn (Document $d) => ['Check: '.($d->email_subject ?: $d->original_name), '/documents/'.$d->id]);
        $load('travel_item', fn ($ids) => TravelItem::query()->whereIn('id', $ids)->get(['id', 'provider', 'starts_on']),
            fn (TravelItem $t) => ['Check: '.$t->provider.' '.$t->starts_on->format('j M'), '/travel?year='.UkTaxYear::fromDate($t->starts_on)->label()]);
        $load('transaction', fn ($ids) => BankTransaction::query()->whereIn('id', $ids)->get(['id', 'counterparty', 'description']),
            fn (BankTransaction $t) => ['Check the suggestion: '.($t->counterparty ?: $t->description), '/inbox']);
        $load('job_candidate', fn ($ids) => JobCandidate::query()->whereIn('id', $ids)->where('status', 'open')->get(['id']),
            fn () => ['Check a possible job', '/jobs/finder']);
        // Unlinking an entry, or removing its link, ends the check: only links that still put an entry on a job count.
        $load('calendar_link', fn ($ids) => CalendarJobLink::query()->whereIn('id', $ids)->whereNotNull('work_job_id')->where('not_job', false)->get(['id', 'summary']),
            fn (CalendarJobLink $l) => ['Check a calendar link'.($l->summary ? ': '.$l->summary : ''), '/jobs/finder']);

        return $out;
    }
}
