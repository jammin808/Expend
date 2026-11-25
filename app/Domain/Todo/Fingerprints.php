<?php

namespace App\Domain\Todo;

use App\Domain\Intel\DetectorLevels;
use App\Domain\Intel\Detectors\TravelUnclaimed;
use App\Domain\Jobs\CandidateText;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Ledger\SourceState;
use App\Domain\Matching\TransferPairer;
use App\Domain\Quick\QuickEntries;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\MerchantRule;
use App\Models\Opportunity;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\Veto;
use App\Models\WorkJob;

/**
 * Actions bound to what was shown (docs/intelligence-cycle.md 4.5 and 7.6, M7): a to-do button carries a short
 * fingerprint of the state it was offered on (the payment's lines, the invoice's status and total, the finding's
 * amount…), and a press is refused when the state has changed since: "This changed since: open it in Expend". On the
 * web it's sent with the action; in Telegram it's the fourth part of a button's data (t|code|id|fp), within Telegram's
 * 64 bytes.
 *
 * Only actions that decide something are bound. A re-check (recheck) decides nothing, and "Not relevant…" by a
 * finding's id alone only asks why: old buttons without a fingerprint keep working for those.
 */
final class Fingerprints
{
    public const LENGTH = 6;

    /** Actions that decide nothing: no fingerprint, and an old button without one still works. */
    public const NEUTRAL = ['recheck'];

    /** Whether this action decides something (and so is bound to what was shown). */
    public static function decides(string $code, string $id): bool
    {
        return ! in_array($code, self::NEUTRAL, true) && ! ($code === 'fnd_dismiss' && ctype_digit($id));
    }

    /**
     * The fingerprint of what this action would act on now: 6 characters of lower-case hex. Null for an action that
     * decides nothing. Something that's gone has a fingerprint too, so a button offered on it never matches anything else.
     */
    public static function of(string $code, string $id): ?string
    {
        if (! self::decides($code, $id)) {
            return null;
        }

        return substr(hash('sha256', json_encode([$code, $id, self::state($code, $id)])), 0, self::LENGTH);
    }

    /**
     * Actions as a page shows them (TodoList::action()'s), each with the fingerprint of what it would act on now.
     *
     * @param  list<array{code:string, id:string}>  $actions
     * @return list<array<string, mixed>>
     */
    public static function attach(array $actions): array
    {
        return array_map(fn (array $a) => $a + ['fp' => self::of((string) $a['code'], (string) $a['id'])], $actions);
    }

    /**
     * Refuse a press whose fingerprint doesn't match what the action would act on now. No fingerprint (null) isn't
     * checked here: Telegram refuses deciding buttons without one itself (old buttons), and the web refuses one without
     * (TodoController::act). Something that has gone, or that has been dealt with since (settled()), isn't refused here
     * either: the action itself says it's already been dealt with, and changes nothing.
     *
     * @throws ChangedSince
     */
    public static function check(string $code, string $id, ?string $fp): void
    {
        if ($fp === null || ! self::decides($code, $id) || self::state($code, $id) === null) {
            return;
        }
        if (! hash_equals((string) self::of($code, $id), strtolower($fp)) && ! self::settled($code, $id)) {
            throw new ChangedSince;
        }
    }

    /**
     * Whether what the action is about has been dealt with since, so its own repeat check says "Already dealt with"
     * and changes nothing (TodoActions: every action is safe to repeat). A press after you did it yourself is then told
     * it's done, not that something changed. Only where that repeat check is certain to do nothing.
     */
    public static function settled(string $code, string $id): bool
    {
        $int = ctype_digit($id) ? (int) $id : 0;
        $lines = fn () => Allocation::query()->where('bank_transaction_id', $int)->get(['kind', 'is_manual', 'confirmed_at']);

        return match ($code) {
            'tx_accept' => ($l = $lines())->isNotEmpty() && $l->every(fn (Allocation $a) => $a->confirmed_at !== null),
            'tx_personal' => $lines()->contains(fn (Allocation $a) => $a->is_manual || $a->confirmed_at !== null),
            'tx_tax' => ($l = $lines())->isNotEmpty() && $l->every(fn (Allocation $a) => $a->kind === 'tax_payment' && $a->confirmed_at !== null),
            'rule_make' => ($key = BankTransaction::query()->whereKey($int)->value('merchant_key')) !== null && MerchantRule::query()->where('merchant_key', $key)->exists(),
            'cand_add', 'cand_dismiss' => JobCandidate::query()->whereKey($int)->where('status', '!=', 'open')->exists(),
            'cand_merge' => preg_match('/^(\d+):\d+$/', $id, $m) && JobCandidate::query()->whereKey((int) $m[1])->where('status', '!=', 'open')->exists(),
            'job_no_po' => (bool) WorkJob::query()->whereKey($int)->value('po_not_required'),
            'stay_accept', 'stay_personal' => TravelItem::query()->whereKey($int)->whereNotNull('decided_at')->exists(),
            'doc_approve' => Document::query()->whereKey($int)->where('status', '!=', 'held')->exists(),
            'opp_add', 'opp_decline', 'opp_dismiss' => ($o = Opportunity::query()->find($int)) !== null && ! $o->isOpen(),
            'fnd_snooze', 'fnd_dismiss' => preg_match('/^(\d+):/', $id, $m) && ($f = Finding::query()->find((int) $m[1])) !== null
                && in_array(Dismissals::hiddenNow($f->key), $code === 'fnd_snooze' ? ['dismissed', 'snoozed'] : ['dismissed'], true),
            'veto_lift' => Veto::query()->whereKey($int)->whereNotNull('lifted_at')->exists(),
            'qx' => ($p = QuickEntries::parseAction($id)) !== null && ($e = QuickEntry::query()->find($p[0])) !== null
                && ! in_array($e->status, QuickEntries::VERBS[$p[1]], true) && ! ($p[1] === 'attach' && $e->isWaiting()),
            default => false,
        };
    }

    /** What the action would act on, as it matters to the decision; null when it's gone (or the action is unknown). */
    private static function state(string $code, string $id): mixed
    {
        $int = ctype_digit($id) ? (int) $id : 0;

        return match ($code) {
            'tx_accept', 'tx_personal', 'tx_tax' => self::tx($int),
            'rule_make', 'rule_no' => self::ruleOffer($int),
            'cand_add', 'cand_dismiss' => JobCandidate::query()->find($int)?->only(self::CANDIDATE),
            'cand_merge' => self::merge($id),
            'job_invoice' => ($job = WorkJob::query()->find($int)) ? $job->only(['status', 'title', 'fee_pence', 'po_number', 'client_id'])
                + ['invoices' => Invoice::query()->where('work_job_id', $int)->orderBy('id')->get(['id', 'status'])->map(fn ($i) => [$i->id, $i->status])->all()] : null,
            'job_no_po' => WorkJob::query()->find($int)?->only(['po_number', 'po_not_required', 'status']),
            'stay_accept', 'stay_personal' => ($t = TravelItem::query()->find($int)) ? $t->only(['status', 'decision', 'work_job_id', 'bank_transaction_id', 'amount_minor', 'currency', 'starts_on', 'ends_on'])
                + ['decided' => $t->decided_at !== null, 'suggestion' => array_intersect_key((array) $t->suggestion, array_flip(['decision', 'job_id', 'business_nights', 'nights']))] : null,
            'doc_approve' => Document::query()->find($int)?->only(['status']),
            'inv_chase', 'inv_send' => ($i = Invoice::query()->find($int)) ? $i->only(['status', 'number', 'total_minor', 'currency', 'due_date', 'work_job_id'])
                + ['sent' => $i->sent_at?->getTimestamp(), 'reminded' => $i->reminded_at?->getTimestamp()] : null,
            'transfers_ok' => TransferPairer::confirmable($int > 0 ? $int : null)['ids'],
            // What the item shows and the job would be made from (its title, client, role, venue, days, rate, PO, reply-by
            // date, hold), never when it was last seen, how sure the reading is, or the words a follow-up email added to
            // its evidence; adding the job also on its being ready for one tap (every detail found word for word:
            // Opportunity::oneTapReady()).
            'opp_add', 'opp_decline', 'opp_dismiss' => ($o = Opportunity::query()->find($int)) ? $o->only(['status', 'title', 'client_id', 'client_name', 'contact_name',
                'role', 'venue', 'postcode', 'po_number', 'hold_rank', 'rate_minor', 'rate_currency', 'rate_unit', 'work_job_id', 'dates'])
                + ['from' => $o->starts_on?->format('Y-m-d'), 'to' => $o->ends_on?->format('Y-m-d'), 'reply_by' => $o->reply_by?->format('Y-m-d')]
                + ($code === 'opp_add' ? ['ready' => $o->oneTapReady()] : []) : null,
            // The finding the press hides, by its key (what it's about): never its title or amount, which a night's run
            // brings up to date (the nights a check has failed, interest added to a penalty), so an alert's only button
            // still works the next day. Back again after it had gone (reopened) is a new one.
            'fnd_snooze', 'fnd_dismiss' => preg_match('/^(\d+):/', $id, $m) && ($f = Finding::query()->find((int) $m[1])) ? $f->only(['key', 'code', 'reopened']) : null,
            'trips_add' => TravelUnclaimed::keyFor($id), // the id itself binds the jobs, the vehicle and the miles (TodoActions::tripsAdd)
            'det_raise', 'det_lower' => DetectorLevels::levelOf(str_replace(':', '.', $id)),
            'veto_lift' => ($v = Veto::query()->find($int)) ? ['key' => $v->veto_key, 'lifted' => $v->lifted_at !== null] : null,
            'qx' => self::quick($id),
            default => null,
        };
    }

    /**
     * An entry from your phone as a button acts on it: its amount, currency, words, date, job, category, how it was paid,
     * its state, its line and the photo's check; for attaching it to a line or "It was the card", that line's decision too.
     */
    private static function quick(string $id): ?array
    {
        $p = QuickEntries::parseAction($id);
        $e = $p ? QuickEntry::query()->find($p[0]) : null;
        if (! $e) {
            return null;
        }
        $state = $e->only(['amount_minor', 'currency', 'what', 'work_job_id', 'category_id', 'paid_with', 'status', 'bank_transaction_id', 'business_pct', 'document_id'])
            + ['on' => $e->spent_on?->format('Y-m-d'), 'read' => ($e->read_check['differs'] ?? []) ?: null]; // a read that agrees changes nothing shown
        if (in_array($p[1], ['attach', 'to_card'], true) && ctype_digit((string) $p[2])) {
            $state['line'] = self::tx((int) $p[2]);
        }

        return $state;
    }

    /** What a possible job shows, and what adding it as a job would make it from. */
    private const CANDIDATE = ['status', 'title', 'client_name', 'starts_on', 'ends_on', 'start_time', 'end_time', 'location', 'postcode', 'po_number'];

    /**
     * "Part of <job>" (id "<possible job>:<job>"): the possible job as shown, the job it would be added to (whose blank
     * details it would fill), and what you've said about its sources (CandidateText::refused), so a press made before
     * you said "Not this job" or "Not a job" is refused.
     */
    private static function merge(string $id): ?array
    {
        if (! preg_match('/^(\d+):(\d+)$/', $id, $m) || ! ($c = JobCandidate::query()->find((int) $m[1]))) {
            return null;
        }

        return [
            'candidate' => $c->only(self::CANDIDATE),
            'job' => WorkJob::query()->find((int) $m[2])?->only(['status', 'title', 'client_id', 'starts_on', 'ends_on', 'location', 'postcode', 'po_number', 'start_time', 'end_time']),
            'refused' => CandidateText::refused($c),
        ];
    }

    /**
     * A payment's decision as it stands: what each line is (with its own client when it has one), by whom, and whether
     * it's confirmed (SourceState, shared with "Confirm and issue" and "Shared with another job"; a line with no client
     * of its own keeps the hash it always had).
     */
    private static function tx(int $id): ?array
    {
        return SourceState::ofTransaction($id);
    }

    /** What "Always do this" for a payment's merchant would save: the rule there is now, or the one offered. */
    private static function ruleOffer(int $txId): ?array
    {
        $key = BankTransaction::query()->whereKey($txId)->value('merchant_key');
        if ($key === null) {
            return null;
        }
        $rule = MerchantRule::query()->where('merchant_key', $key)->first(['id', 'kind', 'category_id', 'business_pct', 'direction']);
        $offer = $rule ? null : app(RuleLearner::class)->offer($key);

        return [
            'rule' => $rule?->only(['id', 'kind', 'category_id', 'business_pct', 'direction']),
            'declined' => in_array($key, (array) Setting::get('rule_offers_declined'), true),
            'offer' => $offer ? array_intersect_key($offer, array_flip(['kind', 'category_id', 'business_pct', 'direction'])) : null,
        ];
    }
}
