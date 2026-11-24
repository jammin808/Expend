<?php

namespace App\Domain\Intel\Backfills;

use App\Domain\Intel\Detectors\TodoGroupsDetector;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ref;
use App\Domain\Todo\FindingsRegistry;
use App\Models\BankTransaction;
use App\Models\Finding;
use App\Models\FindingEvent;
use Illuminate\Support\Facades\DB;

/**
 * The findings backfill (docs/intelligence-cycle.md section 3.5): findings kept before M3 are read into the shared
 * vocabulary. From each key's start (the table in section 4.4, TodoGroupsDetector::classify(); the same parsing as
 * migration 2026_11_29_000901) it sets the code, the record it's about, the job, account or year it belongs to,
 * which way the money goes and its grade; its state comes from how it ended (resolved_at, resolution). Its history
 * starts with a detected event at first_seen_at and, for one that ended, a closing event at resolved_at.
 *
 * Only fills what's empty, and leaves a value null when unsure (a key it can't read keeps a null code). A finding is
 * done once it has a state, so a second run changes nothing.
 *
 * A charge that may be in twice was kept with every charge's money (n × the charge); what's at stake is only the extra
 * ones ((n − 1) × the charge, section 2.2), so its amount and the most it involved are counted again that way
 * (recount()), before the findings history compares them with what's found now: the way it's counted changing isn't
 * recorded as a change to the money. The findings history adopts a finding the same way the
 * moment it touches one not done yet (adopt()), so its events always start where they should.
 */
final class Findings implements Backfill
{
    public const NAME = 'findings';

    public function name(): string
    {
        return self::NAME;
    }

    /** @return array{changed:int, done:bool} */
    public function run(int $chunk = 500): array
    {
        $rows = Finding::query()->whereNull('state')->orderBy('id')->limit(max(1, $chunk))->get();
        $changed = 0;
        foreach ($rows as $f) {
            if (self::adopt($f)) {
                $changed++;
            }
        }

        return ['changed' => $changed, 'done' => ! Finding::query()->whereNull('state')->exists()];
    }

    /**
     * Read a finding kept before M3 into the vocabulary, with the start of its history; nothing for one already
     * read. Returns whether it changed anything.
     */
    public static function adopt(Finding $f): bool
    {
        if ($f->state !== null) {
            return false;
        }

        return DB::transaction(function () use ($f) {
            $fresh = Finding::query()->lockForUpdate()->find($f->id);
            if (! $fresh || $fresh->state !== null) {
                // Read by another run meanwhile: carry on with what it recorded.
                if ($fresh) {
                    $f->setRawAttributes($fresh->getAttributes(), true);
                }

                return false;
            }
            $fill = ['state' => Finding::legacyState($fresh->resolved_at !== null, $fresh->resolution)] + self::recount($fresh);
            $c = TodoGroupsDetector::classify($fresh->key, $fill['amount_pence'] ?? $fresh->amount_pence);
            if ($c !== null) {
                [$scope] = TodoGroupsDetector::scopes([$c]);
                [$grade] = Grades::forCode($c['code']);
                $fill += array_filter(['code' => $fresh->code ?? $c['code'], 'subject_ref' => $fresh->subject_ref ?? $c['subject_ref'], 'scope_ref' => $fresh->scope_ref ?? $scope,
                    'direction' => $fresh->direction ?? $c['direction'], 'grade' => $fresh->grade ?? $grade,
                    'evidence' => $fresh->evidence ?? ($c['subject_ref'] ? Ref::list([$c['subject_ref']]) : null)], fn ($v) => $v !== null);
            }
            $fresh->forceFill($fill)->save();
            if (! $fresh->events()->exists()) {
                // The money it involved when found isn't known: the most it involved stands in for it.
                FindingEvent::query()->create(['finding_id' => $fresh->id, 'event' => 'detected', 'amount_pence' => $fresh->peak_amount_pence ?? $fresh->amount_pence,
                    'currency' => $fresh->currency, 'actor' => 'system', 'note' => 'Found before Expend kept each finding’s history', 'created_at' => $fresh->first_seen_at]);
                if ($fresh->resolved_at !== null) {
                    [$event, $actor, $closing] = match ($fresh->resolution) {
                        'snoozed' => ['snoozed', 'you', null],
                        'dismissed' => ['dismissed', 'you', null],
                        'superseded' => ['resolved', 'system', 'Joined to another gap'],
                        default => ['resolved', 'system', null],
                    };
                    FindingsRegistry::event($fresh, $event, actor: $actor, note: $closing, at: $fresh->resolved_at);
                }
            }
            $f->setRawAttributes($fresh->getAttributes(), true);

            return true;
        });
    }

    /**
     * A duplicate kept before M3, counted as only its extra charges: the amount and the most it involved, each less one
     * charge (the first line's, tx:{t}, which never changes). Nothing when either isn't a whole number of charges, at
     * least two: left as it is when unsure.
     *
     * @return array{amount_pence?:int, peak_amount_pence?:int}
     */
    private static function recount(Finding $f): array
    {
        if (! preg_match('/^dupe:(\d+)$/', $f->key, $m)) {
            return [];
        }
        $charge = (int) BankTransaction::query()->whereKey((int) $m[1])->value('amount_pence');
        $less = fn (?int $v) => $v !== null && $charge !== 0 && $v % $charge === 0 && intdiv($v, $charge) >= 2 ? $v - $charge : null;
        $out = array_filter(['amount_pence' => $less($f->amount_pence), 'peak_amount_pence' => $less($f->peak_amount_pence)], fn ($v) => $v !== null);

        return count($out) === count(array_filter([$f->amount_pence, $f->peak_amount_pence], fn ($v) => $v !== null)) ? $out : [];
    }
}
