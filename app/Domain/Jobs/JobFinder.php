<?php

namespace App\Domain\Jobs;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\DocumentReader;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\Grounding;
use App\Domain\Travel\TravelMatcher;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Finds your jobs in what the app already holds: calendar entries, documents read from email, Gmail, Telegram or
 * uploads (POs, job invites, production packs, call sheets), and stays and journeys.
 *
 *  1. Each one is matched to an existing job when the evidence is clear: the same PO number, or the job's dates
 *     plus its client, venue, postcode or title words. A close call between two jobs is left for you.
 *  2. What's left is grouped by date and shared words into possible jobs for you to add, add to an existing
 *     job, or dismiss. Dismissed sources aren't proposed again.
 *  3. Calendar entries the rules can't call are asked about with AI (if switched on). An AI answer only counts
 *     when the words it quotes are really in the calendar entry and any client it names is one of yours.
 *
 * Links you make are never changed by automation. Everything automatic can be confirmed, changed or undone.
 */
final class JobFinder
{
    private const WORK_WORDS = [
        'show', 'shows', 'gig', 'event', 'events', 'conference', 'rehearsal', 'rehearsals', 'load in', 'load-in', 'loadin', 'load out',
        'load-out', 'get in', 'get-in', 'get out', 'get-out', 'derig', 'de-rig', 'rig', 'install', 'build', 'tech', 'production', 'crew',
        'call time', 'shoot', 'filming', 'recce', 'site visit', 'festival', 'awards', 'launch', 'exhibition', 'expo', 'summit',
        'programming', 'operator', 'lx', 'lighting', 'sound', 'audio', 'video', 'camera', 'vt', 'stage', 'venue', 'pencil', 'hold',
        'booked', 'job', 'po', 'gala', 'tour', 'arena', 'theatre', 'studio', 'broadcast', 'ob', 'travel day',
    ];

    private const PERSONAL_WORDS = [
        'birthday', 'bday', 'dentist', 'doctor', 'gp', 'hospital', 'gym', 'haircut', 'barber', 'vet', 'school', 'nursery',
        'pick up', 'drop off', 'dinner', 'lunch', 'brunch', 'drinks', 'date night', 'holiday', 'annual leave', 'day off',
        'anniversary', 'mot', 'bins', 'yoga', 'swim', 'parkrun', 'church', 'mum', 'dad', 'nan', 'grandma', 'family',
    ];

    /** Words that say nothing about which job it is. */
    private const STOP = [
        'the', 'and', 'for', 'with', 'from', 'this', 'that', 'your', 'our', 'day', 'days', 'job', 'jobs', 'event', 'show', 'po',
        'purchase', 'order', 'booking', 'confirmation', 'invite', 'call', 'sheet', 'pack', 'production', 'crew', 'london',
        'ltd', 'limited', 'plc', 'llp', 'group', 'events', 'hotel', 'stay', 'night', 'nights', 'travel', 'tech', 'build',
        'load', 'rehearsal', 'please', 'attached', 'details', 'info', 'information', 'schedule', 'subject', 're', 'fw', 'fwd',
    ];

    public const JOB_KINDS = ['po', 'job_invite', 'production_pack', 'call_sheet'];

    private array $clients = [];

    private bool $clientsLoaded = false;

    public function __construct(private AiClient $ai, private TravelMatcher $matcher) {}

    /**
     * @return array{linked:int, proposed:int, ai_checked:int, ai_rejected:int, considered:int}
     */
    public function run(?string $taxYear = null, bool $useAi = true): array
    {
        [$from, $to] = $this->window($taxYear);
        $this->clients = Client::query()->pluck('name', 'id')->filter()->all();
        $this->clientsLoaded = true;
        $stats = ['linked' => 0, 'proposed' => 0, 'ai_checked' => 0, 'ai_rejected' => 0, 'considered' => 0];

        $jobs = WorkJob::query()->with('client')->where('status', '!=', 'cancelled')
            ->whereDate('ends_on', '>=', $from->subDays(3))->whereDate('starts_on', '<=', $to->addDays(3))->get();
        $jobPos = $this->jobPoNumbers($jobs);

        $taken = JobCandidate::query()->where(fn ($q) => $q->where('status', '!=', 'open')->orWhereNotNull('edited_at'))
            ->get()->flatMap(fn (JobCandidate $c) => $c->sourceKeys())->flip();

        $signals = collect([...$this->calendarSignals($from, $to), ...$this->documentSignals($from, $to), ...$this->travelSignals($from, $to)])
            ->reject(fn ($s) => isset($taken[$s['key']]));

        if ($useAi && self::aiAllowed()) {
            $signals = $this->askAiAboutCalendar($signals, $jobs, $stats);
        }
        $stats['considered'] = $signals->count();

        // 1. Link to existing jobs where it's clear.
        $left = collect();
        foreach ($signals as $signal) {
            if ($signal['type'] === 'travel' || ($signal['type'] === 'calendar' && $signal['work'] < 1)) {
                $left->push($signal);

                continue;
            }
            $match = $this->bestJob($signal, $jobs, $jobPos);
            if ($match['job'] && ! $match['ambiguous']) {
                $this->linkSignal($signal, $match['job'], $signal['ai'] ?? false ? 'ai' : 'rules', implode('; ', $match['reasons']));
                $stats['linked']++;

                continue;
            }
            $left->push($signal + ['options' => $match['options']]);
        }

        // 2. Group the rest into possible jobs. Unedited open proposals are rebuilt each run.
        DB::transaction(function () use ($left, &$stats) {
            JobCandidate::query()->where('status', 'open')->whereNull('edited_at')->delete();
            foreach ($this->cluster($left) as $cluster) {
                if ($candidate = $this->candidateFrom($cluster)) {
                    JobCandidate::query()->create($candidate);
                    $stats['proposed']++;
                }
            }
        });

        Setting::put('job_finder_last_run', $stats + ['at' => now()->toIso8601String(), 'year' => $taxYear]);
        AuditLog::record('jobs.finder_run', 'JobFinder', null, $stats, null, 'rules');

        return $stats;
    }

    public static function aiAllowed(): bool
    {
        return AiConfig::ready() && (bool) Setting::get('ai_suggestions');
    }

    /** Add a possible job as a new job, with your edits, and link everything it was found from. */
    public function accept(JobCandidate $candidate, array $fields): WorkJob
    {
        return DB::transaction(function () use ($candidate, $fields) {
            $job = WorkJob::query()->create($fields + ['status' => 'booked']);
            AuditLog::record('job.created', $job, null, $job->toArray(), 'From the job finder');
            $this->linkSources($candidate, $job);
            $candidate->update(['status' => 'accepted', 'work_job_id' => $job->id, 'decided_at' => now()]);
            $this->followUp($job);

            return $job;
        });
    }

    /** Add a possible job's sources to a job you already have, filling only the job's blank details. */
    public function merge(JobCandidate $candidate, WorkJob $job): WorkJob
    {
        return DB::transaction(function () use ($candidate, $job) {
            $before = $job->toArray();
            $fill = array_filter([
                'po_number' => $job->po_number ? null : $candidate->po_number,
                'postcode' => $job->postcode ? null : $candidate->postcode,
                'location' => $job->location ? null : $candidate->location,
                'start_time' => $job->start_time ? null : $candidate->start_time,
                'end_time' => $job->end_time ? null : $candidate->end_time,
            ]);
            if ($fill) {
                $job->update($fill);
                AuditLog::record('job.updated', $job, $before, $job->fresh()->toArray(), 'Blank details filled from the job finder');
            }
            $this->linkSources($candidate, $job);
            $candidate->update(['status' => 'merged', 'work_job_id' => $job->id, 'decided_at' => now()]);
            $this->followUp($job);

            return $job;
        });
    }

    /** Not a job: none of its sources is proposed again, and its calendar entries are marked "not a job". */
    public function dismiss(JobCandidate $candidate, ?string $note = null): void
    {
        DB::transaction(function () use ($candidate, $note) {
            foreach ($candidate->sources ?? [] as $source) {
                if ($source['type'] === 'calendar' && ($event = self::event($source['id'], $source['date'] ?? null))) {
                    $this->linkCalendar($event, null, 'you', 'Not a job');
                }
            }
            $candidate->update(['status' => 'dismissed', 'decided_at' => now(), 'note' => $note ? mb_substr($note, 0, 250) : null]);
            AuditLog::record('jobs.candidate_dismissed', $candidate, null, ['title' => $candidate->title, 'sources' => $candidate->sourceKeys()], $note);
        });
    }

    public function linkSources(JobCandidate $candidate, WorkJob $job): void
    {
        foreach ($candidate->sources ?? [] as $source) {
            match ($source['type']) {
                'calendar' => ($event = self::event($source['id'], $source['date'] ?? null)) ? $this->linkCalendar($event, $job, 'you', 'Added from the job finder') : null,
                'document' => ($doc = Document::query()->find($source['id'])) ? $this->linkDocument($doc, $job, 'you') : null,
                'travel' => ($item = TravelItem::query()->find($source['id'])) ? $this->linkTravel($item, $job, 'you') : null,
                default => null,
            };
        }
    }

    public function linkCalendar(CalendarEvent $event, ?WorkJob $job, string $by, ?string $reason = null): CalendarJobLink
    {
        $link = CalendarJobLink::query()->firstOrNew(['event_key' => $event->key()]);
        if ($link->exists && $link->linked_by === 'you' && $by !== 'you') {
            return $link; // your link stays
        }
        $before = $link->exists ? $link->only(['work_job_id', 'linked_by']) : null;
        $link->fill([
            'event_uid' => $event->uid, 'event_starts_at' => $event->starts_at, 'summary' => mb_substr((string) $event->summary, 0, 250),
            'work_job_id' => $job?->id, 'linked_by' => $by, 'reason' => $reason ? mb_substr($reason, 0, 300) : null,
        ])->save();
        AuditLog::record('calendar.linked', $link, $before, $link->only(['work_job_id', 'linked_by', 'summary']), $reason, $by === 'you' ? 'you' : $by);

        return $link;
    }

    public function unlinkCalendar(CalendarEvent $event): void
    {
        $link = CalendarJobLink::query()->where('event_key', $event->key())->first();
        if ($link) {
            AuditLog::record('calendar.unlinked', $link, $link->only(['work_job_id', 'linked_by']), null);
            $link->delete();
        }
    }

    public function linkDocument(Document $document, ?WorkJob $job, string $by): void
    {
        if ($document->job_linked_by === 'you' && $by !== 'you') {
            return;
        }
        $before = ['work_job_id' => $document->work_job_id, 'job_linked_by' => $document->job_linked_by];
        DocumentReader::relink($document, $job?->id);
        $document->update(['job_linked_by' => $job ? $by : ($by === 'you' ? 'you' : null)]);
        $document->children()->update(['job_linked_by' => $job ? $by : null]);
        AuditLog::record('document.job_linked', $document, $before, ['work_job_id' => $job?->id, 'job_linked_by' => $by], null, $by);
    }

    public function linkTravel(TravelItem $item, ?WorkJob $job, string $by): void
    {
        if ($item->job_linked_by === 'you' && $by !== 'you') {
            return;
        }
        $before = $item->only(['work_job_id', 'job_linked_by']);
        $item->update(['work_job_id' => $job?->id, 'job_linked_by' => $job ? $by : ($by === 'you' ? 'you' : null)]);
        AuditLog::record('travel.job_linked', $item, $before, $item->only(['work_job_id', 'job_linked_by']), null, $by);
        if (! $item->decided_at) {
            $this->matcher->suggest($item->fresh());
        }
    }

    /**
     * After a job is added or gets new sources: line up undecided stays and journeys around its dates again (they
     * may now be business), and link an unlinked invoice that quotes the job's PO number.
     *
     * @return array{travel:int, invoices:int}
     */
    public function followUp(WorkJob $job): array
    {
        $job = $job->fresh();
        $travel = 0;
        TravelItem::query()->whereNull('decided_at')->where('status', '!=', 'cancelled')
            ->whereDate('starts_on', '<=', $job->ends_on->copy()->addDay())
            ->where(fn ($q) => $q->whereDate('ends_on', '>=', $job->starts_on->copy()->subDay())->orWhere(fn ($q) => $q->whereNull('ends_on')->whereDate('starts_on', '>=', $job->starts_on->copy()->subDay())))
            ->get()->each(function (TravelItem $item) use (&$travel) {
                try {
                    $this->matcher->suggest($item);
                    $travel++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $invoices = 0;
        if ($po = self::normalisePo($job->po_number)) {
            foreach (Invoice::query()->whereNull('work_job_id')->whereNotNull('po_number')->get() as $invoice) {
                if (self::normalisePo($invoice->po_number) === $po) {
                    $invoice->update(['work_job_id' => $job->id]);
                    AuditLog::record('invoice.job_linked', $invoice, null, ['work_job_id' => $job->id], 'Same PO number as the job', 'rules');
                    $invoices++;
                }
            }
        }

        return ['travel' => $travel, 'invoices' => $invoices];
    }

    // ---------------------------------------------------------------- signals

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function window(?string $taxYear): array
    {
        if ($taxYear) {
            $year = UkTaxYear::fromLabel($taxYear);

            return [CarbonImmutable::parse($year->startsOn()->format('Y-m-d')), CarbonImmutable::parse($year->endsOn()->format('Y-m-d'))];
        }
        $now = CarbonImmutable::today();

        return [$now->subMonths(30), $now->addYear()];
    }

    private function calendarSignals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $links = CalendarJobLink::query()->get()->keyBy('event_key');
        $out = [];
        foreach (CalendarEvent::query()->whereBetween('starts_at', [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 23:59:59')])->orderBy('starts_at')->get() as $event) {
            $link = $links[$event->key()] ?? null;
            if ($link && ($link->linked_by === 'you' || $link->work_job_id)) {
                continue; // decided by you, or already linked automatically
            }
            $text = $event->text();
            if (trim($text) === '') {
                continue;
            }
            $out[] = [
                'key' => 'cal:'.$event->key(), 'type' => 'calendar', 'id' => $event->key(), 'label' => (string) $event->summary,
                'from' => $event->starts_at->format('Y-m-d'), 'to' => $event->lastDay()->format('Y-m-d'),
                'title' => $event->summary, 'client' => $this->clientIn($text), 'location' => self::isLink((string) $event->location) ? null : $event->location,
                'postcode' => self::postcodeIn((string) $event->location), 'po' => null,
                'start_time' => $event->all_day ? null : $event->starts_at->format('H:i'),
                'end_time' => $event->all_day || ! $event->ends_at ? null : $event->ends_at->format('H:i'),
                'text' => $text, 'quote' => $text, 'verified' => true, 'note' => 'From your calendar',
                'work' => $this->workScore($event),
            ];
        }

        return $out;
    }

    private function documentSignals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $docs = Document::query()->where('status', 'extracted')->whereNull('work_job_id')
            ->where(fn ($q) => $q->whereNull('job_linked_by')->orWhere('job_linked_by', '!=', 'you'))
            ->with('facts')->orderBy('id')->get();
        foreach ($docs as $doc) {
            $x = $doc->extraction ?? [];
            $new = $x['new_job'] ?? null;
            $po = $x['po']['po_number'] ?? $doc->facts->firstWhere('field', 'po_number')?->value;
            if (! $new && ! $po && ! in_array($doc->kind, self::JOB_KINDS, true)) {
                continue;
            }
            $start = $new['starts_on'] ?? $doc->facts->firstWhere('field', 'start_date')?->on_date?->format('Y-m-d');
            $end = $new['ends_on'] ?? $doc->facts->firstWhere('field', 'end_date')?->on_date?->format('Y-m-d') ?? $start;
            $dateFact = $doc->facts->first(fn ($f) => in_array($f->field, ['start_date', 'end_date'], true));
            if ($start && (CarbonImmutable::parse($start)->gt($to->addMonth()) || CarbonImmutable::parse($end ?? $start)->lt($from->subMonth()))) {
                continue;
            }
            if (! $start && ! $po) {
                continue;
            }
            $verified = $new ? (bool) ($new['verified'] ?? false) : (bool) $dateFact?->verified;
            $out[] = [
                'key' => 'doc:'.$doc->id, 'type' => 'document', 'id' => $doc->id, 'label' => $doc->email_subject ?: $doc->original_name,
                'from' => $start, 'to' => $end && $end >= (string) $start ? $end : $start,
                'title' => $new['title'] ?? null, 'client' => $new['client'] ?? $this->clientIn((string) $doc->text),
                'location' => $new['venue'] ?? $doc->facts->firstWhere('field', 'venue')?->value,
                'postcode' => $new['postcode'] ?? $doc->facts->firstWhere('field', 'postcode')?->value,
                'po' => $po ? mb_substr((string) $po, 0, 100) : null,
                'start_time' => $doc->facts->firstWhere('field', 'call_time')?->at_time ?? $doc->facts->firstWhere('field', 'start_time')?->at_time,
                'end_time' => $doc->facts->firstWhere('field', 'finish_time')?->at_time,
                'text' => mb_substr(implode("\n", array_filter([$doc->email_subject, $doc->summary, $new['title'] ?? null, $new['client'] ?? null, $new['venue'] ?? null])), 0, 2000),
                'quote' => $new['quote'] ?? $dateFact?->quote, 'verified' => $verified,
                'note' => $verified ? 'Checked against the document' : 'Not checked against the document yet',
                'work' => 3,
            ];
        }

        return $out;
    }

    private function travelSignals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $items = TravelItem::query()->whereNull('work_job_id')->where('status', '!=', 'cancelled')
            ->whereIn('kind', ['hotel', 'flight', 'rail', 'car_hire'])
            ->where(fn ($q) => $q->whereNull('decided_at')->orWhereIn('decision', ['business', 'mixed', 'recharge', 'client_booked']))
            ->whereBetween('starts_on', [$from->format('Y-m-d'), $to->format('Y-m-d')])->get();
        foreach ($items as $item) {
            $place = $item->kind === 'hotel' ? $item->place : ($item->destination ?: $item->place);
            $out[] = [
                'key' => 'travel:'.$item->id, 'type' => 'travel', 'id' => $item->id,
                'label' => TravelItem::KINDS[$item->kind].': '.$item->provider.($place ? ', '.$place : ''),
                'from' => $item->starts_on->format('Y-m-d'), 'to' => ($item->ends_on ?? $item->starts_on)->format('Y-m-d'),
                'title' => null, 'client' => null, 'location' => $place, 'postcode' => $item->kind === 'hotel' ? $item->postcode : null, 'po' => null,
                'start_time' => null, 'end_time' => null, 'text' => (string) $place, 'quote' => null,
                'verified' => $item->unverified_count === 0, 'note' => $item->unverified_count ? 'Some details couldn’t be checked against the email' : 'Read from the booking email',
                'work' => 0,
            ];
        }

        return $out;
    }

    /** How much a calendar entry looks like paid work: 3 or more is work, 1–2 is unsure, 0 or less is not. */
    public function workScore(CalendarEvent $event): int
    {
        if (! $this->clientsLoaded) {
            $this->clients = Client::query()->pluck('name', 'id')->filter()->all();
            $this->clientsLoaded = true;
        }
        $text = ' '.mb_strtolower($event->text()).' ';
        $has = fn (string $w) => (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($w, '/').'(?![\p{L}\p{N}])/u', $text);
        $work = count(array_filter(self::WORK_WORDS, $has));
        $personal = count(array_filter(self::PERSONAL_WORDS, $has));
        $hours = $event->ends_at ? $event->starts_at->diffInMinutes($event->ends_at) / 60 : 0;

        $score = min($work, 2) * 2 + ($this->clientIn($event->text()) ? 3 : 0) - $personal * 4;
        if ($event->location && ! self::isLink($event->location)) {
            $score += 1;
        }
        if (self::isLink((string) $event->location) || preg_match('/\b(zoom|teams|meet\.google|webex)\b/i', $event->text())) {
            $score -= 2; // a video call is rarely a job day
        }
        if ($event->all_day || $hours >= 4) {
            $score += 1;
        }

        return $score;
    }

    // ---------------------------------------------------------------- matching

    /**
     * @return array{job:?WorkJob, ambiguous:bool, reasons:list<string>, options:list<int>}
     */
    private function bestJob(array $s, Collection $jobs, array $jobPos): array
    {
        $scored = [];
        $po = self::normalisePo($s['po']);
        $from = $s['from'] ? CarbonImmutable::parse($s['from']) : null;
        $to = $s['to'] ? CarbonImmutable::parse($s['to']) : $from;
        $sameDay = $from ? $jobs->filter(fn (WorkJob $j) => $j->starts_on->lte($to) && $j->ends_on->gte($from))->count() : 0;

        foreach ($jobs as $job) {
            $score = 0;
            $why = [];
            if ($po && in_array($po, $jobPos[$job->id] ?? [], true)) {
                $score += 10;
                $why[] = "same PO number ({$s['po']})";
            }
            if ($from) {
                $gap = $job->starts_on->gt($to) ? $job->starts_on->diffInDays($to) : ($job->ends_on->lt($from) ? $from->diffInDays($job->ends_on) : 0);
                $gap = (int) abs($gap);
                if ($gap === 0) {
                    $score += 3;
                    $why[] = 'on the job’s dates';
                    if ($s['type'] === 'calendar' && $s['work'] >= 3 && $sameDay === 1) {
                        $score += 2;
                        $why[] = 'looks like work and it’s the only job then';
                    }
                } elseif ($gap === 1) {
                    $score += 1;
                    $why[] = 'the day before or after the job';
                } elseif ($score < 10) {
                    continue; // too far apart, and no PO number in common
                }
            } elseif ($score < 10) {
                continue;
            }
            $jobText = mb_strtolower($job->title.' '.$job->location.' '.$job->client?->name);
            if ($job->client && ($this->mentions($s['text'], $job->client->name) || ($s['client'] && self::same($s['client'], $job->client->name)))) {
                $score += 3;
                $why[] = 'mentions '.$job->client->name;
            }
            $shared = array_values(array_intersect(self::words($s['text'].' '.$s['location']), self::words($jobText)));
            if ($shared) {
                $score += min(2 * count($shared), 4);
                $why[] = 'shares “'.implode('”, “', array_slice($shared, 0, 3)).'”';
            }
            if ($s['postcode'] && $job->postcode) {
                [$a, $b] = [strtoupper(str_replace(' ', '', $s['postcode'])), strtoupper(str_replace(' ', '', $job->postcode))];
                if ($a === $b) {
                    $score += 3;
                    $why[] = 'same postcode';
                } elseif (strtok($s['postcode'], ' ') === strtok($job->postcode, ' ')) {
                    $score += 1;
                }
            }
            if ($score > 0) {
                $scored[] = ['job' => $job, 'score' => $score, 'why' => $why];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = $scored[0] ?? null;
        $second = $scored[1]['score'] ?? 0;
        $options = array_map(fn ($r) => $r['job']->id, array_slice($scored, 0, 4));

        if (! $best || $best['score'] < 5) {
            return ['job' => null, 'ambiguous' => false, 'reasons' => [], 'options' => $options];
        }

        return ['job' => $best['job'], 'ambiguous' => $best['score'] - $second < 3, 'reasons' => $best['why'], 'options' => $options];
    }

    private function linkSignal(array $s, WorkJob $job, string $by, string $reason): void
    {
        match ($s['type']) {
            'calendar' => ($event = self::event($s['id'], $s['from'])) ? $this->linkCalendar($event, $job, $by, ucfirst($reason)) : null,
            'document' => ($doc = Document::query()->find($s['id'])) ? $this->linkDocument($doc, $job, $by) : null,
            default => null,
        };
    }

    /** @return list<list<array>> */
    private function cluster(Collection $signals): array
    {
        $primary = $signals->filter(fn ($s) => $s['type'] === 'document' ? (bool) $s['from'] : ($s['type'] === 'calendar' && $s['work'] >= 3))
            ->sortBy('from')->values();
        $clusters = [];
        foreach ($primary as $s) {
            $joined = false;
            foreach ($clusters as $i => $c) {
                if ($this->belongs($s, $c)) {
                    $clusters[$i][] = $s;
                    $joined = true;
                    break;
                }
            }
            if (! $joined) {
                $clusters[] = [$s];
            }
        }

        // Stays and journeys support the possible job they're next to; on their own they're a weak proposal.
        foreach ($signals->where('type', 'travel') as $t) {
            $best = null;
            foreach ($clusters as $i => $c) {
                [$from, $to] = self::range($c);
                if ($t['from'] <= CarbonImmutable::parse($to)->addDay()->format('Y-m-d') && $t['to'] >= CarbonImmutable::parse($from)->subDay()->format('Y-m-d')) {
                    $best = $i;
                    break;
                }
            }
            if ($best !== null) {
                $clusters[$best][] = $t;
            } else {
                $clusters[] = [$t];
            }
        }

        return $clusters;
    }

    private function belongs(array $s, array $cluster): bool
    {
        [$from, $to] = self::range($cluster);
        $near = $s['from'] <= CarbonImmutable::parse($to)->addDay()->format('Y-m-d') && $s['to'] >= CarbonImmutable::parse($from)->subDay()->format('Y-m-d');
        $overlap = $s['from'] <= $to && $s['to'] >= $from;
        foreach ($cluster as $c) {
            if ($s['po'] && $c['po'] && self::normalisePo($s['po']) === self::normalisePo($c['po'])) {
                return true;
            }
            if (! $near) {
                continue;
            }
            if (($s['client'] && $c['client'] && self::same($s['client'], $c['client']))
                || array_intersect(self::words($s['text'].' '.$s['location']), self::words($c['text'].' '.$c['location']))) {
                return true;
            }
            if ($overlap && $s['type'] !== $c['type']) {
                return true; // a calendar entry and a document for the same days
            }
        }

        return false;
    }

    private function candidateFrom(array $cluster): ?array
    {
        $docs = array_values(array_filter($cluster, fn ($s) => $s['type'] === 'document'));
        $cal = array_values(array_filter($cluster, fn ($s) => $s['type'] === 'calendar'));
        $travel = array_values(array_filter($cluster, fn ($s) => $s['type'] === 'travel'));
        usort($docs, fn ($a, $b) => $b['verified'] <=> $a['verified']);
        $work = $docs || $cal ? [...$docs, ...$cal] : $travel;
        [$from, $to] = self::range($work);
        if (! $from) {
            return null;
        }
        $first = fn (string $field, array ...$groups) => collect($groups)->flatten(1)->pluck($field)->filter()->first();

        $title = $first('title', $docs, $cal);
        if (! $title) {
            $t = $travel[0];
            $title = 'Possible job: '.($t['location'] ? 'away at '.$t['location'] : $t['label']);
        }
        $clientName = $first('client', $docs, $cal);
        $po = $first('po', $docs);
        $score = array_sum(array_map(fn ($d) => $d['verified'] ? 4 : 2, $docs)) + min(count($cal), 2) * 3 + min(count($travel), 2) + ($po ? 3 : 0) + ($clientName ? 2 : 0);
        $why = [];
        foreach ($cluster as $s) {
            $why[] = match ($s['type']) {
                'document' => 'Document “'.mb_substr($s['label'], 0, 80).'”'.($s['verified'] ? ' (checked against the document)' : ' (not checked yet)'),
                'calendar' => 'Calendar: “'.mb_substr($s['label'], 0, 80).'” on '.CarbonImmutable::parse($s['from'])->format('D j M Y').(! empty($s['ai']) ? ' (AI said it’s work, quoting “'.mb_substr($s['ai_quote'], 0, 60).'”)' : ''),
                default => $s['label'].' from '.CarbonImmutable::parse($s['from'])->format('j M').' with no job then',
            };
        }
        if (! $docs && ! $cal) {
            $why[] = 'Only a stay or journey points to this: add the job if it was work, or dismiss it';
        }
        $options = collect($cluster)->pluck('options')->filter()->flatten()->unique()->values()->all();
        $timed = collect($cal)->filter(fn ($c) => $c['start_time'])->sortBy('from');

        return [
            'status' => 'open',
            'title' => mb_substr((string) $title, 0, 150),
            'client_name' => $clientName ? mb_substr($clientName, 0, 120) : null,
            'starts_on' => $from, 'ends_on' => $to,
            'start_time' => $first('start_time', $docs) ?? $timed->first()['start_time'] ?? null,
            'end_time' => $first('end_time', $docs) ?? $timed->last()['end_time'] ?? null,
            'location' => ($l = $first('location', $docs, $cal, $travel)) ? mb_substr($l, 0, 200) : null,
            'postcode' => ($p = $first('postcode', $docs, $cal)) ? mb_substr(strtoupper($p), 0, 10) : null,
            'po_number' => $po,
            'sources' => array_map(fn ($s) => [
                'key' => $s['key'], 'type' => $s['type'], 'id' => $s['id'], 'label' => mb_substr((string) $s['label'], 0, 200),
                'date' => $s['from'], 'to' => $s['to'], 'quote' => $s['quote'] ? mb_substr($s['quote'], 0, 400) : null,
                'verified' => $s['verified'], 'note' => $s['note'], 'ai' => (bool) ($s['ai'] ?? false),
            ], $cluster),
            'reasons' => ['why' => $why, 'jobs' => $options],
            'score' => $score,
            'found_by' => collect($cluster)->contains(fn ($s) => ! empty($s['ai'])) ? 'ai' : 'rules',
        ];
    }

    // ---------------------------------------------------------------- AI, double-checked

    /** Ask about calendar entries the rules can't call. Only answers whose quoted words are really in the entry count. */
    private function askAiAboutCalendar(Collection $signals, Collection $jobs, array &$stats): Collection
    {
        $unsure = $signals->filter(fn ($s) => $s['type'] === 'calendar' && $s['work'] >= 1 && $s['work'] <= 2)->take(120);
        $ask = $unsure->reject(fn ($s) => Cache::has(self::verdictKey($s)));
        if ($ask->isNotEmpty()) {
            try {
                $result = $this->ai->send([
                    'maxTokens' => 8000,
                    'system' => 'You help a UK freelancer who works on events and productions sort their calendar. For each entry say whether it is paid work '
                        .'(a job day, rehearsal, build, travel day for a job, site visit) or not (personal, admin, a call). Answer only from the entry\'s own words. '
                        .'When you say it is work, quote the exact words from the entry that show it. Name a client only if the entry names one from the list.',
                    'messages' => [['role' => 'user', 'content' => 'Clients: '.json_encode(array_values($this->clients))."\n\nCalendar entries:\n"
                        .json_encode($ask->values()->map(fn ($s, $i) => ['i' => $i, 'date' => $s['from'], 'time' => $s['start_time'], 'text' => $s['text']])->all(), JSON_UNESCAPED_UNICODE)]],
                    'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['entries'],
                        'properties' => ['entries' => ['type' => 'array', 'items' => [
                            'type' => 'object', 'additionalProperties' => false, 'required' => ['i', 'work', 'quote', 'client'],
                            'properties' => ['i' => ['type' => 'integer'], 'work' => ['type' => ['boolean', 'null']], 'quote' => ['type' => ['string', 'null']], 'client' => ['type' => ['string', 'null']]],
                        ]]],
                    ]]],
                ]);
                $answers = collect(json_decode($result->text(), true)['entries'] ?? [])->keyBy('i');
                foreach ($ask->values() as $i => $s) {
                    $a = $answers[$i] ?? null;
                    $verdict = $this->checkAiAnswer($s, $a);
                    $stats['ai_checked']++;
                    $stats['ai_rejected'] += $verdict['rejected'] ? 1 : 0;
                    Cache::put(self::verdictKey($s), $verdict, now()->addDays(90));
                }
            } catch (Throwable $e) {
                report($e); // the rules still run
            }
        }

        return $signals->map(function ($s) {
            if ($s['type'] !== 'calendar' || $s['work'] < 1 || $s['work'] > 2) {
                return $s;
            }
            $v = Cache::get(self::verdictKey($s));
            if (! $v || $v['rejected'] || $v['work'] === null) {
                return $s;
            }

            return $v['work']
                ? ['work' => 3, 'ai' => true, 'ai_quote' => $v['quote'], 'client' => $s['client'] ?? $v['client'], 'note' => 'AI said it’s work; the quoted words are in the entry'] + $s
                : ['work' => 0] + $s;
        });
    }

    /** The double-check: the quote has to be in the entry, and a named client has to be yours and in the entry too. */
    private function checkAiAnswer(array $signal, ?array $answer): array
    {
        if (! $answer || $answer['work'] === null) {
            return ['work' => null, 'quote' => null, 'client' => null, 'rejected' => false];
        }
        if ($answer['work'] === false) {
            return ['work' => false, 'quote' => null, 'client' => null, 'rejected' => false];
        }
        $quote = trim((string) $answer['quote']);
        if (mb_strlen(Grounding::normalise($quote)) < 3 || ! Grounding::quoteFound($signal['text'], $quote)) {
            return ['work' => null, 'quote' => $quote, 'client' => null, 'rejected' => true];
        }
        $client = $answer['client'] && in_array($answer['client'], $this->clients, true) && $this->mentions($signal['text'], $answer['client']) ? $answer['client'] : null;

        return ['work' => true, 'quote' => $quote, 'client' => $client, 'rejected' => false];
    }

    private static function verdictKey(array $s): string
    {
        return 'job-finder:cal:'.$s['id'].':'.md5($s['text']);
    }

    // ---------------------------------------------------------------- helpers

    /** A calendar entry by its stable key, looked up on the day it starts. */
    public static function event(string $key, ?string $date): ?CalendarEvent
    {
        return CalendarEvent::query()
            ->when($date, fn ($q) => $q->whereBetween('starts_at', [$date.' 00:00:00', $date.' 23:59:59']))
            ->get()->first(fn (CalendarEvent $e) => $e->key() === $key);
    }

    /** @return array<int, list<string>> each job's PO numbers, from the job and from PO documents linked to it */
    private function jobPoNumbers(Collection $jobs): array
    {
        $out = [];
        foreach ($jobs as $job) {
            if ($p = self::normalisePo($job->po_number)) {
                $out[$job->id][] = $p;
            }
        }
        foreach (Document::query()->whereIn('work_job_id', $jobs->pluck('id'))->whereNotNull('extraction')->get(['id', 'work_job_id', 'extraction']) as $doc) {
            if ($p = self::normalisePo($doc->extraction['po']['po_number'] ?? null)) {
                $out[$doc->work_job_id][] = $p;
            }
        }

        return $out;
    }

    public static function normalisePo(?string $po): ?string
    {
        $p = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $po));

        return strlen((string) $p) >= 3 ? $p : null;
    }

    private function clientIn(string $text): ?string
    {
        foreach ($this->clients as $name) {
            if ($this->mentions($text, $name)) {
                return $name;
            }
        }

        return null;
    }

    private function mentions(string $text, string $name): bool
    {
        $n = Grounding::normalise(preg_replace('/\b(ltd|limited|plc|llp|the)\b/i', '', $name));

        return mb_strlen($n) >= 3 && str_contains(Grounding::normalise($text), $n);
    }

    private static function same(string $a, string $b): bool
    {
        $strip = fn ($s) => Grounding::normalise(preg_replace('/\b(ltd|limited|plc|llp|the)\b/i', '', $s));

        return $strip($a) !== '' && $strip($a) === $strip($b);
    }

    /** @return list<string> distinctive words (4+ letters, not generic) */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower($text), $m);

        return array_values(array_unique(array_diff($m[0], self::STOP, self::WORK_WORDS)));
    }

    /** @return array{0:?string,1:?string} */
    private static function range(array $signals): array
    {
        $from = collect($signals)->pluck('from')->filter()->min();
        $to = collect($signals)->pluck('to')->filter()->max();

        return [$from, $to ?? $from];
    }

    private static function isLink(string $location): bool
    {
        return (bool) preg_match('#^https?://|zoom\.us|teams\.microsoft|meet\.google#i', trim($location));
    }

    private static function postcodeIn(string $text): ?string
    {
        return preg_match('/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i', $text, $m) ? strtoupper($m[1].' '.$m[2]) : null;
    }
}
