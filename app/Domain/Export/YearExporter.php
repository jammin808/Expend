<?php

namespace App\Domain\Export;

use App\Domain\Tax\MileageCalculator;
use App\Domain\Tax\ReturnSummary;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\ManualExpense;
use App\Support\Money;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A spreadsheet of one tax year: the return figures, every transaction with its decision, and mileage.
 * Doubles as the fallback for filing if the app isn't finished, and as the records pack for HMRC enquiries.
 */
final class YearExporter
{
    public static function write(string $label, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $summary = ReturnSummary::for($label);
        $writer->getCurrentSheet()->setName('Return figures');
        $writer->addRow(Row::fromValues(["Self-employment figures for {$label}", '']));
        $writer->addRow(Row::fromValues(['Turnover', self::pounds($summary['turnover_pence'])]));
        $writer->addRow(Row::fromValues(['Other business income', self::pounds($summary['other_income_pence'])]));
        foreach ($summary['expense_lines'] as $line) {
            $writer->addRow(Row::fromValues(["Box {$line['box']}: {$line['name']}", self::pounds($line['allowable_pence']), 'Disallowable', self::pounds($line['disallowable_pence'])]));
        }
        $writer->addRow(Row::fromValues(['Total allowable expenses', self::pounds($summary['allowable_expenses_pence'])]));
        $writer->addRow(Row::fromValues(['Net profit', self::pounds($summary['net_profit_pence'])]));
        $writer->addRow(Row::fromValues(['Still to decide (not counted)', $summary['pending']['unreviewed_count'] + $summary['pending']['suggested_count']]));

        $writer->addNewSheetAndMakeItCurrent()->setName('Transactions');
        // Each line as the ledger shows it: when, which account and card, where, the currency it was paid in and the rate.
        $writer->addRow(Row::fromValues(['Date', 'Time', 'Account', 'Card', 'Payment type', 'Place', 'Description', 'Amount', 'Original amount', 'Currency',
            'Rate (per £1)', 'Rate used', 'Paid amount', 'Paid currency', 'Decision', 'Category', 'Job', 'Line amount', 'Business %', 'Status', 'Decided by', 'Note']));

        BankTransaction::query()->where('tax_year', $label)->with(['account', 'allocations.category', 'allocations.job'])
            ->orderBy('posted_on')->orderBy('id')->chunk(500, function ($txs) use ($writer) {
                foreach ($txs as $tx) {
                    $base = [
                        $tx->effectiveDate()->format('Y-m-d'), $tx->transacted_time ? substr((string) $tx->transacted_time, 0, 5) : '', $tx->account->name,
                        $tx->card_last4 ? '•••• '.$tx->card_last4 : '', self::safe((string) $tx->payment_type), self::safe((string) $tx->place),
                        self::safe($tx->description), self::pounds($tx->amount_pence), ...self::original($tx->original_amount_minor, $tx->original_currency, $tx->amount_pence,
                            $tx->fx_units_per_gbp, $tx->fx_source),
                        // The currency the shop charged, where it isn't the account's own ($90.00 from the euro account).
                        $tx->merchant_currency && $tx->merchant_amount_minor !== null ? Money::major((int) $tx->merchant_amount_minor, $tx->merchant_currency) : '',
                        $tx->merchant_currency && $tx->merchant_amount_minor !== null ? $tx->merchant_currency : '',
                    ];
                    if ($tx->allocations->isEmpty()) {
                        $writer->addRow(Row::fromValues([...$base, 'Undecided', '', '', '', '', 'unreviewed', '', '']));
                    }
                    foreach ($tx->allocations as $a) {
                        $writer->addRow(Row::fromValues([...$base, ...self::allocationCells($a)]));
                    }
                }
            });

        ManualExpense::query()->where('tax_year', $label)->with(['allocations.category', 'allocations.job'])->orderBy('spent_on')->each(function (ManualExpense $e) use ($writer) {
            $base = [$e->spent_on->format('Y-m-d'), '', 'Manual: '.$e->paid_with, '', '', '', self::safe($e->description), self::pounds($e->amount_pence),
                ...self::original($e->original_amount_minor, $e->original_currency, $e->amount_pence, $e->fx_units_per_gbp, $e->fx_source), '', ''];
            foreach ($e->allocations as $a) {
                $writer->addRow(Row::fromValues([...$base, ...self::allocationCells($a)]));
            }
        });

        $mileage = MileageCalculator::forYear($label);
        $writer->addNewSheetAndMakeItCurrent()->setName('Mileage');
        $writer->addRow(Row::fromValues(['Date', 'Vehicle', 'From', 'To', 'Miles', 'Job', 'Purpose', 'Claim', 'Rate']));
        foreach ($mileage['trips'] as $trip) {
            $writer->addRow(Row::fromValues([$trip['trip_on'], $trip['vehicle'], self::safe($trip['from_place']), self::safe($trip['to_place']),
                (float) $trip['miles'], $trip['job']['title'] ?? 'General business', self::safe((string) $trip['purpose']), self::pounds($trip['claim_pence']), $trip['note']]));
        }

        $writer->close();
    }

    private static function allocationCells(Allocation $a): array
    {
        return [
            Allocation::KINDS[$a->kind] ?? $a->kind, $a->category?->name ?? '', $a->job ? self::safe($a->job->title) : '',
            self::pounds($a->amount_pence), $a->business_use_pct ?? '', $a->confirmed_at ? 'confirmed' : 'suggested',
            $a->decided_by, self::safe((string) $a->note),
        ];
    }

    private const RATE_SOURCES = [
        'hmrc_monthly' => 'HMRC monthly rate', 'manual' => 'Your rate', 'exchange' => 'The exchange it came from',
        'document' => 'The document’s sterling figure', 'bank' => 'The bank’s rate',
    ];

    /**
     * The amount in the currency it was paid in (in that currency's own units: ¥1,500 is 1500), the currency, and the
     * rate to the pound with where it came from. With no rate stored, the bank's own: what it charged in pounds.
     *
     * @return array{0:float|string, 1:string, 2:float|string, 3:string}
     */
    private static function original(?int $minor, ?string $currency, int $pence, mixed $rate, ?string $source): array
    {
        if ($minor === null || ! $currency) {
            return ['', $currency ?? '', '', ''];
        }
        $amount = Money::major($minor, $currency);
        if (strtoupper($currency) === 'GBP') {
            return [$amount, $currency, '', ''];
        }
        if ($rate === null || (float) $rate <= 0) {
            $rate = $pence !== 0 && $minor !== 0 ? round(abs($amount) / (abs($pence) / 100), 6) : null;
            // Where the sterling came from stays as stored (a pocket's exchange); only a line with none is the bank's.
            $source = $rate !== null ? ($source ?: 'bank') : $source;
        }

        return [$amount, $currency, $rate !== null ? (float) $rate : '', $source ? (self::RATE_SOURCES[$source] ?? $source) : ''];
    }

    private static function pounds(int $pence): float
    {
        return round($pence / 100, 2);
    }

    /** Stop spreadsheet formula injection from bank descriptions. */
    public static function safe(string $text): string
    {
        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }
}
