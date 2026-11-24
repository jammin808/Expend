<?php

namespace App\Domain\Import;

use App\Domain\Audit\AuditLog;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\TaxYear;
use App\Support\UkTaxYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class Importer
{
    public function __construct(private Suggester $suggester, private TransferPairer $pairer) {}

    /**
     * Read a file with a profile and report what importing it would do, without changing anything.
     *
     * @return array{rows:list<NormalizedRow>, errors:list<string>, new:int, duplicates:int, balance:array, first:?string, last:?string, sample:list<array>}
     */
    public function preview(Account $account, string $path, ImportProfile $profile): array
    {
        $csv = CsvReader::read($path);
        $problems = $profile->problems($csv['headers']);
        if ($problems) {
            throw new RuntimeException(implode(' ', $problems));
        }

        ['rows' => $rows, 'errors' => $errors] = RowNormalizer::normalize($profile, $csv['rows']);
        $plan = $this->plan($account, $rows);
        $dates = array_map(fn (NormalizedRow $r) => $r->effectiveDate()->format('Y-m-d'), $rows);
        $errors = array_merge($errors, $plan['locked'] ? ["{$plan['locked']} rows fall in a locked tax year (".implode(', ', $plan['locked_years']).'). Unlock it first, or import a file without those dates.'] : []);
        sort($dates);

        return [
            'rows' => $rows,
            'errors' => $errors,
            'new' => count($plan['new']),
            'duplicates' => $plan['duplicates'],
            'locked' => $plan['locked'],
            'balance' => BalanceCheck::run($rows),
            'first' => $dates[0] ?? null,
            'last' => $dates ? end($dates) : null,
            'sample' => array_map(fn (NormalizedRow $r) => [
                'date' => $r->effectiveDate()->format('Y-m-d'),
                'description' => $r->description,
                'amount_pence' => $r->amountPence,
                'balance_pence' => $r->balanceAfterPence,
                'currency' => $r->originalCurrency,
                'original_minor' => $r->originalAmountMinor,
                'duplicate' => ! in_array($r, $plan['new'], true),
            ], array_slice($rows, 0, 12)),
        ];
    }

    public function commit(Account $account, string $path, string $originalName, ImportProfile $profile): ImportBatch
    {
        $preview = $this->preview($account, $path, $profile);
        if ($preview['errors']) {
            throw new RuntimeException('Fix these rows first: '.implode(' ', array_slice($preview['errors'], 0, 5)));
        }
        $batch = $this->store($account, $preview['rows'], $path, $originalName, $profile->toArray(), $preview['balance'], $preview['first'], $preview['last']);
        if ($batch->status !== 'quarantined') {
            $this->afterImport($batch);
        }

        return $batch;
    }

    /**
     * Save normalised rows from a file into one account as one batch (new rows only). A statement whose running
     * balance doesn't add up is held back: its rows are kept but count nowhere until you accept it (with a reason)
     * or discard it.
     *
     * @param  list<NormalizedRow>  $rows
     */
    public function store(Account $account, array $rows, string $path, string $originalName, array $profile, array $balance, ?string $first, ?string $last): ImportBatch
    {
        $sha = hash_file('sha256', $path);
        $stored = 'imports/'.$sha.'.csv';
        Storage::disk('local')->put($stored, file_get_contents($path));
        $quarantine = $balance['status'] === 'failed';

        return DB::transaction(function () use ($account, $rows, $sha, $stored, $originalName, $profile, $quarantine, $balance, $first, $last) {
            $plan = $this->plan($account, $rows);

            $batch = ImportBatch::create([
                'account_id' => $account->id,
                'original_filename' => $originalName,
                'file_sha256' => $sha,
                'stored_path' => $stored,
                'row_count' => count($rows),
                'imported_count' => count($plan['new']),
                'duplicate_count' => $plan['duplicates'],
                'first_date' => $first,
                'last_date' => $last,
                'balance_check' => $balance['status'],
                'balance_check_notes' => $balance['notes'],
                'status' => $quarantine ? 'quarantined' : 'committed',
            ]);

            foreach ($plan['new'] as $index => $row) {
                BankTransaction::create([
                    'account_id' => $account->id,
                    'import_batch_id' => $batch->id,
                    'posted_on' => $row->postedOn,
                    'transaction_on' => $row->transactionOn,
                    'tax_year' => UkTaxYear::fromDate($row->effectiveDate())->label(),
                    'description' => $row->description,
                    'counterparty' => $row->counterparty,
                    'reference' => $row->reference,
                    'merchant_key' => MerchantKey::from($row->description, $row->counterparty),
                    'amount_pence' => $row->amountPence,
                    'balance_after_pence' => $row->balanceAfterPence,
                    'original_amount_minor' => $row->originalAmountMinor,
                    'original_currency' => $row->originalCurrency,
                    'balance_after_minor' => $row->nativeAmountMinor !== null ? $row->nativeBalanceMinor : null,
                    'fx_units_per_gbp' => $row->fxUnitsPerGbp,
                    'fx_rate_date' => $row->fxRateDate,
                    'fx_source' => $row->fxSource,
                    'fee_minor' => $row->feeMinor,
                    'bank_category' => $row->bankCategory,
                    'raw' => $row->raw,
                    ...self::details($row),
                    'fingerprint' => $row->fingerprint($account->id),
                    'occurrence' => $plan['occurrences'][$index],
                    'state' => $quarantine ? 'quarantined' : 'active',
                    'live' => $quarantine ? null : true,
                ]);
            }

            $account->forceFill(['import_profile' => $profile])->save();

            AuditLog::record($quarantine ? 'import.quarantined' : 'import.committed', $batch, null, [
                'account' => $account->name, 'file' => $originalName, 'imported' => count($plan['new']),
                'duplicates' => $plan['duplicates'], 'balance_check' => $balance['status'],
            ]);

            return $batch;
        });
    }

    /**
     * Money moving between your accounts first (both sides imported), then your rules and the matchers, then lines
     * that are your own money moving even though the other side isn't imported.
     */
    public function afterImport(ImportBatch ...$batches): void
    {
        $this->pairer->pairAll();
        $this->suggester->suggestFor(BankTransaction::query()->whereIn('import_batch_id', array_map(fn (ImportBatch $b) => $b->id, $batches))->get());
        $this->pairer->suggestOneSided();
    }

    /**
     * Accept a held statement anyway, with your reason. Rows that have since been imported from another
     * statement are left reversed rather than duplicated.
     */
    public function accept(ImportBatch $batch, string $reason): int
    {
        $this->assertStatus($batch, 'quarantined');
        $rows = $this->rows($batch, 'quarantined');
        $this->assertUnlocked($rows);

        $activated = DB::transaction(function () use ($batch, $rows, $reason) {
            $activated = 0;
            foreach ($rows as $tx) {
                $taken = BankTransaction::query()->where('account_id', $tx->account_id)->where('fingerprint', $tx->fingerprint)->where('occurrence', $tx->occurrence)->exists();
                $tx->forceFill($taken ? ['state' => 'reversed', 'live' => null] : ['state' => 'active', 'live' => true])->saveQuietly();
                $activated += $taken ? 0 : 1;
            }
            $batch->update(['status' => 'committed', 'imported_count' => $activated, 'review_note' => 'Accepted despite the balance check: '.$reason]);
            AuditLog::record('import.accepted', $batch, ['status' => 'quarantined'], ['status' => 'committed', 'activated' => $activated], $reason);

            return $activated;
        });
        $this->afterImport($batch);

        return $activated;
    }

    /** Discard a held statement. Its rows are kept, reversed, for the record. */
    public function discard(ImportBatch $batch, string $reason): void
    {
        $this->assertStatus($batch, 'quarantined');
        DB::transaction(function () use ($batch, $reason) {
            $count = BankTransaction::withoutGlobalScope('active')->where('import_batch_id', $batch->id)->where('state', 'quarantined')
                ->update(['state' => 'reversed', 'live' => null]);
            $batch->update(['status' => 'discarded', 'review_note' => $reason]);
            AuditLog::record('import.discarded', $batch, ['status' => 'quarantined'], ['status' => 'discarded', 'rows_kept_reversed' => $count], $reason);
        });
    }

    /** Bring an undone import back: its rows and your decisions on them, except rows imported again since. */
    public function restore(ImportBatch $batch, string $reason): int
    {
        $this->assertStatus($batch, 'reverted');
        $rows = $this->rows($batch, 'reversed');
        $this->assertUnlocked($rows);

        return DB::transaction(function () use ($batch, $rows, $reason) {
            $restored = 0;
            foreach ($rows as $tx) {
                if (BankTransaction::query()->where('account_id', $tx->account_id)->where('fingerprint', $tx->fingerprint)->where('occurrence', $tx->occurrence)->exists()) {
                    continue; // imported again from another statement since: that copy stays
                }
                // Re-pair a card bill or transfer with the same partner, if it's still free; otherwise the pairer looks again.
                $partner = $tx->transfer_pair_id ? BankTransaction::query()->find($tx->transfer_pair_id) : null;
                $repair = $partner && $partner->transfer_pair_id === null && $partner->amount_pence === -$tx->amount_pence;
                $tx->forceFill(['state' => 'active', 'live' => true, 'transfer_pair_id' => $repair ? $partner->id : null])->saveQuietly();
                if ($repair) {
                    $partner->forceFill(['transfer_pair_id' => $tx->id])->saveQuietly();
                }
                Allocation::withoutGlobalScope('not_voided')->where('bank_transaction_id', $tx->id)->whereNotNull('voided_at')->update(['voided_at' => null]);
                $restored++;
            }
            app(TransferPairer::class)->pairAll();
            $batch->update(['status' => 'committed', 'review_note' => 'Restored: '.$reason]);
            AuditLog::record('import.restored', $batch, ['status' => 'reverted'], ['status' => 'committed', 'restored' => $restored], $reason);

            return $restored;
        });
    }

    /** @return Collection<int, BankTransaction> */
    private function rows(ImportBatch $batch, string $state)
    {
        return BankTransaction::withoutGlobalScope('active')->where('import_batch_id', $batch->id)->where('state', $state)->orderBy('id')->get();
    }

    private function assertStatus(ImportBatch $batch, string $status): void
    {
        if ($batch->status !== $status) {
            throw new RuntimeException("This import is {$batch->status}, so that can't be done.");
        }
    }

    private function assertUnlocked($rows): void
    {
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label');
        $touched = $rows->pluck('tax_year')->unique()->intersect($locked);
        if ($touched->isNotEmpty()) {
            throw new RuntimeException('This import includes transactions in a locked tax year ('.$touched->implode(', ').'). Unlock it first.');
        }
    }

    /**
     * Undo an import. Nothing is deleted: its transactions are reversed and your decisions on them voided, so
     * they count nowhere but stay on record, and the import can be restored.
     */
    public function revert(ImportBatch $batch, string $reason): void
    {
        $this->assertStatus($batch, 'committed');
        $lockedYears = TaxYear::query()->whereNotNull('locked_at')->pluck('label');
        $touched = $batch->transactions()->whereIn('tax_year', $lockedYears)->distinct()->pluck('tax_year');
        if ($touched->isNotEmpty()) {
            throw new RuntimeException('This import includes transactions in a locked tax year ('.$touched->implode(', ').'). Unlock it first.');
        }

        DB::transaction(function () use ($batch, $reason) {
            $ids = $batch->transactions()->pluck('id');
            $confirmed = Allocation::query()->whereIn('bank_transaction_id', $ids)->whereNotNull('confirmed_at')->count();
            BankTransaction::withoutGlobalScope('active')->whereIn('transfer_pair_id', $ids)->update(['transfer_pair_id' => null]);
            Allocation::query()->whereIn('bank_transaction_id', $ids)->update(['voided_at' => now()]);
            // The undone rows keep their pair link on record (so a restore can re-pair them); their partners are freed.
            BankTransaction::withoutGlobalScope('active')->whereIn('id', $ids)->update(['state' => 'reversed', 'live' => null]);
            $batch->update(['status' => 'reverted', 'review_note' => $reason]);
            AuditLog::record('import.reverted', $batch, ['transactions' => $ids->count(), 'confirmed_decisions_voided' => $confirmed], null, $reason);
        });
    }

    /** @param list<NormalizedRow> $rows @return array{new:int, duplicates:int} */
    public function planCounts(Account $account, array $rows): array
    {
        $plan = $this->plan($account, $rows);

        return ['new' => count($plan['new']), 'duplicates' => $plan['duplicates']];
    }

    /**
     * Decide which rows are new. Identical rows within one file are numbered (occurrence 1, 2, ...), so two genuine
     * £3.20 coffees on the same day both survive, but the same coffee in two overlapping statements is skipped.
     *
     * @param  list<NormalizedRow>  $rows
     * @return array{new: list<NormalizedRow>, occurrences: list<int>, duplicates:int, locked:int, locked_years:list<string>}
     */
    private function plan(Account $account, array $rows): array
    {
        $counts = [];
        $new = [];
        $occurrences = [];
        $duplicates = 0;
        $locked = 0;
        $lockedYears = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $hitYears = [];

        foreach ($rows as $row) {
            $fp = $row->fingerprint($account->id);
            $counts[$fp] = ($counts[$fp] ?? 0) + 1;
            $exists = BankTransaction::query()
                ->where('account_id', $account->id)->where('fingerprint', $fp)->where('occurrence', $counts[$fp])->exists();
            if ($exists) {
                $duplicates++;
            } elseif (in_array($year = UkTaxYear::fromDate($row->effectiveDate())->label(), $lockedYears, true)) {
                $locked++;
                $hitYears[$year] = true;
            } else {
                $new[] = $row;
                $occurrences[] = $counts[$fp];
            }
        }

        return ['new' => $new, 'occurrences' => $occurrences, 'duplicates' => $duplicates, 'locked' => $locked, 'locked_years' => array_keys($hitYears)];
    }

    /** @return array{transacted_time:?string, place:?string, card_last4:?string, payment_type:?string} */
    private static function details(NormalizedRow $row): array
    {
        $d = LineDetails::from($row->raw, $row->description, $row->effectiveDate());

        return ['transacted_time' => $d['time'], 'place' => $d['place'], 'card_last4' => $d['card_last4'], 'payment_type' => $d['payment_type']];
    }
}
