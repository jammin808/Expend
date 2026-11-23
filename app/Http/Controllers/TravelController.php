<?php

namespace App\Http\Controllers;

use App\Domain\Documents\AiConfig;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailScanner;
use App\Domain\Matching\Suggester;
use App\Domain\Travel\TravelMatcher;
use App\Jobs\ReadTravelEmail;
use App\Jobs\ScanGmail;
use App\Models\BankTransaction;
use App\Models\GmailMessage;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Hotel stays and journeys found in Gmail, lined up with your jobs, for you to decide. */
class TravelController extends Controller
{
    public function index(Request $request): Response
    {
        $label = UkTaxYear::fromLabel($request->query('year') ?: UkTaxYear::fromDate(now())->label())->label();
        $year = UkTaxYear::fromLabel($label);
        $from = $year->startsOn()->format('Y-m-d');
        $to = $year->endsOn()->format('Y-m-d');

        $items = TravelItem::query()->with(['document', 'transaction.account', 'job'])
            ->whereBetween('starts_on', [$from, $to])->orderBy('starts_on')->get();

        $checks = Verification::forMany('travel_item', $items->pluck('id'));
        $messages = GmailMessage::query()->whereBetween('sent_at', [$year->startsOn()->subMonths(2), $year->endsOn()->addMonth()->endOfDay()]);

        return Inertia::render('travel/index', [
            'year' => $label,
            'gmail' => [
                'configured' => GmailClient::configured(), 'connected' => GmailClient::connected(), 'account' => Setting::get('gmail_account'),
                'progress' => ScanGmail::progress($label), 'searches' => array_map(fn ($s) => $s['label'], GmailScanner::SEARCHES),
                'waiting' => (clone $messages)->where('status', 'stored')->where('search', '!=', 'job_documents')->count(),
            ],
            'aiReady' => AiConfig::ready() && Setting::get('ai_extraction') !== 'off',
            'items' => $items->map(fn (TravelItem $i) => self::row($i) + ['check' => $checks[$i->id] ?? null])->values(),
            'jobs' => WorkJob::query()->whereBetween('starts_on', [$year->startsOn()->subMonth(), $year->endsOn()->addMonth()])
                ->orderBy('starts_on')->get(['id', 'title', 'starts_on'])->map(fn ($j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d')]),
            'log' => (clone $messages)->latest('sent_at')->limit(200)->get()->map(fn (GmailMessage $m) => [
                'id' => $m->id, 'search' => $m->search, 'from' => $m->email_from, 'subject' => $m->subject,
                'sent_at' => $m->sent_at?->format('Y-m-d'), 'status' => $m->status, 'error' => $m->error, 'document_id' => $m->document_id,
            ]),
            'homePostcode' => Setting::get('home_postcode'),
            'decisions' => TravelItem::DECISIONS,
            'kinds' => TravelItem::KINDS,
        ]);
    }

    /** Start the Gmail searches for a tax year. */
    public function scan(Request $request): RedirectResponse
    {
        $data = $request->validate(['tax_year' => 'required|string|max:7', 'searches' => 'array', 'searches.*' => Rule::in(array_keys(GmailScanner::SEARCHES))]);
        if (! GmailClient::connected()) {
            return back()->with('error', 'Connect Gmail first, in Settings › Integrations.');
        }
        $label = UkTaxYear::fromLabel($data['tax_year'])->label();
        foreach ($data['searches'] ?? ['hotels', 'travel'] as $search) {
            ScanGmail::save($label, $search, ['found' => 0, 'new' => 0, 'state' => 'queued', 'error' => null, 'updated_at' => now()->toIso8601String()]);
            ScanGmail::dispatch($search, $label);
        }

        return back()->with('success', "Searching Gmail for {$label}. Results appear here as each email is read, usually within a few minutes.");
    }

    /** Re-run the checks: stays and journeys you haven't decided, and travel payments with no email (TfL, taxis, parking). */
    public function check(Request $request, TravelMatcher $matcher, Suggester $suggester): RedirectResponse
    {
        $label = UkTaxYear::fromLabel((string) $request->input('tax_year'))->label();
        $year = UkTaxYear::fromLabel($label);
        // Shared hosting stops long requests, so this works within a time budget and says if there's more to do.
        $started = microtime(true);
        $items = TravelItem::query()->whereNull('decided_at')->whereBetween('starts_on', [$year->startsOn(), $year->endsOn()])->get();
        $checked = $failed = 0;
        foreach ($items as $item) {
            if (microtime(true) - $started > 60) {
                break;
            }
            try {
                $matcher->suggest($item);
                $checked++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }
        $made = 0;
        if (microtime(true) - $started < 60) {
            $payments = BankTransaction::query()->where('tax_year', $label)->where('review_status', 'unreviewed')->whereDoesntHave('allocations')->limit(2000)->get();
            $made = $suggester->suggestFor($payments);
        }
        $rest = $items->count() - $checked - $failed;

        return back()->with($failed ? 'error' : 'success', "Checked {$checked} stays and journeys against your jobs and made {$made} new payment suggestions for your Inbox."
            .($failed ? " {$failed} couldn’t be checked (see the log)." : '').($rest > 0 ? " {$rest} are left: press it again to carry on." : ''));
    }

    public function decide(Request $request, TravelItem $item, TravelMatcher $matcher): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(array_keys(TravelItem::DECISIONS))],
            'job_id' => 'nullable|integer|exists:work_jobs,id',
            'business_nights' => 'nullable|integer|min:1|max:60',
            'note' => 'nullable|string|max:500',
        ]);
        try {
            $outcome = $matcher->decide($item, $data['decision'], $data['job_id'] ?? null, $data['business_nights'] ?? null, $data['note'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', match ($outcome) {
            'applied' => 'Saved, and applied to its payment.',
            'kept' => 'Saved. You’d already decided its payment yourself, so that was left as you set it.',
            'unlinked' => 'Saved. The payment that had been matched to it is no longer linked.',
            default => 'Saved.',
        });
    }

    public function reopen(TravelItem $item, TravelMatcher $matcher): RedirectResponse
    {
        $item->update(['decision' => null, 'decided_at' => null, 'business_nights' => null]);
        try {
            $matcher->suggest($item->fresh());
        } catch (Throwable $e) {
            report($e);
        }

        return back()->with('success', 'Reopened. Its payment keeps your last decision until you decide again.');
    }

    public function reread(GmailMessage $message): RedirectResponse
    {
        $message->update(['status' => 'stored', 'error' => null]);
        ReadTravelEmail::dispatch($message->id);

        return back()->with('success', 'Reading it again.');
    }

    public static function row(TravelItem $i): array
    {
        return [
            'id' => $i->id, 'kind' => $i->kind, 'provider' => $i->provider, 'reference' => $i->reference, 'status' => $i->status,
            'starts_on' => $i->starts_on->format('Y-m-d'), 'ends_on' => $i->ends_on?->format('Y-m-d'), 'nights' => $i->kind === 'hotel' ? count($i->nights()) : null,
            'start_time' => $i->start_time, 'place' => $i->place, 'destination' => $i->destination, 'postcode' => $i->postcode,
            'amount_minor' => $i->amount_minor, 'currency' => $i->currency, 'payment' => $i->payment, 'booker' => $i->booker, 'booked_for' => $i->booked_for,
            'source' => $i->source, 'evidence' => $i->evidence ?? [], 'unverified_count' => $i->unverified_count,
            'document' => $i->document ? ['id' => $i->document->id, 'subject' => $i->document->email_subject ?: $i->document->original_name] : null,
            'transaction' => $i->transaction ? [
                'id' => $i->transaction->id, 'date' => $i->transaction->effectiveDate()->format('Y-m-d'), 'amount_pence' => $i->transaction->amount_pence,
                'description' => $i->transaction->description, 'account' => $i->transaction->account?->name, 'review_status' => $i->transaction->review_status,
            ] : null,
            'job' => $i->job ? ['id' => $i->job->id, 'title' => $i->job->title, 'starts_on' => $i->job->starts_on->format('Y-m-d')] : null,
            'suggestion' => $i->suggestion, 'decision' => $i->decision, 'business_nights' => $i->business_nights,
            'decided_at' => $i->decided_at?->format('Y-m-d H:i'), 'note' => $i->note,
        ];
    }
}
