<?php

namespace App\Domain\Charges;

use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Intel\Detectors\JobCloseout;
use App\Models\WorkJob;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The words about a cost: whether an invoice line may already hold it (it names the provider or merchant, or it's for
 * travel or expenses: JobCloseout's couldHold and forExpenses, made public and working for any cost), and how a cost
 * or a job is described on an invoice line, with nothing on it that shouldn't be: no card digits, bank references,
 * email addresses or postcodes, and never where you set off from (your home address never goes on an invoice).
 */
final class CostWords
{
    /** Words on an invoice line that say it may carry costs passed on to the client. */
    public const EXPENSE_WORDS = JobCloseout::EXPENSE_WORDS;

    /** What MorningReport::clean() puts in place of what it takes out, removed again here. */
    private const MASKS = '/\[(?:email|account|card|sort code|number|postcode|ref)\]/';

    /** A UK postcode in any case ("ls1 4ap", "LS14AP"). */
    private const POSTCODE = '/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i';

    /** Whether a line's words say it's for travel or expenses. */
    public static function forExpenses(string $text): bool
    {
        $text = mb_strtolower($text);
        foreach (self::EXPENSE_WORDS as $w) {
            if (preg_match('/\b'.preg_quote($w, '/').'/u', $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first of these lines that may hold a cost at another amount: one naming one of the cost's words (its
     * provider or merchant, at least 3 letters), or one for travel or expenses. Null when none could.
     *
     * @param  iterable<string|null>|string|null  $words
     * @param  iterable<object|array{description?:string}>  $lines  invoice lines (or anything with a description)
     */
    public static function couldHold(iterable|string|null $words, iterable $lines): ?string
    {
        $words = collect(is_iterable($words) ? $words : [$words])->map(fn ($w) => mb_strtolower(trim((string) $w)))->filter(fn ($w) => mb_strlen($w) >= 3)->values();
        foreach ($lines as $line) {
            $description = (string) (is_array($line) ? ($line['description'] ?? '') : ($line->description ?? ''));
            $text = mb_strtolower($description);
            if ($words->contains(fn (string $w) => str_contains($text, $w)) || self::forExpenses($text)) {
                return $description;
            }
        }

        return null;
    }

    /**
     * Text fit for an invoice: card digits, account numbers, bank references, email addresses and postcodes taken out
     * (MorningReport::clean), with what's left tidied.
     */
    public static function clean(?string $text): string
    {
        $text = MorningReport::clean((string) $text);
        $text = (string) preg_replace([self::MASKS, self::POSTCODE], '', $text);
        $text = (string) preg_replace(['/\s+([,.;:)])/', '/\(\s*\)/', '/([,;:])(?:\s*[,;:])+/', '/\s{2,}/'], ['$1', '', '$1', ' '], $text);

        return trim($text, " \t\n\r\0\x0B,;:-");
    }

    /** Where the job was, fit for an invoice: its location with any postcode taken out, or null. */
    public static function venue(WorkJob $job): ?string
    {
        $venue = self::clean($job->location);

        return $venue === '' ? null : $venue;
    }

    /** The job's dates as an invoice line says them: "3 Jun 2026", "3–5 Jun 2026", "30 Jun – 2 Jul 2026". */
    public static function dates(WorkJob $job): string
    {
        $dates = $job->workDates();
        $first = CarbonImmutable::parse($dates[0] ?? $job->starts_on);
        $last = CarbonImmutable::parse($dates === [] ? ($job->ends_on ?? $job->starts_on) : end($dates));

        return self::span($first, $last);
    }

    public static function span(CarbonImmutable $first, CarbonImmutable $last): string
    {
        if ($first->isSameDay($last)) {
            return $first->format('j M Y');
        }
        if ($first->isSameMonth($last)) {
            return $first->format('j').'–'.$last->format('j M Y');
        }
        if ($first->isSameYear($last)) {
            return $first->format('j M').' – '.$last->format('j M Y');
        }

        return $first->format('j M Y').' – '.$last->format('j M Y');
    }

    /**
     * A cost as an invoice line describes it: "Train: Trainline, London to Leeds, 12 Mar 2026". The caller adds how
     * it's charged ("(at cost)").
     */
    public static function cost(string $class, ?string $who, ?string $where, ?string $date): string
    {
        $parts = array_values(array_filter([self::clean($who), self::clean($where)], fn (string $p) => $p !== ''));
        if ($date !== null) {
            $parts[] = CarbonImmutable::parse($date)->format('j M Y');
        }

        return CostClass::label($class).($parts === [] ? '' : ': '.implode(', ', $parts));
    }

    /**
     * A mileage trip as an invoice line describes it: "Mileage 12 Mar: Leeds Arena and back, 142.5 miles at 45p".
     * Only the job's venue is named, never where the trip started or ended.
     */
    public static function mileage(string $date, ?string $venue, string $miles, ?int $rateMinor = null, string $currency = 'GBP'): string
    {
        return 'Mileage '.CarbonImmutable::parse($date)->format('j M').': '.($venue !== null && $venue !== '' ? $venue.' and back' : 'to the job and back')
            .', '.self::miles($miles).' miles'.($rateMinor !== null ? ' at '.self::rate($rateMinor, $currency) : '');
    }

    /** Miles as they're said: "142.5", "285". */
    public static function miles(string $miles): string
    {
        $text = (string) BigDecimal::of($miles)->stripTrailingZeros();

        return str_contains($text, '.') ? $text : number_format((int) $text);
    }

    /** A rate a mile (or a day): "45p", "£1.20". */
    public static function rate(int $minor, string $currency = 'GBP'): string
    {
        return $currency === 'GBP' && $minor < 100 ? $minor.'p' : Money::format($minor, $currency, false);
    }
}
