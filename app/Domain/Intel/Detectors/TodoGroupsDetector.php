<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\CoversCodes;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use Illuminate\Support\Facades\DB;

/**
 * The to-do list's own groups as findings (detect.todo, docs/intelligence-cycle.md section 4.4): it reads every item
 * TodoList finds (TodoList::scan(), which only reads) and says what each is about in the shared vocabulary, by its
 * key: its code, the record it's about, the job, account or year it belongs to, and which way the money goes.
 *
 * The three kinds that have their own detectors (payments to HMRC, mileage, filed returns) are left to them, and the
 * routine groups (payments, rules, transfers, stays: FindingsRegistry::ROUTINE) are proposals, not findings.
 *
 * The table (classify()) is also how the findings backfill reads the keys of findings kept before M3.
 */
final class TodoGroupsDetector implements CoversCodes, Detector
{
    public const CODE = 'todo';

    public const VERSION = 1;

    /**
     * Each key's start and the code it gives, in the order they're tried (section 4.4, plus the kinds added since:
     * health, work to answer, bookings, quarterly updates and M1's three).
     */
    public const TABLE = [
        ['health:audit', 'health.audit_chain'], ['health:starling', 'health.starling'], ['health:calendar', 'health.calendar'], ['health:mailbox', 'health.mailbox'],
        ['health:failed_jobs:', 'health.failed_jobs'], ['health:ai_cap', 'health.ai_cap'],
        // M4's detectors, each a detector of its own (ELSEWHERE): here so their keys read the same everywhere.
        ['closeout:', 'job.closeout'], ['double:', 'travel.double_claim'], ['unlinked:', 'income.unlinked'], ['receipt:', 'receipt.unmatched'],
        ['noreceipt:', 'cost.no_receipt'], ['xdupe:', 'dupe.cross_source'], ['rule_drift:', 'rule.drift'], ['saving:', 'tax.saving'],
        ['unchecked:', 'acct.unchecked'], ['unverifiable:', 'check.unverifiable'], ['locked_drift:', 'year.locked_drift'],
        // Expenses from your phone (QuickEntryChecks), a detector of its own.
        ['quick_waiting:', 'receipt.waiting'], ['quick_which:', 'receipt.which'], ['quick_copy:', 'receipt.second_copy'], ['quick_photo_waiting:', 'quick.photo_waiting'],
        ['quick_photo:', 'quick.photo_differs'], ['quick_line:', 'quick.line_differs'], ['quick_held:', 'quick.held'], ['quick_card:', 'quick.maybe_card'], ['quick_same:', 'quick.maybe_same'],
        ['import:', 'import.held'], ['invoice_overdue:', 'invoice.overdue'], ['underbilled:', 'invoice.underbilled'], ['recharge:', 'travel.recharge'],
        // Income design v3: paid more than it was for, and paid then voided with nothing to replace it.
        ['invoice_overpaid:', 'invoice.overpaid'], ['invoice_void_paid:', 'invoice.void_paid'],
        // Clients, jobs and invoices: "Is it done?", a follow-up or client draft, a cost charged on their form a month
        // ago, and a cost linked to a job after its invoice went.
        ['job_done:', 'job.done_due'], ['draft:', 'invoice.draft'], ['claimed:', 'cost.claimed'], ['charge:', 'cost.late'],
        ['check:', 'check.failed'], ['deadline:', 'tax.deadline'], ['penalty:', 'tax.penalty'], ['submission:', 'tax.submission'],
        ['hmrc:', 'tax.hmrc_unallocated'], ['drift:', 'year.filed_drift'], ['opp_reply:', 'job.offer_reply'], ['opp_job:', 'job.booking'],
        ['invoice:', 'job.uninvoiced'], ['unsent:', 'invoice.unsent'], ['mileage:', 'travel.unclaimed'], ['candidate:', 'job.candidate'],
        ['dupe:', 'tx.duplicate'], ['no_po:', 'job.no_po'], ['gap:', 'acct.gap'], ['stale:', 'acct.stale'], ['missing:', 'travel.payment_missing'], ['doc:', 'doc.held'],
    ];

    /** Gmail needing reconnecting has a key of its own, with nothing after it. */
    public const GMAIL = 'health.gmail';

    /** The keys whose findings come from their own detectors, not from this one. */
    public const OWN_DETECTORS = ['hmrc:' => HmrcUnallocated::CODE, 'mileage:' => TravelUnclaimed::CODE, 'drift:' => YearFiledDrift::CODE];

    /**
     * Codes in the table that other detectors make (the health checks and M4's): this one never reports them, though
     * classify() still reads their keys (for the backfill, and for a finding kept without its code).
     */
    public const ELSEWHERE = [...Health::CODES, 'job.closeout', 'travel.double_claim', 'income.unlinked', 'receipt.unmatched', 'cost.no_receipt', 'dupe.cross_source',
        'rule.drift', 'tax.saving', 'acct.unchecked', 'check.unverifiable', 'year.locked_drift', ...QuickEntryChecks::CODES];

    /** What a failed check's subject type is cited as. */
    private const CHECK_SUBJECTS = ['document' => 'doc', 'travel_item' => 'travel', 'transaction' => 'tx', 'job_candidate' => 'cand'];

    /** @var array<string, Coverage> by code: what the last detect() couldn't work out in full */
    private array $short = [];

    private bool $ran = false;

    public function code(): string
    {
        return self::CODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    /** @return list<string> */
    public function codes(): array
    {
        $own = [...array_values(self::OWN_DETECTORS), ...self::ELSEWHERE];

        return array_values(array_unique(array_merge([self::GMAIL], array_values(array_filter(array_column(self::TABLE, 1), fn ($c) => ! in_array($c, $own, true))))));
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->short = [];
        $this->ran = false;
        $scan = TodoList::scan(withDetectors: false);
        foreach ([[$scan['failed'], Coverage::Failed], [$scan['capped'], Coverage::Capped]] as [$prefixes, $coverage]) {
            foreach ($prefixes as $prefix) {
                foreach (self::codesFor($prefix) as $code) {
                    $this->short[$code] = ($this->short[$code] ?? Coverage::Complete)->and($coverage);
                }
            }
        }
        $items = array_values(array_filter($scan['items'], fn ($i) => ! in_array($i['group'], FindingsRegistry::ROUTINE, true) && self::classify($i['key']) !== null
            && ! isset(self::OWN_DETECTORS[self::prefixOf($i['key'])]) && ! in_array(self::classify($i['key'])['code'], self::ELSEWHERE, true)));
        $classified = array_map(fn ($i) => self::classify($i['key'], isset($i['amount']) ? (int) $i['amount'] : null), $items);
        $scopes = self::scopes($classified);
        $drafts = [];
        foreach ($items as $n => $i) {
            $drafts[] = self::draft($i, $classified[$n], $scopes[$n]);
        }
        $this->ran = true;

        return $drafts;
    }

    public function coverage(): Coverage
    {
        if (! $this->ran) {
            return Coverage::Failed;
        }

        return array_reduce($this->short, fn (Coverage $all, Coverage $c) => $all->and($c), Coverage::Complete);
    }

    public function coverageOf(string $code): Coverage
    {
        return $this->ran ? ($this->short[$code] ?? Coverage::Complete) : Coverage::Failed;
    }

    /** The finding a to-do item is, from the item TodoList built. */
    public static function draft(array $item, array $classified, ?string $scope): FindingDraft
    {
        [$grade] = Grades::forCode($classified['code']);

        return new FindingDraft(
            key: $item['key'], code: $classified['code'], group: $item['group'], priority: (int) $item['priority'], title: $item['title'], detail: $item['detail'], href: $item['href'],
            subjectRef: $classified['subject_ref'], scopeRef: $scope, evidence: $item['why'] ?? [], amountPence: isset($item['amount']) ? (int) $item['amount'] : null,
            currency: isset($item['amount']) ? ($item['currency'] ?? null) : null, direction: $classified['direction'], grade: $item['grade'] ?? $grade, dueOn: $item['due_on'] ?? null,
            actions: $item['actions'] ?? [], hiddenUnder: $item['hidden_under'] ?? [], dismissKey: $item['dismiss_key'] ?? null,
            meta: array_intersect_key($item, ['range' => true]), detectorVersion: self::VERSION,
        );
    }

    /** The kind a key starts with, up to its first colon ("hmrc:"), or the whole key when it has none ("gmail"). */
    public static function prefixOf(string $key): string
    {
        return str_contains($key, ':') ? substr($key, 0, strpos($key, ':') + 1) : $key;
    }

    /**
     * What a to-do key is about (section 4.4), or null for a key it doesn't know (a routine item, or one it can't
     * read): the code, the record it's about, which way the money goes, and where to find the job, account or year
     * it belongs to ('scope': a ref already, or the table and id to look it up in: scopes()). The direction of a
     * filed return's difference comes from its amount: less than you filed (you may owe) or more (you could claim).
     *
     * @return array{code:string, subject_ref:?string, direction:?string, scope:array{ref?:string, table?:string, id?:int, column?:string, kind?:string}|null}|null
     */
    public static function classify(string $key, ?int $amount = null): ?array
    {
        if ($key === 'gmail') {
            return ['code' => self::GMAIL, 'subject_ref' => 'set:gmail_last_result', 'direction' => 'none', 'scope' => null];
        }
        $code = null;
        foreach (self::TABLE as [$prefix, $c]) {
            if (str_starts_with($key, $prefix)) {
                $code = $c;
                break;
            }
        }
        if ($code === null) {
            return null;
        }
        $rest = substr($key, strpos($key, ':') + 1);
        $parts = explode(':', $rest);
        $int = fn (string $s) => ctype_digit($s) ? (int) $s : null;
        $year = fn (string $s) => preg_match('/^\d{4}-\d{2}$/', $s) ? $s : null;
        $id = $int($parts[0]);
        $lookup = fn (string $table, string $column, string $kind) => $id === null ? null : ['table' => $table, 'id' => $id, 'column' => $column, 'kind' => $kind];
        $out = fn (?string $subject, ?string $direction, ?array $scope) => ['code' => $code, 'subject_ref' => $subject, 'direction' => $direction, 'scope' => $scope];

        return match ($code) {
            'health.audit_chain' => $out('set:'.TodoList::AUDIT_RESULT, 'none', null),
            'health.starling' => $out('set:starling_last_result', 'none', null),
            'health.calendar' => $out('set:calendar_synced_at', 'none', null),
            'health.ai_cap' => $out('set:ai_monthly_cap_usd', 'none', null),
            'health.mailbox' => $out('set:'.Health::MAILBOX_RESULT, 'none', null),
            'job.closeout' => $out(Ref::maybe('job', $id), 'owed_to_you', $id !== null ? ['ref' => 'job:'.$id] : null),
            'travel.double_claim' => $parts[0] === 'trip' ? $out(Ref::maybe('trip', $int($parts[1] ?? '')), 'at_risk', null)
                : $out(Ref::maybe('year', $year($parts[1] ?? '')), 'at_risk', ($y = $year($parts[1] ?? '')) ? ['ref' => 'year:'.$y] : null),
            'income.unlinked' => $parts[0] === 'exp' ? $out(Ref::maybe('exp', $int($parts[1] ?? '')), 'none', null) : $out(Ref::maybe('tx', $id), 'none', $lookup('bank_transactions', 'tax_year', 'year')),
            'cost.no_receipt' => $parts[0] === 'exp' ? $out(Ref::maybe('exp', $int($parts[1] ?? '')), 'none', null) : $out(Ref::maybe('tx', $id), 'none', $lookup('bank_transactions', 'tax_year', 'year')),
            'receipt.unmatched' => $out(Ref::maybe('doc', $id), 'unrecorded', null),
            'dupe.cross_source' => $out(Ref::maybe('exp', $id), 'at_risk', null),
            'rule.drift' => $out(Ref::maybe('rule', $id), 'none', null),
            'tax.saving' => ($y = $year($parts[0])) ? $out('year:'.$y, 'claimable', ['ref' => 'year:'.$y]) : $out(null, 'claimable', null),
            'acct.unchecked' => $out(Ref::maybe('acct', $id), 'none', $id !== null ? ['ref' => 'acct:'.$id] : null),
            'check.unverifiable' => $out(self::checkSubject($rest), 'none', null),
            'year.locked_drift' => ($y = $year($parts[0])) ? $out('year:'.$y, 'none', ['ref' => 'year:'.$y]) : $out(null, 'none', null),
            'health.failed_jobs' => $out(null, 'none', null), // background work has no ref of its own
            'import.held' => $out(Ref::maybe('batch', $id), 'unrecorded', $lookup('import_batches', 'account_id', 'acct')),
            'invoice.overdue', 'invoice.underbilled', 'invoice.unsent', 'invoice.draft' => $out(Ref::maybe('inv', $id), 'owed_to_you', $lookup('invoices', 'work_job_id', 'job')),
            'invoice.overpaid', 'invoice.void_paid' => $out(Ref::maybe('inv', $id), 'none', $lookup('invoices', 'work_job_id', 'job')),
            'job.done_due' => $out(Ref::maybe('job', $id), 'owed_to_you', $id !== null ? ['ref' => 'job:'.$id] : null),
            // A cost's key is its button id (Charges\CostRef::toId): tx:381:j45, travel:7, trip:3.
            'cost.claimed', 'cost.late' => self::cost($code, $parts),
            'travel.recharge' => $out(Ref::maybe('travel', $id), 'owed_to_you', $lookup('travel_items', 'work_job_id', 'job')),
            'travel.payment_missing' => $out(Ref::maybe('travel', $id), 'unrecorded', $lookup('travel_items', 'work_job_id', 'job')),
            'check.failed' => $out(self::checkSubject($rest), 'none', null),
            'tax.deadline' => $out(Ref::maybe('year', $year($parts[0])), 'none', ($y = $year($parts[0])) ? ['ref' => 'year:'.$y] : null),
            'tax.penalty', 'tax.submission' => $out(Ref::maybe('year', $year($parts[0])), 'owed_by_you', ($y = $year($parts[0])) ? ['ref' => 'year:'.$y] : null),
            'tax.hmrc_unallocated' => $out(Ref::maybe('tx', $id), 'none', $lookup('bank_transactions', 'tax_year', 'year')),
            // Until the payments still to decide are done, a filed return's difference has no money either way: none.
            'year.filed_drift' => $out(Ref::maybe('year', $year($parts[0])), $amount === null ? 'none' : ($amount < 0 ? 'owed_by_you' : 'claimable'),
                ($y = $year($parts[0])) ? ['ref' => 'year:'.$y] : null),
            'travel.unclaimed' => ($y = $year($parts[0]))
                ? $out('year:'.$y, 'claimable', ['ref' => 'year:'.$y])
                : $out($parts[0] === 'setup' ? 'set:home_postcode' : null, 'none', null),
            'job.offer_reply', 'job.booking' => $out(null, 'none', null),
            'job.uninvoiced' => $out(Ref::maybe('job', $id), 'owed_to_you', $id !== null ? ['ref' => 'job:'.$id] : null),
            'job.no_po' => $out(Ref::maybe('job', $id), 'none', $id !== null ? ['ref' => 'job:'.$id] : null),
            'job.candidate' => $out(Ref::maybe('cand', $id), 'none', null),
            'tx.duplicate' => $out(Ref::maybe('tx', $id), 'at_risk', $lookup('bank_transactions', 'account_id', 'acct')),
            'acct.gap' => $out(Ref::maybe('acct', $id), in_array('twice', $parts, true) ? 'none' : 'unrecorded', $id !== null ? ['ref' => 'acct:'.$id] : null),
            'acct.stale' => $out(Ref::maybe('acct', $id), 'none', $id !== null ? ['ref' => 'acct:'.$id] : null),
            'doc.held' => $out(Ref::maybe('doc', $id), 'none', null),
            default => $out(null, null, null),
        };
    }

    /**
     * The job, account or year each classified key belongs to, looked up together (one query per table), in the
     * same order; null where it has none or it can't be found.
     *
     * @param  list<array|null>  $classified  classify()'s
     * @return list<?string>
     */
    public static function scopes(array $classified): array
    {
        $wanted = [];
        foreach ($classified as $c) {
            if (isset($c['scope']['table'])) {
                $wanted[$c['scope']['table'].'.'.$c['scope']['column']][] = $c['scope']['id'];
            }
        }
        $found = [];
        foreach ($wanted as $where => $ids) {
            [$table, $column] = explode('.', $where);
            foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
                foreach (DB::table($table)->whereIn('id', $chunk)->pluck($column, 'id') as $id => $value) {
                    $found[$where][$id] = $value;
                }
            }
        }

        return array_map(function ($c) use ($found) {
            if ($c === null || $c['scope'] === null) {
                return null;
            }
            if (isset($c['scope']['ref'])) {
                return Ref::valid($c['scope']['ref']) ? $c['scope']['ref'] : null;
            }
            $value = $found[$c['scope']['table'].'.'.$c['scope']['column']][$c['scope']['id']] ?? null;

            return $value === null || $value === '' ? null : Ref::maybe($c['scope']['kind'], (string) $value);
        }, $classified);
    }

    /** @return list<string> the codes whose keys start with this (a failed or capped kind, "penalty:") */
    public static function codesFor(string $prefix): array
    {
        if ($prefix === 'gmail') {
            return [self::GMAIL];
        }
        $out = [];
        foreach (self::TABLE as [$p, $code]) {
            if (str_starts_with($p, $prefix) || str_starts_with($prefix, $p)) {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * What a cost's key is about: its record (the payment, expense, booking or trip) and, for one on a job, that job.
     *
     * @param  string  $code  cost.claimed or cost.late
     * @param  list<string>  $parts  the key after its kind: tx, 381, j45
     * @return array{code:string, subject_ref:?string, direction:?string, scope:?array}
     */
    private static function cost(string $code, array $parts): array
    {
        $id = ctype_digit($parts[1] ?? '') ? (int) $parts[1] : null;
        $job = isset($parts[2]) && preg_match('/^j(\d+)$/', $parts[2], $m) === 1 ? (int) $m[1] : null;
        $subject = in_array($parts[0] ?? null, ['tx', 'exp', 'travel', 'trip'], true) ? Ref::maybe($parts[0], $id) : null;

        return ['code' => $code, 'subject_ref' => $subject, 'direction' => 'owed_to_you', 'scope' => $job !== null ? ['ref' => 'job:'.$job] : null];
    }

    /** The subject a failed check is about: check:document:12 is doc:12; a kind with no ref of its own stays a check ref. */
    private static function checkSubject(string $rest): ?string
    {
        [$type, $id] = explode(':', $rest, 2) + [null, null];
        if ($type === null || $id === null || ! ctype_digit($id)) {
            return null;
        }

        return isset(self::CHECK_SUBJECTS[$type]) ? Ref::make(self::CHECK_SUBJECTS[$type], $id) : Ref::maybe('check', $type.':'.$id);
    }
}
