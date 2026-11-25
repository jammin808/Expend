<?php

namespace Tests\Feature;

use App\Domain\Jobs\JobDays;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The calendar's day picker as the phone runs it (resources/js/lib/day-pick.ts, in node): the month grid, tapping,
 * filling a gap, picking an entry's days and leaving them out again, the 120-day limit, the words in the bar, which
 * entries are ticked for you, the small-hours load-out that belongs to the evening before, and keeping the pick on the
 * phone for a day. Where the server decides the same thing (the kind of a day, the later of two finishes), the page
 * must agree with it.
 */
class CalendarPickCodeTest extends TestCase
{
    public function test_the_month_grid_starts_on_monday_and_a_clock_change_moves_no_day(): void
    {
        $out = $this->node(<<<'JS'
            const cells = (m) => pick.monthCells(m);
            const weekdays = (m) => cells(m).filter((c, i) => i % 7 === 0).map((c) => new Date(c.date + 'T12:00:00Z').getUTCDay());
            const steps = (m) => cells(m).slice(1).every((c, i) => pick.addDays(cells(m)[i].date, 1) === c.date);
            const inMonth = (m) => cells(m).filter((c) => c.inMonth).map((c) => c.day);
            out({
                march: { first: cells('2026-03')[0].date, last: cells('2026-03').at(-1).date, mondays: weekdays('2026-03'), steps: steps('2026-03'), days: inMonth('2026-03').length, length: cells('2026-03').length },
                october: { first: cells('2026-10')[0].date, last: cells('2026-10').at(-1).date, mondays: weekdays('2026-10'), steps: steps('2026-10'), days: inMonth('2026-10').length },
                clock: [pick.addDays('2026-03-28', 1), pick.addDays('2026-03-29', 1), pick.addDays('2026-10-24', 1), pick.addDays('2026-10-25', 1)],
                weekday: [pick.weekday('2026-03-29'), pick.weekday('2026-03-30'), pick.weekday('2026-10-25'), pick.weekday('2026-10-26')],
                between: pick.daysBetween('2026-03-01', '2026-04-01'),
                february: cells('2027-02').length,
            });
            JS);

        // 1 March 2026 is a Sunday: the grid starts on Monday 23 February and ends on Sunday 5 April.
        $this->assertSame(['first' => '2026-02-23', 'last' => '2026-04-05', 'mondays' => [1, 1, 1, 1, 1, 1], 'steps' => true, 'days' => 31, 'length' => 42], $out['march']);
        $this->assertSame(['first' => '2026-09-28', 'last' => '2026-11-01', 'mondays' => [1, 1, 1, 1, 1], 'steps' => true, 'days' => 31], $out['october']);
        // The clocks go forward on 29 March and back on 25 October 2026: every day is still one day.
        $this->assertSame(['2026-03-29', '2026-03-30', '2026-10-25', '2026-10-26'], $out['clock']);
        $this->assertSame([6, 0, 6, 0], $out['weekday']);
        $this->assertSame(31, $out['between']);
        $this->assertSame(28, $out['february'], 'February 2027 starts on a Monday and is four whole weeks');

        // The same in another time zone: the grid doesn't depend on the phone's clock.
        $this->assertSame($out, $this->node(<<<'JS'
            const cells = (m) => pick.monthCells(m);
            const weekdays = (m) => cells(m).filter((c, i) => i % 7 === 0).map((c) => new Date(c.date + 'T12:00:00Z').getUTCDay());
            const steps = (m) => cells(m).slice(1).every((c, i) => pick.addDays(cells(m)[i].date, 1) === c.date);
            const inMonth = (m) => cells(m).filter((c) => c.inMonth).map((c) => c.day);
            out({
                march: { first: cells('2026-03')[0].date, last: cells('2026-03').at(-1).date, mondays: weekdays('2026-03'), steps: steps('2026-03'), days: inMonth('2026-03').length, length: cells('2026-03').length },
                october: { first: cells('2026-10')[0].date, last: cells('2026-10').at(-1).date, mondays: weekdays('2026-10'), steps: steps('2026-10'), days: inMonth('2026-10').length },
                clock: [pick.addDays('2026-03-28', 1), pick.addDays('2026-03-29', 1), pick.addDays('2026-10-24', 1), pick.addDays('2026-10-25', 1)],
                weekday: [pick.weekday('2026-03-29'), pick.weekday('2026-03-30'), pick.weekday('2026-10-25'), pick.weekday('2026-10-26')],
                between: pick.daysBetween('2026-03-01', '2026-04-01'),
                february: cells('2027-02').length,
            });
            JS, 'Pacific/Auckland'));
    }

    public function test_a_tap_picks_a_day_and_a_second_tap_drops_it(): void
    {
        $out = $this->node(<<<'JS'
            let s = pick.emptySelection();
            const a = pick.toggle(s, '2025-11-03');
            const b = pick.toggle(a.sel, '2025-11-05');
            const c = pick.toggle(b.sel, '2025-11-03');
            out({
                a: [a.did, pick.pickedDates(a.sel), a.sel.dates['2025-11-03'], a.sel.last],
                b: [b.did, pick.pickedDates(b.sel), b.sel.last, b.sel.prev],
                c: [c.did, pick.pickedDates(c.sel), c.sel.last, c.sel.prev],
                untouched: pick.pickedDates(s),
            });
            JS);

        $this->assertSame(['picked', ['2025-11-03'], ['tap'], '2025-11-03'], $out['a']);
        $this->assertSame(['picked', ['2025-11-03', '2025-11-05'], '2025-11-05', '2025-11-03'], $out['b']);
        $this->assertSame(['dropped', ['2025-11-05'], '2025-11-03', '2025-11-05'], $out['c']);
        $this->assertSame([], $out['untouched'], 'A tap makes a new pick; the old one is unchanged');
    }

    public function test_fill_offers_the_gap_and_fills_it_across_two_months(): void
    {
        $out = $this->node(<<<'JS'
            const tap = (s, d) => pick.toggle(s, d).sel;
            let s = tap(tap(pick.emptySelection(), '2025-10-30'), '2025-11-03');
            const offer = pick.fillOffer(s);
            const filled = pick.fill(s, offer.from, offer.to);
            // Tapped 1, then 10, then 5: the gap on the side of the tap before (6 to 9), not the smaller one.
            const side = tap(tap(tap(pick.emptySelection(), '2025-11-01'), '2025-11-10'), '2025-11-05');
            // With no tap before on either side, the smaller gap.
            const smaller = { ...side, prev: null };
            // Dropping a day offers nothing until the next tap.
            const dropped = tap(s, '2025-11-03');
            out({
                offer, filled: pick.pickedDates(filled), origins: filled.dates['2025-11-01'], after: pick.fillOffer({ ...filled, last: null }),
                side: pick.fillOffer(side), smaller: pick.fillOffer(smaller), dropped: pick.fillOffer(dropped),
                words: pick.summary(pick.fillDates(offer), { year: 2025 }),
            });
            JS);

        $this->assertSame(['from' => '2025-10-31', 'to' => '2025-11-02', 'count' => 3], $out['offer']);
        $this->assertSame(['2025-10-30', '2025-10-31', '2025-11-01', '2025-11-02', '2025-11-03'], $out['filled']);
        $this->assertSame(['fill'], $out['origins']);
        $this->assertNull($out['after']);
        $this->assertSame(['from' => '2025-11-06', 'to' => '2025-11-09', 'count' => 4], $out['side']);
        $this->assertSame(['from' => '2025-11-02', 'to' => '2025-11-04', 'count' => 3], $out['smaller']);
        $this->assertNull($out['dropped']);
        $this->assertSame('Fri 31 Oct–Sun 2 Nov', $out['words']);
    }

    public function test_no_fill_is_offered_for_a_gap_over_62_days_and_no_pick_holds_more_than_120_days(): void
    {
        $out = $this->node(<<<'JS'
            const tap = (s, d) => pick.toggle(s, d).sel;
            const gap62 = pick.fillOffer(tap(tap(pick.emptySelection(), '2026-01-01'), '2026-03-05'));
            const gap63 = pick.fillOffer(tap(tap(pick.emptySelection(), '2026-01-01'), '2026-03-06'));
            let s = pick.emptySelection();
            for (let i = 0; i < 120; i++) s = tap(s, pick.addDays('2026-01-01', i * 2));
            const refused = pick.toggle(s, '2026-01-02');
            const filled = pick.fill(s, '2026-01-02', '2026-01-02');
            const offer = pick.fillOffer(tap(s, '2026-01-01'));
            const festival = { key: 'f'.repeat(40), date: '2026-01-01', last_day: '2026-12-31', summary: 'Panto season' };
            const big = pick.pickEntry(pick.emptySelection(), festival);
            out({ gap62, gap63, n: pick.count(s), refused: [refused.did, pick.count(refused.sel), refused.sel === s], filled: pick.count(filled), offer,
                big: [big.picked, big.total, big.capped, pick.count(big.sel)], cap: pick.CAP, longest: pick.FILL_MAX });
            JS);

        $this->assertSame(['from' => '2026-01-02', 'to' => '2026-03-04', 'count' => 62], $out['gap62']);
        $this->assertNull($out['gap63']);
        $this->assertSame(120, $out['n']);
        $this->assertSame(['refused', 120, true], $out['refused'], 'The 121st day is refused and the pick is left as it was');
        $this->assertSame(120, $out['filled']);
        $this->assertNull($out['offer'], 'No Fill that would go past 120 days');
        $this->assertSame([120, 365, true, 120], $out['big'], 'An entry longer than the limit picks its first 120 days and says so');
        $this->assertSame([120, 62], [$out['cap'], $out['longest']]);
    }

    public function test_the_bar_says_which_days_in_a_few_words(): void
    {
        $out = $this->node(<<<'JS'
            out([
                pick.summary(['2025-11-05', '2025-11-03', '2025-11-04', '2025-11-07'], { year: 2025 }),
                pick.summary(['2025-10-31', '2025-11-01', '2025-11-02', '2025-11-03'], { year: 2025 }),
                pick.summary(['2025-11-03', '2025-11-05', '2025-11-07', '2025-11-10', '2025-11-11'], { year: 2025 }),
                pick.summary(['2025-11-03', '2025-11-05', '2025-11-07', '2025-11-10'], { year: 2025 }),
                pick.summary(['2025-12-29', '2025-12-30', '2025-12-31', '2026-01-01', '2026-01-02'], { year: 2026 }),
                pick.summary(['2025-10-28', '2025-11-03'], { year: 2025 }),
                pick.summary([]),
                pick.plural(1, 'day'), pick.plural(3, 'entry', 'entries'),
            ]);
            JS);

        $this->assertSame([
            'Mon 3–Wed 5, Fri 7 Nov',
            'Fri 31 Oct–Mon 3 Nov',
            'Mon 3, Wed 5, Fri 7 Nov (+2 more days)',
            'Mon 3, Wed 5, Fri 7 Nov (+1 more day)',
            'Mon 29 Dec 2025–Fri 2 Jan',
            'Tue 28 Oct, Mon 3 Nov',
            '',
            '1 day', '3 entries',
        ], $out);
    }

    public function test_leaving_an_entry_out_drops_only_the_days_picked_through_it(): void
    {
        $out = $this->node(<<<'JS'
            const festival = { key: 'a'.repeat(40), date: '2025-08-10', last_day: '2025-08-16', summary: 'Big Festival', all_day: true };
            let s = pick.toggle(pick.emptySelection(), '2025-08-10').sel;      // tapped
            s = pick.fill(s, '2025-08-20', '2025-08-21');                      // filled
            const picked = pick.pickEntry(s, festival);
            const shared = picked.sel.dates['2025-08-10'];
            const out1 = pick.unpickEntry(picked.sel, festival.key);
            out({
                picked: [picked.picked, picked.total, picked.capped, pick.count(picked.sel), picked.sel.overrides[festival.key], pick.entryPicked(picked.sel, festival.key)],
                shared,
                left: pick.pickedDates(out1), origins: out1.dates['2025-08-10'], override: out1.overrides[festival.key], circle: pick.entryPicked(out1, festival.key),
            });
            JS);

        $this->assertSame([7, 7, false, 9, 'in', true], $out['picked']);
        $this->assertSame(['tap', 'entry:'.str_repeat('a', 40)], $out['shared']);
        $this->assertSame(['2025-08-10', '2025-08-20', '2025-08-21'], $out['left'], 'The day you tapped and the days you filled stay');
        $this->assertSame(['tap'], $out['origins']);
        $this->assertSame('out', $out['override']);
        $this->assertFalse($out['circle']);
    }

    public function test_an_entry_is_ticked_for_you_only_when_it_looks_like_work_or_matches_one_that_does(): void
    {
        $out = $this->node(<<<'JS'
            const e = (key, summary, more = {}) => ({ key: key.repeat(40), date: '2025-11-03', last_day: '2025-11-03', summary, state: 'free', ...more });
            const rig = e('a', 'Rig: Winter Expo', { base: 'winter expo', score: 4 });
            const allDay = e('b', 'Winter Expo', { base: 'winter expo', score: 1, all_day: true });
            const physio = e('c', 'Physio', { base: 'physio', score: 1, location: 'Riverside Clinic' });
            const weekly = e('d', 'Crew meeting', { base: 'crew meeting', score: 2, recurring: true });
            const weekly3 = e('e', 'Crew call', { base: 'crew call', score: 3, recurring: true });
            const taken = e('f', 'De-rig: Winter Expo', { base: 'winter expo', score: 4, state: 'job' });
            const marked = e('g', 'Winter Expo drinks', { base: 'winter expo drinks', score: 2, state: 'not_job' });
            const noScore = e('h', 'Gig', { base: 'gig', default_include: true });
            const sel = pick.toggle(pick.emptySelection(), '2025-11-03').sel;
            const list = [rig, allDay, physio, weekly, weekly3, taken, marked, noScore];
            out({
                alone: list.map((x) => pick.defaultInclude(x)),
                same: pick.defaultInclude(allDay, ['winter expo']),
                included: pick.includedEntries(list, sel).map((x) => x.summary),
                unticked: pick.includedEntries(list, pick.setOverride(sel, rig.key, 'out')).map((x) => x.summary),
                ticked: pick.includedEntries(list, pick.setOverride(sel, physio.key, 'in')).map((x) => x.summary),
                job: pick.includedEntries([allDay], sel, ['winter expo']).map((x) => x.summary),
            });
            JS);

        // Rig (score 4) and the server's own verdict are ticked; the physio (1), a weekly entry scoring 2, one on a
        // job and one you said isn't work are not.
        $this->assertSame([true, false, false, false, true, false, false, true], $out['alone']);
        $this->assertTrue($out['same'], 'The all-day "Winter Expo" beside "Rig: Winter Expo" is ticked too');
        $this->assertSame(['Rig: Winter Expo', 'Winter Expo', 'Crew call', 'Gig'], $out['included']);
        $this->assertSame(['Crew call', 'Gig'], $out['unticked'], 'Unticking the rig takes the same-title entry with it');
        $this->assertSame(['Rig: Winter Expo', 'Winter Expo', 'Physio', 'Crew call', 'Gig'], $out['ticked']);
        $this->assertSame(['Winter Expo'], $out['job'], 'Adding to a job: the same title as the job\'s own entries');
    }

    public function test_a_load_out_after_midnight_belongs_to_the_evening_before(): void
    {
        $out = $this->node(<<<'JS'
            const show = { key: 's'.repeat(40), date: '2025-11-07', last_day: '2025-11-07', summary: 'Show: Winter Expo', all_day: false, start_time: '22:00', end_time: '23:30' };
            const loadOut = { key: 'l'.repeat(40), date: '2025-11-08', last_day: '2025-11-08', summary: 'Load-out', all_day: false, start_time: '00:30', end_time: '04:00' };
            const morning = { ...loadOut, key: 'm'.repeat(40), start_time: '07:00', end_time: '09:00' };
            const overnight = { key: 'o'.repeat(40), date: '2025-11-07', last_day: '2025-11-08', summary: 'Night shoot', all_day: false, start_time: '22:00', end_time: '07:00' };
            const fri = pick.toggle(pick.emptySelection(), '2025-11-07').sel;
            const both = pick.toggle(fri, '2025-11-08').sel;
            out({
                fold: [pick.foldTarget(loadOut, fri.dates), pick.foldTarget(loadOut, ['2025-11-06']), pick.foldTarget(morning, fri.dates), pick.foldTarget({ ...loadOut, all_day: true }, fri.dates)],
                touched: pick.touchedEntries([show, loadOut, morning], fri).map((e) => e.summary),
                offered: pick.includedEntries([{ ...show, score: 4 }, { ...loadOut, score: 4 }], fri).map((e) => e.summary),
                taken: pick.includedEntries([{ ...show, score: 4 }, { ...loadOut, score: 4 }], pick.setOverride(fri, loadOut.key, 'in')).map((e) => e.summary),
                on: pick.entryOn(loadOut, fri),
                friday: pick.dayDefaults('2025-11-07', [show, loadOut], fri.dates),
                saturday: pick.dayDefaults('2025-11-08', [loadOut], both.dates),
                runsOn: pick.dayDefaults('2025-11-07', [overnight], fri.dates),
                empty: pick.dayDefaults('2025-11-09', [], fri.dates),
                kinds: ['Rig: Winter Expo', 'Load-in', 'De-rig: Winter Expo', 'Get out', 'Awards night', 'Drive to Leeds', 'Production meeting'].map(pick.kindFrom),
                latest: [pick.latest('23:30', '04:00'), pick.latest('04:00', '05:59'), pick.latest('18:00', '06:00'), pick.latest(null, '07:00')],
            });
            JS);

        $this->assertSame(['2025-11-07', null, null, null], $out['fold']);
        $this->assertSame(['Show: Winter Expo', 'Load-out'], $out['touched'], 'The 07:00 entry the next day isn\'t Friday\'s');
        $this->assertSame(['Show: Winter Expo'], $out['offered'], 'The load-out is offered on Friday, not ticked for you');
        $this->assertSame(['Show: Winter Expo', 'Load-out'], $out['taken']);
        $this->assertSame('2025-11-07', $out['on']);
        $this->assertSame(['kind' => 'show', 'start_time' => '22:00', 'end_time' => '04:00'], $out['friday'], 'The show runs on to 04:00, with no Saturday');
        // Saturday tapped too: the load-out is still Friday evening's (its finish is on Friday), so it gives Saturday
        // nothing of its own (the strip offers to drop Saturday: "Put it on Fri 7").
        $this->assertSame(['kind' => 'work', 'start_time' => null, 'end_time' => null], $out['saturday']);
        $this->assertSame(['kind' => 'work', 'start_time' => '22:00', 'end_time' => null], $out['runsOn'], 'A finish on a day not picked isn\'t copied onto Friday');
        $this->assertSame(['kind' => 'work', 'start_time' => null, 'end_time' => null], $out['empty']);

        // The page names a day's kind and picks the later finish as the server does.
        $titles = ['Rig: Winter Expo', 'Load-in', 'De-rig: Winter Expo', 'Get out', 'Awards night', 'Drive to Leeds', 'Production meeting'];
        $this->assertSame(array_map(JobDays::kindFrom(...), $titles), $out['kinds']);
        $this->assertSame([JobDays::latest('23:30', '04:00'), JobDays::latest('04:00', '05:59'), JobDays::latest('18:00', '06:00'), JobDays::latest(null, '07:00')], $out['latest']);
    }

    public function test_a_pick_is_kept_on_the_phone_for_a_day_and_no_longer(): void
    {
        $out = $this->node(<<<'JS'
            const now = Date.UTC(2025, 10, 3, 9, 0);
            let s = pick.toggle(pick.emptySelection(), '2025-11-03').sel;
            s = pick.setOverride({ ...s, token: '01JC0Z8XQ6M3V9K2T4R7P5N8BD' }, 'a'.repeat(40), 'out');
            const raw = pick.serialise(s, { days: { '2025-11-03': { kind: 'rig' } } }, now);
            const back = pick.restore(raw, now + 23 * 3600_000);
            const tampered = JSON.parse(raw);
            tampered.sel.dates['2025-02-30'] = ['tap'];
            tampered.sel.dates['not a date'] = ['tap'];
            tampered.sel.token = 'x';
            const cleaned = pick.restore(JSON.stringify(tampered), now);
            out({
                back: back && [pick.pickedDates(back.sel), back.sel.overrides, back.sel.token, back.sel.last, back.draft],
                late: pick.restore(raw, now + 25 * 3600_000),
                future: pick.restore(raw, now - 25 * 3600_000),
                garbage: [pick.restore('{', now), pick.restore(null, now), pick.restore('"x"', now), pick.restore(JSON.stringify({ v: 2 }), now)],
                cleaned: cleaned && [pick.pickedDates(cleaned.sel), cleaned.sel.token],
                empty: pick.restore(pick.serialise(pick.emptySelection(), null, now), now),
            });
            JS);

        $this->assertSame([['2025-11-03'], [str_repeat('a', 40) => 'out'], '01JC0Z8XQ6M3V9K2T4R7P5N8BD', '2025-11-03', ['days' => ['2025-11-03' => ['kind' => 'rig']]]], $out['back']);
        $this->assertNull($out['late'], 'Forgotten after 24 hours');
        $this->assertNull($out['future']);
        $this->assertSame([null, null, null, null], $out['garbage']);
        $this->assertSame([['2025-11-03'], null], $out['cleaned'], 'What can\'t be read back is left out');
        $this->assertNull($out['empty'], 'Nothing picked is nothing kept');
    }

    /**
     * Runs a script with `pick` (resources/js/lib/day-pick.ts, its types stripped by node) and `out(value)` in node,
     * and returns what it printed. Skipped where node is older than 22.
     */
    public function test_a_small_hours_entry_between_two_picked_days_is_the_evening_befores_only(): void
    {
        $out = $this->node(<<<'JS'
            const fri = { key: 'f', date: '2026-12-04', last_day: '2026-12-04', summary: 'Show: Winter Expo', all_day: false, start_time: '19:00', end_time: '23:00' };
            const getIn = { key: 'g', date: '2026-12-05', last_day: '2026-12-05', summary: 'Get-in: Winter Expo', all_day: false, start_time: '00:30', end_time: '03:00' };
            const sat = { key: 's', date: '2026-12-05', last_day: '2026-12-05', summary: 'Show: Winter Expo', all_day: false, start_time: '18:00', end_time: '23:00' };
            const picked = { '2026-12-04': ['tap'], '2026-12-05': ['tap'] };
            out({
                fri: pick.dayDefaults('2026-12-04', [fri, getIn, sat], picked),
                sat: pick.dayDefaults('2026-12-05', [fri, getIn, sat], picked),
                on: pick.entryOn(getIn, picked),
                alone: pick.dayDefaults('2026-12-05', [getIn], { '2026-12-05': ['tap'] }),
            });
            JS);

        $this->assertSame(['kind' => 'show', 'start_time' => '19:00', 'end_time' => '03:00'], $out['fri']);
        $this->assertSame(['kind' => 'show', 'start_time' => '18:00', 'end_time' => '23:00'], $out['sat'], 'Saturday is its own show, not the get-in');
        $this->assertSame('2026-12-04', $out['on']);
        $this->assertSame(['kind' => 'rig', 'start_time' => '00:30', 'end_time' => '03:00'], $out['alone'], 'With Friday not picked it is Saturday’s');
    }

    public function test_adding_to_a_job_its_own_days_are_never_filled_or_picked_again(): void
    {
        $out = $this->node(<<<'JS'
            const own = new Set(['2026-12-17', '2026-12-18', '2026-12-19']);
            let s = pick.toggle(pick.emptySelection(), '2026-12-16').sel;
            s = pick.toggle(s, '2026-12-20').sel;
            const between = pick.fillOffer(s, own);
            let t = pick.toggle(pick.emptySelection(), '2026-12-17').sel; // only as a fold, never by a tap
            t = pick.toggle(pick.emptySelection(), '2026-12-20').sel;
            t = pick.toggle(t, '2026-12-14').sel;
            const gap = pick.fillOffer(t, own);
            const filled = pick.pickedDates(pick.fill(t, '2026-12-14', '2026-12-20', 'fill', own));
            const festival = { key: 'x', date: '2026-12-16', last_day: '2026-12-21', summary: 'Acme Christmas party week', all_day: true };
            const entry = pick.pickEntry(pick.emptySelection(), festival, own);
            out({ between, gap, filled, entry: [pick.pickedDates(entry.sel), entry.picked, entry.total, entry.capped], plain: pick.fillOffer(s) });
            JS);

        $this->assertNull($out['between'], 'The job’s own days fill the gap: nothing to offer');
        $this->assertSame(['from' => '2026-12-15', 'to' => '2026-12-16', 'count' => 2], $out['gap']);
        $this->assertSame(['2026-12-14', '2026-12-15', '2026-12-16', '2026-12-20'], $out['filled']);
        $this->assertSame([['2026-12-16', '2026-12-20', '2026-12-21'], 3, 6, false], $out['entry']);
        $this->assertSame(['from' => '2026-12-17', 'to' => '2026-12-19', 'count' => 3], $out['plain'], 'On the calendar page nothing is skipped');
    }

    public function test_an_entry_linked_automatically_to_another_job_is_ticked_to_move_as_the_review_ticks_it(): void
    {
        $out = $this->node(<<<'JS'
            const base = { date: '2026-12-21', last_day: '2026-12-21', summary: 'Show: Northern Lights gala', state: 'job', score: 3 };
            out({
                auto: pick.defaultInclude({ ...base, key: 'a', job: { id: 7 }, by: 'rules' }),
                yours: pick.defaultInclude({ ...base, key: 'b', job: { id: 7 }, by: 'you' }),
                thisJob: pick.defaultInclude({ ...base, key: 'c', job: { id: 9 }, by: 'you' }, [], 9),
                notJob: pick.defaultInclude({ ...base, key: 'd', state: 'not_job', job: null, by: 'you' }),
                free: pick.defaultInclude({ ...base, key: 'e', state: 'free', job: null, by: null }),
                season: pick.pickEntry(pick.emptySelection(), { key: 's', date: '2025-09-01', last_day: '2026-11-30', all_day: true }).total,
            });
            JS);

        $this->assertSame(['auto' => true, 'yours' => false, 'thisJob' => true, 'notJob' => false, 'free' => true, 'season' => 456], $out);
    }

    private function node(string $script, string $timezone = 'Europe/London'): mixed
    {
        $probe = new Process(['node', '-e', 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Node 22 or later is needed to run the page code.');
        }
        $file = tempnam(sys_get_temp_dir(), 'pick').'.mjs';
        file_put_contents($file, "const pick = await import(process.argv[2]);\nconst out = (v) => console.log(JSON.stringify(v));\n".$script);
        $run = new Process(['node', '--no-warnings', '--experimental-strip-types', $file, 'file://'.resource_path('js/lib/day-pick.ts')], null, ['TZ' => $timezone]);
        $run->run();
        @unlink($file);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return json_decode(trim($run->getOutput()), true);
    }
}
