<?php

namespace App\Domain\Quick;

use App\Domain\Audit\AuditLog;
use App\Domain\Fx\RateUnavailable;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Ledger\ManualExpenses;
use App\Domain\Todo\TodoList;
use App\Models\Document;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * A receipt photo from the phone, read with AI, checked against what you typed (recorded as the verification "checked
 * against what you typed"). The AI isn't told your figures, so it can't simply agree with them; and a photo has no
 * text to check its figures against, so nothing changes without your answer:
 *
 *  - the same amount and currency: "The photo agrees";
 *  - another amount: "The photo looks like £15.20; you typed £12.50" (quick.photo_differs), Use or Keep;
 *  - dates more than a day apart, or the same figure in another currency: the same, for the date or the currency;
 *  - no receipt in it: "Expend couldn't see a receipt in this photo: take it again if it's blurred".
 *
 * Your answer is kept (read_check.answered) and never asked again for that read. The merchant read from the photo
 * fills in What when you only tapped a chip (never what you typed).
 */
final class QuickReadCheck
{
    /** Dates further apart than this are asked about. */
    public const DATE_DAYS = 1;

    public function __construct(private ManualExpenses $expenses, private ReceiptAttacher $attacher) {}

    /**
     * After a document is read (ReadDocument), or when the phone sends a photo Expend had already read: compare its
     * receipt with the entry it belongs to and keep the result.
     */
    public function afterRead(Document $document): ?QuickEntry
    {
        $document->unsetRelation('quickOwner');
        $entry = ReceiptMatch::owner($document);
        if (! $entry || in_array($entry->status, ['withdrawn', 'dismissed', 'duplicate', 'receiving'], true) || $document->extracted_at === null && $document->extraction === null) {
            return null;
        }
        $r = $document->extraction['receipt'] ?? null;
        $check = self::compare($entry, is_array($r) ? $r : null, (array) (($entry->read_check ?? [])['answered'] ?? []));
        $fill = ['read_check' => $check];
        $named = is_array($r) && trim((string) ($r['merchant'] ?? '')) !== '' && in_array($entry->what_source, [null, 'chip'], true);

        $row = DB::transaction(function () use ($entry, $fill, $named, $r) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $before = ['read_check' => $row->read_check, 'what' => $row->what, 'what_source' => $row->what_source];
            $row->forceFill($fill);
            if ($named && in_array($row->what_source, [null, 'chip'], true)) {
                $row->forceFill(['what' => mb_substr(trim((string) $r['merchant']), 0, 500), 'what_source' => 'photo']);
            }
            if (! $row->isDirty()) {
                return $row;
            }
            $row->save();
            AuditLog::record('quick_entry.read_checked', $row, $before, ['read_check' => $row->read_check, 'what' => $row->what, 'what_source' => $row->what_source],
                'Checked against what you typed', 'checker');

            return $row;
        });
        // The name read from the photo goes on the expense too (its description and merchant), never over what you typed.
        if ($named && $row->what_source === 'photo' && $row->manual_expense_id && ($expense = ManualExpense::query()->find($row->manual_expense_id))) {
            try {
                $this->expenses->correct($expense, ['description' => (string) $row->what], 'system', 'Name read from your photo');
            } catch (Throwable $e) {
                report($e); // a locked year: left as it was
            }
        }
        // A card receipt sent with no amount: matched on what was read.
        if ($row->amount_minor === null && $row->isWaiting()) {
            $this->attacher->forEntry($row);
        }
        TodoList::forget();

        return $row->fresh();
    }

    /**
     * The read receipt against the entry.
     *
     * @param  array<string, mixed>|null  $r  the extraction's receipt
     * @param  array<string, mixed>  $answered  your answers so far
     * @return array<string, mixed>
     */
    public static function compare(QuickEntry $entry, ?array $r, array $answered = []): array
    {
        if ($r === null || ! is_int($r['amount_minor'] ?? null)) {
            return ['status' => 'not_receipt', 'note' => 'Expend couldn’t see a receipt in this photo: take it again if it’s blurred.', 'answered' => $answered, 'at' => now()->toIso8601String()];
        }
        $readCurrency = strtoupper((string) ($r['currency'] ?? 'GBP')) ?: 'GBP';
        $read = ['hundredths' => (int) $r['amount_minor'], 'currency' => $readCurrency, 'date' => $r['date'] ?? null, 'merchant' => $r['merchant'] ?? null];
        $differs = [];
        $mine = $entry->hundredths();
        if ($mine !== null) {
            $currency = $entry->currency ?: 'GBP';
            if ($read['hundredths'] !== $mine) {
                $differs['amount'] = ['read' => $read['hundredths'], 'currency' => $readCurrency, 'mine' => $mine, 'mine_currency' => $currency];
            } elseif ($readCurrency !== $currency) {
                $differs['currency'] = ['read' => $readCurrency, 'mine' => $currency, 'hundredths' => $mine];
            }
        }
        if ($read['date'] && $entry->spent_on) {
            try {
                $on = CarbonImmutable::parse($read['date'])->startOfDay();
                if (abs($on->diffInDays(CarbonImmutable::parse($entry->spent_on->format('Y-m-d')))) > self::DATE_DAYS) {
                    $differs['date'] = ['read' => $on->format('Y-m-d'), 'mine' => $entry->spent_on->format('Y-m-d')];
                }
            } catch (Throwable) {
                // a date that can't be read isn't compared
            }
        }
        // An answer you gave for this same read stands.
        foreach ($differs as $field => $d) {
            if (($answered[$field]['read'] ?? null) === $d['read']) {
                unset($differs[$field]);
            }
        }

        return ['status' => $differs === [] ? ($mine === null ? 'read' : 'agrees') : 'differs', 'read' => $read, 'differs' => $differs, 'answered' => $answered,
            'at' => now()->toIso8601String()];
    }

    /** "The photo looks like £15.20; you typed £12.50" for each thing that differs. @return list<array{field:string, text:string, use:string, keep:string}> */
    public static function questions(QuickEntry $e): array
    {
        $out = [];
        foreach ((array) (($e->read_check ?? [])['differs'] ?? []) as $field => $d) {
            $out[] = match ($field) {
                'amount' => ['field' => 'amount', 'text' => 'The photo looks like '.Money::format($d['read'], $d['currency'], false).'; you typed '.Money::format($d['mine'], $d['mine_currency'], false),
                    'use' => 'Use '.Money::format($d['read'], $d['currency'], false), 'keep' => 'Keep '.Money::format($d['mine'], $d['mine_currency'], false)],
                'currency' => ['field' => 'currency', 'text' => 'The photo looks like '.Money::format($d['hundredths'], $d['read'], false).'; you typed '.Money::format($d['hundredths'], $d['mine'], false),
                    'use' => 'It was '.Money::format($d['hundredths'], $d['read'], false), 'keep' => 'Keep '.Money::format($d['hundredths'], $d['mine'], false)],
                'date' => ['field' => 'date', 'text' => 'The photo looks like it’s from '.CarbonImmutable::parse($d['read'])->format('j M').'; you said '.CarbonImmutable::parse($d['mine'])->format('j M'),
                    'use' => 'Use '.CarbonImmutable::parse($d['read'])->format('j M'), 'keep' => 'Keep '.CarbonImmutable::parse($d['mine'])->format('j M')],
                default => ['field' => $field, 'text' => 'The photo differs', 'use' => 'Use the photo’s', 'keep' => 'Keep mine'],
            };
        }

        return $out;
    }

    /**
     * Your answer: use what the photo says, or keep what you typed, for one thing that differs (or all of them).
     * "Use" on a cash or personal expense corrects it (ManualExpenses::correct: its year, sterling and one line as
     * yours); on a card receipt it changes the receipt's figures and matches it again. "Keep" is remembered.
     *
     * @throws InvalidArgumentException a locked year, a split expense, or no rate
     */
    public function answer(QuickEntry $entry, string $choice, ?string $field = null): string
    {
        $entry->refresh();
        $differs = (array) (($entry->read_check ?? [])['differs'] ?? []);
        $fields = $field !== null ? array_intersect([$field], array_keys($differs)) : array_keys($differs);
        if ($fields === []) {
            throw (new ModelNotFoundException)->setModel(QuickEntry::class, [$entry->id]); // answered already
        }
        $changes = [];
        if ($choice === 'use') {
            foreach ($fields as $f) {
                $d = $differs[$f];
                if ($f === 'amount') {
                    $changes['currency'] = $d['currency'];
                    $changes['amount_minor'] = (int) Money::fromHundredths((int) $d['read'], $d['currency']);
                } elseif ($f === 'currency') {
                    $changes['currency'] = $d['read'];
                    $changes['amount_minor'] = (int) Money::fromHundredths((int) $d['hundredths'], $d['read']);
                } elseif ($f === 'date') {
                    $changes['spent_on'] = $d['read'];
                }
            }
            if ($entry->status === 'added' && $entry->manual_expense_id && ($expense = ManualExpense::query()->find($entry->manual_expense_id))) {
                try {
                    $this->expenses->correct($expense, $changes + (isset($changes['currency']) || isset($changes['amount_minor']) ? ['sterling_pence' => null] : []), 'you', 'Used what the photo says');
                } catch (RateUnavailable $e) {
                    throw new InvalidArgumentException($e->getMessage());
                }
            }
        }
        DB::transaction(function () use ($entry, $choice, $fields, $differs, $changes) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $check = $row->read_check ?? [];
            $before = ['read_check' => $check] + QuickEntries::snapshot($row);
            foreach ($fields as $f) {
                $check['answered'][$f] = ['answer' => $choice, 'read' => $differs[$f]['read'], 'at' => now()->toIso8601String()];
                unset($check['differs'][$f]);
            }
            if (($check['differs'] ?? []) === []) {
                $check['status'] = 'answered';
            }
            $fill = ['read_check' => $check];
            if ($choice === 'use') {
                if (isset($changes['amount_minor'])) {
                    $fill['amount_minor'] = $changes['amount_minor'];
                    $fill['currency'] = $changes['currency'];
                    $fill['sterling_pence'] = null;
                }
                if (isset($changes['spent_on'])) {
                    $fill['spent_on'] = $changes['spent_on'];
                    $fill['tax_year'] = UkTaxYear::fromDate($changes['spent_on'])->label();
                }
            }
            $row->forceFill($fill)->save();
            AuditLog::record('quick_entry.read_answered', $row, $before, ['read_check' => $row->read_check] + QuickEntries::snapshot($row),
                $choice === 'use' ? 'Used what the photo says' : 'Kept what you typed');
        });
        if ($choice === 'use' && $entry->paid_with === 'business') {
            $this->attacher->refit($entry); // on a line it no longer fits, or waiting: matched again
        }
        TodoList::forget();

        return $choice === 'use' ? 'Changed to what the photo says.' : 'Kept what you typed.';
    }
}
