<?php

namespace App\Domain\Intel\Detectors;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\MerchantRule;

/**
 * Rules you keep changing (rule.drift, M4): a rule whose decisions you changed or undid at least OVERRIDES times in
 * the last DAYS days (on different payments), as the audit trail records it: your decision on a payment replacing
 * lines the rule had made, with a different kind, category or business share (what a rule sets), or your undoing
 * them. Adding a job, an invoice or a note to what the rule decided (putting a payment on a job's costs, settling a
 * stay on the Travel page) isn't changing it. Only your own decisions count: deciding a payment was always recorded
 * as you, and an undo only since actors were recorded honestly (M2, HONEST_FROM): before that, automatic clears were
 * recorded as you too. Only reads.
 */
final class RuleDrift implements Detector
{
    public const CODE = 'rule.drift';

    public const VERSION = 2;

    public const OVERRIDES = 2;

    public const DAYS = 90;

    /** When automatic clears stopped being recorded as yours (M2). */
    public const HONEST_FROM = '2026-11-24 00:00:00';

    private ?int $checked = null;

    private Coverage $coverage = Coverage::Failed;

    public function code(): string
    {
        return self::CODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function coverage(): Coverage
    {
        return $this->coverage;
    }

    public function checked(): ?int
    {
        return $this->checked;
    }

    /** @return list<FindingDraft> */
    public function detect(CycleContext $ctx): array
    {
        $this->coverage = Coverage::Failed;
        $this->checked = null;
        $since = now()->subDays(self::DAYS)->utc()->format('Y-m-d H:i:s');
        $events = AuditEvent::query()->where('actor', 'you')->where('subject_type', 'BankTransaction')->where('occurred_at', '>=', $since)
            ->where(fn ($q) => $q->where('action', 'allocation.decided')
                ->orWhere(fn ($q) => $q->where('action', 'allocation.cleared')->where('occurred_at', '>=', max($since, self::HONEST_FROM))))
            ->where(fn ($q) => $q->where('before', 'like', '%"decided_by":"rule"%')->orWhere('before', 'like', '%"decided_by": "rule"%'))
            ->orderBy('id')->get(['id', 'action', 'subject_id', 'before', 'after', 'occurred_at', 'hash']);
        $by = [];
        foreach ($events as $e) {
            $before = collect(is_array($e->before) ? $e->before : [])->filter(fn ($l) => is_array($l) && ($l['decided_by'] ?? null) === 'rule' && isset($l['merchant_rule_id']));
            $after = collect(is_array($e->after) ? $e->after : []);
            foreach ($before->groupBy('merchant_rule_id') as $rule => $lines) {
                if ($e->action === 'allocation.decided' && self::same($lines->all(), $after->all())) {
                    continue; // you decided what the rule had: not a change
                }
                $by[(int) $rule][$e->subject_id] = $e;
            }
        }
        $rules = MerchantRule::query()->whereIn('id', array_keys($by) ?: [0])->with('category:id,name')->get()->keyBy('id');
        $drafts = [];
        foreach ($by as $ruleId => $changes) {
            $rule = $rules[$ruleId] ?? null;
            if (! $rule || count($changes) < self::OVERRIDES) {
                continue; // a rule since removed has nothing left to change
            }
            $n = count($changes);
            $latest = collect($changes)->sortByDesc('id')->values();
            $drafts[] = new FindingDraft(
                key: 'rule_drift:'.$ruleId, code: self::CODE, group: 'records', priority: 3,
                title: 'You’ve changed what your rule for '.$rule->merchant_key.' decided '.$n.' times in '.self::DAYS.' days',
                detail: 'The rule '.($rule->auto_confirm ? 'confirms' : 'suggests').' '.mb_strtolower(Allocation::KINDS[$rule->kind] ?? $rule->kind)
                    .($rule->category ? ' ('.$rule->category->name.')' : '').' for every payment from there, and you’ve changed it on '.$n.' of them, most recently on '
                    .$latest->first()->occurred_at->format('j M Y').'. If '.$rule->merchant_key.' varies, change the rule or remove it in Settings › Rules, so it stops deciding for you.',
                href: '/settings/rules', subjectRef: Ref::make('rule', $ruleId),
                evidence: [...$latest->take(9)->map(fn (AuditEvent $e) => Ref::make('audit', $e->id, null, $e->hash ?: null))->all(),
                    ...$latest->take(9)->map(fn (AuditEvent $e) => Ref::make('tx', $e->subject_id))->all()],
                direction: 'none', grade: Grades::forCode(self::CODE)[0], meta: ['overrides' => $n], detectorVersion: self::VERSION,
            );
        }
        $this->checked = count($by);
        $this->coverage = Coverage::Complete;

        return $drafts;
    }

    /**
     * Whether your lines say what the rule's did, in what a rule sets: the same kind, category and business share for
     * each. A job, invoice or note added to them is yours to add, not a change to the rule's decision.
     *
     * @param  list<array>  $rule
     * @param  list<array>  $yours
     */
    private static function same(array $rule, array $yours): bool
    {
        $shape = fn (array $lines) => collect($lines)->filter(fn ($l) => is_array($l))
            ->map(fn ($l) => implode('|', [$l['kind'] ?? '', $l['category_id'] ?? '', $l['business_use_pct'] ?? '']))->sort()->values()->all();

        return $shape($rule) === $shape($yours);
    }
}
