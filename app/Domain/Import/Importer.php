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
use App\Support\UkTaxYear;
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
        sort($dates);

        return [
            'rows' => $rows,
            'errors' => $errors,
            'new' => count($plan['new']),
            'duplicates' => $plan['duplicates'],
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

        $sha = hash_file('sha256', $path);
        $stored = 'imports/'.$sha.'.csv';
        Storage::disk('local')->put($stored, file_get_contents($path));

        $batch = DB::transaction(function () use ($account, $preview, $sha, $stored, $originalName, $profile) {
            $plan = $this->plan($account, $preview['rows']);

            $batch = ImportBatch::create([
                'account_id' => $account->id,
                'original_filename' => $originalName,
                'file_sha256' => $sha,
                'stored_path' => $stored,
                'row_count' => count($preview['rows']),
                'imported_count' => count($plan['new']),
                'duplicate_count' => $plan['duplicates'],
                'first_date' => $preview['first'],
                'last_date' => $preview['last'],
                'balance_check' => $preview['balance']['status'],
                'balance_check_notes' => $preview['balance']['notes'],
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
                    'bank_category' => $row->bankCategory,
                    'raw' => $row->raw,
                    'fingerprint' => $row->fingerprint($account->id),
                    'occurrence' => $plan['occurrences'][$index],
                ]);
            }

            $account->forceFill(['import_profile' => $profile->toArray()])->save();

            AuditLog::record('import.committed', $batch, null, [
                'account' => $account->name, 'file' => $originalName, 'imported' => count($plan['new']),
                'duplicates' => $plan['duplicates'], 'balance_check' => $preview['balance']['status'],
            ]);

            return $batch;
        });

        $this->pairer->pairAll();
        $this->suggester->suggestFor(BankTransaction::query()->where('import_batch_id', $batch->id)->get());

        return $batch;
    }

    /** Undo an import: removes its transactions and their allocations, with an audit record. */
    public function revert(ImportBatch $batch, string $reason): void
    {
        DB::transaction(function () use ($batch, $reason) {
            $ids = $batch->transactions()->pluck('id');
            $confirmed = Allocation::query()->whereIn('bank_transaction_id', $ids)->whereNotNull('confirmed_at')->count();
            BankTransaction::query()->whereIn('transfer_pair_id', $ids)->update(['transfer_pair_id' => null]);
            Allocation::query()->whereIn('bank_transaction_id', $ids)->delete();
            BankTransaction::query()->whereIn('id', $ids)->update(['transfer_pair_id' => null]);
            BankTransaction::query()->whereIn('id', $ids)->delete();
            $batch->update(['status' => 'reverted']);
            AuditLog::record('import.reverted', $batch, ['transactions' => $ids->count(), 'confirmed_decisions_removed' => $confirmed], null, $reason);
        });
    }

    /**
     * Decide which rows are new. Identical rows within one file are numbered (occurrence 1, 2, ...), so two genuine
     * £3.20 coffees on the same day both survive, but the same coffee in two overlapping statements is skipped.
     *
     * @param  list<NormalizedRow>  $rows
     * @return array{new: list<NormalizedRow>, occurrences: list<int>, duplicates:int}
     */
    private function plan(Account $account, array $rows): array
    {
        $counts = [];
        $new = [];
        $occurrences = [];
        $duplicates = 0;

        foreach ($rows as $row) {
            $fp = $row->fingerprint($account->id);
            $counts[$fp] = ($counts[$fp] ?? 0) + 1;
            $exists = BankTransaction::query()
                ->where('account_id', $account->id)->where('fingerprint', $fp)->where('occurrence', $counts[$fp])->exists();
            if ($exists) {
                $duplicates++;
            } else {
                $new[] = $row;
                $occurrences[] = $counts[$fp];
            }
        }

        return ['new' => $new, 'occurrences' => $occurrences, 'duplicates' => $duplicates];
    }
}
