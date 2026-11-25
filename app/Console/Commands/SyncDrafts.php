<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every open draft brought up to date with what's linked to its job (InvoiceDraftSync, as 'policy'): nightly, on its
 * own schedule whatever the cycle's mode, and by hand. A draft that's already up to date takes no lock and writes
 * nothing. A draft that fails is reported and the rest carry on.
 *
 * --before-rollback, run before the release before this one is put back: that release reads every line of a draft
 * as charged (its total sums them all), so a line left unticked would be billed. Each draft's unticked lines are
 * deleted, whether from the job or typed by you, and the lines that stay read as your own typed lines again (where
 * each came from forgotten, and synced_at cleared, as if made before line sources), audited as 'policy'
 * (invoice.rollback_prepared, listing the lines removed). Issued invoices aren't touched.
 */
class SyncDrafts extends Command
{
    protected $signature = 'ledger:sync-drafts {--before-rollback : Make drafts safe for the release before this one: remove unticked lines}';

    protected $description = 'Bring every draft invoice up to date with what is linked to its job';

    public function handle(InvoiceDraftSync $sync): int
    {
        if ($this->option('before-rollback')) {
            return $this->beforeRollback();
        }
        $changed = 0;
        $failed = 0;
        $drafts = Invoice::query()->where('status', 'draft')->orderBy('id')->pluck('id');
        foreach ($drafts as $id) {
            try {
                $invoice = Invoice::query()->find($id);
                if ($invoice === null) {
                    continue;
                }
                $r = AuditContext::with('policy', fn () => $sync->sync($invoice));
                $changed += $r['changed'] > 0 ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->warn("Draft {$id} couldn’t be brought up to date: {$e->getMessage()}");
            }
        }
        $this->info("{$drafts->count()} ".($drafts->count() === 1 ? 'draft' : 'drafts')." checked: {$changed} changed".($failed > 0 ? ", {$failed} failed" : '').'.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function beforeRollback(): int
    {
        $removed = 0;
        $drafts = 0;
        foreach (Invoice::query()->where('status', 'draft')->orderBy('id')->pluck('id') as $id) {
            $done = AuditContext::with('policy', fn () => DB::transaction(function () use ($id) {
                AuditLog::lockHead();
                $invoice = Invoice::query()->lockForUpdate()->find($id);
                if ($invoice === null || $invoice->status !== 'draft') {
                    return null;
                }
                $lines = InvoiceLine::query()->where('invoice_id', $id)->lockForUpdate()->get();
                // Every unticked line: the release before would bill it, whoever made it.
                $gone = $lines->filter(fn (InvoiceLine $l) => ! $l->included);
                $kept = $lines->filter(fn (InvoiceLine $l) => ($l->source !== null || $l->source_ref !== null) && $l->included);
                if ($gone->isEmpty() && $kept->isEmpty() && $invoice->synced_at === null) {
                    return null;
                }
                $gone->each->delete();
                foreach ($kept as $line) {
                    $line->forceFill(['source' => null, 'source_ref' => null, 'source_job_id' => null, 'flag' => null, 'ticked_by' => null, 'own_text' => false,
                        'own_amount' => false, 'source_amount_minor' => null, 'source_quantity' => null])->save();
                }
                $invoice->forceFill(['synced_at' => null, 'total_minor' => (int) InvoiceLine::query()->where('invoice_id', $id)->where('included', true)->sum('amount_minor')])->save();
                AuditLog::record('invoice.rollback_prepared', $invoice, ['lines' => $lines->count()],
                    ['removed' => $gone->pluck('id')->map(fn ($i) => (int) $i)->values()->all(), 'kept_as_typed' => $kept->count()],
                    'Before going back to the release before line sources: unticked lines removed');

                return $gone->count();
            }));
            if ($done !== null) {
                $drafts++;
                $removed += $done;
            }
        }
        $this->info("{$drafts} ".($drafts === 1 ? 'draft' : 'drafts')." made ready for the release before: {$removed} unticked ".($removed === 1 ? 'line' : 'lines').' removed.');

        return self::SUCCESS;
    }
}
