<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work you might get, found in an email or document before there's a job: an availability check, a hold or pencil,
 * an offer, a booking confirmation. Every detail carries the words it came from (evidence) and whether they were
 * found in the source. Automation finds and updates these; only you make one a job, decline or dismiss it.
 */
class Opportunity extends Model
{
    public const STATUSES = [
        'enquiry' => 'Asked if you’re free',
        'held' => 'On hold',
        'offered' => 'Offered',
        'confirmed' => 'Confirmed',
        'declined' => 'Declined by you',
        'lost' => 'Went elsewhere or cancelled',
        'expired' => 'Passed with no answer',
        'dismissed' => 'Not work',
    ];

    /** How far along an opportunity is. Automation only ever moves it forward along this line. */
    public const STAGES = ['enquiry' => 1, 'held' => 2, 'offered' => 3, 'confirmed' => 4];

    /** Still waiting on you or on them. */
    public const OPEN = ['enquiry', 'held', 'offered', 'confirmed'];

    /** What you decided: automation never changes these. */
    public const YOURS = ['declined', 'dismissed'];

    public const SOURCES = ['gmail', 'email', 'telegram', 'upload', 'assistant', 'manual'];

    public const DAY_KINDS = ['work', 'rig', 'derig', 'travel'];

    public const RATE_UNITS = ['day', 'job', 'hour'];

    protected $fillable = [
        'status', 'hold_rank', 'title', 'client_id', 'client_name', 'contact_name', 'contact_email', 'role', 'venue', 'postcode',
        'starts_on', 'ends_on', 'dates', 'rate_minor', 'rate_currency', 'rate_unit', 'reply_by', 'po_number', 'work_job_id',
        'source', 'found_by', 'confidence', 'evidence', 'thread_key', 'fingerprint', 'rejected_job_ids', 'owner_fields',
        'first_seen_at', 'last_seen_at', 'decided_at', 'decided_by', 'reopened_at', 'note',
    ];

    /** The detail each column belongs to, as its evidence and your edits name it ("rate_minor" is the rate). */
    public const DETAIL_OF = [
        'client_name' => 'client', 'client_id' => 'client', 'contact_name' => 'contact', 'contact_email' => 'contact',
        'starts_on' => 'dates', 'ends_on' => 'dates', 'dates' => 'dates', 'rate_minor' => 'rate', 'rate_currency' => 'rate', 'rate_unit' => 'rate',
    ];

    protected $casts = [
        'hold_rank' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'dates' => 'array', 'rate_minor' => 'integer',
        'reply_by' => 'date', 'confidence' => 'integer', 'evidence' => 'array', 'rejected_job_ids' => 'array', 'owner_fields' => 'array',
        'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'decided_at' => 'datetime', 'reopened_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }

    /** @return list<int> the documents its evidence came from, oldest first */
    public function documentIds(): array
    {
        $ids = [];
        foreach ($this->evidence ?? [] as $e) {
            if (($e['type'] ?? null) === 'document' && is_int($e['id'] ?? null)) {
                $ids[$e['id']] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return Collection<int, Document> */
    public function documents(): Collection
    {
        $ids = $this->documentIds();

        return $ids ? Document::query()->whereIn('id', $ids)->orderBy('received_at')->orderBy('id')->get() : new Collection;
    }

    /** @return list<array> the evidence for one detail (title, client, dates, rate, status...) */
    public function evidenceFor(string $field): array
    {
        return array_values(array_filter($this->evidence ?? [], fn ($e) => ($e['field'] ?? null) === $field));
    }

    /**
     * Details whose words weren't found in the document they came from (a scan, or a quote that isn't there): to be
     * checked before relying on them. Its days are checked one by one: every day listed needs words that give it, so
     * one day the reader made up (a rig day the email never mentions) leaves the dates unchecked. The rate counts as
     * checked only when the words give that amount, currency and unit. A detail you set yourself is yours, not unchecked.
     *
     * @return list<string>
     */
    public function unverifiedFields(): array
    {
        $fields = [];
        foreach ($this->evidence ?? [] as $e) {
            $f = $e['field'] ?? null;
            if ($f === null) {
                continue;
            }
            $fields[$f] = ($fields[$f] ?? false) || (bool) ($e['verified'] ?? false);
        }
        if (isset($fields['dates'])) {
            $checked = [];
            foreach ($this->evidenceFor('dates') as $e) {
                if ($e['verified'] ?? false) {
                    $checked[substr((string) ($e['value'] ?? ''), 0, 10)] = true;
                }
            }
            $days = $this->dayDates();
            $fields['dates'] = $days === [] ? $fields['dates'] : array_diff($days, array_keys($checked)) === [];
        }
        if (isset($fields['rate']) && $this->rate_minor) {
            $value = $this->rate_minor.' '.($this->rate_currency ?? 'GBP').'/'.($this->rate_unit ?? 'job');
            $fields['rate'] = collect($this->evidenceFor('rate'))->contains(fn ($e) => ($e['verified'] ?? false) && ($e['value'] ?? null) === $value);
        }
        $yours = array_flip($this->owner_fields ?? []);

        return array_values(array_filter(array_keys(array_filter($fields, fn ($ok) => ! $ok)), fn ($f) => ! isset($yours[$f])));
    }

    /** Whether you set this detail yourself (Edit details, or work you told the app about): emails never change it. */
    public function ownerSet(string $detail): bool
    {
        return in_array(self::DETAIL_OF[$detail] ?? $detail, $this->owner_fields ?? [], true);
    }

    /**
     * Whether it can be made a job with one tap (the to-do list, Telegram): it has its days, and where it stands, its
     * days, title, client and rate were all found word for word in its emails (or set by you). One spotted by the
     * rules alone (no AI reading) is always opened and checked first.
     */
    public function oneTapReady(): bool
    {
        return $this->found_by !== 'rules' && $this->dayDates() !== []
            && array_intersect(['status', 'dates', 'title', 'client', 'rate'], $this->unverifiedFields()) === [];
    }

    /**
     * The latest word that it may be off (a cancellation, a release, "gone with someone else") while it's still open:
     * the rules can't be sure enough to close it, so you're shown the words and decide. Null when there's none.
     *
     * @return ?array{quote: ?string, id: mixed, verified: bool}
     */
    public function mayBeOff(): ?array
    {
        if (! $this->isOpen()) {
            return null;
        }
        $last = collect($this->evidenceFor('status'))->last();

        return ($last['value'] ?? null) === 'lost' ? ['quote' => $last['quote'] ?? null, 'id' => $last['id'] ?? null, 'verified' => (bool) ($last['verified'] ?? false)] : null;
    }

    /** @return list<string> its days (Y-m-d), oldest first: the dates listed, or every day from first to last */
    public function dayDates(): array
    {
        if ($this->dates) {
            $dates = array_values(array_unique(array_column($this->dates, 'date')));
            sort($dates);

            return $dates;
        }
        if (! $this->starts_on) {
            return [];
        }
        $out = [];
        $end = $this->ends_on ?? $this->starts_on;
        for ($d = $this->starts_on->copy(), $i = 0; $d->lte($end) && $i < 400; $d->addDay(), $i++) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true) && ! $this->work_job_id;
    }

    /** You made it a job (as opposed to the rules linking it to a job you'd already made). */
    public function isAccepted(): bool
    {
        return $this->work_job_id !== null && $this->decided_by !== null && $this->status === 'confirmed';
    }

    /** Waiting on you or on them, with no job yet. */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN)->whereNull('work_job_id');
    }

    /** Confirmed, but there's no job for it yet: you need to add it (or say it isn't one). */
    public function scopeNeedsJob(Builder $q): Builder
    {
        return $q->where('status', 'confirmed')->whereNull('work_job_id');
    }
}
