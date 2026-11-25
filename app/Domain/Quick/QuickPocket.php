<?php

namespace App\Domain\Quick;

use App\Domain\Jobs\JobDays;
use App\Domain\Matching\Categoriser;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The day's data the phone keeps (the "pocket", in IndexedDB), so the quick add screen never waits for the network:
 * today, the locked and filed years, your accounts, categories and chips, your jobs with their days, HMRC's rates for
 * this month and last, your recent records (for the same-receipt check before Save) and your last entries. Only
 * reads. Sent with an ETag: the phone asks again only when its copy is old, and a 304 when nothing changed.
 */
final class QuickPocket
{
    /** Jobs from this many days back... */
    public const JOBS_BACK = 45;

    /** ...to this many ahead. */
    public const JOBS_AHEAD = 14;

    /** Records from this many days back, for the same-receipt check. */
    public const RECENT_DAYS = 3;

    /** The last entries listed under Recent. */
    public const LAST_ENTRIES = 20;

    /**
     * @return array{etag:string, data:array<string, mixed>}
     */
    public static function build(): array
    {
        $today = CarbonImmutable::now('Europe/London')->startOfDay();
        $data = [
            'version' => 1,
            'today' => $today->format('Y-m-d'),
            'locked_years' => TaxYear::query()->whereNotNull('locked_at')->orderBy('label')->pluck('label')->all(),
            'filed_years' => TaxYear::query()->whereNull('locked_at')->whereIn('status', ['filed', 'amending'])->orderBy('label')->pluck('label')->all(),
            'open_from' => self::openFrom($today),
            'accounts' => self::accounts(),
            'categories' => self::categories(),
            'chips' => self::chips($today),
            'recent_whats' => self::recentWhats(),
            'hints' => QuickHints::forPhone(),
            'jobs' => self::jobs($today),
            'rates' => self::rates($today),
            'recent' => self::recent($today),
            'entries' => QuickEntry::query()->with(['category', 'bankTransaction', 'duplicateOf'])->whereNotIn('status', ['receiving'])
                ->whereNot(fn ($q) => $q->where('status', 'withdrawn')->whereNull('amount_minor')->whereNull('document_id')) // a tombstone
                ->orderByDesc('id')->limit(self::LAST_ENTRIES)->get()->map(fn (QuickEntry $e) => QuickEntries::summary($e) + ['spent_on' => $e->spent_on?->format('Y-m-d'),
                    'paid_with' => $e->paid_with, 'channel' => $e->channel, 'amount_minor' => $e->amount_minor, 'currency' => $e->currency])->values()->all(),
            'simplified_mileage' => ($n = Vehicle::query()->count()) > 0 && Vehicle::query()->where('method', 'simplified')->count() === $n,
            'job_costs' => Categoriser::JOB_COSTS,
            'overnight_until' => JobDays::OVERNIGHT_UNTIL,
            'max_upload_bytes' => QuickWorker::maxUploadBytes(),
            'telegram_bot' => Setting::get('telegram_bot_username'),
        ];

        return ['etag' => '"'.substr(hash('sha256', (string) json_encode($data)), 0, 32).'"', 'data' => $data + ['now' => now()->toIso8601String()]];
    }

    /** Only the ETag (for /quick/token): the same as build()'s. */
    public static function etag(): string
    {
        return self::build()['etag'];
    }

    /** The first day a date may be picked: the start of the earliest tax year that isn't locked. */
    private static function openFrom(CarbonImmutable $today): string
    {
        $open = TaxYear::query()->whereNull('locked_at')->orderBy('starts_on')->value('starts_on');
        $current = UkTaxYear::fromDate($today);
        $from = $open ? CarbonImmutable::parse(substr((string) $open, 0, 10)) : $current->previous()->startsOn();

        return min($from, $current->startsOn())->format('Y-m-d');
    }

    /** @return list<array<string, mixed>> active accounts that aren't cash, with the date of their latest line */
    private static function accounts(): array
    {
        $latest = BankTransaction::query()->select('account_id', DB::raw('max(posted_on) as latest'))->groupBy('account_id')->pluck('latest', 'account_id');

        return Account::query()->where('kind', '!=', 'cash')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind'])
            ->map(fn (Account $a) => ['id' => $a->id, 'name' => $a->name, 'kind' => $a->kind, 'latest_line_on' => isset($latest[$a->id]) ? substr((string) $latest[$a->id], 0, 10) : null])
            ->values()->all();
    }

    /** @return list<array<string, mixed>> business expense categories, each saying whether it can go on a job */
    private static function categories(): array
    {
        $parents = Category::query()->whereNotNull('parent_id')->pluck('parent_id')->unique()->flip();

        return Category::query()->where('kind', 'expense')->orderBy('sort')->get(['id', 'code', 'name', 'parent_id', 'is_allowable', 'is_capital'])
            ->map(fn (Category $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'parent_id' => $c->parent_id, 'has_children' => isset($parents[$c->id]),
                'job_cost' => in_array($c->code, Categoriser::JOB_COSTS, true), 'allowable' => (bool) $c->is_allowable, 'capital' => (bool) $c->is_capital])->values()->all();
    }

    /** @return list<array{label:string, category_id:int, code:string}> six chips: your most used categories, then the usual ones */
    public static function chips(CarbonImmutable $today): array
    {
        $since = $today->subDays(180)->format('Y-m-d');
        $counts = [];
        foreach (QuickEntry::query()->whereNotNull('category_id')->whereNotIn('status', QuickEntry::NOT_LIVE)->whereDate('spent_on', '>=', $since)
            ->select('category_id', DB::raw('count(*) as n'))->groupBy('category_id')->pluck('n', 'category_id') as $id => $n) {
            $counts[(int) $id] = ($counts[(int) $id] ?? 0) + (int) $n;
        }
        foreach (DB::table('allocations')->join('manual_expenses', 'manual_expenses.id', '=', 'allocations.manual_expense_id')->whereNull('allocations.voided_at')
            ->where('allocations.kind', 'business_expense')->whereDate('manual_expenses.spent_on', '>=', $since)
            ->select('allocations.category_id', DB::raw('count(*) as n'))->groupBy('allocations.category_id')->pluck('n', 'category_id') as $id => $n) {
            $counts[(int) $id] = ($counts[(int) $id] ?? 0) + (int) $n;
        }
        arsort($counts);
        $byCode = Category::query()->where('kind', 'expense')->get(['id', 'code', 'name'])->keyBy('id');
        $labels = array_column(QuickHints::DEFAULT_CHIPS, 0, 1);
        $out = [];
        foreach (array_keys($counts) as $id) {
            if (($c = $byCode->get($id)) && count($out) < 6) {
                $out[$c->code] = ['label' => $labels[$c->code] ?? self::short($c->name), 'category_id' => $c->id, 'code' => $c->code];
            }
        }
        $ids = $byCode->pluck('id', 'code');
        foreach (QuickHints::DEFAULT_CHIPS as [$label, $code]) {
            if (count($out) < 6 && ! isset($out[$code]) && isset($ids[$code])) {
                $out[$code] = ['label' => $label, 'category_id' => (int) $ids[$code], 'code' => $code];
            }
        }

        return array_values($out);
    }

    /** "Parking, tolls and road charges" → "Parking"; "Taxi and ride-hailing" → "Taxi". */
    private static function short(string $name): string
    {
        $first = preg_split('/,| and | \(/', $name)[0] ?? $name;

        return trim($first) !== '' ? trim($first) : $name;
    }

    /** @return list<array{what:string, category_id:?int, n:int}> the words of your last 50 entries, for suggestions offline */
    private static function recentWhats(): array
    {
        $out = [];
        foreach (QuickEntry::query()->whereNotNull('what')->whereIn('what_source', ['typed', 'photo', 'telegram'])->whereNotIn('status', QuickEntry::NOT_LIVE)
            ->orderByDesc('id')->limit(50)->get(['what', 'category_id']) as $e) {
            $key = mb_strtolower(trim((string) $e->what));
            $out[$key] ??= ['what' => (string) $e->what, 'category_id' => $e->category_id, 'n' => 0];
            $out[$key]['n']++;
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> jobs from JOBS_BACK days ago to JOBS_AHEAD ahead, not cancelled, with their days */
    private static function jobs(CarbonImmutable $today): array
    {
        $from = $today->subDays(self::JOBS_BACK)->format('Y-m-d');
        $to = $today->addDays(self::JOBS_AHEAD)->format('Y-m-d');

        return WorkJob::query()->with(['days', 'client'])->where('status', '!=', 'cancelled')->whereDate('starts_on', '<=', $to)->whereDate('ends_on', '>=', $from)
            ->orderBy('starts_on')->get()->map(fn (WorkJob $j) => [
                'id' => $j->id, 'title' => $j->title, 'location' => $j->location, 'client' => $j->client?->name, 'status' => $j->status,
                'starts_on' => $j->starts_on->format('Y-m-d'), 'ends_on' => $j->ends_on->format('Y-m-d'),
                'days' => $j->workDates(),
            ])->values()->all();
    }

    /** @return array<string, array<string, string>> HMRC's rates kept for this month and last: month => currency => units per £1 */
    private static function rates(CarbonImmutable $today): array
    {
        $out = [];
        foreach ([$today->startOfMonth(), $today->startOfMonth()->subMonth()] as $month) {
            $out[$month->format('Y-m')] = ExchangeRate::query()->whereDate('period_start', $month->format('Y-m-d'))->where('source', 'hmrc_monthly')
                ->pluck('units_per_gbp', 'currency')->map(fn ($r) => (string) Money::rate($r))->all();
        }

        return $out;
    }

    /**
     * Your records from the last few days, for the same-receipt check before Save: phone entries (and Telegram drafts)
     * and expenses you added by hand.
     *
     * @return list<array<string, mixed>>
     */
    private static function recent(CarbonImmutable $today): array
    {
        $since = $today->subDays(self::RECENT_DAYS)->format('Y-m-d');
        $out = [];
        foreach (QuickEntry::query()->whereNotIn('status', ['withdrawn', 'dismissed', 'duplicate', 'receiving'])->whereNotNull('amount_minor')->whereDate('spent_on', '>=', $since)
            ->orderByDesc('id')->limit(100)->get() as $e) {
            $out[] = ['ref' => 'quick:'.$e->id, 'client_key' => $e->client_key, 'amount_minor' => $e->amount_minor, 'currency' => $e->currency, 'what' => $e->what,
                'spent_on' => $e->spent_on?->format('Y-m-d'), 'at' => ($e->captured_at ?? $e->created_at)?->toIso8601String(), 'paid_with' => $e->paid_with, 'state' => $e->status,
                'channel' => $e->channel];
        }
        $own = QuickEntry::query()->whereNotNull('manual_expense_id')->pluck('manual_expense_id')->all();
        foreach (ManualExpense::query()->whereNotIn('id', $own ?: [0])->where('amount_pence', '<', 0)->whereDate('spent_on', '>=', $since)->orderByDesc('id')->limit(50)->get() as $x) {
            $out[] = ['ref' => 'exp:'.$x->id, 'client_key' => null, 'amount_minor' => abs((int) ($x->original_currency ? $x->original_amount_minor : $x->amount_pence)),
                'currency' => $x->original_currency ?: 'GBP', 'what' => $x->description, 'spent_on' => $x->spent_on?->format('Y-m-d'), 'at' => $x->created_at?->toIso8601String(),
                'paid_with' => $x->paid_with, 'state' => 'added', 'channel' => 'hand'];
        }

        return $out;
    }
}
