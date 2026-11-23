<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\CalendarSync;
use App\Http\Presenters\Present;
use App\Models\BankTransaction;
use App\Models\Verification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $year = $request->string('year')->toString() ?: null;
        $status = $request->string('status')->toString() ?: null;

        $query = BankTransaction::query()->with(['account', 'allocations.category', 'allocations.job'])
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

        return Inertia::render('inbox', [
            'items' => $items->map(fn (BankTransaction $tx) => Present::tx($tx) + [
                'check' => $checks[$tx->id] ?? null,
                'merchant_count' => (int) ($merchantCounts[$tx->merchant_key] ?? 1),
                'events' => CalendarSync::eventsOn($tx->effectiveDate()->format('Y-m-d')),
                'jobs_near' => Present::jobsNear($tx->effectiveDate()->format('Y-m-d')),
            ])->values(),
            'remaining' => $remaining,
            'filters' => ['year' => $year, 'status' => $status],
            'lookups' => Present::lookups(),
        ]);
    }
}
