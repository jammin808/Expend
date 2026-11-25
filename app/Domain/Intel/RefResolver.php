<?php

namespace App\Domain\Intel;

use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\AssistantLog;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\Document;
use App\Models\Finding;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\JobDay;
use App\Models\ManualExpense;
use App\Models\MerchantRule;
use App\Models\MileageTrip;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\StatementBalance;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Support\Collection;

/**
 * Turns evidence refs into what the page shows for each: a label in plain words, a link, whether the record is
 * still there, whether it's still as it was when relied on (its pin), and the latest check of it. One query per
 * kind, however many refs (docs/intelligence-cycle.md section 2.1).
 *
 * A pin that no longer matches is 'source_changed' ("changed since this was relied on"); a record that's gone is
 * 'source_missing'. Neither is an error. Only reads.
 */
final class RefResolver
{
    /** Check results in the shared vocabulary (section 2.2), from what Verifier records. */
    public const CHECKS = ['verified' => 'passed', 'second_read_agreed' => 'passed', 'partly' => 'partial', 'mismatch' => 'failed', 'second_read_disagreed' => 'failed',
        'source_missing' => 'failed', 'unverifiable' => 'not_checkable'];

    /** The subject type Verifier keeps checks under, for the kinds that have them. */
    private const CHECKED = ['doc' => 'document', 'travel' => 'travel_item', 'tx' => 'transaction', 'cand' => 'job_candidate'];

    /** What an actor reads as in a label. */
    private const ACTORS = ['you' => 'you', 'rule' => 'your rule', 'matcher' => 'matching', 'ai' => 'AI', 'policy' => 'an automatic decision', 'checker' => 'the double-check', 'system' => 'Expend', 'server' => 'the server', 'rules' => 'matching'];

    /**
     * @param  iterable<?string>  $refs
     * @return array<string, array{ref:string, kind:?string, label:string, href:?string, exists:bool, pin_ok:?bool, state:string, check:?string}> by ref
     */
    public static function resolveMany(iterable $refs): array
    {
        $parsed = [];
        $out = [];
        foreach ($refs as $ref) {
            if ($ref === null || isset($out[$ref]) || isset($parsed[$ref])) {
                continue;
            }
            $p = Ref::parse($ref);
            if ($p === null) {
                $out[$ref] = self::row($ref, null, $ref, null, false);

                continue;
            }
            $parsed[$ref] = $p;
        }
        $byKind = collect($parsed)->groupBy('kind', true);
        foreach ($byKind as $kind => $group) {
            /** @var Collection<string, array> $group */
            $ids = $group->pluck('id')->unique()->values()->all();
            $found = self::load($kind, $ids);
            $checks = isset(self::CHECKED[$kind]) ? Verification::query()->where('subject_type', self::CHECKED[$kind])->whereIn('subject_id', array_filter(array_map('intval', $ids)))->pluck('status', 'subject_id') : collect();
            foreach ($group as $ref => $p) {
                $hit = $found[$p['id']] ?? null;
                $check = isset($checks[(int) $p['id']]) ? (self::CHECKS[$checks[(int) $p['id']]] ?? null) : (isset(self::CHECKED[$kind]) && $hit ? 'unchecked' : null);
                if ($hit === null) {
                    $out[$ref] = self::row($ref, $kind, self::missingLabel($kind, $p['id']), null, false);

                    continue;
                }
                $pinOk = $p['pin'] === null || $hit['pin'] === null ? null : str_starts_with(strtolower((string) $hit['pin']), $p['pin']);
                $out[$ref] = self::row($ref, $kind, $hit['label'].($p['locator'] ? ' · '.self::locator($p['locator']) : ''), $hit['href'], true, $pinOk, $check);
            }
        }

        return $out;
    }

    private static function row(string $ref, ?string $kind, string $label, ?string $href, bool $exists, ?bool $pinOk = null, ?string $check = null): array
    {
        return ['ref' => $ref, 'kind' => $kind, 'label' => $label, 'href' => $href, 'exists' => $exists, 'pin_ok' => $pinOk,
            'state' => ! $exists ? 'source_missing' : ($pinOk === false ? 'source_changed' : 'ok'), 'check' => $check];
    }

    /**
     * The records of one kind, by id: their label, link and the content hash a pin is a prefix of (null: no pin).
     *
     * @param  list<string>  $ids
     * @return array<string, array{label:string, href:?string, pin:?string}>
     */
    private static function load(string $kind, array $ids): array
    {
        $ints = array_values(array_filter(array_map(fn ($id) => ctype_digit((string) $id) ? (int) $id : null, $ids), fn ($id) => $id !== null));
        $by = fn (iterable $rows, callable $map, string $key = 'id') => collect($rows)->mapWithKeys(fn ($r) => [(string) $r->{$key} => $map($r)])->all();
        $date = fn ($d) => $d?->format('j M Y');

        return match ($kind) {
            'tx' => $by(BankTransaction::query()->with('account:id,name')->whereIn('id', $ints)->get(), fn (BankTransaction $t) => [
                'label' => trim(($t->counterparty ?: $t->description).' · '.$t->posted_on->format('j M Y').' · '.Money::format($t->amount_pence).($t->account ? ' · '.$t->account->name : '')),
                'href' => '/transactions?year='.$t->tax_year.'&q='.urlencode($t->counterparty ?: $t->description), 'pin' => null]),
            'quick' => $by(QuickEntry::query()->whereIn('id', $ints)->get(), fn (QuickEntry $e) => [
                'label' => trim('Your receipt from your phone · '.$e->words().' · '.$date($e->spent_on)), 'href' => '/quick/entries/'.$e->id, 'pin' => null]),
            'exp' => $by(ManualExpense::query()->whereIn('id', $ints)->get(), fn (ManualExpense $e) => [
                'label' => trim($e->description.' · '.$date($e->spent_on).' · '.Money::format(-abs((int) $e->amount_pence))), 'href' => '/transactions?year='.$e->tax_year,
                'pin' => sha1($e->spent_on?->format('Y-m-d').'|'.$e->amount_pence.'|'.$e->description)]),
            'batch' => $by(ImportBatch::query()->with('account:id,name')->whereIn('id', $ints)->get(), fn (ImportBatch $b) => [
                'label' => 'Statement '.$b->original_filename.($b->account ? ' · '.$b->account->name : ''), 'href' => '/imports', 'pin' => $b->file_sha256]),
            'acct' => $by(Account::query()->whereIn('id', $ints)->get(['id', 'name']), fn (Account $a) => ['label' => $a->name, 'href' => '/accounts', 'pin' => null]),
            'sbal' => $by(StatementBalance::query()->with('account:id,name')->whereIn('id', $ints)->get(), fn (StatementBalance $s) => [
                'label' => ($s->account?->name ?? 'Account').' balance on '.$date($s->as_of), 'href' => '/accounts', 'pin' => null]),
            'doc' => $by(Document::query()->whereIn('id', $ints)->get(['id', 'email_subject', 'original_name', 'sha256']), fn (Document $d) => [
                'label' => $d->email_subject ?: $d->original_name, 'href' => '/documents/'.$d->id, 'pin' => $d->sha256]),
            'gmail' => $by(GmailMessage::query()->whereIn('message_id', $ids)->get(['message_id', 'subject', 'email_from', 'sent_at']), fn (GmailMessage $g) => [
                'label' => 'Email'.($g->email_from ? ' from '.$g->email_from : '').($g->sent_at ? ' · '.$date($g->sent_at) : '').($g->subject ? ' · '.$g->subject : ''), 'href' => null, 'pin' => null], 'message_id'),
            'cal' => $by(CalendarEvent::query()->whereIn('event_key', $ids)->get(['event_key', 'summary', 'starts_at']), fn (CalendarEvent $c) => [
                'label' => 'Calendar: '.$c->summary.($c->starts_at ? ' · '.$date($c->starts_at) : ''), 'href' => '/jobs/calendar', 'pin' => null], 'event_key'),
            'travel' => $by(TravelItem::query()->whereIn('id', $ints)->get(['id', 'kind', 'provider', 'place', 'starts_on']), fn (TravelItem $t) => [
                'label' => (TravelItem::KINDS[$t->kind] ?? 'Booking').': '.($t->kind === 'hotel' ? ($t->place ?: $t->provider) : $t->provider).' · '.$date($t->starts_on),
                'href' => '/travel?year='.UkTaxYear::fromDate($t->starts_on)->label(), 'pin' => null]),
            'job' => $by(WorkJob::query()->whereIn('id', $ints)->get(['id', 'title', 'starts_on']), fn (WorkJob $j) => ['label' => $j->title.' · '.$date($j->starts_on), 'href' => '/jobs/'.$j->id, 'pin' => null]),
            'jday' => $by(JobDay::query()->with('job:id,title')->whereIn('id', $ints)->get(), fn (JobDay $d) => [
                'label' => ($d->job?->title ?? 'Job').' · '.$date($d->on_date), 'href' => $d->work_job_id ? '/jobs/'.$d->work_job_id : null, 'pin' => null]),
            'cand' => $by(JobCandidate::query()->whereIn('id', $ints)->get(['id', 'title', 'starts_on']), fn (JobCandidate $c) => ['label' => 'Possible job: '.$c->title, 'href' => '/jobs/finder', 'pin' => null]),
            'trip' => $by(MileageTrip::query()->whereIn('id', $ints)->get(['id', 'trip_on', 'to_place', 'miles', 'tax_year']), fn (MileageTrip $t) => [
                'label' => 'Trip to '.$t->to_place.' · '.$date($t->trip_on).' · '.$t->miles.' miles', 'href' => '/mileage?year='.$t->tax_year, 'pin' => null]),
            'rule' => $by(MerchantRule::query()->whereIn('id', $ints)->get(['id', 'merchant_key']), fn (MerchantRule $r) => ['label' => 'Your rule for '.$r->merchant_key, 'href' => '/settings/rules', 'pin' => null]),
            'inv' => $by(Invoice::query()->whereIn('id', $ints)->get(['id', 'number', 'status', 'total_minor', 'currency', 'pdf_sha256']), fn (Invoice $i) => [
                'label' => ($i->number ? 'Invoice '.$i->number : 'Draft invoice').' · '.Money::format((int) $i->total_minor, $i->currency ?: 'GBP'), 'href' => '/invoices/'.$i->id, 'pin' => $i->pdf_sha256]),
            'check' => self::checks($ids),
            'audit' => $by(AuditEvent::query()->whereIn('id', $ints)->get(['id', 'action', 'actor', 'occurred_at', 'hash']), fn (AuditEvent $e) => [
                'label' => self::auditLabel($e), 'href' => null, 'pin' => $e->hash]),
            'year' => self::years($ids),
            'set' => self::settings($ids),
            'fnd' => $by(Finding::query()->whereIn('id', $ints)->get(['id', 'title']), fn (Finding $f) => ['label' => $f->title, 'href' => '/todo/history', 'pin' => null]),
            'ask' => $by(AssistantLog::query()->whereIn('id', $ints)->get(['id', 'question', 'created_at']), fn (AssistantLog $l) => [
                'label' => 'You asked: '.mb_strimwidth((string) $l->question, 0, 80, '…'), 'href' => '/assistant', 'pin' => null]),
            default => [],
        };
    }

    /**
     * Checks, cited by what they checked ("{subject_type}:{subject_id}"): one query for them all.
     *
     * @param  list<string>  $ids
     */
    private static function checks(array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            [$type, $subject] = explode(':', $id, 2) + [null, null];
            if ($type !== null && ctype_digit((string) $subject)) {
                $wanted[$type][] = (int) $subject;
            }
        }
        if ($wanted === []) {
            return [];
        }
        $found = Verification::query()->where(function ($q) use ($wanted) {
            foreach ($wanted as $type => $subjects) {
                $q->orWhere(fn ($q) => $q->where('subject_type', $type)->whereIn('subject_id', $subjects));
            }
        })->get(['subject_type', 'subject_id', 'status']);
        $out = [];
        foreach ($found as $v) {
            $out[$v->subject_type.':'.$v->subject_id] = ['label' => 'Double-check: '.(Verification::LABELS[$v->status] ?? $v->status), 'href' => null, 'pin' => null];
        }

        return $out;
    }

    /**
     * Settings, by key: one query for those that aren't defaults or results Expend keeps. A key kept encrypted is
     * never cited.
     *
     * @param  list<string>  $keys
     */
    private static function settings(array $keys): array
    {
        $keys = array_values(array_filter($keys, fn ($k) => ! in_array($k, Setting::ENCRYPTED, true)));
        $always = [TodoList::AUDIT_RESULT, 'gmail_last_result', 'starling_last_result', 'calendar_synced_at', 'mailbox_last_result'];
        $look = array_values(array_filter($keys, fn ($k) => ! array_key_exists($k, Setting::DEFAULTS) && ! in_array($k, $always, true)));
        $stored = $look === [] ? [] : array_flip(Setting::query()->whereIn('key', $look)->pluck('key')->all());

        return collect($keys)->filter(fn ($k) => array_key_exists($k, Setting::DEFAULTS) || in_array($k, $always, true) || isset($stored[$k]))
            ->mapWithKeys(fn ($k) => [$k => ['label' => self::settingLabel($k), 'href' => self::settingHref($k), 'pin' => null]])->all();
    }

    /** @param  list<string>  $labels */
    private static function years(array $labels): array
    {
        $tracked = TaxYear::query()->whereIn('label', $labels)->pluck('status', 'label');
        $out = [];
        foreach ($labels as $label) {
            if (! preg_match('/^\d{4}-\d{2}$/', $label)) {
                continue;
            }
            try {
                UkTaxYear::fromLabel($label);
            } catch (\Throwable) {
                continue;
            }
            $out[$label] = ['label' => 'Tax year '.$label.(isset($tracked[$label]) && $tracked[$label] !== 'open' ? ' ('.$tracked[$label].')' : ''), 'href' => '/years/'.$label, 'pin' => null];
        }

        return $out;
    }

    private static function auditLabel(AuditEvent $e): string
    {
        $what = str_replace(['.', '_'], ' ', $e->action);

        return ucfirst($what).' by '.(self::ACTORS[$e->actor] ?? $e->actor).' on '.$e->occurred_at?->format('j M Y');
    }

    private static function settingLabel(string $key): string
    {
        return match ($key) {
            'gmail_last_result' => 'Gmail connection',
            'starling_last_result' => 'Starling import',
            'calendar_synced_at' => 'Calendar refresh',
            'mailbox_last_result' => 'Forwarding mailbox',
            'home_postcode' => 'Your home postcode',
            'ai_monthly_cap_usd' => 'This month’s AI spending cap',
            TodoList::AUDIT_RESULT => 'Nightly audit check',
            default => 'Setting: '.str_replace('_', ' ', $key),
        };
    }

    private static function settingHref(string $key): ?string
    {
        return match ($key) {
            'gmail_last_result', 'starling_last_result', 'ai_monthly_cap_usd', 'mailbox_last_result' => '/settings/integrations',
            'calendar_synced_at', 'home_postcode' => '/settings/ledger',
            default => null,
        };
    }

    /** What a record of each kind is called, in plain words, for one that's no longer there. */
    private const KIND_NAMES = ['tx' => 'A bank line', 'exp' => 'A cash expense', 'batch' => 'An imported statement', 'acct' => 'An account', 'sbal' => 'A statement balance',
        'doc' => 'A document', 'gmail' => 'An email', 'cal' => 'A calendar entry', 'travel' => 'A booking', 'job' => 'A job', 'jday' => 'A day of a job', 'cand' => 'A possible job',
        'jfind' => 'A link the job finder made', 'trip' => 'A mileage trip', 'rule' => 'A rule', 'inv' => 'An invoice', 'check' => 'A double-check', 'audit' => 'A change in the history',
        'fx' => 'An exchange rate', 'law' => 'A tax rule', 'taxp' => 'A tax figure', 'year' => 'A tax year', 'run' => 'A nightly check', 'fnd' => 'A finding', 'ask' => 'A question you asked',
        'set' => 'A setting', 'quick' => 'An expense from your phone'];

    private static function missingLabel(string $kind, string $id): string
    {
        return (self::KIND_NAMES[$kind] ?? 'A record').' that’s no longer there';
    }

    private static function locator(string $locator): string
    {
        return match (true) {
            (bool) preg_match('/^p(\d+)$/', $locator, $m) => 'page '.$m[1],
            (bool) preg_match('/^l(\d+)$/', $locator, $m) => 'line '.$m[1],
            (bool) preg_match('/^row(\d+)$/', $locator, $m) => 'row '.$m[1],
            str_starts_with($locator, 'f=') => str_replace('_', ' ', substr($locator, 2)),
            default => $locator,
        };
    }
}
