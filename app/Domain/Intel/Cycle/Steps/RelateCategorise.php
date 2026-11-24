<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Domain\Matching\Categoriser;
use App\Models\BankTransaction;

/**
 * relate.categorise (docs/intelligence-cycle.md 4.2): suggests what each undecided payment is, the same work as
 * ledger:categorise (chunk(), which the command also calls): the whole suggestion chain (your rules, open invoices,
 * HMRC, fines, car hire and travel, then what you decided before), as for a new statement, 300 payments at a time. It
 * stops between chunks when its time is up and carries on from the last payment done.
 */
final class RelateCategorise implements CycleStep
{
    public const KEY = 'relate.categorise';

    public const CHUNK = 300;

    public function __construct(private Categoriser $categoriser, private int $chunk = self::CHUNK) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Relate;
    }

    public function writes(): bool
    {
        return true;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $after = (int) ($ctx->cursor['last_id'] ?? 0);
        $made = (int) ($ctx->cursor['made'] ?? 0);
        $looked = (int) ($ctx->cursor['looked'] ?? 0);
        do {
            [$n, $last, $count] = self::chunk($this->categoriser, $after, $this->chunk);
            if ($last === null) {
                return StepOutcome::ok(['suggested' => $made, 'looked_at' => $looked]);
            }
            $made += $n;
            $looked += $count;
            $after = $last;
        } while ($ctx->hasTime());

        return StepOutcome::more(['last_id' => $after, 'made' => $made, 'looked' => $looked], ['suggested' => $made, 'looked_at' => $looked]);
    }

    /**
     * Suggest for the next $size undecided payments after $after (by id).
     *
     * @return array{0:int, 1:?int, 2:int} suggestions made, the last id done (null: none left), payments looked at
     */
    public static function chunk(Categoriser $categoriser, int $after, int $size = self::CHUNK): array
    {
        $txs = BankTransaction::query()->where('review_status', 'unreviewed')->whereDoesntHave('allocations')->whereNull('transfer_pair_id')
            ->where('id', '>', $after)->orderBy('id')->limit($size)->get();
        if ($txs->isEmpty()) {
            return [0, null, 0];
        }

        return [$categoriser->suggestFor($txs), (int) $txs->last()->id, $txs->count()];
    }
}
