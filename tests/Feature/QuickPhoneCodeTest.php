<?php

namespace Tests\Feature;

use App\Domain\Quick\QuickText;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The phone's side of quick add as the phone runs it, in node: public/quick-store.js (the keypad, what each reply
 * from Expend means for an entry, the waits after a failed send, Undo, today's job, offline suggestions, the
 * same-receipt check, the text parser) and the sending queue itself over a stand-in IndexedDB and server (fields
 * first, then photos, the same client key on every try, a lost reply then Undo, signed out, an old form token).
 * The text parser must read every message exactly as the server's QuickText does. Skipped where node isn't installed.
 */
class QuickPhoneCodeTest extends TestCase
{
    private const TODAY = '2026-11-25';

    public function test_the_keypad_types_amounts_in_the_currencys_own_decimals(): void
    {
        $out = $this->node(<<<'JS'
            const type = (keys, digits = 2) => keys.split(' ').reduce((t, k) => Q.keypad(t, k, digits), '');
            out({
                pounds: [type('1 2'), Q.keypadMinor(type('1 2'), 2), type('1 2 . 5'), Q.keypadMinor('12.5', 2), type('1 2 . 5 0 7'), Q.keypadMinor('12.50', 2)],
                point: [type('.'), type('. 5'), type('1 . .'), Q.keypadMinor('12.', 2), Q.keypadAmount('12.'), Q.keypadAmount('')],
                zeros: [type('0 0 7'), type('0 . 0 5'), Q.keypadMinor('0', 2)],
                back: [type('1 2 back'), type('back'), type('1 2 3 clear')],
                yen: [type('1 5 0 0', 0), type('1 5 . 5', 0), Q.keypadMinor('1500', 0)],
                dinar: [type('1 2 . 3 4 5 6', 3), Q.keypadMinor('12.345', 3)],
                long: type('9 9 9 9 9 9 9 9 9'),
                junk: [Q.keypadMinor('12,50', 2), Q.keypadMinor('12.505', 2), Q.keypadMinor('abc', 2)],
                text: [Q.keypadText(1250, 2), Q.keypadText(1200, 2), Q.keypadText(1505, 2), Q.keypadText(1500, 0), Q.keypadText(12345, 3), Q.keypadText(5, 2)],
            });
            JS);

        $this->assertSame(['12', 1200, '12.5', 1250, '12.50', 1250], $out['pounds'], '12 is £12.00; 12.5 is £12.50; no third decimal');
        $this->assertSame(['0.', '0.5', '1.', 1200, '12', null], $out['point']);
        $this->assertSame(['7', '0.05', 0], $out['zeros'], 'no leading zeros');
        $this->assertSame(['1', '', ''], $out['back']);
        $this->assertSame(['1500', '155', 1500], $out['yen'], 'no point for yen');
        $this->assertSame(['12.345', 12345], $out['dinar']);
        $this->assertSame('9999999', $out['long'], 'at most £9,999,999');
        $this->assertSame([null, null, null], $out['junk']);
        $this->assertSame(['12.50', '12', '15.05', '1500', '12.345', '0.05'], $out['text'], 'a shared or edited amount fills the keypad as it reads');
    }

    public function test_each_reply_from_expend_moves_the_entry_on_as_the_design_says(): void
    {
        $out = $this->node(<<<'JS'
            const now = 1_000_000;
            const photo = { bytes: new ArrayBuffer(8), sha256: 'a'.repeat(64), size: 8, type: 'image/jpeg' };
            const plain = { client_key: 'k', state: 'sending', attempts: 0 };
            const withPhoto = { ...plain, photo };
            const added = (wanted) => ({ status: 201, body: { entry: { id: 7, state: 'added', words: '£12.50 parking', photo_wanted: wanted, message: 'Added as a cash expense', href: '/quick/entries/7' }, warnings: [{ code: 'quick.maybe_card', message: 'Looks like a Starling card payment' }] } });
            const pick = (p, keys) => Object.fromEntries(keys.map((k) => [k, p[k] ?? null]));
            const f = (entry, res) => Q.nextState(entry, 'fields', res, now);
            out({
                created: pick(f(plain, added(false)), ['state', 'server_id', 'words', 'result', 'warnings', 'href']),
                photoNext: pick(f(withPhoto, added(true)), ['state', 'photo_drop']),
                notWanted: pick(f(withPhoto, added(false)), ['state', 'photo_drop']),
                repeat: pick(f(plain, { status: 200, body: { repeat: true, entry: { id: 7, state: 'added', words: '£12.50 parking', photo_wanted: false, message: 'Added as a cash expense' } } }), ['state', 'result', 'changed']),
                changed: pick(f(plain, { status: 200, body: { repeat: true, changed: true, message: 'This was sent before your change, so nothing changed. Open it to change it.', entry: { id: 7 } } }), ['state', 'result', 'changed']),
                receiving: pick(f(plain, { status: 202, body: { state: 'receiving', retry_after: 30 } }), ['state', 'next_at']),
                withdrawn: pick(f(plain, { status: 200, body: { state: 'withdrawn', entry: { id: 7 } } }), ['state']),
                signedOut: pick(f(plain, { status: 401, body: { message: 'Unauthenticated.' } }), ['state', 'stop', 'last_error']),
                oldToken: pick(f(plain, { status: 419, body: null }), ['state', 'stop']),
                malformed: pick(f(plain, { status: 422, body: { message: 'The client key field must be a valid UUID.', errors: { client_key: ['The client key field must be a valid UUID.'] } } }), ['state', 'last_error']),
                tooBig: pick(f(plain, { status: 413, body: null }), ['state', 'last_error']),
                busy: pick(f(plain, { status: 429, retryAfter: 20, body: null }), ['state', 'next_at', 'attempts']),
                broken: pick(f(plain, { status: 500, body: null }), ['state', 'attempts', 'next_at']),
                noSignal: pick(f({ ...plain, attempts: 2 }, { status: 0 }), ['state', 'attempts', 'next_at', 'last_error']),
                photoStored: pick(Q.nextState(withPhoto, 'photo', { status: 200, body: { sha256: 'a'.repeat(64), document_id: 9 } }, now), ['state', 'photo_drop', 'document_id']),
                photoOther: pick(Q.nextState(withPhoto, 'photo', { status: 200, body: { sha256: 'b'.repeat(64), document_id: 9 } }, now), ['state', 'photo_drop', 'mismatches']),
                mismatch1: pick(Q.nextState(withPhoto, 'photo', { status: 422, body: { error: 'photo_mismatch' } }, now), ['state', 'mismatches']),
                mismatch3: pick(Q.nextState({ ...withPhoto, mismatches: 2 }, 'photo', { status: 422, body: { error: 'photo_mismatch' } }, now), ['state', 'mismatches']),
                photoError: pick(Q.nextState(withPhoto, 'photo', { status: 422, body: { error: 'photo_error', message: 'The upload broke off' } }, now), ['state', 'attempts']),
                photoTooBig: pick(Q.nextState(withPhoto, 'photo', { status: 413, body: { error: 'too_big', message: 'Too big to send from your phone (over 8 MB). Upload it from a computer.' } }, now), ['state', 'last_error']),
                postTooBig: pick(Q.nextState(withPhoto, 'photo', { status: 413, body: { message: 'The POST data is too large.' } }, now), ['state', 'last_error']),
                photoUnknown: pick(Q.nextState(withPhoto, 'photo', { status: 404, body: { error: 'not_found' } }, now), ['state', 'next_at']),
                photoSignedOut: pick(Q.nextState(withPhoto, 'photo', { status: 401 }, now), ['state', 'stop']),
                photoServer: pick(Q.nextState(withPhoto, 'photo', { status: 500, body: { error: 'store_failed' } }, now), ['state', 'attempts']),
                withdrawOk: pick(Q.nextState(plain, 'withdraw', { status: 200, body: { state: 'withdrawn' } }, now), ['state']),
                withdrawLocked: pick(Q.nextState(plain, 'withdraw', { status: 409, body: { state: 'added', message: 'Kept: 2024-25 is locked' } }, now), ['state', 'kept', 'result']),
                withdrawNoSignal: pick(Q.nextState(plain, 'withdraw', { status: 0 }, now), ['state', 'attempts']),
            });
            JS);

        $this->assertSame(['state' => 'sent', 'server_id' => 7, 'words' => '£12.50 parking', 'result' => 'Added as a cash expense', 'warnings' => ['Looks like a Starling card payment'], 'href' => '/quick/entries/7'], $out['created']);
        $this->assertSame(['state' => 'photo_pending', 'photo_drop' => null], $out['photoNext'], 'the fields are in; the photo goes next');
        $this->assertSame(['state' => 'sent', 'photo_drop' => true], $out['notWanted'], 'a photo Expend already has is dropped');
        $this->assertSame(['state' => 'sent', 'result' => 'Added as a cash expense', 'changed' => false], $out['repeat']);
        $this->assertSame(['state' => 'sent', 'result' => 'This was sent before your change, so nothing changed. Open it to change it.', 'changed' => true], $out['changed']);
        $this->assertSame(['state' => 'receiving', 'next_at' => 1_030_000], $out['receiving'], 'asked again in 30 s');
        $this->assertSame(['state' => 'withdrawn'], $out['withdrawn'], 'a tombstone: Undo got there first');
        $this->assertSame(['state' => 'signed_out', 'stop' => true, 'last_error' => 'Sign in to send'], $out['signedOut']);
        $this->assertSame(['state' => 'signed_out', 'stop' => true], $out['oldToken'], 'a 419 that a fresh token didn’t cure is signed out');
        $this->assertSame(['state' => 'needs_you', 'last_error' => 'The client key field must be a valid UUID.'], $out['malformed'], 'in the server’s own words');
        $this->assertSame('needs_you', $out['tooBig']['state']);
        $this->assertStringContainsString('Too big to send from your phone', $out['tooBig']['last_error']);
        $this->assertSame(['state' => 'waiting', 'next_at' => 1_020_000, 'attempts' => null], $out['busy'], '429 waits for Retry-After and isn’t a failed try');
        $this->assertSame(['state' => 'waiting', 'attempts' => 1, 'next_at' => 1_015_000], $out['broken']);
        $this->assertSame(['state' => 'waiting', 'attempts' => 3, 'next_at' => 1_060_000, 'last_error' => 'No signal'], $out['noSignal']);
        $this->assertSame(['state' => 'sent', 'photo_drop' => true, 'document_id' => 9], $out['photoStored'], 'bytes are dropped only when Expend’s sha256 matches');
        $this->assertSame(['state' => 'photo_pending', 'photo_drop' => null, 'mismatches' => 1], $out['photoOther']);
        $this->assertSame(['state' => 'photo_pending', 'mismatches' => 1], $out['mismatch1']);
        $this->assertSame(['state' => 'needs_you', 'mismatches' => 3], $out['mismatch3'], 'retried twice, then it needs you');
        $this->assertSame(['state' => 'photo_pending', 'attempts' => 1], $out['photoError']);
        $this->assertSame(['state' => 'needs_you', 'last_error' => 'Too big to send from your phone (over 8 MB). Upload it from a computer.'], $out['photoTooBig']);
        $this->assertSame(['state' => 'needs_you', 'last_error' => 'Too big to send from your phone. Upload it from a computer.'], $out['postTooBig'], 'never Laravel’s words');
        $this->assertSame(['state' => 'waiting', 'next_at' => 1_000_000], $out['photoUnknown'], 'Expend hasn’t the entry: its fields go again first');
        $this->assertSame(['state' => 'photo_pending', 'stop' => true], $out['photoSignedOut']);
        $this->assertSame(['state' => 'photo_pending', 'attempts' => 1], $out['photoServer']);
        $this->assertSame(['state' => 'withdrawn'], $out['withdrawOk']);
        $this->assertSame(['state' => 'sent', 'kept' => true, 'result' => 'Kept: 2024-25 is locked'], $out['withdrawLocked']);
        $this->assertSame(['state' => 'withdraw_pending', 'attempts' => 1], $out['withdrawNoSignal']);
    }

    public function test_failed_sends_wait_longer_each_time_and_opening_the_app_starts_again(): void
    {
        $out = $this->node(<<<'JS'
            out({
                steps: [1, 2, 3, 4, 5, 6, 7, 8, 20].map((n) => Q.backoff(n) / 1000),
                reset: Q.resetBackoff({ state: 'waiting', attempts: 5, next_at: 999, tries: 5 }),
                signedOut: Q.resetBackoff({ state: 'signed_out', attempts: 1, next_at: 5 }).state,
                stale: Q.recoverStale([
                    { client_key: 'a', state: 'sending', sending_kind: 'fields', sending_at: 0 },
                    { client_key: 'b', state: 'sending', sending_kind: 'photo', sending_at: 0 },
                    { client_key: 'c', state: 'sending', sending_kind: 'withdraw', sending_at: 0 },
                    { client_key: 'd', state: 'sending', sending_kind: 'fields', sending_at: 200_000 },
                    { client_key: 'e', state: 'waiting' },
                ], 250_000),
                due: [Q.isDue({ state: 'waiting', next_at: 10 }, 5), Q.isDue({ state: 'waiting', next_at: 10 }, 10), Q.isDue({ state: 'sent' }, 10), Q.isDue({ state: 'needs_you' }, 10), Q.isDue({ state: 'photo_pending' }, 10)],
            });
            JS);

        $this->assertSame([15, 30, 60, 120, 300, 600, 1800, 1800, 1800], $out['steps'], '15 s, 30 s, 1, 2, 5 and 10 minutes, then every 30 minutes');
        $this->assertSame(['state' => 'waiting', 'attempts' => 0, 'next_at' => 0, 'tries' => 5], $out['reset'], 'the tries so far are still shown');
        $this->assertSame('waiting', $out['signedOut']);
        $this->assertSame([['key' => 'a', 'state' => 'waiting'], ['key' => 'b', 'state' => 'photo_pending'], ['key' => 'c', 'state' => 'withdraw_pending']], $out['stale'], 'left sending over 2 minutes: the app was killed');
        $this->assertSame([false, true, false, false, true], $out['due']);
    }

    public function test_undo_only_deletes_what_never_left_the_phone(): void
    {
        $out = $this->node(<<<'JS'
            const apply = (cur, patch, kind) => Q.applyPatch(cur, patch, kind);
            out({
                rule: [
                    Q.withdrawRule({ state: 'waiting', tries: 0 }),
                    Q.withdrawRule({ state: 'waiting', tries: 1 }),
                    Q.withdrawRule({ state: 'sending', tries: 0 }),
                    Q.withdrawRule({ state: 'sent', tries: 1 }),
                    Q.withdrawRule({ state: 'photo_pending', tries: 1 }),
                    Q.withdrawRule({ state: 'withdraw_pending', tries: 1 }),
                    Q.withdrawRule({ state: 'withdrawn', tries: 2 }),
                    Q.withdrawRule(null),
                ],
                // Undo tapped while the fields were out: the reply doesn't undo the Undo.
                raced: apply({ client_key: 'k', state: 'withdraw_pending', photo: { bytes: new ArrayBuffer(4), sha256: 'x' } }, { state: 'sent', server_id: 7, photo_drop: true }, 'fields'),
                normal: apply({ client_key: 'k', state: 'sending', sending_kind: 'photo', photo: { bytes: new ArrayBuffer(4), sha256: 'x' } }, { state: 'sent', photo_drop: true }, 'photo'),
            });
            JS);

        $this->assertSame(['delete', 'withdraw', 'withdraw', 'withdraw', 'withdraw', 'withdraw', 'delete', 'gone'], $out['rule']);
        $this->assertSame('withdraw_pending', $out['raced']['state']);
        $this->assertSame(7, $out['raced']['server_id']);
        $this->assertSame('sent', $out['normal']['state']);
        $this->assertNull($out['normal']['photo']['bytes'] ?? null, 'the bytes go once Expend has them');
        $this->assertSame('x', $out['normal']['photo']['sha256'], 'the photo’s record stays for the list');
    }

    public function test_todays_job_is_picked_only_when_it_is_clear(): void
    {
        $out = $this->node(<<<'JS'
            const pocket = { jobs: [
                { id: 1, title: 'Leeds Arena Awards', days: ['2026-11-24', '2026-11-25'] },
                { id: 2, title: 'Bristol Conference', days: ['2026-11-26'] },
                { id: 3, title: 'Manchester Gala', days: ['2026-11-20'] },
            ] };
            const two = { jobs: [...pocket.jobs, { id: 4, title: 'Sheffield Expo', days: ['2026-11-25'] }] };
            const base = { date: '2026-11-25', today: '2026-11-25', time: '14:05', now: 10_000_000 };
            const pick = (r) => ({ job: r.job_id, source: r.source, on: r.on, near: r.near, date: r.date, carried: r.carried });
            out({
                one: pick(Q.defaultJob(pocket, base)),
                two: pick(Q.defaultJob(two, base)),
                none: pick(Q.defaultJob(pocket, { ...base, date: '2026-11-27', today: '2026-11-27' })),
                overnight: pick(Q.defaultJob(pocket, { ...base, date: '2026-11-26', today: '2026-11-26', time: '01:30' })),
                morning: pick(Q.defaultJob({ jobs: [pocket.jobs[1]] }, { ...base, date: '2026-11-27', today: '2026-11-27', time: '07:00' })),
                overhead: pick(Q.defaultJob(pocket, { ...base, jobCost: false })),
                jobCost: pick(Q.defaultJob(pocket, { ...base, jobCost: true })),
                launch: pick(Q.defaultJob(pocket, { ...base, launchJobId: 3, jobCost: false })),
                carry: pick(Q.defaultJob(pocket, { ...base, carry: { job_id: 3, job_source: 'you', date: '2026-11-20', day: '2026-11-25', at: 10_000_000 - 29 * 60_000 } })),
                carryLate: pick(Q.defaultJob(pocket, { ...base, carry: { job_id: 3, job_source: 'you', date: '2026-11-20', day: '2026-11-25', at: 10_000_000 - 31 * 60_000 } })),
                carryYesterday: pick(Q.defaultJob(pocket, { ...base, carry: { job_id: 3, job_source: 'you', date: '2026-11-20', day: '2026-11-24', at: 10_000_000 - 60_000 } })),
                empty: pick(Q.defaultJob(null, base)),
            });
            JS);

        $this->assertSame(['job' => 1, 'source' => 'today', 'on' => [1], 'near' => [2], 'date' => '2026-11-25', 'carried' => false], $out['one'], 'one job on today: picked');
        $this->assertSame(['job' => null, 'source' => null, 'on' => [1, 4], 'near' => [2], 'date' => '2026-11-25', 'carried' => false], $out['two'], 'two jobs: listed first, none picked');
        $this->assertSame(['job' => null, 'source' => null, 'on' => [], 'near' => [2], 'date' => '2026-11-27', 'carried' => false], $out['none'], 'a job a day either side is listed, not picked');
        $this->assertSame([1, 2], $out['overnight']['on'], 'before 06:00 yesterday’s job counts too');
        $this->assertSame(['job' => null, 'source' => null, 'on' => [], 'near' => [2], 'date' => '2026-11-27', 'carried' => false], $out['morning'], 'after 06:00 it doesn’t');
        $this->assertSame(['job' => null, 'source' => null, 'on' => [1], 'near' => [2], 'date' => '2026-11-25', 'carried' => false], $out['overhead'], 'software or a phone bill never goes on a job by itself');
        $this->assertSame(1, $out['jobCost']['job']);
        $this->assertSame(['job' => 3, 'source' => 'you'], array_intersect_key($out['launch'], ['job' => 0, 'source' => 0]), '?job=3 is your pick, kept for any category');
        $this->assertSame(['job' => 3, 'source' => 'you', 'date' => '2026-11-20', 'carried' => true], array_intersect_key($out['carry'], ['job' => 0, 'source' => 0, 'date' => 0, 'carried' => 0]), 'saved 29 minutes ago today: the job and date carry over');
        $this->assertSame(['job' => 1, 'date' => '2026-11-25', 'carried' => false], array_intersect_key($out['carryLate'], ['job' => 0, 'date' => 0, 'carried' => 0]), '31 minutes: worked out again');
        $this->assertSame(['job' => 1, 'date' => '2026-11-25', 'carried' => false], array_intersect_key($out['carryYesterday'], ['job' => 0, 'date' => 0, 'carried' => 0]), 'another London day: worked out again');
        $this->assertNull($out['empty']['job']);
    }

    public function test_offline_suggestions_and_the_same_receipt_check(): void
    {
        $out = $this->node(<<<'JS'
            const pocket = {
                recent_whats: [{ what: 'NCP Leeds', category_id: 11, count: 4 }, { what: 'NCP Bristol', category_id: 11, count: 1 }, { what: 'Pret', category_id: 20, count: 2 }],
                hints: [{ words: ['parking', 'car park', 'ncp'], category_id: 11, code: 'travel_parking' }, { words: ['train', 'lner'], category_id: 12, code: 'travel_rail' }],
            };
            const records = [
                { client_key: 'a', amount_minor: 1250, currency: 'GBP', date: '2026-11-25', at: 100, what: 'parking', state: 'sent' },
                { client_key: 'b', amount_minor: 1250, currency: 'GBP', date: '2026-11-24', at: 50, what: 'parking', state: 'waiting' },
                { client_key: 'c', amount_minor: 1500, currency: 'EUR', date: '2026-11-25', at: 200, what: 'taxi', state: 'sent' },
                { client_key: 'd', amount_minor: 900, currency: 'GBP', date: '2026-11-25', at: 300, what: 'lunch', state: 'withdrawn' },
                { client_key: null, amount_minor: 4000, currency: 'GBP', date: '2026-11-20', at: '2026-11-20T10:00:00Z', what: 'fuel', state: 'added' },
            ];
            const same = (c) => { const r = Q.sameReceipt(c, records); return r ? r.client_key ?? r.what : null; };
            out({
                exact: Q.suggestOffline(pocket, 'NCP Leeds'),
                firstWord: Q.suggestOffline(pocket, 'ncp manchester'),
                hint: Q.suggestOffline(pocket, 'LNER to York'),
                phrase: Q.suggestOffline(pocket, 'car park at the arena'),
                none: Q.suggestOffline(pocket, 'gift'),
                blank: Q.suggestOffline(pocket, '  '),
                same: [
                    same({ amount_minor: 1250, currency: 'GBP', date: '2026-11-25' }),
                    same({ amount_minor: 1250, currency: 'GBP', date: '2026-11-25', client_key: 'a' }),
                    same({ amount_minor: 1500, currency: 'GBP', date: '2026-11-25' }),
                    same({ amount_minor: 1500, currency: 'EUR', date: '2026-11-27' }),
                    same({ amount_minor: 1500, currency: 'EUR', date: '2026-11-28' }),
                    same({ amount_minor: 900, currency: 'GBP', date: '2026-11-25' }),
                    same({ amount_minor: 4000, currency: 'GBP', date: '2026-11-22' }),
                    same({ amount_minor: 4000, currency: 'GBP', date: '2026-11-23' }),
                ],
            });
            JS);

        $this->assertSame(['category_id' => 11, 'count' => 4, 'source' => 'recent', 'what' => 'NCP Leeds'], $out['exact'], 'your own words first');
        $this->assertSame(['category_id' => 11, 'count' => 4, 'source' => 'recent', 'what' => 'NCP Leeds'], $out['firstWord']);
        $this->assertSame(['category_id' => 12, 'code' => 'travel_rail', 'count' => 0, 'source' => 'hint', 'what' => 'lner'], $out['hint'], 'then the word list the server serves');
        $this->assertSame(11, $out['phrase']['category_id']);
        $this->assertNull($out['none']);
        $this->assertNull($out['blank']);
        $this->assertSame(['a', 'b', null, 'c', null, null, 'fuel', null], $out['same'], 'same amount and currency within 2 days; never a removed one, never itself');
    }

    public function test_tidying_keeps_sent_entries_14_days_and_never_deletes_shares_without_asking(): void
    {
        $out = $this->node(<<<'JS'
            const day = 86_400_000; const now = 100 * day;
            out(Q.prune([
                { client_key: 'old', state: 'sent', sent_at: now - 15 * day },
                { client_key: 'new', state: 'sent', sent_at: now - 13 * day },
                { client_key: 'gone', state: 'withdrawn' },
                { client_key: 'wait', state: 'waiting', created_at: now - 40 * day },
                { client_key: 'needs', state: 'needs_you', created_at: now - 40 * day },
            ], [{ id: 1, at: now - 15 * day }, { id: 2, at: now - day }], now));
            JS);

        $this->assertSame(['remove' => ['old', 'gone'], 'askShared' => [1]], $out, 'waiting entries are never tidied away');
    }

    public function test_the_phone_reads_texts_exactly_as_the_server_does(): void
    {
        $texts = ['£12.50 parking Leeds', '12.50 parking', '€15 taxi Berlin', '15 EUR taxi', '£1,250.00 laptop', '12,50 parking', 'parking £5', 'parking 5', 'parking 5.00',
            '£5 cash parking yesterday', 'How much did I spend on parking?', '/todo', 'spent £12 on parking', 'paid 7.20 for lunch on monday', '£12.50 car park', '12.50 car park',
            '£8 parking 23 Nov', '£8 parking on 23rd November 2025', '£8 parking Nov 2', '£8 parking 30 Nov', '£40 fuel card', '£40 fuel business card Leeds', '£3.40 coffee personal card tue',
            '1500 JPY taxi', '15.5 JPY taxi', '£12.505 parking', '£9.99 gaffa tape screwfix 3/11/26', 'taxi home £23.40', 'a long message with lots of words £5', '£0 nothing',
            'spent 12 on fuel yesterday', '£12.50', '£12.50 parking at NCP.', '£5 at NCP', '£20 hotel 12-14 Nov', '£5 EUR', '12.50 CHF lunch', '12.50 chf lunch', '12.50 abc lunch',
            '£2.50 tea sat', '£30 fuel starling', '£30 fuel Amex yesterday', '£ 12 parking', '12.345 KWD dinner', 'Thanks!', '£4.20 coffee from Pret', '£15 parking Tue 24 Nov'];
        $accounts = [['id' => 3, 'name' => 'Starling Business'], ['id' => 5, 'name' => 'Amex']];
        $cases = array_map(fn ($t) => ['text' => $t, 'today' => self::TODAY, 'accounts' => $accounts], $texts);
        // The shared fixtures (tests/fixtures/quick-text.json), each with its own day, accounts and the answer both must give.
        $fixture = base_path('tests/fixtures/quick-text.json');
        $expected = 0;
        if (is_file($fixture)) {
            foreach ((array) (json_decode((string) file_get_contents($fixture), true)['cases'] ?? []) as $c) {
                $cases[] = ['text' => (string) $c['text'], 'today' => (string) ($c['today'] ?? self::TODAY), 'accounts' => $c['accounts'] ?? [], 'expect' => $c['expect'] ?? null, 'fixture' => true];
                $expected++;
            }
        }

        $php = [];
        foreach ($cases as $i => $c) {
            // The server takes accounts as lower-case names (and first words) to ids, as QuickText::accountNames() makes them.
            $names = [];
            foreach ($c['accounts'] as $a) {
                $name = mb_strtolower(trim($a['name']));
                $names[$name] = $a['id'];
                $first = strtok($name, ' ');
                if ($first !== false && mb_strlen($first) >= 3 && ! isset($names[$first])) {
                    $names[$first] = $a['id'];
                }
            }
            $r = QuickText::parse($c['text'], CarbonImmutable::parse($c['today']), $names);
            $php[$i] = $r === null ? null : ['minor' => $r['minor'], 'currency' => $r['currency'], 'amount' => $r['amount'], 'what' => $r['what'], 'paid_with' => $r['paid_with'],
                'account_id' => $r['account_id'], 'date' => $r['date'], 'date_word' => $r['date_word']];
        }

        $node = $this->node('const cases = '.json_encode($cases).";\n".<<<'JS'
            const digits = (c) => ({ JPY: 0, KWD: 3 })[c] ?? 2;
            out(cases.map((c) => {
                const r = Q.parseText(c.text, c.today, { accounts: c.accounts, digits });
                return r === null ? null : { minor: r.minor, currency: r.currency, amount: r.amount, what: r.what, paid_with: r.paid_with, account_id: r.account_id, date: r.date, date_word: r.date_word };
            }));
            JS);

        foreach ($cases as $i => $c) {
            $this->assertSame($php[$i], $node[$i], "“{$c['text']}” is read differently on the phone");
            if (! empty($c['fixture'])) {
                $this->assertSame($c['expect'], $node[$i], "“{$c['text']}” isn’t read as the shared fixture says");
            }
        }
        if (is_file($fixture)) {
            $this->assertGreaterThan(0, $expected, 'the shared fixture has cases');
        }
        // And what they mean, for a few.
        $by = array_combine(array_column($cases, 'text'), array_slice($node, 0, count($cases)));
        $this->assertSame(['minor' => 500, 'what' => 'parking', 'paid_with' => 'cash', 'date' => '2026-11-24'], array_intersect_key($by['£5 cash parking yesterday'], ['minor' => 0, 'what' => 0, 'paid_with' => 0, 'date' => 0]));
        $this->assertNull($by['12,50 parking'], 'a decimal comma is refused');
        $this->assertNull($by['How much did I spend on parking?'], 'questions go to the assistant');
        $this->assertSame(['paid_with' => 'business', 'account_id' => 3], array_intersect_key($by['£30 fuel starling'], ['paid_with' => 0, 'account_id' => 0]));
    }

    public function test_a_word_in_the_text_names_the_job_only_when_one_job_fits(): void
    {
        $out = $this->node(<<<'JS'
            const jobs = [
                { id: 1, title: 'Leeds Arena Awards', venue: 'First Direct Arena', client: 'Acme Events', days: ['2026-11-25'] },
                { id: 2, title: 'Bristol Conference', venue: 'Bristol Beacon', client: 'Acme Events', days: ['2026-11-24'] },
                { id: 3, title: 'Leeds Town Hall', venue: null, client: null, days: ['2026-12-20'] },
            ];
            const job = (t) => Q.parseText(t, '2026-11-25', { jobs }).job_id;
            out([job('£12.50 parking Leeds'), job('£8 taxi Bristol'), job('£5 parking arena'), job('£20 lunch acme'), job('£12.50 parking')]);
            JS);

        // Leeds Town Hall is weeks away; "arena" says what a place is, not which; both jobs are for Acme Events.
        $this->assertSame([1, 2, null, null, null], $out);
    }

    public function test_the_queue_sends_fields_first_then_photos_with_the_same_key_every_time(): void
    {
        $out = $this->node(<<<'JS'
            const sha = 'c'.repeat(64);
            const entry = (key, photo, at) => ({
                client_key: key, created_at: at, saved_at: at, state: 'waiting', attempts: 0, tries: 0, next_at: 0,
                fields: { channel: 'phone', paid_with: 'cash', amount: '12.50', currency: 'GBP', what: 'Parking', what_source: 'chip', spent_on: '2026-11-25', photo: photo ? { sha256: sha, size: 4 } : null },
                payload_sha: 'p-' + key, photo: photo ? { bytes: new Uint8Array([1, 2, 3, 4]).buffer, type: 'image/jpeg', sha256: sha, size: 4, name: 'receipt-20261125-1405.jpg' } : null,
                summary: { amount_minor: 1250, currency: 'GBP', what: 'Parking', date: '2026-11-25' },
            });
            await Q.put(entry('k1', false, 1));
            await Q.put(entry('k2', true, 2));
            let fieldsTries = 0;
            const fetch = fakeFetch((call) => {
                if (call.url === '/quick/token') return { status: 200, body: { csrf: 'tok', max_upload_bytes: 2_000_000, pocket_etag: 'e1', now: 'x' } };
                if (call.url === '/quick/entries') {
                    const body = JSON.parse(call.body);
                    // The first try of k2 dies on the way back: the same key goes again and Expend answers "repeat".
                    if (body.client_key === 'k2' && fieldsTries++ === 0) return 'network';
                    const repeat = body.client_key === 'k2';
                    return { status: repeat ? 200 : 201, body: { ...(repeat ? { repeat: true } : {}), entry: { id: body.client_key === 'k1' ? 1 : 2, state: 'added', words: '£12.50 parking', photo_wanted: body.client_key === 'k2', message: 'Added as a cash expense' } } };
                }
                if (call.url.endsWith('/photo')) return { status: 200, body: { sha256: sha, document_id: 9 } };
                if (call.url === '/quick/pocket') return { status: 200, headers: { ETag: '"e1"' }, body: { today: '2026-11-25', jobs: [] } };
                return { status: 404, body: null };
            });
            const first = await Q.flush({ fetch });
            const afterFirst = (await Q.list()).map((e) => [e.client_key, e.state, e.attempts, e.tries]);
            // k2 waits 15 s after the lost reply; Send now starts again at once.
            const second = await Q.flush({ fetch, reset: true });
            const list = await Q.list();
            const pocket = await Q.getPocket();
            const photoCall = fetch.calls.find((c) => c.url.endsWith('/photo'));
            const posts = fetch.calls.filter((c) => c.url === '/quick/entries').map((c) => JSON.parse(c.body));
            out({
                first, second, afterFirst,
                order: fetch.calls.map((c) => c.method + ' ' + c.url),
                states: list.map((e) => [e.client_key, e.state, e.server_id ?? null, e.photo ? e.photo.bytes === null : null]),
                keys: posts.map((p) => [p.client_key, p.payload_sha]),
                csrf: fetch.calls.filter((c) => c.method === 'POST').map((c) => c.headers['X-CSRF-TOKEN']),
                accept: fetch.calls[0].headers.Accept,
                photo: photoCall ? { method: photoCall.method, spoof: photoCall.body.get('_method'), name: photoCall.body.get('photo').name, size: photoCall.body.get('photo').size } : null,
                pocket: pocket ? { etag: pocket.etag, today: pocket.data.today } : null,
                counts: await Q.counts(),
            });
            JS);

        $this->assertSame([['k1', 'sent', 0, 1], ['k2', 'waiting', 1, 1]], $out['afterFirst'], 'the record that got through is in; the other waits');
        $this->assertSame([
            'GET /quick/token', 'POST /quick/entries', 'POST /quick/entries', 'GET /quick/pocket',
            'GET /quick/token', 'POST /quick/entries', 'POST /quick/entries/k2/photo',
        ], $out['order'], 'fields first, then the photo; the pocket only after sending, and only when it’s stale');
        $this->assertSame([['k1', 'p-k1'], ['k2', 'p-k2'], ['k2', 'p-k2']], $out['keys'], 'the same client key and payload on every try');
        $this->assertSame([['k1', 'sent', 1, null], ['k2', 'sent', 2, true]], $out['states'], 'photo bytes dropped once Expend’s sha256 matched');
        $this->assertSame(['tok', 'tok', 'tok', 'tok'], $out['csrf']);
        $this->assertSame('application/json', $out['accept'], 'a signed-out reply is a 401, not the login page');
        $this->assertSame(['method' => 'POST', 'spoof' => 'PUT', 'name' => 'receipt-20261125-1405.jpg', 'size' => 4], $out['photo'], 'PUT /quick/entries/{key}/photo, sent as a POST PHP can read');
        $this->assertSame(['etag' => '"e1"', 'today' => '2026-11-25'], $out['pocket']);
        $this->assertSame(['sent' => 1, 'left' => 1, 'signedOut' => false, 'offline' => false, 'busy' => false], $out['first']);
        $this->assertSame(['sent' => 2, 'left' => 0, 'signedOut' => false, 'offline' => false, 'busy' => false], $out['second']);
        $this->assertSame(0, $out['counts']['total']);
    }

    public function test_undo_after_a_lost_reply_withdraws_on_the_server_and_never_just_forgets(): void
    {
        $out = $this->node(<<<'JS'
            const base = { created_at: 1, saved_at: 1, state: 'waiting', attempts: 0, tries: 0, next_at: 0, fields: { paid_with: 'cash' }, payload_sha: 'p', photo: null, summary: { amount_minor: 1250, currency: 'GBP', what: 'Parking', date: '2026-11-25' } };
            await Q.put({ ...base, client_key: 'never' });
            await Q.put({ ...base, client_key: 'lost', created_at: 2 });
            const neverSent = await Q.withdraw('never');
            // The fields reach Expend but the reply is lost on one bar of signal.
            let phase = 'lost';
            const fetch = fakeFetch((call) => {
                if (call.url === '/quick/token') return { status: 200, body: { csrf: 't' } };
                if (call.url === '/quick/entries') return phase === 'lost' ? 'network' : { status: 200, body: { state: 'withdrawn', entry: { id: 5 } } };
                if (call.url.endsWith('/withdraw')) return { status: 200, body: { state: 'withdrawn', entry: { id: 5, state: 'withdrawn' } } };
                if (call.url === '/quick/pocket') return { status: 304 };
                return { status: 404 };
            });
            await Q.flush({ fetch, pocket: false });
            const afterLost = await Q.get('lost');
            phase = 'back';
            CTX.fetch = fetch;
            const undo = await Q.withdraw('lost');
            const pending = (await Q.get('lost')).state;
            // Q.withdraw sends at once (with the page's own fetch); wait for it.
            await new Promise((r) => setTimeout(r, 100));
            await Q.flush({ fetch, pocket: false });
            const end = await Q.get('lost');
            await Q.tidy();
            out({
                neverSent, undo, pending,
                afterLost: [afterLost.state, afterLost.tries, afterLost.attempts],
                end: end ? end.state : null,
                gone: (await Q.list()).length,
                calls: fetch.calls.map((c) => c.method + ' ' + c.url),
            });
            JS);

        $this->assertSame('deleted', $out['neverSent'], 'never tried: deleted on the phone');
        $this->assertSame(['waiting', 1, 1], $out['afterLost']);
        $this->assertSame('pending', $out['undo'], 'tried once: it may be in Expend, so it’s withdrawn there');
        $this->assertContains($out['pending'], ['withdraw_pending', 'sending'], 'marked for withdrawing (and perhaps already on its way)');
        $this->assertSame('withdrawn', $out['end']);
        $this->assertSame(0, $out['gone'], 'once Expend says withdrawn, the phone forgets it');
        $this->assertContains('POST /quick/entries/lost/withdraw', $out['calls']);
        $this->assertSame(1, count(array_filter($out['calls'], fn ($c) => $c === 'POST /quick/entries')), 'the withdraw goes instead of sending the fields again');
    }

    public function test_undo_waits_for_expends_answer_and_says_when_a_locked_year_keeps_it(): void
    {
        $out = $this->node(<<<'JS'
            const base = { created_at: 1, saved_at: 1, state: 'sent', attempts: 0, tries: 1, next_at: 0, fields: { paid_with: 'cash' }, payload_sha: 'p', photo: null, server_id: 4, summary: { amount_minor: 1250, currency: 'GBP', what: 'Parking', date: '2024-11-25' } };
            await Q.put({ ...base, client_key: 'open' });
            await Q.put({ ...base, client_key: 'locked' });
            CTX.fetch = fakeFetch((call) => {
                if (call.url === '/quick/token') return { status: 200, body: { csrf: 't' } };
                if (call.url === '/quick/entries/open/withdraw') return { status: 200, body: { state: 'withdrawn', entry: { id: 4, state: 'withdrawn' } } };
                if (call.url === '/quick/entries/locked/withdraw') return { status: 409, body: { state: 'added', message: 'Kept: 2024-25 is locked', entry: { id: 5 } } };
                return { status: 404 };
            });
            const open = await Q.withdraw('open', { wait: true });
            const locked = await Q.withdraw('locked', { wait: true });
            const kept = await Q.get('locked');
            CTX.fetch = () => Promise.reject(new TypeError('Failed to fetch'));
            await Q.put({ ...base, client_key: 'offline' });
            const offline = await Q.withdraw('offline', { wait: true });
            out({ open, locked, kept: [kept.state, kept.result], offline, offlineState: (await Q.get('offline')).state });
            JS);

        $this->assertSame('withdrawn', $out['open']);
        $this->assertSame('kept', $out['locked']);
        $this->assertSame(['sent', 'Kept: 2024-25 is locked'], $out['kept'], 'nothing more is sent; the phone says why it stays');
        $this->assertSame('pending', $out['offline']);
        $this->assertSame('withdraw_pending', $out['offlineState'], 'no signal: the withdraw waits and goes by itself');
    }

    public function test_signed_out_old_tokens_no_signal_and_a_killed_app(): void
    {
        $out = $this->node(<<<'JS'
            const base = { created_at: 1, saved_at: 1, state: 'waiting', attempts: 0, tries: 0, next_at: 0, fields: { paid_with: 'cash' }, payload_sha: 'p', photo: null, summary: { amount_minor: 1250, currency: 'GBP', what: 'Parking', date: '2026-11-25' } };
            await Q.put({ ...base, client_key: 'a' });
            // Signed out: nothing is sent and the screen says "Sign in to send".
            const out1 = await Q.flush({ fetch: fakeFetch(() => ({ status: 401, body: { message: 'Unauthenticated.' } })), pocket: false });
            const c1 = await Q.counts();
            // No signal: the entry waits 15 s; nothing reached Expend, so it isn't a "try".
            const out2 = await Q.flush({ reset: true, fetch: fakeFetch(() => 'network'), pocket: false });
            const a2 = await Q.get('a');
            // An old form token: one fresh token, then it goes.
            let n = 0;
            const f3 = fakeFetch((call) => {
                if (call.url === '/quick/token') return { status: 200, body: { csrf: 'fresh' + n++ } };
                if (call.url === '/quick/entries') return call.headers['X-CSRF-TOKEN'] === 'fresh0' ? { status: 419, body: { message: 'CSRF token mismatch.' } } : { status: 201, body: { entry: { id: 4, state: 'added', photo_wanted: false } } };
                return { status: 404 };
            });
            const out3 = await Q.flush({ reset: true, fetch: f3, pocket: false });
            // The app was killed mid-send three minutes ago: the entry goes back to waiting and is sent.
            await Q.put({ ...base, client_key: 'b', state: 'sending', sending_kind: 'fields', sending_at: Date.now() - 180_000, tries: 1 });
            const f4 = fakeFetch((call) => call.url === '/quick/token' ? { status: 200, body: { csrf: 't' } } : { status: 201, body: { entry: { id: 5, state: 'added', photo_wanted: false } } });
            const out4 = await Q.flush({ fetch: f4, pocket: false });
            out({
                out1, c1, out2, a2: [a2.state, a2.attempts, a2.tries, a2.next_at - Date.now() > 10_000],
                out3, calls3: f3.calls.map((c) => c.method + ' ' + c.url + ' ' + (c.headers['X-CSRF-TOKEN'] ?? '')),
                a3: (await Q.get('a')).state, out4, b: (await Q.get('b')).state, signedOut: await Q.getMeta('signed_out'),
            });
            JS);

        $this->assertTrue($out['out1']['signedOut']);
        $this->assertSame(['waiting' => 1, 'needs_you' => 0, 'shared' => 0, 'signed_out' => true, 'signed_out_now' => true, 'total' => 1], $out['c1']);
        $this->assertTrue($out['out2']['offline']);
        $this->assertSame(['waiting', 1, 0, true], $out['a2']);
        $this->assertSame(['GET /quick/token ', 'POST /quick/entries fresh0', 'GET /quick/token ', 'POST /quick/entries fresh1'], $out['calls3']);
        $this->assertSame('sent', $out['a3']);
        $this->assertSame(1, $out['out3']['sent']);
        $this->assertSame('sent', $out['b']);
        $this->assertFalse($out['signedOut'], 'signed in again: the screen stops saying so');
    }

    public function test_opening_signed_in_again_clears_sign_in_to_send_and_a_photo_too_big_can_be_left_behind(): void
    {
        $out = $this->node(<<<'JS'
            const base = { created_at: 1, saved_at: 1, attempts: 0, tries: 1, next_at: 0, fields: { paid_with: 'cash' }, payload_sha: 'p', summary: { amount_minor: 1250, currency: 'GBP', what: 'Parking', date: '2026-11-25' } };
            // Signed out, nothing waiting: the screen still learns it on open.
            const s1 = await Q.checkSignIn(fakeFetch(() => ({ status: 401, body: { error: 'signed_out' } })));
            const c1 = await Q.counts();
            // Back after signing in (no fresh page load): the token answers, and "Sign in to send" goes at once.
            const s2 = await Q.checkSignIn(fakeFetch(() => ({ status: 200, body: { csrf: 't' } })));
            const c2 = await Q.counts();
            const s3 = await Q.checkSignIn(fakeFetch(() => 'network'));
            // A photo the server refused as too big, on an entry Expend has: kept without it.
            await Q.put({ ...base, client_key: 'big', state: 'needs_you', server_id: 15, last_error: 'Too big to send from your phone (over 1.9 MB). Upload it from a computer.',
                photo: { bytes: new ArrayBuffer(8), type: 'image/heic', sha256: 'a'.repeat(64), size: 8, name: 'r.heic', original: true } });
            await Q.put({ ...base, client_key: 'new', state: 'needs_you', photo: { bytes: new ArrayBuffer(8), type: 'image/heic', sha256: 'b'.repeat(64), size: 8, name: 'r.heic', original: true } });
            const kept = [await Q.keepWithoutPhoto('big'), await Q.keepWithoutPhoto('new')];
            const big = await Q.get('big');
            const c3 = await Q.counts();
            out({ s1, c1, s2, c2, s3, kept, big: [big.state, big.photo.bytes, big.last_error], c3: [c3.waiting, c3.needs_you] });
            JS);

        $this->assertSame(['signed_out', true, false], [$out['s1'], $out['c1']['signed_out_now'], $out['c1']['signed_out']], 'nothing waits, but the screen knows');
        $this->assertSame(['signed_in', false], [$out['s2'], $out['c2']['signed_out_now']]);
        $this->assertSame('offline', $out['s3']);
        $this->assertSame([true, false], $out['kept'], 'only an entry Expend already has can be kept without its photo');
        $this->assertSame(['sent', null, null], $out['big']);
        $this->assertSame([0, 1], $out['c3'], 'the red "needs you" goes for it');
    }

    public function test_the_day_s_data_is_kept_and_asked_for_again_only_when_stale(): void
    {
        $out = $this->node(<<<'JS'
            const f = fakeFetch((call) => call.headers['If-None-Match'] === '"v1"' ? { status: 304 } : { status: 200, headers: { ETag: '"v1"' }, body: { today: '2026-11-25', chips: [{ label: 'Parking', category_id: 11 }] } });
            const a = await Q.refreshPocket({ fetch: f });
            const b = await Q.refreshPocket({ fetch: f });
            const c = await Q.refreshPocket({ fetch: f, force: true });
            const d = await Q.refreshPocket({ fetch: f, etag: '"v2"' });
            out({ calls: f.calls.map((x) => x.headers['If-None-Match'] ?? 'none'), a: a.data.chips.length, b: b.etag, c: c.data.today, d: d.etag });
            JS);

        $this->assertSame(['none', '"v1"', '"v1"'], $out['calls'], 'fresh data isn’t asked for; a stale or changed pocket is asked for with If-None-Match');
        $this->assertSame(1, $out['a']);
        $this->assertSame('"v1"', $out['b']);
        $this->assertSame('2026-11-25', $out['c'], 'a 304 keeps the data');
    }

    public function test_the_page_reads_the_pocket_whatever_is_missing(): void
    {
        $out = $this->nodeTs(<<<'JS'
            const empty = lib.normalisePocket(null);
            const p = lib.normalisePocket({
                today: '2026-11-25', locked_years: ['2023-24', { label: '2024-25' }], filed_years: [],
                categories: [{ id: 11, code: 'travel_parking', name: 'Parking, tolls and road charges', job_cost: true }, { id: 30, code: 'software', name: 'Software', job_cost: false }],
                chips: [], jobs: [{ id: 1, title: 'Leeds Arena', dates: ['2026-11-25'] }],
                rates: [{ currency: 'EUR', month: '2026-11-01', units_per_gbp: '1.1612' }],
                accounts: [{ id: 3, name: 'Starling', kind: 'bank', last_line_on: '2026-11-20' }],
            });
            out({
                empty: [empty.jobs.length, empty.chips.length, empty.locked_years.length],
                chips: p.chips.map((c) => [c.label, c.category_id]),
                locked: p.locked_years, days: p.jobs[0].days, rate: p.rates.EUR,
                words: [lib.countsWords({ waiting: 2, needs_you: 1, shared: 0, signed_out: false, total: 3 }), lib.countsWords({ waiting: 0, needs_you: 0, shared: 0, signed_out: false, total: 0 }), lib.countsWords({ waiting: 1, needs_you: 0, shared: 2, signed_out: true, total: 3 })],
                years: [lib.taxYearOf('2026-04-05'), lib.taxYearOf('2026-04-06'), lib.taxYearOf('2027-01-01')],
            });
            JS);

        $this->assertSame([0, 0, 0], $out['empty']);
        $this->assertSame([['Parking', 11], ['Fuel', null], ['Food', null], ['Taxi', null], ['Train', null], ['Kit', null]], $out['chips'], 'the six default chips before any history');
        $this->assertSame(['2023-24', '2024-25'], $out['locked']);
        $this->assertSame(['2026-11-25'], $out['days']);
        $this->assertSame(['2026-11' => 1.1612], $out['rate']);
        $this->assertSame([['waiting' => '2 waiting', 'needs' => '1 needs you'], null, ['waiting' => 'Sign in to send 3', 'needs' => null]], $out['words']);
        $this->assertSame(['2025-26', '2026-27', '2026-27'], $out['years']);
    }

    public function test_the_home_screen_manifest_and_the_worker_template(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertIsArray($manifest, 'the manifest parses');
        $this->assertSame(['Expend', 'Expend', '/inbox', '/quick?source=home', '/', 'standalone'], [$manifest['name'], $manifest['short_name'], $manifest['id'], $manifest['start_url'], $manifest['scope'], $manifest['display']]);
        $this->assertSame(['/quick?source=shortcut', '/todo'], array_column($manifest['shortcuts'], 'url'));
        $this->assertSame(['action' => '/quick/share', 'method' => 'POST', 'enctype' => 'multipart/form-data'], array_intersect_key($manifest['share_target'], ['action' => 0, 'method' => 0, 'enctype' => 0]));
        $this->assertSame('files', $manifest['share_target']['params']['files'][0]['name']);
        $this->assertContains('application/pdf', $manifest['share_target']['params']['files'][0]['accept']);
        foreach (array_merge($manifest['icons'], ...array_column($manifest['shortcuts'], 'icons')) as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            [$w, $h] = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], "{$w}x{$h}");
        }
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertStringContainsString('href="/quick"', (string) file_get_contents(public_path('offline.html')));

        $sw = (string) file_get_contents(resource_path('sw/sw.js'));
        $this->assertSame(1, substr_count($sw, "const VERSION = '__VERSION__';"), 'the version marker QuickController fills in');
        $this->assertSame(1, substr_count($sw, 'const PRECACHE = [/* __PRECACHE__ */];'), 'the precache marker QuickController fills in');
        $this->assertStringContainsString("importScripts('/quick-store.js?v='", $sw);
        $this->assertStringContainsString("'X-Expend-Shell'", $sw, 'only a real screen is kept, never the login page');
        $this->assertStringContainsString("redirect: 'manual'", $sw);
        $this->assertFileExists(resource_path('sw/off.js'));

        // The template runs as it is, and filled in.
        $filled = str_replace(["'__VERSION__'", '[/* __PRECACHE__ */]'], [json_encode('abc123def456'), json_encode(['/offline.html', '/build/assets/app-x.js'])], $sw);
        $out = $this->nodeWorker($filled);
        $this->assertSame(['install', 'activate', 'fetch', 'sync', 'message'], $out['events']);
        $this->assertSame('/quick-store.js?v=abc123def456', $out['imported']);
    }

    public function test_the_blade_page_loads_the_store_and_keeps_the_launch_address(): void
    {
        $blade = (string) file_get_contents(resource_path('views/app.blade.php'));
        $this->assertStringContainsString('<title inertia>Expend</title>', $blade);
        $this->assertStringContainsString('apple-mobile-web-app-title" content="Expend"', $blade);
        $this->assertStringContainsString('apple-mobile-web-app-status-bar-style" content="default"', $blade);
        $this->assertStringContainsString("'quick/') ? ', viewport-fit=cover'", $blade, 'viewport-fit=cover only for the quick screens');
        $this->assertStringContainsString('window.__quickLaunch', $blade);
        $this->assertStringContainsString('ExpendQuick.boot()', $blade);
        $this->assertMatchesRegularExpression('#<script src="/quick-store.js\?v=\{\{.*\}\}" defer></script>#', $blade);
        $this->assertLessThan(strpos($blade, '@vite('), strpos($blade, '/quick-store.js'), 'the store runs before the app');
        $this->assertStringContainsString('nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"', $blade, 'the inline script carries the page’s nonce');
    }

    /** Run a script against public/quick-store.js in a fresh vm context with a stand-in IndexedDB and fetch. */
    private function node(string $script): mixed
    {
        $this->needsNode();
        $file = tempnam(sys_get_temp_dir(), 'quick').'.mjs';
        file_put_contents($file, self::HARNESS."\n".'const Q = loadStore(process.argv[2]);'."\n".'const out = (v) => console.log(JSON.stringify(v));'."\n".$script);
        $run = new Process(['node', '--no-warnings', $file, public_path('quick-store.js')], null, ['TZ' => 'Europe/London']);
        $run->setTimeout(60);
        $run->run();
        @unlink($file);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput().$run->getOutput());

        return json_decode(trim($run->getOutput()), true);
    }

    /** Run a script against resources/js/lib/quick.ts (its types stripped), with quick-store.js loaded as the page has it. */
    private function nodeTs(string $script): mixed
    {
        $this->needsNode();
        if (! is_dir(base_path('node_modules/react'))) {
            $this->markTestSkipped('The node modules aren’t installed.');
        }
        $file = tempnam(sys_get_temp_dir(), 'quickts').'.mjs';
        file_put_contents($file, "const lib = await import(process.argv[2]);\nconst out = (v) => console.log(JSON.stringify(v));\n".$script);
        $run = new Process(['node', '--no-warnings', '--experimental-strip-types', $file, 'file://'.resource_path('js/lib/quick.ts')], base_path(), ['TZ' => 'Europe/London']);
        $run->setTimeout(60);
        $run->run();
        @unlink($file);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return json_decode(trim($run->getOutput()), true);
    }

    /** Evaluate the worker in a stand-in worker scope: which events it listens for, and what it imports. */
    private function nodeWorker(string $source): mixed
    {
        $this->needsNode();
        $sw = tempnam(sys_get_temp_dir(), 'sw').'.js';
        file_put_contents($sw, $source);
        $file = tempnam(sys_get_temp_dir(), 'swrun').'.mjs';
        file_put_contents($file, <<<'JS'
            import fs from 'node:fs';
            import vm from 'node:vm';
            const events = [];
            let imported = null;
            const scope = {
                location: { origin: 'https://expend.test' },
                addEventListener: (type) => events.push(type),
                importScripts: (url) => { imported = url; scope.ExpendQuick = {}; },
                URL, Response, Request, Headers, console, Set, Array,
            };
            scope.self = scope;
            vm.createContext(scope);
            vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), scope);
            console.log(JSON.stringify({ events, imported }));
            JS);
        $run = new Process(['node', '--no-warnings', $file, $sw]);
        $run->run();
        @unlink($file);
        @unlink($sw);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return json_decode(trim($run->getOutput()), true);
    }

    private function needsNode(): void
    {
        $probe = new Process(['node', '-e', 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Node 22 or later is needed to run the phone code.');
        }
    }

    /**
     * Loads quick-store.js as a classic script with a fake self, a small in-memory IndexedDB and a scripted fetch
     * (fakeFetch(fn): fn gets {url, method, headers, body} and returns {status, body, headers}, or 'network').
     */
    private const HARNESS = <<<'JS'
        import fs from 'node:fs';
        import vm from 'node:vm';

        function fakeIndexedDB() {
            const dbs = new Map();
            const later = (fn) => setTimeout(fn, 0);
            class Tx {
                constructor(db) { this.db = db; this.pending = 0; this.done = false; this.oncomplete = null; this.onerror = null; this.onabort = null; this.error = null; later(() => this.check()); }
                objectStore(name) { return new Store(this.db.defs.get(name), this.db.stores.get(name), this); }
                check() { if (this.done || this.pending > 0) return; later(() => { if (this.done || this.pending > 0) return; this.done = true; if (this.oncomplete) this.oncomplete(); }); }
                fail(e) { if (this.done) return; this.done = true; this.error = e; if (this.onerror) this.onerror(); }
                abort() { if (this.done) return; this.done = true; if (this.onabort) this.onabort(); }
            }
            class Store {
                constructor(def, data, tx) { this.def = def; this.data = data; this.tx = tx; }
                req(fn) {
                    const r = { result: undefined, error: null, onsuccess: null, onerror: null };
                    this.tx.pending++;
                    later(() => {
                        try { r.result = fn(); if (r.onsuccess) r.onsuccess({ target: r }); }
                        catch (e) { r.error = e; if (r.onerror) r.onerror({ target: r }); this.tx.fail(e); }
                        this.tx.pending--; this.tx.check();
                    });
                    return r;
                }
                keyOf(value, key) {
                    if (!this.def.keyPath) return key ?? ++this.def.seq;
                    if (value[this.def.keyPath] === undefined && this.def.autoIncrement) value[this.def.keyPath] = ++this.def.seq;
                    return value[this.def.keyPath];
                }
                get(key) { return this.req(() => (this.data.has(key) ? structuredClone(this.data.get(key)) : undefined)); }
                getAll() { return this.req(() => [...this.data.values()].map((v) => structuredClone(v))); }
                count() { return this.req(() => this.data.size); }
                put(value, key) { const v = structuredClone(value); const k = this.keyOf(v, key); return this.req(() => { this.data.set(k, v); return k; }); }
                add(value, key) { const v = structuredClone(value); const k = this.keyOf(v, key); return this.req(() => { if (this.data.has(k)) throw new Error('ConstraintError'); this.data.set(k, v); return k; }); }
                delete(key) { return this.req(() => { this.data.delete(key); }); }
            }
            return {
                open(name, version) {
                    const req = { result: null, error: null, onsuccess: null, onerror: null, onupgradeneeded: null, onblocked: null };
                    later(() => {
                        let db = dbs.get(name);
                        if (!db) { db = { defs: new Map(), stores: new Map(), version: 0 }; dbs.set(name, db); }
                        req.result = {
                            objectStoreNames: { contains: (n) => db.defs.has(n) },
                            createObjectStore(n, opts = {}) { db.defs.set(n, { keyPath: opts.keyPath, autoIncrement: !!opts.autoIncrement, seq: 0 }); db.stores.set(n, new Map()); },
                            transaction: () => new Tx(db),
                            close() {},
                        };
                        if (db.version < version) { db.version = version; if (req.onupgradeneeded) req.onupgradeneeded(); }
                        if (req.onsuccess) req.onsuccess();
                    });
                    return req;
                },
            };
        }

        function fakeFetch(script) {
            const calls = [];
            const fn = async (url, init = {}) => {
                const call = { url, method: init.method || 'GET', headers: init.headers || {}, body: init.body };
                calls.push(call);
                const r = await script(call, calls.length);
                if (r === 'network') throw new TypeError('Failed to fetch');
                const headers = new Map(Object.entries(r.headers || {}).map(([k, v]) => [k.toLowerCase(), v]));
                if (r.body !== undefined && r.body !== null && !headers.has('content-type')) headers.set('content-type', 'application/json');
                return { status: r.status, headers: { get: (k) => headers.get(k.toLowerCase()) ?? null }, json: async () => r.body };
            };
            fn.calls = calls;
            return fn;
        }

        function loadStore(path) {
            const ctx = {
                console, Intl, TextEncoder, setTimeout, clearTimeout, setInterval, clearInterval, structuredClone, FormData, Blob, AbortController,
                crypto: globalThis.crypto, indexedDB: fakeIndexedDB(),
                // No network unless a test gives one (CTX.fetch = fakeFetch(...)).
                fetch: () => Promise.reject(new TypeError('Failed to fetch')),
            };
            ctx.self = ctx;
            vm.createContext(ctx);
            vm.runInContext(fs.readFileSync(path, 'utf8'), ctx);
            globalThis.CTX = ctx;
            return ctx.ExpendQuick;
        }
        JS;
}
