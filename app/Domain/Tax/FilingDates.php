<?php

namespace App\Domain\Tax;

use App\Models\TaxYear;
use App\Support\Months;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;

/**
 * The dates a Self Assessment return and its tax hang on, from what you've told Expend about HMRC's letters.
 *
 *  - Filing date (TMA 1970 s8(1D)-(1G)). Only a notice to file creates one; with no notice there is no late filing
 *    penalty, and the duty to tell HMRC by 5 October (s7) applies instead. A notice given by 31 July gives 31 October
 *    (paper) and 31 January (online). For a later notice HMRC's system uses the notice date + 3 months + 7 days
 *    (SAM121025): an estimate, because HMRC don't publish how it handles month ends. A deferral moves the date, and a
 *    deadline printed on HMRC's notice beats anything worked out here. An unfiled return is measured against the
 *    online date; a return delivered on paper against the paper date.
 *  - Balancing payment (s59B): 31 January, or, where you told HMRC by 5 October but the notice came after 31 October,
 *    the last day of the 3 months beginning with the notice date (s59B(3)). That date is the law, and late payment
 *    penalties run from it; HMRC's system starts interest from the notice date + 3 months + 7 days (SAM60050,
 *    SAM60052), so interest is worked out from that, as an estimate. Told HMRC by 5 October but no notice yet: the
 *    tax is due 31 January if the notice comes by 31 October, otherwise 3 months after it, so the date is an estimate.
 *  - Telling HMRC by 5 October (s7): needed only where there's no notice to file by then. Telling them in time meets
 *    the duty, so there's no failure-to-notify penalty (FA 2008 Sch 41), notice or not.
 *  - The last day you can file your own return (s34A): 5 April four years after the year, or 3 months from a notice
 *    received within those 4 years. If HMRC made a determination (s28C), that's replaced by the last day a return can
 *    replace it (s28C(5), s34A(2)(b)): the later of the last day of 3 years beginning with the filing date and 12
 *    months from the determination, which can be before or after the 4-year date. After that the determination stands.
 */
final class FilingDates
{
    /**
     * @return array{notice:string, notice_date:?CarbonImmutable, told_by_5_october:?bool, online:?CarbonImmutable, paper:?CarbonImmutable,
     *     filing_date:?CarbonImmutable, filing_basis:?string, late_notice:bool, balancing_due:CarbonImmutable, balancing_basis:string,
     *     interest_from:CarbonImmutable, s59b3_date:?CarbonImmutable, notify_by:CarbonImmutable, missed_notify:bool,
     *     own_return_last:CarbonImmutable, own_return_basis:string, s34a_last:CarbonImmutable, determination_last:?CarbonImmutable,
     *     determination_on:?CarbonImmutable, notes:list<string>}
     */
    public static function for(string $label, ?TaxYear $record = null, ?bool $filed = null): array
    {
        $period = UkTaxYear::fromLabel($label);
        $record ??= TaxYear::query()->where('label', $period->label())->first();
        $filed ??= $record !== null && ($record->filed_on !== null || in_array($record->status, ['filed', 'amending'], true));
        $y = $period->startYear;
        $date = fn ($d) => $d ? CarbonImmutable::parse($d->format('Y-m-d')) : null;

        $notice = match ($record?->notice_to_file) {
            'given' => 'given',
            'not_given' => 'not_given',
            default => 'assumed',
        };
        $noticeDate = $notice === 'not_given' ? null : $date($record?->notice_date);
        $told = $record?->told_hmrc_by_5_october;
        $july31 = CarbonImmutable::create($y + 1, 7, 31);
        $oct5 = CarbonImmutable::create($y + 1, 10, 5);
        $oct31 = CarbonImmutable::create($y + 1, 10, 31);
        $jan31 = CarbonImmutable::create($y + 2, 1, 31);
        $notes = [];
        $fmt = fn (CarbonImmutable $d) => $d->format('j F Y');

        $online = $paper = $filingDate = null;
        $basis = null;
        $lateNotice = false;
        if ($notice !== 'not_given') {
            $online = $jan31;
            $paper = $oct31;
            $basis = 'law';
            if ($noticeDate && $noticeDate->gt($july31)) {
                // HMRC's system: notice date + 3 months + 7 days for printing and post (SAM121025, SAM120520).
                $lateNotice = true;
                $x = Months::add($noticeDate, 3)->addDays(7);
                $paper = $x;
                $online = $x->gt($jan31) ? $x : $jan31;
                $basis = 'estimate';
                $notes[] = 'HMRC’s notice to file came after 31 July ('.$fmt($noticeDate).'), so the deadline is worked out the way HMRC’s system does it (the notice date plus 3 months and 7 days): '
                    .$fmt($online).' online'.($paper->eq($online) ? ' or on paper' : ', '.$fmt($paper).' on paper').'. That is an estimate: the date printed on HMRC’s notice is the one that counts.';
            }
            $deferred = $date($record?->deferred_to);
            if ($deferred) {
                // HMRC deferred the deadline: the paper date moves, and the online date never falls before it.
                $paper = $deferred;
                $online = $deferred->gt($online) ? $deferred : $online;
                $basis = 'deferred';
            }
            $filingDate = $filed && $record?->filed_method === 'paper' ? $paper : $online;
            $override = $date($record?->filing_date_override);
            if ($override) {
                $filingDate = $override;
                $basis = 'notice';
            }
        }

        // The balancing payment: 31 January, unless s59B(3) applies (told HMRC by 5 October, notice after 31 October).
        // Interest starts from HMRC's own date for it, the notice date + 3 months + 7 days (an estimate).
        $due = $jan31;
        $dueBasis = 'law';
        $interestFrom = $jan31;
        $s59b3 = null;
        if ($noticeDate && $noticeDate->gt($oct31)) {
            $s59b3 = Months::periodEnd($noticeDate, 3);
            if ($told === true) {
                $due = $s59b3;
                $rdd = Months::add($noticeDate, 3)->addDays(7);
                $interestFrom = $rdd->gt($s59b3) ? $rdd : $s59b3;
                $notes[] = 'You told HMRC by 5 October '.($y + 1).' and their notice to file came after 31 October, so the tax was due on '.$fmt($s59b3)
                    .' (3 months from the notice). Interest is worked out from '.$fmt($interestFrom).', the date HMRC’s system uses (the notice date plus 3 months and 7 days): '
                    .'an estimate, since the law would let HMRC charge it from '.$fmt($s59b3).'. Late payment penalties run from '.$fmt($s59b3).'.';
            } elseif ($told === null) {
                $dueBasis = 'assumed';
                $notes[] = 'Assumes you told HMRC you needed to file after 5 October '.($y + 1).', so the tax was still due on 31 January '.($y + 2).'. If you told them by 5 October, it’s due on '
                    .$fmt($s59b3).' instead: say so on this page.';
            }
        } elseif ($notice === 'not_given' && $told === true) {
            // Told HMRC in time, but no notice yet: due 31 January if it comes by 31 October, else 3 months after it.
            $dueBasis = 'estimate';
            $today = CarbonImmutable::today();
            if ($today->gt($oct31)) {
                $due = Months::periodEnd($today, 3);
                $due = $due->gt($jan31) ? $due : $jan31;
                $interestFrom = Months::add($today, 3)->addDays(7)->max($jan31);
                $notes[] = 'You told HMRC by 5 October '.($y + 1).' that you need to file, but no notice to file has come. As it will come after 31 October, the tax is due 3 months after it: '
                    .'taken as '.$fmt($due).', as if it came today. That’s an estimate; record the notice’s date on this page when it comes.';
            } else {
                $notes[] = 'You told HMRC by 5 October '.($y + 1).' that you need to file, but no notice to file has come yet. If it comes by 31 October '.($y + 1).', the tax is due on 31 January '
                    .($y + 2).'; if it comes later, 3 months after it. Record the notice’s date on this page when it comes.';
            }
        }

        // s7: telling HMRC by 5 October meets the duty. A notice that arrives after 5 October doesn't undo not doing so.
        $missedNotify = $told !== true && ($notice === 'not_given' || ($noticeDate !== null && $noticeDate->gt($oct5)));

        // s34A: your own return, if HMRC made no determination. A notice within the 4 years gives 3 months from it.
        $fourYears = CarbonImmutable::create($y + 5, 4, 5);
        $s34aLast = $fourYears;
        if ($noticeDate && $noticeDate->lte($fourYears)) {
            $fromNotice = Months::periodEnd($noticeDate, 3);
            $s34aLast = $fromNotice->gt($s34aLast) ? $fromNotice : $s34aLast;
        }
        // s28C(5)-(6): a determination can only be replaced by the end of 3 years beginning with the filing date (31
        // January, or the end of 3 months from a notice given after 31 October), or 12 months from the determination.
        $determinationLast = null;
        $determinationOn = $date($record?->determination_on);
        if ($notice !== 'not_given') {
            $s28cFiling = $noticeDate && $noticeDate->gt($oct31) ? Months::periodEnd($noticeDate, 3) : $jan31;
            $determinationLast = Months::periodEnd($s28cFiling, 36);
            // Where you've said when HMRC made it, 12 months from the determination can be later.
            if ($determinationOn && Months::periodEnd($determinationOn, 12)->gt($determinationLast)) {
                $determinationLast = Months::periodEnd($determinationOn, 12);
            }
        }
        // Once there's a determination, its window, not the 4-year date, is the last day for your own return (s34A(2)(b)).
        [$ownLast, $ownBasis] = $determinationOn && $determinationLast ? [$determinationLast, 'determination'] : [$s34aLast, 's34a'];

        return [
            'notice' => $notice, 'notice_date' => $noticeDate, 'told_by_5_october' => $told,
            'online' => $online, 'paper' => $paper, 'filing_date' => $filingDate, 'filing_basis' => $basis, 'late_notice' => $lateNotice,
            'balancing_due' => $due, 'balancing_basis' => $dueBasis, 'interest_from' => $interestFrom, 's59b3_date' => $s59b3,
            'notify_by' => $oct5, 'missed_notify' => $missedNotify,
            'own_return_last' => $ownLast, 'own_return_basis' => $ownBasis, 's34a_last' => $s34aLast,
            'determination_last' => $determinationLast, 'determination_on' => $determinationOn,
            'notes' => $notes,
        ];
    }

    /** The filing date that applies (null when HMRC sent no notice to file), for pages and reminders. */
    public static function deadline(string $label, ?TaxYear $record = null): ?CarbonImmutable
    {
        return self::for($label, $record)['filing_date'];
    }

    /**
     * The last day to amend a filed return: 12 months after its filing date (TMA 1970 s9ZA(2)), so 31 October for a
     * paper return filed under a notice given by 31 July, and later for a late notice. 29 February + 12 months is 28
     * February.
     */
    public static function amendBy(string $label, ?TaxYear $record = null): CarbonImmutable
    {
        $filing = self::for($label, $record, true)['filing_date'] ?? UkTaxYear::fromLabel($label)->filingDeadline();

        return Months::add(CarbonImmutable::parse($filing->format('Y-m-d')), 12);
    }
}
