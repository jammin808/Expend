<?php

namespace App\Http\Controllers;

use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\PoStatus;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\TransferPairer;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\MileageTrip;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The connections map: jobs in the middle, linked to their calendar entries, documents and POs, stays and journeys,
 * costs, income, invoices and mileage, laid out on a timeline. Links you made are solid, automatic ones dashed, and
 * possible ones (a cost on a job's day, a calendar entry that looks like work) dotted, with what needs attention flagged.
 */
class ConnectionsController extends Controller
{
    public function index(Request $request, JobFinder $finder): Response
    {
        $focus = $request->integer('job') ? WorkJob::query()->find($request->integer('job')) : null;
        $year = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('year')) ? (string) $request->query('year') : null;
        $showAll = $request->boolean('all');

        [$from, $to] = match (true) {
            $focus !== null => [CarbonImmutable::parse($focus->starts_on->format('Y-m-d'))->subDays(21), CarbonImmutable::parse($focus->ends_on->format('Y-m-d'))->addDays(45)],
            $year !== null => [CarbonImmutable::parse(UkTaxYear::fromLabel($year)->startsOn()->format('Y-m-d')), CarbonImmutable::parse(UkTaxYear::fromLabel($year)->endsOn()->format('Y-m-d'))],
            default => [CarbonImmutable::today()->subMonths(4)->startOfMonth(), CarbonImmutable::today()->addMonth()->endOfMonth()],
        };
        $f = $from->format('Y-m-d');
        $t = $to->format('Y-m-d');

        $nodes = [];
        $edges = [];
        $flag = function (string $node, string $code) use (&$nodes) {
            $nodes[$node]['flags'][] = $code;
        };
        $edge = function (string $a, string $b, string $kind, ?string $label = null) use (&$edges) {
            $edges[$a.'|'.$b] = ['from' => $a, 'to' => $b, 'kind' => $kind, 'label' => $label];
        };

        // Jobs, and whether each has a PO and an invoice.
        $jobs = WorkJob::query()->with(['client', 'days'])->whereDate('ends_on', '>=', $f)->whereDate('starts_on', '<=', $t)->orderBy('starts_on')->get();
        $po = PoStatus::forJobs($jobs->keyBy('id'));
        foreach ($jobs as $job) {
            $id = 'job:'.$job->id;
            $nodes[$id] = ['id' => $id, 'type' => 'job', 'label' => $job->title, 'sub' => trim(($job->client?->name ?? '').' · '.($job->location ?? ''), ' ·'),
                'date' => $job->starts_on->format('Y-m-d'), 'end' => $job->ends_on->format('Y-m-d'), 'href' => '/jobs/'.$job->id, 'flags' => [],
                'data' => ['status' => $job->status, 'po' => $po[$job->id]['po']['number'] ?? null, 'invoice' => $po[$job->id]['invoice']['number'] ?? null]];
            if ($job->status === 'cancelled') {
                $nodes[$id]['flags'][] = 'cancelled';

                continue;
            }
            if ($po[$job->id]['missing']) {
                $flag($id, 'no_po');
            }
            if (! $po[$job->id]['invoice'] && $job->ends_on->lt(now()->subDays(14))) {
                $flag($id, 'not_invoiced');
            }
        }
        $jobIds = $jobs->pluck('id')->all();
        $jobNode = fn (?int $jobId) => $jobId && isset($nodes['job:'.$jobId]) ? 'job:'.$jobId : null;
        // Which job is on (or the day either side of) each date, worked out once: its real days, so the days between a
        // rig and a de-rig aren't its. A job working the date itself wins over one it's next to.
        $onDay = [];
        $nextTo = [];
        foreach ($jobs->where('status', '!=', 'cancelled') as $j) {
            foreach ($j->workDates() as $d) {
                $onDay[$d] ??= $j;
                $day = CarbonImmutable::parse($d);
                $nextTo[$day->subDay()->format('Y-m-d')] ??= $j;
                $nextTo[$day->addDay()->format('Y-m-d')] ??= $j;
            }
        }
        $onDay += $nextTo;
        $jobOn = fn (string $date): ?WorkJob => $onDay[$date] ?? null;

        // Possible jobs from the job finder.
        foreach (JobCandidate::query()->where('status', 'open')->whereDate('ends_on', '>=', $f)->whereDate('starts_on', '<=', $t)->get() as $c) {
            $id = 'candidate:'.$c->id;
            $nodes[$id] = ['id' => $id, 'type' => 'candidate', 'label' => $c->title, 'sub' => 'Possible job: '.($c->client_name ?? 'from '.count($c->sources).' sources'),
                'date' => $c->starts_on->format('Y-m-d'), 'end' => $c->ends_on->format('Y-m-d'), 'href' => '/jobs/finder', 'flags' => ['possible_job'], 'data' => ['sources' => $c->sourceKeys()]];
        }

        // Calendar entries: linked ones, and any that look like work but aren't set up as a job.
        $links = CalendarJobLink::query()->get()->keyBy('event_key');
        $candidateKeys = JobCandidate::query()->where('status', 'open')->get()->flatMap(fn ($c) => collect($c->sourceKeys())->mapWithKeys(fn ($k) => [$k => $c->id]));
        foreach (CalendarEvent::query()->whereBetween('starts_at', [$from->subDay()->format('Y-m-d 00:00:00'), $to->addDay()->format('Y-m-d 23:59:59')])->orderBy('starts_at')->limit(1500)->get() as $e) {
            if ($e->day() < $f || $e->day() > $t) {
                continue;
            }
            $key = $e->key();
            $link = $links[$key] ?? null;
            $score = $finder->workScore($e);
            $linked = $link?->work_job_id && in_array($link->work_job_id, $jobIds, true);
            $notJob = (bool) $link?->not_job;
            if (! $linked && ! $showAll && ($notJob || $score < 1) && ! isset($candidateKeys['cal:'.$key])) {
                continue; // personal entries stay off the map unless you ask for everything
            }
            $id = 'cal:'.$key;
            $nodes[$id] = ['id' => $id, 'type' => 'calendar', 'label' => (string) $e->summary ?: '(no title)', 'sub' => trim(($e->all_day ? 'All day' : $e->localStart()->format('H:i')).' · '.$e->location, ' ·'),
                'date' => $e->day(), 'end' => JobDays::lastDay($e), 'flags' => [],
                'data' => ['key' => $key, 'location' => $e->location, 'time' => $e->all_day ? null : $e->localStart()->format('H:i'), 'end_time' => $e->all_day || ! $e->ends_at ? null : $e->localEnd()->format('H:i'),
                    'linked_by' => $link?->linked_by, 'not_job' => $notJob, 'work' => $score]];
            if ($linked) {
                $edge($id, 'job:'.$link->work_job_id, $link->linked_by === 'you' ? 'confirmed' : 'auto', $link->reason);
            } elseif ($c = $candidateKeys['cal:'.$key] ?? null) {
                $edge($id, 'candidate:'.$c, 'possible');
                $flag($id, 'calendar_unallocated');
            } elseif (! $notJob && $score >= 3) {
                $flag($id, 'calendar_unallocated');
                if (($j = $jobOn($e->day())) && ! in_array($j->id, $link?->rejected ?? [], true)) {
                    $edge($id, 'job:'.$j->id, 'possible', 'Same day as this job');
                }
            } elseif ($notJob) {
                $nodes[$id]['flags'][] = 'not_job';
            }
        }

        // Documents: POs, job invites, production packs, call sheets and other job paperwork.
        $docs = Document::query()->whereNull('parent_id')->with('children')
            ->where(fn ($q) => $q->whereBetween('received_at', [$f.' 00:00:00', $t.' 23:59:59'])->orWhereIn('work_job_id', $jobIds))
            ->where(fn ($q) => $q->whereNotNull('work_job_id')->orWhereIn('kind', ['po', 'job_invite', 'production_pack', 'call_sheet', 'booking', 'receipt', 'invoice'])
                ->orWhereHas('children', fn ($q) => $q->whereNotNull('work_job_id')->orWhereIn('kind', ['po', 'job_invite', 'production_pack', 'call_sheet'])))
            ->orderBy('received_at')->limit(400)->get();
        $docChecks = Verification::forMany('document', $docs->pluck('id'));
        foreach ($docs as $d) {
            $id = 'doc:'.$d->id;
            $jobId = $d->work_job_id ?? $d->children->firstWhere('work_job_id', '!=', null)?->work_job_id;
            $isPo = PoStatus::isPo($d) || $d->children->contains(fn ($c) => PoStatus::isPo($c));
            $nodes[$id] = ['id' => $id, 'type' => 'document', 'label' => $d->email_subject ?: $d->original_name, 'sub' => (Document::KINDS[$isPo ? 'po' : $d->kind] ?? $d->kind),
                'date' => ($d->received_at ?? $d->created_at)->format('Y-m-d'), 'href' => '/documents/'.$d->id, 'flags' => $isPo ? ['po'] : [],
                'data' => ['job_id' => $jobId, 'linked_by' => $d->job_linked_by, 'check' => $docChecks[$d->id] ?? null]];
            if (($docChecks[$d->id]['warn'] ?? false)) {
                $flag($id, 'check');
            }
            if ($jn = $jobNode($jobId)) {
                $edge($id, $jn, $d->job_linked_by === 'you' ? 'confirmed' : 'auto', $isPo ? 'PO' : null);
            } elseif ($c = $candidateKeys['doc:'.$d->id] ?? null) {
                $edge($id, 'candidate:'.$c, 'possible');
            } elseif (in_array($d->kind, ['po', 'job_invite', 'production_pack', 'call_sheet'], true) || $isPo) {
                $flag($id, 'doc_no_job');
            }
        }

        // Stays and journeys.
        $travel = TravelItem::query()->where('status', '!=', 'cancelled')->whereDate('starts_on', '>=', $f)->whereDate('starts_on', '<=', $t)->orderBy('starts_on')->get();
        $travelChecks = Verification::forMany('travel_item', $travel->pluck('id'));
        foreach ($travel as $item) {
            $id = 'travel:'.$item->id;
            $nodes[$id] = ['id' => $id, 'type' => 'travel', 'label' => (TravelItem::KINDS[$item->kind] ?? $item->kind).': '.($item->kind === 'hotel' ? ($item->place ?: $item->provider) : $item->provider),
                'sub' => $item->decision ? (TravelItem::DECISIONS[$item->decision] ?? $item->decision) : 'Not decided', 'date' => $item->starts_on->format('Y-m-d'),
                'end' => ($item->ends_on ?? $item->starts_on)->format('Y-m-d'), 'amount' => $item->amount_minor ? -$item->amount_minor : null, 'href' => '/travel?year='.UkTaxYear::fromDate($item->starts_on)->label(),
                'flags' => [], 'data' => ['job_id' => $item->work_job_id, 'linked_by' => $item->job_linked_by, 'check' => $travelChecks[$item->id] ?? null, 'kind' => $item->kind]];
            if ($travelChecks[$item->id]['warn'] ?? false) {
                $flag($id, 'check');
            }
            // Missing expense: the booking says it was paid, but no payment has turned up in your statements.
            if (! $item->bank_transaction_id && $item->amount_minor && in_array($item->payment, ['paid', 'pay_at_property'], true)
                && ! in_array($item->decision, ['client_booked', 'personal'], true) && ($item->ends_on ?? $item->starts_on)->lt(now()->subDays(10))) {
                $flag($id, 'payment_missing');
            }
            if ($jn = $jobNode($item->work_job_id)) {
                $edge($id, $jn, $item->job_linked_by === 'you' || $item->decided_at ? 'confirmed' : 'auto');
            } elseif ($c = $candidateKeys['travel:'.$item->id] ?? null) {
                $edge($id, 'candidate:'.$c, 'possible');
                $flag($id, 'stay_no_job');
            } elseif (! in_array($item->decision, ['personal'], true)) {
                $flag($id, 'stay_no_job');
            }
        }

        // Costs and income: what's linked to a job, and business or undecided costs on a job's days that aren't.
        $txs = BankTransaction::query()->with(['allocations.category', 'account'])
            ->where(fn ($q) => $q->whereBetween('posted_on', [$f, $t])->orWhereHas('allocations', fn ($q) => $q->whereIn('work_job_id', $jobIds)))
            ->orderBy('posted_on')->limit(3000)->get();
        OwnAccounts::flush();
        foreach ($txs as $tx) {
            $date = $tx->effectiveDate()->format('Y-m-d');
            $jobAllocs = $tx->allocations->whereNotNull('work_job_id');
            $business = $tx->allocations->firstWhere('kind', 'business_expense');
            // Money moving between your accounts is never a job's cost, and nor is a fee split off it (a currency
            // exchange's). A split of a transfer and a real cost (a hotel paid with a top-up) shows only its cost.
            $spending = $tx->allocations->where('kind', '!=', 'transfer');
            if (OwnAccounts::isTransfer($tx) && ($spending->isEmpty() || TransferPairer::movesMoney($tx->allocations))) {
                continue;
            }
            $undecided = $tx->allocations->isEmpty() && $tx->amount_pence < 0 && ! OwnAccounts::reason($tx);
            $near = $tx->amount_pence < 0 && $jobAllocs->isEmpty() && ($business || $undecided) ? $jobOn($date) : null;
            if ($jobAllocs->isEmpty() && ! $near) {
                continue;
            }
            $income = $tx->allocations->contains('kind', 'business_income');
            $id = 'tx:'.$tx->id;
            $main = $spending->first() ?? $tx->allocations->first();
            $cat = $main?->category?->name;
            $nodes[$id] = ['id' => $id, 'type' => $income ? 'income' : 'expense', 'label' => $tx->counterparty ?: $tx->description, 'sub' => $cat ?? ($undecided ? 'Not decided' : (Allocation::KINDS[$main?->kind] ?? '')),
                'date' => $date, 'amount' => $spending->count() < $tx->allocations->count() ? (int) $spending->sum('amount_pence') : $tx->amount_pence, 'flags' => [],
                'data' => ['tx' => $tx->id, 'source' => 'bank', 'account' => $tx->account?->name, 'review_status' => $tx->review_status, 'category' => $cat,
                    'allowable' => $main?->category?->is_allowable]];
            foreach ($jobAllocs as $a) {
                if ($jn = $jobNode($a->work_job_id)) {
                    $edge($id, $jn, $a->confirmed_at ? 'confirmed' : 'auto');
                }
            }
            if ($near) {
                $flag($id, $undecided ? 'undecided_on_job_day' : 'cost_no_job');
                $edge($id, 'job:'.$near->id, 'possible', 'On this job’s dates: is it for it?');
            }
        }
        foreach (Allocation::query()->with(['manualExpense', 'category'])->whereNotNull('manual_expense_id')->whereIn('work_job_id', $jobIds)->get() as $a) {
            $id = 'manual:'.$a->manual_expense_id;
            $nodes[$id] ??= ['id' => $id, 'type' => 'expense', 'label' => $a->manualExpense->description, 'sub' => $a->category?->name ?? 'Manual expense',
                'date' => $a->manualExpense->spent_on->format('Y-m-d'), 'amount' => $a->manualExpense->amount_pence, 'flags' => [],
                'data' => ['tx' => $a->manual_expense_id, 'source' => 'manual', 'category' => $a->category?->name, 'allowable' => $a->category?->is_allowable]];
            $edge($id, 'job:'.$a->work_job_id, $a->confirmed_at ? 'confirmed' : 'auto');
        }

        $paidJobs = Allocation::query()->whereIn('work_job_id', $jobIds)->where('kind', 'business_income')->distinct()->pluck('work_job_id')->flip();
        foreach (Invoice::query()->whereIn('work_job_id', $jobIds)->where('status', '!=', 'void')->get() as $inv) {
            $id = 'invoice:'.$inv->id;
            $nodes[$id] = ['id' => $id, 'type' => 'invoice', 'label' => $inv->number ?? 'Draft invoice', 'sub' => ucfirst($inv->status), 'date' => ($inv->issue_date ?? $inv->created_at)->format('Y-m-d'),
                'amount' => $inv->total_minor, 'href' => '/invoices/'.$inv->id, 'flags' => [], 'data' => []];
            $edge($id, 'job:'.$inv->work_job_id, 'confirmed');
            // Missing income: issued, past its due date, and no payment from the client linked to the job.
            if ($inv->status === 'issued' && $inv->due_date && $inv->due_date->lt(today()) && ! isset($paidJobs[$inv->work_job_id])) {
                $flag($id, 'income_missing');
                $flag('job:'.$inv->work_job_id, 'income_missing');
            }
        }
        foreach (MileageTrip::query()->whereIn('work_job_id', $jobIds)->get() as $trip) {
            $id = 'mileage:'.$trip->id;
            $nodes[$id] = ['id' => $id, 'type' => 'expense', 'label' => 'Mileage: '.$trip->to_place, 'sub' => $trip->miles.' miles', 'date' => $trip->trip_on->format('Y-m-d'),
                'href' => '/mileage?year='.$trip->tax_year, 'flags' => [], 'data' => ['mileage' => true]];
            $edge($id, 'job:'.$trip->work_job_id, 'confirmed');
        }

        // Keep the map to what's in range (documents for in-range jobs can arrive earlier; they stay).
        $edges = array_values(array_filter($edges, fn ($e) => isset($nodes[$e['from']], $nodes[$e['to']])));
        $attention = [];
        foreach (self::ATTENTION as $code => $label) {
            $ids = array_keys(array_filter($nodes, fn ($n) => in_array($code, $n['flags'], true)));
            if ($ids) {
                $attention[] = ['code' => $code, 'label' => $label, 'count' => count($ids), 'ids' => $ids];
            }
        }

        return Inertia::render('connections', [
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'attention' => $attention,
            'range' => ['from' => $f, 'to' => $t, 'year' => $year, 'job' => $focus?->id, 'all' => $showAll],
            'jobs' => WorkJob::query()->where('status', '!=', 'cancelled')->whereDate('ends_on', '>=', $from->subMonths(2)->format('Y-m-d'))->whereDate('starts_on', '<=', $to->addMonths(2)->format('Y-m-d'))
                ->orderBy('starts_on')->get(['id', 'title', 'starts_on'])->map(fn ($j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d')]),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'lookups' => Present::lookups(),
        ]);
    }

    public const ATTENTION = [
        'no_po' => 'Jobs with no PO',
        'calendar_unallocated' => 'Calendar entries that look like jobs but aren’t set up',
        'not_invoiced' => 'Jobs not invoiced',
        'income_missing' => 'Invoices overdue with no payment received',
        'payment_missing' => 'Paid bookings with no payment in your statements',
        'cost_no_job' => 'Business costs on a job’s days, not linked to it',
        'undecided_on_job_day' => 'Undecided payments on a job’s days',
        'stay_no_job' => 'Stays and journeys with no job',
        'doc_no_job' => 'Job documents with no job',
        'check' => 'AI findings that didn’t pass the double-check',
        'possible_job' => 'Possible jobs to review',
    ];
}
