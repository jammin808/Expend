<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\CalendarSync;
use App\Domain\Income\IncomeTargets;
use App\Domain\Income\IncomeText;
use App\Domain\Income\PayerAliases;
use App\Domain\Intel\Methods;
use App\Domain\Intel\RefResolver;
use App\Domain\Intel\Vetoes;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Verification;
use App\Models\Veto;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $year = $request->string('year')->toString() ?: null;
        $status = $request->string('status')->toString() ?: null;

        $query = BankTransaction::query()->with(['account', 'allocations.category', 'allocations.job', 'quickReceipt', ...Present::ALLOCATION_LINKS])
            ->whereIn('review_status', $status ? [$status] : ['unreviewed', 'suggested'])
            ->when($year, fn ($q) => $q->where('tax_year', $year))
            ->orderBy('tax_year')->orderBy('posted_on')->orderBy('id');

        $remaining = (clone $query)->count();
        $items = $query->limit(30)->get();

        $merchantCounts = BankTransaction::query()
            ->whereIn('merchant_key', $items->pluck('merchant_key')->unique())
            ->whereIn('review_status', ['unreviewed', 'suggested'])
            ->when($year, fn ($q) => $q->where('tax_year', $year))
            ->selectRaw('merchant_key, COUNT(*) as n')->groupBy('merchant_key')->pluck('n', 'merchant_key');

        $checks = Verification::forMany('transaction', $items->pluck('id'));
        // What each suggestion weighed (its runner-ups), and what you've said no to for each payment: a few queries for all.
        $alternatives = self::alternatives($items);
        $vetoes = Veto::query()->active()->whereIn('subject_ref', $items->map(fn (BankTransaction $tx) => 'tx:'.$tx->id)->all() ?: [''])->orderBy('id')->get()->groupBy('subject_ref');
        $events = AuditEvent::query()->whereIn('id', $vetoes->flatten(1)->pluck('audit_event_id')->filter()->all() ?: [0])->get()->keyBy('id');
        // Money in (income design v3): one-tap chips for what it may be for, read together for the page, and who the
        // payer usually pays for.
        $moneyIn = $items->filter(fn (BankTransaction $tx) => $tx->amount_pence > 0)->values();
        $targets = IncomeTargets::forMany($moneyIn);
        $aliases = PayerAliases::forKeys($moneyIn->pluck('merchant_key'));
        // Who each suggestion is from, for the whole page at once.
        $clients = IncomeText::clients($items->flatMap(fn (BankTransaction $tx) => $tx->allocations->all())->values());
        $soleNames = Client::query()->whereKey($moneyIn->map(fn (BankTransaction $tx) => $aliases->sole($tx->merchant_key, 1))->filter()->unique()->values()->all() ?: [0])->pluck('name', 'id');

        // Each day's calendar read once for the page.
        $eventsOn = $items->map(fn (BankTransaction $tx) => $tx->effectiveDate()->format('Y-m-d'))->unique()
            ->mapWithKeys(fn (string $day) => [$day => CalendarSync::eventsOn($day)])->all();

        return Inertia::render('inbox', [
            'items' => $items->map(fn (BankTransaction $tx) => Present::tx($tx) + [
                'check' => $checks[$tx->id] ?? null,
                'merchant_count' => (int) ($merchantCounts[$tx->merchant_key] ?? 1),
                'events' => $eventsOn[$tx->effectiveDate()->format('Y-m-d')] ?? [],
                // "Jobs around then" is for money out: money in has its chips.
                'jobs_near' => $tx->amount_pence > 0 ? [] : Present::jobsNear($tx->effectiveDate()->format('Y-m-d')),
                'targets' => $targets[$tx->id] ?? [],
                'payer' => $tx->amount_pence > 0 ? self::payer($tx, $aliases, $soleNames) : null,
                'suggestion_text' => self::suggestionText($tx, $clients),
                'suggestion_why' => self::why($tx, $alternatives),
                'said_no' => ($vetoes->get('tx:'.$tx->id) ?? collect())->map(fn (Veto $v) => ['id' => $v->id, 'what' => Vetoes::describe($v, $events[$v->audit_event_id] ?? null, 'tx:'.$tx->id)])->values()->all(),
            ])->values(),
            'remaining' => $remaining,
            'filters' => ['year' => $year, 'status' => $status],
            'lookups' => Present::lookups(),
            // "Not this one" asks why, with these (Intel\Vetoes::REASONS).
            'not_this_reasons' => Vetoes::REASONS,
        ]);
    }

    /** "ACME PAYROLL · usually Acme Events (3 payments)", "… · pays for several clients", or null when it's new. */
    private static function payer(BankTransaction $tx, PayerAliases $aliases, Collection $names): ?string
    {
        $key = $tx->merchant_key;
        if ($aliases->shared($key)) {
            return $key.' · pays for several clients';
        }
        $sole = $aliases->sole($key, 1);
        if ($sole === null) {
            return null;
        }
        $n = $aliases->count($key, $sole);

        return $key.' · usually '.($names[$sole] ?? 'one client').' ('.$n.' '.($n === 1 ? 'payment' : 'payments').')';
    }

    /** An open suggestion in words (IncomeText: "INV-041 £600 + INV-043 £750 · Acme Events"), or null. */
    /** @param  array<int, array{0:?int, 1:?string}>  $clients  each line's client and name (IncomeText::clients) */
    private static function suggestionText(BankTransaction $tx, array $clients = []): ?string
    {
        return $tx->allocations->isNotEmpty() && Vetoes::isOpenSuggestion($tx->allocations) ? IncomeText::suggestion($tx->allocations, $clients) : null;
    }

    /**
     * What an open suggestion rests on, for its card: the method in plain words, and the alternatives it weighed ("Also
     * possible: …"), each with how far behind in plain words. Null when there's no open suggestion.
     *
     * @param  array{refs:array<string, array<string, mixed>>, ways:array<string, string>}  $resolved  the alternatives,
     *                                                                                                 resolved (alternatives())
     * @return array{method:?string, alternatives:list<array{ref:string, label:string, href:?string, score:?int, note:?string}>}|null
     */
    private static function why(BankTransaction $tx, array $resolved): ?array
    {
        $first = $tx->allocations->first();
        if (! $first || $first->confirmed_at !== null || $first->is_manual) {
            return null;
        }
        $method = Methods::of($first)['method'];
        $alts = array_slice((array) ($first->alternatives ?? []), 0, 3);
        $learned = self::learned($method);
        // A guess learned from your decisions weighed the other ways you decided payments from there: "Personal (1 of
        // your last 4)", not the payment it took as the example. Another record (an invoice, a transfer's other side) is
        // named, with how far behind it was.
        $of = (int) ($first->score ?? 0) + array_sum(array_map(fn ($a) => (int) ($a['score'] ?? 0), (array) ($first->alternatives ?? [])));

        return [
            'method' => $method !== null && $first->decided_by !== 'rule' ? Methods::describe($method) : null,
            'alternatives' => array_values(array_filter(array_map(function (array $a) use ($resolved, $learned, $first, $of) {
                $score = isset($a['score']) ? (int) $a['score'] : null;
                if ($learned) {
                    $way = $resolved['ways'][$a['ref']] ?? null;

                    return $way === null ? null : ['ref' => $a['ref'], 'label' => $way, 'href' => null, 'score' => $score,
                        'note' => $score === null ? null : ($first->score !== null ? "{$score} of your last {$of}" : 'you decided it that way '.$score.' '.($score === 1 ? 'time' : 'times'))];
                }
                $behind = $score !== null && $first->score !== null ? (int) $first->score - $score : null;

                return ['ref' => $a['ref'], 'label' => (string) ($resolved['refs'][$a['ref']]['label'] ?? $a['ref']), 'href' => $resolved['refs'][$a['ref']]['href'] ?? null,
                    'score' => $score, 'note' => $behind !== null && $behind > 0 ? $behind.' '.($behind === 1 ? 'point' : 'points').' behind' : null];
            }, $alts))),
        ];
    }

    /** Whether a method is a guess learned from your decisions, whose runner-ups are example payments of the other ways. */
    private static function learned(?string $method): bool
    {
        return $method !== null && str_starts_with($method, 'categorise.');
    }

    /**
     * The alternatives the open suggestions weighed, resolved for all of them together: other records by name (refs),
     * and, for a learned guess, each other way you decided, in words, from its example payment's lines (ways; the job is
     * that payment's own, so it's left out).
     *
     * @param  Collection<int, BankTransaction>  $items
     * @return array{refs:array<string, array<string, mixed>>, ways:array<string, string>}
     */
    private static function alternatives(Collection $items): array
    {
        $refs = [];
        $examples = [];
        foreach ($items as $tx) {
            $first = $tx->allocations->first();
            foreach (array_column(array_slice((array) ($first?->alternatives ?? []), 0, 3), 'ref') as $ref) {
                if (self::learned(Methods::of($first)['method']) && preg_match('/^tx:(\d+)$/', (string) $ref, $m)) {
                    $examples[$ref] = (int) $m[1];
                } else {
                    $refs[] = $ref;
                }
            }
        }
        $ways = [];
        if ($examples !== []) {
            $lines = Allocation::query()->whereIn('bank_transaction_id', array_values(array_unique($examples)))
                ->get(['bank_transaction_id', 'kind', 'category_id', 'business_use_pct', 'work_job_id'])->groupBy('bank_transaction_id');
            $categories = Category::query()->pluck('name', 'id');
            foreach ($examples as $ref => $id) {
                if (isset($lines[$id])) {
                    $parts = array_map(fn (array $p) => [$p[0], $p[1], $p[2], null], Vetoes::shapeParts($lines[$id]));
                    $ways[$ref] = Vetoes::shapeWords($parts, $categories, collect());
                }
            }
        }

        return ['refs' => RefResolver::resolveMany(array_values(array_unique($refs))), 'ways' => $ways];
    }
}
