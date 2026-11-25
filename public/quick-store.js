/**
 * Expend quick add: what the phone keeps and how it sends it. One plain script, loaded by every page
 * (resources/views/app.blade.php) and by the service worker (importScripts), so the page and the worker follow the
 * same rules. It defines self.ExpendQuick.
 *
 * Kept on the phone, in the IndexedDB database 'expend-quick':
 *   entries  one per expense added on the phone, keyed by its client_key, with the photo's bytes until Expend has them
 *   shared   files and text shared from Android's share sheet, not yet made into entries
 *   pocket   the day's data from GET /quick/pocket (jobs, categories, chips, rates…), so the screen never waits
 *   meta     the sending lease, the unfinished entry, how you paid last time, the last save, sign-in state
 *
 * Sending: under the Web Lock 'expend-send' (or a 60-second lease), ask GET /quick/token, then send the fields of
 * every waiting entry (POST /quick/entries), then the photos (PUT /quick/entries/{key}/photo). Repeating a send never
 * adds twice: the client_key is the server's key. Undo of anything that may have reached Expend is sent as a
 * withdraw, never just deleted here.
 *
 * The pure rules (keypad, nextState, backoff, the withdraw rule, parseText, defaultJob, suggestOffline,
 * sameReceipt, prune) take everything they need as arguments, and tests/Feature/QuickPhoneCodeTest.php runs them in
 * node.
 */
(function (root) {
    'use strict';

    var DB_NAME = 'expend-quick';
    var DB_VERSION = 1;
    var MINUTE = 60 * 1000;
    var DAY = 24 * 60 * MINUTE;

    /** Waits after a failed send: 15 s, 30 s, 1, 2, 5 and 10 minutes, then every 30 minutes. */
    var BACKOFF_S = [15, 30, 60, 120, 300, 600];
    var BACKOFF_MAX_S = 1800;
    /** Expend is still working on it (202): ask again in 30 s. */
    var RECEIVING_WAIT = 30 * 1000;
    /** An entry left 'sending' this long (the app was killed mid-send) goes back to waiting. */
    var STALE_SENDING = 2 * MINUTE;
    /** A photo whose bytes arrive different is sent again twice, then it needs you. */
    var PHOTO_MISMATCH_LIMIT = 3;
    var LEASE_MS = 60 * 1000;
    var LEASE_RENEW_MS = 20 * 1000;
    var FIELDS_TIMEOUT = 15 * 1000;
    var POCKET_MAX_AGE = 10 * MINUTE;
    /** Sent entries stay in Recent this long. */
    var KEEP_SENT = 14 * DAY;
    /** Shared files are never deleted without asking; after this long the screen asks. */
    var ASK_SHARED_AFTER = 14 * DAY;
    /** The job and date carry over to the next entry only on the same London day and within this time. */
    var CARRY_MS = 30 * MINUTE;
    /** Before this, yesterday's job still counts as today's (a load-out after midnight), as JobDays::OVERNIGHT_UNTIL. */
    var OVERNIGHT_UNTIL = '06:00';
    /** The same amount and currency within this many days asks "Add another?". */
    var SAME_RECEIPT_DAYS = 2;

    /** States an entry can be in on the phone. */
    var UNSENT = ['waiting', 'sending', 'photo_pending', 'receiving', 'signed_out', 'withdraw_pending', 'needs_you'];
    var FIELD_STATES = ['waiting', 'signed_out', 'receiving'];

    // ------------------------------------------------------------------ pure rules

    function has(list, value) {
        return list.indexOf(value) >= 0;
    }

    /** How long to wait after the n-th failed try in a row, in ms. */
    function backoff(attempts) {
        var n = Math.max(1, attempts | 0);
        return (n <= BACKOFF_S.length ? BACKOFF_S[n - 1] : BACKOFF_MAX_S) * 1000;
    }

    /** Opening the app, coming online and Send now start the waits again from 15 s and send at once. */
    function resetBackoff(entry) {
        var next = Object.assign({}, entry, { attempts: 0, next_at: 0 });
        if (entry.state === 'signed_out') next.state = 'waiting';
        return next;
    }

    /**
     * The on-screen keypad. digits is the currency's number of decimal places (2 for pounds, 0 for yen): no more
     * decimals than that can be typed, and none at all for yen.
     */
    function keypad(text, key, digits) {
        text = text || '';
        digits = digits === undefined || digits === null ? 2 : digits;
        if (key === 'back') return text.slice(0, -1);
        if (key === 'clear') return '';
        if (key === '.') {
            if (digits === 0 || text.indexOf('.') >= 0) return text;
            return (text === '' ? '0' : text) + '.';
        }
        if (!/^[0-9]$/.test(key)) return text;
        var dot = text.indexOf('.');
        if (dot >= 0) return text.length - dot - 1 >= digits ? text : text + key;
        if (text === '0') return key;
        if (text.length >= 7) return text;
        return text + key;
    }

    /** What the keypad shows, in the currency's minor units: '12' is 1200 and '12.5' is 1250 in pounds. Null when empty. */
    function keypadMinor(text, digits) {
        digits = digits === undefined || digits === null ? 2 : digits;
        if (!text || !/^\d+(\.\d*)?$/.test(text)) return null;
        var parts = text.split('.');
        var frac = parts[1] || '';
        if (frac.length > digits) return null;
        var whole = parseInt(parts[0] || '0', 10);
        var minor = whole * Math.pow(10, digits) + (digits > 0 ? parseInt((frac + '0000').slice(0, digits), 10) : 0);
        return minor;
    }

    /** The amount as sent to Expend: what was typed, without a trailing point ('12.' is '12'). */
    function keypadAmount(text) {
        if (!text) return null;
        var clean = text.replace(/\.$/, '');
        return clean === '' ? null : clean;
    }

    /** The keypad text for an amount in minor units, when a share or an edit fills it in: 1250 is '12.50', 1200 is '12'. */
    function keypadText(minor, digits) {
        if (minor === null || minor === undefined) return '';
        digits = digits === undefined || digits === null ? 2 : digits;
        if (digits === 0) return String(minor);
        var s = String(Math.abs(minor));
        while (s.length <= digits) s = '0' + s;
        var whole = s.slice(0, s.length - digits);
        var frac = s.slice(s.length - digits);
        return /^0+$/.test(frac) ? whole : whole + '.' + frac;
    }

    function messageOf(body, fallback) {
        if (!body || typeof body !== 'object') return fallback;
        if (typeof body.message === 'string' && body.message) return body.message;
        if (body.errors && typeof body.errors === 'object') {
            for (var k in body.errors) {
                var v = body.errors[k];
                if (Array.isArray(v) && v.length) return String(v[0]);
                if (typeof v === 'string') return v;
            }
        }
        return fallback;
    }

    function codeOf(body) {
        if (!body || typeof body !== 'object') return null;
        return body.code || body.error || body.reason || null;
    }

    function hasPhotoBytes(entry) {
        return !!(entry && entry.photo && entry.photo.bytes);
    }

    /** The server's own words for an upload over its limit; anything else (a proxy's, Laravel's) gets the phone's. */
    function tooBig(body) {
        var mine = 'Too big to send from your phone. Upload it from a computer.';
        return body && body.error === 'too_big' ? messageOf(body, mine) : mine;
    }

    /**
     * What a reply from Expend means for an entry. kind is 'fields', 'photo' or 'withdraw'; res is
     * {status, body, retryAfter} with status 0 for no signal or a timeout. Returns the fields to change, plus
     * stop: true when sending should stop for now (signed out). Pure: flush() writes the result.
     */
    function nextState(entry, kind, res, now) {
        var status = (res && res.status) | 0;
        var body = (res && res.body) || {};
        var attempts = (entry && entry.attempts) | 0;
        var back = kind === 'photo' ? 'photo_pending' : kind === 'withdraw' ? 'withdraw_pending' : 'waiting';
        var retry = function (why) {
            var a = attempts + 1;
            return { state: back, attempts: a, next_at: now + backoff(a), last_error: why };
        };
        var later = function () {
            var wait = res && res.retryAfter > 0 ? res.retryAfter * 1000 : MINUTE;
            return { state: back, next_at: now + wait, last_error: 'Expend asked to wait a moment' };
        };

        if (status === 0) return retry('No signal');
        if (status === 429) return later();
        if (status === 401 || status === 419) {
            return { state: kind === 'fields' ? 'signed_out' : back, last_error: 'Sign in to send', stop: true };
        }
        if (status >= 500 || status === 408) return retry('Expend didn’t answer (' + status + ')');

        if (kind === 'withdraw') {
            if (status === 200 || status === 201 || status === 204) {
                var final = body.state || 'withdrawn';
                if (final === 'withdrawn') {
                    return { state: 'withdrawn', attempts: 0, next_at: 0, last_error: null, result: 'Removed' };
                }
                // Expend answered with what it is now and kept it.
                return { state: 'sent', attempts: 0, next_at: 0, last_error: null, kept: true, result: messageOf(body.entry, 'Kept') };
            }
            if (status === 409) {
                // Expend kept it (a locked year): nothing to remove on the phone, and nothing more to send.
                return { state: 'sent', attempts: 0, next_at: 0, last_error: null, kept: true, result: messageOf(body, 'Kept: that year is locked') };
            }
            if (status === 404) return retry('Not found yet');
            return { state: 'needs_you', last_error: messageOf(body, 'Expend couldn’t remove it (' + status + ')') };
        }

        if (kind === 'photo') {
            if (status === 200 || status === 201) {
                var mine = entry && entry.photo ? entry.photo.sha256 : null;
                if (body.sha256 && mine && body.sha256 === mine) {
                    return {
                        state: 'sent',
                        attempts: 0,
                        next_at: 0,
                        last_error: null,
                        photo_drop: true,
                        mismatches: 0,
                        document_id: body.document_id || null,
                    };
                }
                return photoMismatch(entry, now, attempts);
            }
            if (status === 404) {
                // Expend doesn't have the entry yet: send its fields again first.
                var a = attempts + 1;
                return { state: 'waiting', attempts: a, next_at: a > 1 ? now + backoff(a - 1) : now, last_error: null };
            }
            if (status === 413) return { state: 'needs_you', last_error: tooBig(body) };
            if (status === 422) {
                if (codeOf(body) === 'photo_mismatch') return photoMismatch(entry, now, attempts);
                return retry(messageOf(body, 'The photo didn’t arrive'));
            }
            return { state: 'needs_you', last_error: messageOf(body, 'Expend couldn’t take the photo (' + status + ')') };
        }

        // The fields.
        if (status === 202) {
            return { state: 'receiving', next_at: now + RECEIVING_WAIT, last_error: null };
        }
        if (status === 200 || status === 201) {
            var server = body.entry || {};
            if (body.state === 'withdrawn') {
                return { state: 'withdrawn', attempts: 0, next_at: 0, last_error: null, result: 'Removed', server_id: server.id || null };
            }
            // photo_wanted says whether Expend still wants the photo (not when it's a duplicate, or already has it).
            var wanted = typeof server.photo_wanted === 'boolean' ? server.photo_wanted : hasPhotoBytes(entry);
            var patch = {
                state: wanted && hasPhotoBytes(entry) ? 'photo_pending' : 'sent',
                attempts: 0,
                next_at: 0,
                last_error: null,
                words: server.words || (entry && entry.words) || null,
                result: body.changed
                    ? messageOf(body, 'This was sent before your change, so nothing changed. Open it to change it.')
                    : server.message || null,
                server_id: server.id || (entry && entry.server_id) || null,
                server_state: server.state || null,
                href: server.href || (server.id ? '/quick/entries/' + server.id : null),
                warnings: (Array.isArray(body.warnings) ? body.warnings : [])
                    .map(function (w) {
                        return typeof w === 'string' ? w : w && w.message;
                    })
                    .filter(Boolean),
                changed: !!body.changed,
            };
            if (!wanted && hasPhotoBytes(entry)) patch.photo_drop = true;
            return patch;
        }
        if (status === 413) return { state: 'needs_you', last_error: tooBig(body) };
        if (status === 404) return retry('Not found');
        return { state: 'needs_you', last_error: messageOf(body, 'Expend couldn’t take this (' + status + ')') };
    }

    function photoMismatch(entry, now, attempts) {
        var m = ((entry && entry.mismatches) | 0) + 1;
        if (m >= PHOTO_MISMATCH_LIMIT) {
            return { state: 'needs_you', mismatches: m, last_error: 'The photo didn’t arrive in one piece. Tap Send now on better signal.' };
        }
        var a = attempts + 1;
        return { state: 'photo_pending', mismatches: m, attempts: a, next_at: now + backoff(a), last_error: 'The photo didn’t arrive in one piece' };
    }

    /**
     * Apply a reply to the entry as it is now. If Undo was tapped while the request was out, the entry stays
     * 'withdraw_pending' (its withdraw is sent next), whatever the reply.
     */
    function applyPatch(current, patch, kind) {
        var next = Object.assign({}, current, patch);
        delete next.stop;
        delete next.photo_drop;
        if (patch.photo_drop && current.photo) next.photo = Object.assign({}, current.photo, { bytes: null });
        if (current.state === 'withdraw_pending' && kind !== 'withdraw') {
            next.state = 'withdraw_pending';
            next.next_at = 0;
        }
        next.sending_kind = null;
        next.sending_at = null;
        if ((next.state === 'sent' || next.state === 'photo_pending') && !current.sent_at) next.sent_at = Date.now();
        return next;
    }

    /**
     * Undo and Delete: an entry never tried is deleted here; anything that may have reached Expend becomes
     * 'withdraw_pending' and is withdrawn on the server, so an expense made by a send whose reply was lost goes too.
     */
    function withdrawRule(entry) {
        if (!entry) return 'gone';
        if (entry.state === 'withdrawn') return 'delete';
        if (entry.state === 'withdraw_pending') return 'withdraw';
        if ((entry.tries | 0) === 0 && entry.state !== 'sending') return 'delete';
        return 'withdraw';
    }

    /** Entries left 'sending' for over 2 minutes (the app was killed mid-send), with the state each goes back to. */
    function recoverStale(entries, now) {
        var out = [];
        (entries || []).forEach(function (e) {
            if (e.state === 'sending' && now - (e.sending_at || 0) > STALE_SENDING) {
                var kind = e.sending_kind;
                out.push({ key: e.client_key, state: kind === 'photo' ? 'photo_pending' : kind === 'withdraw' ? 'withdraw_pending' : 'waiting' });
            }
        });
        return out;
    }

    /** Is the entry due to be sent now? */
    function isDue(entry, now) {
        if (!has(['waiting', 'signed_out', 'receiving', 'photo_pending', 'withdraw_pending'], entry.state)) return false;
        return (entry.next_at || 0) <= now;
    }

    /** What the lists show for an entry's state. */
    function stateWords(entry) {
        switch (entry.state) {
            case 'waiting':
            case 'receiving':
                return 'Waiting to send';
            case 'sending':
                return 'Sending…';
            case 'photo_pending':
                return 'Photo still to send';
            case 'sent':
                return 'Sent ✓';
            case 'needs_you':
                return 'Needs you';
            case 'signed_out':
                return 'Sign in to send';
            case 'withdraw_pending':
                return 'Removing…';
            case 'withdrawn':
                return 'Removed';
            default:
                return entry.state || '';
        }
    }

    /** Sent entries older than 14 days and withdrawn ones are removed; shared files over 14 days old are only asked about. */
    function prune(entries, shared, now) {
        var remove = [];
        (entries || []).forEach(function (e) {
            if (e.state === 'withdrawn') remove.push(e.client_key);
            else if (e.state === 'sent' && now - (e.sent_at || e.saved_at || e.created_at || 0) > KEEP_SENT) remove.push(e.client_key);
        });
        var ask = (shared || [])
            .filter(function (s) {
                return now - (s.at || 0) > ASK_SHARED_AFTER;
            })
            .map(function (s) {
                return s.id;
            });
        return { remove: remove, askShared: ask };
    }

    // Dates are YYYY-MM-DD, worked out at UTC noon so a clock change never moves a day.
    function addDays(date, n) {
        var d = new Date(date + 'T12:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    }

    function daysBetween(a, b) {
        return Math.round((Date.parse(b + 'T12:00:00Z') - Date.parse(a + 'T12:00:00Z')) / DAY);
    }

    /** The date and time in London for a moment (ms). */
    function london(ms) {
        var parts = {};
        new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/London',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(new Date(ms))
            .forEach(function (p) {
                parts[p.type] = p.value;
            });
        return { date: parts.year + '-' + parts.month + '-' + parts.day, time: parts.hour + ':' + parts.minute };
    }

    /** The UK tax year a date is in: 2026-04-05 is '2025-26', 2026-04-06 is '2026-27'. */
    function taxYear(date) {
        var y = parseInt(date.slice(0, 4), 10);
        var start = date.slice(5) >= '04-06' ? y : y - 1;
        return start + '-' + String((start + 1) % 100).padStart(2, '0');
    }

    function jobDays(job) {
        return (job && (job.days || job.dates)) || [];
    }

    /**
     * Today's job. Jobs come from the pocket (from 45 days back to 14 ahead, not cancelled, with their days).
     * o: {date, today, time, now, launchJobId, carry: {job_id, job_source, date, day, at}, jobCost}
     *   - ?job=14 in the launch address picks that job as yours;
     *   - the last save's job and date carry over on the same London day within 30 minutes;
     *   - exactly one job working on the date is picked ('on today'); before 06:00 yesterday's job counts too;
     *   - two or more are listed first, none picked; with none, jobs a day either side are listed, none picked;
     *   - the picked job is dropped when the category isn't a job cost (jobCost false), unless you picked it.
     * Returns {job_id, source ('you'|'today'|null), date, on: [ids], near: [ids], carried}.
     */
    function defaultJob(pocket, o) {
        var jobs = (pocket && pocket.jobs) || [];
        var date = o.date || o.today;
        var result = { job_id: null, source: null, date: date, on: [], near: [], carried: false };
        if (o.launchJobId) {
            result.job_id = Number(o.launchJobId);
            result.source = 'you';
            return result;
        }
        var carry = o.carry;
        if (carry && carry.at && o.now - carry.at < CARRY_MS && carry.day === o.today) {
            result.carried = true;
            result.date = carry.date || date;
            result.job_id = carry.job_id || null;
            result.source = carry.job_id ? carry.job_source || 'today' : null;
            date = result.date;
        }
        var dates = [date];
        if (date === o.today && o.time && o.time < OVERNIGHT_UNTIL) dates.push(addDays(date, -1));
        var on = jobs.filter(function (j) {
            return dates.some(function (d) {
                return has(jobDays(j), d);
            });
        });
        var near = jobs.filter(function (j) {
            return !has(on, j) && (has(jobDays(j), addDays(date, -1)) || has(jobDays(j), addDays(date, 1)));
        });
        result.on = on.map(function (j) {
            return j.id;
        });
        result.near = near.map(function (j) {
            return j.id;
        });
        if (!result.carried) {
            result.job_id = on.length === 1 ? on[0].id : null;
            result.source = result.job_id ? 'today' : null;
        }
        if (result.job_id && result.source !== 'you' && o.jobCost === false) {
            result.job_id = null;
            result.source = null;
        }
        return result;
    }

    function words(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/[^a-z0-9£€$&' -]+/g, ' ')
            .split(/\s+/)
            .filter(function (w) {
                return w.length > 0;
            });
    }

    /**
     * A category for what was typed, with no signal: your own last 50 entries' words first
     * (pocket.recent_whats: [{what, category_id, count}]), then the word list (pocket.hints:
     * [{word, category_id}] or [{words: [...], category_id}]). Returns {category_id, count, source, what} or null.
     */
    function suggestOffline(pocket, what) {
        var typed = words(what);
        if (!typed.length || !pocket) return null;
        var joined = typed.join(' ');
        var recent = pocket.recent_whats || [];
        var best = null;
        recent.forEach(function (r) {
            if (!r || !r.category_id) return;
            var w = words(r.what).join(' ');
            if (!w) return;
            var score = w === joined ? 3 : w.split(' ')[0] === typed[0] ? 2 : 0;
            if (score && (!best || score > best.score || (score === best.score && (r.count || 0) > best.count))) {
                best = { score: score, category_id: r.category_id, count: r.count || 1, what: r.what };
            }
        });
        if (best) return { category_id: best.category_id, count: best.count, source: 'recent', what: best.what };
        var hints = pocket.hints || [];
        for (var i = 0; i < hints.length; i++) {
            var h = hints[i];
            var list = h.words || (h.word ? [h.word] : []);
            for (var j = 0; j < list.length; j++) {
                var hw = String(list[j]).toLowerCase();
                if (hw.indexOf(' ') >= 0 ? (' ' + joined + ' ').indexOf(' ' + hw + ' ') >= 0 : has(typed, hw)) {
                    return { category_id: h.category_id || null, code: h.code || null, count: 0, source: 'hint', what: list[j] };
                }
            }
        }
        return null;
    }

    var DEAD = ['withdrawn', 'withdraw_pending', 'dismissed', 'duplicate'];

    /**
     * The same receipt twice? candidate: {amount_minor, currency, date, client_key}; records: this phone's entries
     * and pocket.recent (the server's entries, Telegram drafts and hand-added expenses from the last 3 days), each
     * {amount_minor, currency, date, at, what, state, client_key}. The same amount and currency within 2 days gives
     * the latest such record, else null.
     */
    function sameReceipt(candidate, records) {
        if (!candidate || !candidate.amount_minor) return null;
        var cur = (candidate.currency || 'GBP').toUpperCase();
        var found = null;
        (records || []).forEach(function (r) {
            if (!r || has(DEAD, r.state || r.status)) return;
            if (candidate.client_key && r.client_key === candidate.client_key) return;
            if ((r.amount_minor | 0) !== (candidate.amount_minor | 0)) return;
            if ((r.currency || 'GBP').toUpperCase() !== cur) return;
            if (!r.date || !candidate.date || Math.abs(daysBetween(r.date, candidate.date)) > SAME_RECEIPT_DAYS) return;
            var at = typeof r.at === 'number' ? r.at : Date.parse(r.at || r.date + 'T12:00:00Z');
            if (!found || at > found._at) found = Object.assign({}, r, { _at: at });
        });
        if (found) delete found._at;
        return found;
    }

    // -------------------------------------------------------------- reading text
    // The same rules as the server's App\Domain\Quick\QuickText::parse (tests/fixtures/quick-text.json is read by
    // both tests). No lookbehind in these patterns: older iPhones can't compile it.

    var SYMBOLS = { '£': 'GBP', '€': 'EUR', $: 'USD' };
    /** Currencies taken by their code in any case; any other ISO code only in capitals ("15 CHF"). */
    var CODES = ['gbp', 'eur', 'usd', 'chf', 'cad', 'aud', 'nzd', 'jpy', 'sek', 'nok', 'dkk', 'pln', 'czk', 'huf', 'aed', 'isk'];
    var SHORT_WORDS = 6;
    var WEEKDAYS = {
        monday: 1,
        mon: 1,
        tuesday: 2,
        tue: 2,
        tues: 2,
        wednesday: 3,
        wed: 3,
        weds: 3,
        thursday: 4,
        thu: 4,
        thur: 4,
        thurs: 4,
        friday: 5,
        fri: 5,
        saturday: 6,
        sat: 6,
        sunday: 7,
        sun: 7,
    };
    var MONTH_WORDS =
        'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
    var WEEKDAY_WORDS = '(?:mon(?:day)?|tue(?:s(?:day)?)?|wed(?:s|nesday)?|thu(?:r(?:s(?:day)?)?)?|fri(?:day)?|sat(?:urday)?|sun(?:day)?)';
    var MONTH_NUMBERS = { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12 };
    /** Words that say what a cost was, never which job (QuickText::NOT_NAMES). */
    var NOT_NAMES = [
        'the',
        'and',
        'for',
        'parking',
        'park',
        'car',
        'fuel',
        'petrol',
        'diesel',
        'food',
        'lunch',
        'dinner',
        'breakfast',
        'coffee',
        'taxi',
        'train',
        'tube',
        'bus',
        'hotel',
        'kit',
        'cable',
        'tape',
        'cash',
        'card',
        'personal',
        'business',
        'today',
        'yesterday',
        'arena',
        'hall',
        'centre',
        'center',
        'theatre',
        'events',
        'event',
        'show',
        'live',
        'awards',
        'conference',
        'festival',
        'stadium',
        'club',
        'venue',
        'station',
        'services',
        'airport',
    ];
    /** QuickText::ASKING: a message starting with one of these is a question, "?" or not. */
    var ASKING = [
        'how',
        'what',
        'whats',
        'what’s',
        "what's",
        'why',
        'when',
        'where',
        'which',
        'who',
        'whose',
        'can',
        'could',
        'should',
        'would',
        'shall',
        'do',
        'does',
        'did',
        'is',
        'are',
        'was',
        'were',
        'will',
        'has',
        'have',
        'am',
        'tell',
        'show',
        'explain',
        'please',
    ];
    /** QuickText::CURRENCY_WORDS, and the other currencies' names that are never booked in pounds. */
    var CURRENCY_WORDS = {
        euro: 'EUR',
        euros: 'EUR',
        dollar: 'USD',
        dollars: 'USD',
        bucks: 'USD',
        pound: 'GBP',
        pounds: 'GBP',
        quid: 'GBP',
        yen: 'JPY',
    };
    var OTHER_MONEY_WORDS = [
        'franc',
        'francs',
        'krona',
        'kronor',
        'krone',
        'kroner',
        'zloty',
        'zlotys',
        'forint',
        'forints',
        'dirham',
        'dirhams',
        'rupee',
        'rupees',
        'lira',
        'rand',
        'peso',
        'pesos',
        'yuan',
        'renminbi',
        'ringgit',
        'baht',
        'shekel',
        'shekels',
        'koruna',
        'cents',
        'pence',
    ];
    var NOT_BEFORE_LAST = ['on', 'of', 'over', 'under', 'than', 'about', 'per', 'is', 'was'];
    var NOT_BEFORE_NUMBER = ['at', 'by', 'for', 'from', 'to', 'until', 'till', 'before', 'after', 'around'];
    /** QuickText::COST_WORDS: a plain whole number after one of these is an amount in pounds ("parking 8"). */
    var COST_WORDS = [
        'parking',
        'park',
        'toll',
        'tolls',
        'fuel',
        'petrol',
        'diesel',
        'taxi',
        'cab',
        'uber',
        'train',
        'tube',
        'bus',
        'tram',
        'ferry',
        'hotel',
        'food',
        'lunch',
        'dinner',
        'breakfast',
        'coffee',
        'snack',
        'sandwich',
        'meal',
        'kit',
        'cable',
        'cables',
        'tape',
        'batteries',
        'stamps',
        'postage',
    ];
    /** QuickText::MAX_HUNDREDTHS: the keypad's limit, £9,999,999, in hundredths of any currency. */
    var MAX_HUNDREDTHS = 999999999;
    var AMOUNT = '(?:([£€$])\\s?)?(\\d{1,3}(?:,\\d{3})+|\\d+)(?:\\.(\\d{1,3}))?(?:\\s?([A-Za-z]{3}))?';
    var FIRST = new RegExp('^(?:(?:spent|paid)\\s+)?' + AMOUNT + '(?=\\s|$)(.*)$', 'i');
    var LAST = new RegExp('^(.+?)\\s' + AMOUNT + '$', 'i');
    // A currency's decimal places, for a code the page doesn't pass digits for (Money::digits): the zero ones.
    var ZERO_DIGITS = ['JPY', 'KRW', 'ISK', 'VND', 'CLP', 'UGX', 'XAF', 'XOF', 'XPF', 'PYG', 'RWF', 'KMF', 'GNF', 'DJF', 'BIF', 'VUV'];
    var THREE_DIGITS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    function digitsFor(currency, ctx) {
        if (ctx && typeof ctx.digits === 'function') return ctx.digits(currency);
        return has(ZERO_DIGITS, currency) ? 0 : has(THREE_DIGITS, currency) ? 3 : 2;
    }

    var isoCodes = null;

    function isoCode(code) {
        if (isoCodes === null) {
            try {
                isoCodes = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('currency') : [];
            } catch {
                isoCodes = [];
            }
        }
        return has(isoCodes, code) || has(ZERO_DIGITS, code) || has(THREE_DIGITS, code);
    }

    /** QuickText::amountOf: {minor, currency, amount} or null (an unknown code, more decimals than the currency has). */
    function amountOf(symbol, whole, decimals, code, ctx) {
        var currency = SYMBOLS[symbol] || 'GBP';
        if (code !== '') {
            var known = has(CODES, code.toLowerCase()) || (code === code.toUpperCase() && isoCode(code));
            if (!known || (symbol !== '' && code.toUpperCase() !== currency)) return null;
            currency = code.toUpperCase();
        }
        var digits = digitsFor(currency, ctx);
        var dec = decimals;
        if (digits === 2) {
            if (dec.length > 2) return null;
        } else {
            dec = dec.replace(/0+$/, '');
            if (dec.length > digits) return null;
        }
        var unit = Math.pow(10, digits);
        var minor = parseInt(whole.replace(/,/g, ''), 10) * unit + (digits > 0 ? parseInt((dec + '0000').slice(0, digits), 10) : 0);
        // Too big to be a cost added from the phone (QuickText::MAX_HUNDREDTHS).
        if (!isFinite(minor) || (digits >= 2 ? minor / Math.pow(10, digits - 2) : minor * Math.pow(10, 2 - digits)) > MAX_HUNDREDTHS) return null;
        var amount = digits > 0 ? Math.floor(minor / unit) + '.' + String(minor % unit).padStart(digits, '0') : String(minor);
        return { minor: minor, currency: currency, amount: amount };
    }

    function monthOf(word) {
        return MONTH_NUMBERS[String(word).toLowerCase().slice(0, 3)] || null;
    }

    function validDay(d, m, y) {
        if (!m || d < 1 || d > 31) return false;
        var probe = new Date(Date.UTC(y || 2000, m - 1, d));
        return probe.getUTCMonth() === m - 1 && probe.getUTCDate() === d;
    }

    /**
     * The first date written out in the text (DateWords::spans, the kinds a cost is texted with): "23 Nov",
     * "Tue 23rd November 2026", "Nov 23", "23/11/26", or the first day of "12-14 Nov". {at, len, d, m, y} or null.
     */
    function firstDate(text) {
        var wd = '(?:' + WEEKDAY_WORDS + '\\.?,?\\s+)?(?:the\\s+)?';
        var th = '(?:st|nd|rd|th)?';
        var mon = '(' + MONTH_WORDS + ')\\.?';
        var dash = '\\s*(?:-|–|—|to|until|till|thru|through)\\s*';
        var of = '\\s+(?:of\\s+)?';
        var year = '(?:,?\\s+((?:19|20)\\d{2}))?';
        // (^|[^...]) stands in for a lookbehind: group 1 is the character before the day, if any.
        var before = '(^|[^\\d£$€:.\\/])';
        var patterns = [
            ['range', new RegExp(before + '\\b' + wd + '(\\d{1,2})' + th + dash + wd + '(\\d{1,2})' + th + of + mon + year + '\\b', 'gi')],
            ['single', new RegExp(before + '\\b' + wd + '(\\d{1,2})' + th + of + mon + year + '\\b', 'gi')],
            [
                'us',
                new RegExp(
                    '()\\b(?:' +
                        WEEKDAY_WORDS +
                        '\\.?,?\\s+)?' +
                        mon +
                        '\\s+(\\d{1,2})' +
                        th +
                        '(?![:.]\\d)(?:' +
                        dash +
                        '(\\d{1,2})' +
                        th +
                        '(?![:.]\\d))?' +
                        year +
                        '\\b',
                    'gi',
                ),
            ],
            ['numeric', new RegExp('()\\b(\\d{1,2})\\/(\\d{1,2})\\/((?:20)?\\d{2})\\b', 'g')],
        ];
        var found = [];
        patterns.forEach(function (p) {
            var re = p[1];
            var m;
            re.lastIndex = 0;
            while ((m = re.exec(text)) !== null) {
                var lead = m[1] || '';
                var at = m.index + lead.length;
                var len = m[0].length - lead.length;
                if (len === 0) {
                    re.lastIndex++;
                    continue;
                }
                var clash = found.some(function (f) {
                    return at < f.at + f.len && at + len > f.at;
                });
                if (clash) continue;
                var y = function (s) {
                    return s ? (s.length === 2 ? 2000 + parseInt(s, 10) : parseInt(s, 10)) : null;
                };
                var d, mo, yr;
                if (p[0] === 'range') {
                    d = parseInt(m[2], 10);
                    mo = monthOf(m[4]);
                    yr = y(m[5]);
                } else if (p[0] === 'single') {
                    d = parseInt(m[2], 10);
                    mo = monthOf(m[3]);
                    yr = y(m[4]);
                } else if (p[0] === 'us') {
                    if (String(m[2]).toLowerCase() === 'may') continue;
                    d = parseInt(m[3], 10);
                    mo = monthOf(m[2]);
                    yr = y(m[5]);
                } else {
                    d = parseInt(m[2], 10);
                    mo = parseInt(m[3], 10);
                    yr = y(m[4]);
                }
                if (!validDay(d, mo, yr)) continue;
                found.push({ at: at, len: len, d: d, m: mo, y: yr });
            }
        });
        found.sort(function (a, b) {
            return a.at - b.at;
        });
        return found[0] || null;
    }

    function pad2(n) {
        return String(n).padStart(2, '0');
    }

    function dropOn(keep) {
        if (keep.length && has(['on', 'from', 'for', 'at'], keep[keep.length - 1].toLowerCase())) keep.pop();
    }

    /**
     * QuickText::dayOf: a day without its year is the last one on or before today (29 February: the last leap
     * year's); with its year, that day if it exists. 'YYYY-MM-DD' or null.
     */
    function dayOf(d, m, y, today) {
        var iso = function (yr) {
            return yr + '-' + pad2(m) + '-' + pad2(d);
        };
        if (y) return validDay(d, m, y) ? iso(y) : null;
        var ty = parseInt(today.slice(0, 4), 10);
        for (var yr = ty; yr >= ty - 8; yr--) {
            if (validDay(d, m, yr) && iso(yr) <= today) return iso(yr);
        }
        return null;
    }

    function lowerWord(w) {
        return String(w || '')
            .replace(/^[.,;:!]+|[.,;:!]+$/g, '')
            .toLowerCase();
    }

    /**
     * Read a cost in a few words: '£12.50 parking Leeds', '12.50 parking', '€15 taxi Berlin', 'parking £5',
     * '£5 cash parking yesterday'. By fixed rules, the same as the server's QuickText::parse, never by AI.
     * Returns {minor, amount_minor, currency, amount, what, paid_with, account_id, date, date_word, job_id} or null
     * (a question, a /command, a decimal comma, no amount where one is expected).
     * ctx (optional): {digits(currency), accounts: [{id, name}], jobs: [{id, title, venue, client, days}]}.
     */
    function parseText(text, today, ctx) {
        if (typeof text !== 'string') return null;
        text = text.replace(/\s+/g, ' ').trim();
        if (text === '' || text.charAt(0) === '/' || text.indexOf('?') >= 0 || text.length > 300) return null;
        // A bank's notification ("You spent £12.50 at NCP"): the amount comes after "spent".
        text = text.replace(/^(?:you|i)\s+(?=(?:spent|paid)\b)/i, '');
        if (has(ASKING, lowerWord(text.split(' ')[0]))) return null; // a question, even without its "?"
        var first = null;
        var rest = '';
        var m = FIRST.exec(text);
        if (m) {
            first = amountOf(m[1] || '', m[2], m[3] || '', m[4] || '', ctx);
            rest = (m[5] || '').trim();
            if (first === null && (m[4] || '') !== '') {
                // "12.50 car park": a word, not a currency code.
                first = amountOf(m[1] || '', m[2], m[3] || '', '', ctx);
                rest = (m[4] + (m[5] || '')).trim();
            }
            // A currency written as a word ("15 euros taxi"): that currency, never pounds by mistake.
            var word = lowerWord(rest.split(' ')[0]);
            var isWord = Object.prototype.hasOwnProperty.call(CURRENCY_WORDS, word);
            if (first !== null && word !== '' && !m[4] && (isWord || has(OTHER_MONEY_WORDS, word))) {
                var named = isWord ? CURRENCY_WORDS[word] : null;
                if (named === null || (m[1] && SYMBOLS[m[1]] !== named)) return null;
                first = amountOf(m[1] || '', m[2], m[3] || '', named, ctx);
                rest = rest.slice(rest.split(' ')[0].length).trim();
            }
        }
        if (first === null) {
            m = LAST.exec(text);
            if (!m || m[1].trim().split(/\s+/).length + 1 > SHORT_WORDS) return null;
            var before = m[1].trim().split(/\s+/).map(lowerWord);
            var last = before[before.length - 1];
            if (has(NOT_BEFORE_LAST, last)) return null;
            // Last in a short message only when it's plainly money: a currency, pence ("parking 5.00"), or a whole
            // number after a word that says what a cost was ("parking 8"; "meet at 8" and "bus at 8" are times).
            var costWord = before.some(function (w) {
                return has(COST_WORDS, w);
            });
            if (!m[2] && !m[5] && !m[4] && (!costWord || m[3].indexOf(',') >= 0 || has(NOT_BEFORE_NUMBER, last))) return null;
            first = amountOf(m[2] || '', m[3], m[4] || '', m[5] || '', ctx);
            rest = m[1].trim();
        }
        if (first === null || !(first.minor > 0)) return null;

        var paid = null;
        var account = null;
        var date = today;
        var dateWord = null;
        var span = firstDate(rest);
        var on = span ? dayOf(span.d, span.m, span.y, today) : null;
        if (on) {
            date = on;
            dateWord = rest.substr(span.at, span.len);
            rest = (rest.slice(0, span.at) + ' ' + rest.slice(span.at + span.len)).trim();
        }
        var accounts = {};
        ((ctx && ctx.accounts) || []).forEach(function (a) {
            var name = String(a.name || '')
                .trim()
                .toLowerCase();
            if (!name) return;
            accounts[name] = a.id;
            var firstWord = name.split(' ')[0];
            if (firstWord.length >= 3 && !(firstWord in accounts)) accounts[firstWord] = a.id;
        });
        var list = rest === '' ? [] : rest.split(/\s+/);
        var keep = [];
        for (var i = 0; i < list.length; i++) {
            var w = list[i];
            var l = lowerWord(w);
            var next = lowerWord(list[i + 1]);
            if (l === 'cash') {
                paid = 'cash';
                continue;
            }
            if (l === 'personal') {
                paid = 'personal_card';
                if (next === 'card') i++;
                continue;
            }
            if (l === 'card' || l === 'business') {
                paid = paid || 'business';
                if (l === 'business' && (next === 'card' || next === 'account')) i++;
                continue;
            }
            if (Object.prototype.hasOwnProperty.call(accounts, l)) {
                paid = 'business';
                account = accounts[l];
                continue;
            }
            if (dateWord === null && (l === 'today' || l === 'yesterday')) {
                date = l === 'today' ? today : addDays(today, -1);
                dateWord = l;
                dropOn(keep);
                continue;
            }
            if (dateWord === null && Object.prototype.hasOwnProperty.call(WEEKDAYS, l)) {
                var iso = new Date(today + 'T12:00:00Z').getUTCDay() || 7;
                date = addDays(today, -((iso - WEEKDAYS[l] + 7) % 7));
                dateWord = l;
                dropOn(keep);
                continue;
            }
            keep.push(w);
        }
        // "spent £12 on parking", "£5 at NCP", "paid £5 to NCP": the joining word goes.
        while (keep.length && has(['on', 'for', 'at', 'to'], keep[0].toLowerCase())) keep.shift();
        dropOn(keep);
        var what = keep.join(' ').trim();

        return {
            minor: first.minor,
            amount_minor: first.minor,
            currency: first.currency,
            amount: first.amount,
            what: what,
            paid_with: paid,
            account_id: account,
            date: date,
            date_word: dateWord,
            job_id: jobByWords(what, date, (ctx && ctx.jobs) || []),
        };
    }

    /**
     * The job a few words name (QuickText::jobFor, the 'words' part): a word of three letters or more, not one that
     * says what a cost was, found in exactly one job's title, venue or client, among jobs working within two days.
     */
    function jobByWords(what, date, jobs) {
        var typed = String(what || '')
            .toLowerCase()
            .split(/[^\p{L}\p{N}]+/u)
            .filter(function (w) {
                return w.length >= 3 && !has(NOT_NAMES, w);
            });
        if (!typed.length || !jobs.length) return null;
        var near = jobs.filter(function (j) {
            return jobDays(j).some(function (d) {
                return Math.abs(daysBetween(d, date)) <= 2;
            });
        });
        var named = near.filter(function (j) {
            var hay =
                ' ' +
                String([j.title, j.venue, j.client].join(' '))
                    .toLowerCase()
                    .replace(/[^\p{L}\p{N}]+/gu, ' ') +
                ' ';
            return typed.some(function (w) {
                return hay.indexOf(' ' + w + ' ') >= 0;
            });
        });
        return named.length === 1 ? named[0].id : null;
    }

    /** The entry's fields as one string with sorted keys, for payload_sha. */
    function canonical(value) {
        if (value === null || typeof value !== 'object') return JSON.stringify(value === undefined ? null : value);
        if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
        return (
            '{' +
            Object.keys(value)
                .sort()
                .filter(function (k) {
                    return value[k] !== undefined;
                })
                .map(function (k) {
                    return JSON.stringify(k) + ':' + canonical(value[k]);
                })
                .join(',') +
            '}'
        );
    }

    function hex(buffer) {
        var bytes = new Uint8Array(buffer);
        var out = '';
        for (var i = 0; i < bytes.length; i++) out += bytes[i].toString(16).padStart(2, '0');
        return out;
    }

    function sha256(data) {
        var bytes = typeof data === 'string' ? new TextEncoder().encode(data) : data;
        return root.crypto.subtle.digest('SHA-256', bytes).then(hex);
    }

    function uuid() {
        if (root.crypto && typeof root.crypto.randomUUID === 'function') return root.crypto.randomUUID();
        var b = new Uint8Array(16);
        root.crypto.getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        var h = hex(b);
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    // ---------------------------------------------------------------- IndexedDB

    var opening = null;

    function open() {
        if (opening) return opening;
        opening = new Promise(function (resolve, reject) {
            if (!root.indexedDB) {
                reject(new Error('This browser can’t keep entries on the phone'));
                return;
            }
            var req = root.indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains('entries')) db.createObjectStore('entries', { keyPath: 'client_key' });
                if (!db.objectStoreNames.contains('shared')) db.createObjectStore('shared', { keyPath: 'id', autoIncrement: true });
                if (!db.objectStoreNames.contains('pocket')) db.createObjectStore('pocket');
                if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta');
            };
            req.onsuccess = function () {
                var db = req.result;
                db.onversionchange = function () {
                    db.close();
                    opening = null;
                };
                db.onclose = function () {
                    opening = null;
                };
                resolve(db);
            };
            req.onerror = function () {
                opening = null;
                reject(req.error);
            };
            req.onblocked = function () {
                opening = null;
                reject(new Error('Expend is open in another tab: close it and try again'));
            };
        });
        return opening;
    }

    /** Run fn(store, done) in one transaction; resolves with what done() was given once the transaction completes. */
    function tx(storeName, mode, fn) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var t = db.transaction(storeName, mode);
                var out;
                t.oncomplete = function () {
                    resolve(out);
                };
                t.onerror = function () {
                    reject(t.error);
                };
                t.onabort = function () {
                    reject(t.error || new Error('Not saved'));
                };
                try {
                    fn(t.objectStore(storeName), function (value) {
                        out = value;
                    });
                } catch (error) {
                    try {
                        t.abort();
                    } catch {
                        // Already finished.
                    }
                    reject(error);
                }
            });
        });
    }

    function getAll(storeName) {
        return tx(storeName, 'readonly', function (s, done) {
            s.getAll().onsuccess = function (e) {
                done(e.target.result || []);
            };
        });
    }

    function list() {
        return getAll('entries').then(function (all) {
            return all.sort(function (a, b) {
                return (a.created_at || 0) - (b.created_at || 0);
            });
        });
    }

    function get(key) {
        return tx('entries', 'readonly', function (s, done) {
            s.get(key).onsuccess = function (e) {
                done(e.target.result || null);
            };
        });
    }

    function put(entry) {
        return tx('entries', 'readwrite', function (s, done) {
            s.put(entry);
            done(entry);
        }).then(function (saved) {
            notify();
            return saved;
        });
    }

    function remove(key) {
        return tx('entries', 'readwrite', function (s, done) {
            s.delete(key);
            done(true);
        }).then(function () {
            notify();
            return true;
        });
    }

    /** Read-modify-write in one transaction. fn gets the entry and returns the new one, or null to leave it. */
    function update(key, fn) {
        return tx('entries', 'readwrite', function (s, done) {
            var r = s.get(key);
            r.onsuccess = function () {
                var cur = r.result;
                if (!cur) {
                    done(null);
                    return;
                }
                var next = fn(cur);
                if (next) {
                    s.put(next);
                    done(next);
                } else {
                    done(null);
                }
            };
        });
    }

    function getMeta(key) {
        return tx('meta', 'readonly', function (s, done) {
            s.get(key).onsuccess = function (e) {
                done(e.target.result === undefined ? null : e.target.result);
            };
        });
    }

    function setMeta(key, value) {
        return tx('meta', 'readwrite', function (s, done) {
            if (value === null || value === undefined) s.delete(key);
            else s.put(value, key);
            done(value);
        });
    }

    /** The pocket as kept: {data, etag, fetched_at} or null. */
    function getPocket() {
        return tx('pocket', 'readonly', function (s, done) {
            s.get('day').onsuccess = function (e) {
                done(e.target.result || null);
            };
        });
    }

    function setPocket(value) {
        return tx('pocket', 'readwrite', function (s, done) {
            if (value) s.put(value, 'day');
            else s.delete('day');
            done(value);
        });
    }

    function addShared(item) {
        return tx('shared', 'readwrite', function (s, done) {
            s.add(item).onsuccess = function (e) {
                done(e.target.result);
            };
        }).then(function (id) {
            notify();
            return id;
        });
    }

    function listShared() {
        return getAll('shared').then(function (all) {
            return all.sort(function (a, b) {
                return (a.at || 0) - (b.at || 0) || a.id - b.id;
            });
        });
    }

    function removeShared(id) {
        return tx('shared', 'readwrite', function (s, done) {
            s.delete(id);
            done(true);
        }).then(function () {
            notify();
            return true;
        });
    }

    function countStore(storeName) {
        return tx(storeName, 'readonly', function (s, done) {
            s.count().onsuccess = function (e) {
                done(e.target.result || 0);
            };
        });
    }

    /** The counts for the pill and the + button: {waiting, needs_you, shared, signed_out, total}. */
    function counts() {
        return Promise.all([list(), countStore('shared'), getMeta('signed_out')]).then(function (r) {
            var waiting = 0;
            var needs = 0;
            r[0].forEach(function (e) {
                if (e.state === 'needs_you') needs++;
                else if (has(UNSENT, e.state)) waiting++;
            });
            return {
                waiting: waiting,
                needs_you: needs,
                shared: r[1],
                signed_out: !!r[2] && waiting + needs > 0,
                signed_out_now: !!r[2],
                total: waiting + needs + r[1],
            };
        });
    }

    var channel = null;

    /** Tell every open page (and the worker) that something changed. */
    function notify() {
        try {
            if (!channel && typeof BroadcastChannel !== 'undefined') channel = new BroadcastChannel('expend-quick');
            if (channel) channel.postMessage({ type: 'changed', at: Date.now() });
        } catch {
            // Not available here.
        }
        if (typeof root.dispatchEvent === 'function' && typeof root.Event === 'function' && typeof root.document !== 'undefined') {
            try {
                root.dispatchEvent(new root.Event('expend-quick'));
            } catch {
                // Not a window.
            }
        }
    }

    // ---------------------------------------------------------------- sending

    var me = 'c' + Math.random().toString(36).slice(2);

    /** Only one page or worker sends at a time: the Web Lock 'expend-send', or a 60 s lease in meta renewed every 20 s. */
    function withLock(fn) {
        var nav = root.navigator;
        if (nav && nav.locks && typeof nav.locks.request === 'function') {
            return nav.locks.request('expend-send', { ifAvailable: true }, function (lock) {
                return lock ? fn() : { busy: true };
            });
        }
        return tx('meta', 'readwrite', function (s, done) {
            var r = s.get('lease');
            r.onsuccess = function () {
                var lease = r.result;
                if (lease && lease.until > Date.now() && lease.owner !== me) {
                    done(false);
                    return;
                }
                s.put({ owner: me, until: Date.now() + LEASE_MS }, 'lease');
                done(true);
            };
        }).then(function (mine) {
            if (!mine) return { busy: true };
            var timer = setInterval(function () {
                setMeta('lease', { owner: me, until: Date.now() + LEASE_MS }).catch(function () {});
            }, LEASE_RENEW_MS);
            var release = function () {
                clearInterval(timer);
                return setMeta('lease', null).catch(function () {});
            };
            return Promise.resolve()
                .then(fn)
                .then(
                    function (out) {
                        return release().then(function () {
                            return out;
                        });
                    },
                    function (error) {
                        return release().then(function () {
                            throw error;
                        });
                    },
                );
        });
    }

    function request(url, init, ms, doFetch) {
        var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timer = ctl
            ? setTimeout(function () {
                  ctl.abort();
              }, ms)
            : null;
        var options = Object.assign({ credentials: 'same-origin', cache: 'no-store', redirect: 'follow' }, init);
        if (ctl) options.signal = ctl.signal;
        return Promise.resolve()
            .then(function () {
                return (doFetch || root.fetch.bind(root))(url, options);
            })
            .then(function (res) {
                var type = (res.headers && res.headers.get('Content-Type')) || '';
                var after = res.headers && res.headers.get('Retry-After');
                var parse =
                    type.indexOf('json') >= 0
                        ? res.json().catch(function () {
                              return null;
                          })
                        : Promise.resolve(null);
                return parse.then(function (body) {
                    return {
                        status: res.status,
                        body: body,
                        retryAfter: after ? parseInt(after, 10) || null : null,
                        headers: res.headers,
                        html: type.indexOf('html') >= 0,
                    };
                });
            })
            .catch(function (error) {
                return { status: 0, body: null, error: String((error && error.name) || error) };
            })
            .then(function (out) {
                if (timer) clearTimeout(timer);
                return out;
            });
    }

    function jsonHeaders(token) {
        var h = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (token && token.csrf) h['X-CSRF-TOKEN'] = token.csrf;
        return h;
    }

    /** GET /quick/token: {csrf, max_upload_bytes, pocket_etag, now}; 'signed_out'; or null with no signal. */
    function getToken(doFetch) {
        return request('/quick/token', { method: 'GET', headers: jsonHeaders(null) }, FIELDS_TIMEOUT, doFetch).then(function (res) {
            if (res.status === 200 && res.body && res.body.csrf) {
                return Promise.all([
                    setMeta('token_info', { max_upload_bytes: res.body.max_upload_bytes || null, at: Date.now() }),
                    markSignedOut(false),
                ])
                    .catch(function () {})
                    .then(function () {
                        return res.body;
                    });
            }
            if (res.status === 401 || res.status === 419 || (res.status === 200 && res.html)) {
                return markSignedOut(true)
                    .catch(function () {})
                    .then(function () {
                        return 'signed_out';
                    });
            }
            return null;
        });
    }

    /** Keep whether Expend says you're signed out, and tell the pages when that changes (the banner, the pill). */
    function markSignedOut(value) {
        return getMeta('signed_out').then(function (was) {
            if (!!was === !!value) return value;
            return setMeta('signed_out', !!value).then(function () {
                notify();
                return value;
            });
        });
    }

    /**
     * Ask Expend whether you're signed in (the screen opens from the phone's copy, which can't tell): 'signed_in',
     * 'signed_out' or 'offline'. A remember-me cookie signs you back in on the way.
     */
    function checkSignIn(doFetch) {
        return getToken(doFetch).then(function (token) {
            return token === 'signed_out' ? 'signed_out' : token ? 'signed_in' : 'offline';
        });
    }

    function photoTimeout(entry) {
        var size = (entry.photo && entry.photo.size) || 0;
        return 20 * 1000 + Math.ceil(size / (50 * 1024)) * 1000;
    }

    function send(kind, entry, token, doFetch) {
        var key = encodeURIComponent(entry.client_key);
        if (kind === 'withdraw') {
            return request(
                '/quick/entries/' + key + '/withdraw',
                {
                    method: 'POST',
                    headers: Object.assign({ 'Content-Type': 'application/json' }, jsonHeaders(token)),
                    body: '{}',
                },
                FIELDS_TIMEOUT,
                doFetch,
            );
        }
        if (kind === 'photo') {
            var form = new FormData();
            // PHP reads files only from a POST: the route is a PUT, so say so the Laravel way.
            form.append('_method', 'PUT');
            form.append(
                'photo',
                new Blob([entry.photo.bytes], { type: entry.photo.type || 'application/octet-stream' }),
                entry.photo.name || 'receipt',
            );
            return request(
                '/quick/entries/' + key + '/photo',
                { method: 'POST', headers: jsonHeaders(token), body: form },
                photoTimeout(entry),
                doFetch,
            );
        }
        var body = Object.assign({}, entry.fields, { client_key: entry.client_key, payload_sha: entry.payload_sha });
        return request(
            '/quick/entries',
            {
                method: 'POST',
                headers: Object.assign({ 'Content-Type': 'application/json' }, jsonHeaders(token)),
                body: JSON.stringify(body),
            },
            FIELDS_TIMEOUT,
            doFetch,
        );
    }

    var EXPECT = { fields: FIELD_STATES, photo: ['photo_pending'], withdraw: ['withdraw_pending'] };

    function sendOne(key, kind, ctx) {
        var snapshot = null;
        return update(key, function (cur) {
            if (!has(EXPECT[kind], cur.state)) return null;
            if (kind === 'photo' && !hasPhotoBytes(cur)) return Object.assign(cur, { state: 'sent' });
            snapshot = cur;
            return Object.assign({}, cur, { state: 'sending', sending_kind: kind, sending_at: Date.now(), tries: (cur.tries | 0) + 1 });
        }).then(function (marked) {
            if (!marked || !snapshot) return { skipped: true };
            notify();
            return send(kind, snapshot, ctx.token, ctx.fetch)
                .then(function (res) {
                    if (res.status !== 419 || ctx.refreshed) return res;
                    // An old form token: get a fresh one once and try again.
                    ctx.refreshed = true;
                    return getToken(ctx.fetch).then(function (t) {
                        if (!t || t === 'signed_out') return res;
                        ctx.token = t;
                        return send(kind, snapshot, ctx.token, ctx.fetch);
                    });
                })
                .then(function (res) {
                    var patch = nextState(snapshot, kind, res, Date.now());
                    return update(key, function (cur) {
                        return applyPatch(cur, patch, kind);
                    }).then(function () {
                        notify();
                        if (patch.state === 'sent' || patch.state === 'photo_pending' || patch.state === 'withdrawn') ctx.sent++;
                        return { stop: !!patch.stop || res.status === 429, status: res.status };
                    });
                });
        });
    }

    /**
     * Send what's waiting. opts: {reset (start the waits again), deadline (ms; stop starting new requests after),
     * only (one client_key), fetch (for tests), pocket (false to skip the pocket refresh), from}.
     * Resolves {sent, left, signedOut, offline, busy}.
     */
    function flush(opts) {
        opts = opts || {};
        var ctx = { token: null, refreshed: false, fetch: opts.fetch, sent: 0 };
        var summary = { sent: 0, left: 0, signedOut: false, offline: false, busy: false };
        var timeUp = function () {
            return opts.deadline && Date.now() > opts.deadline;
        };

        return withLock(function () {
            var now = Date.now();
            return list()
                .then(function (all) {
                    var fixes = recoverStale(all, now);
                    var resets = opts.reset
                        ? all.filter(function (e) {
                              return has(UNSENT, e.state) && e.state !== 'needs_you' && (!opts.only || e.client_key === opts.only);
                          })
                        : [];
                    var only = opts.only
                        ? all.filter(function (e) {
                              return e.client_key === opts.only && e.state === 'needs_you';
                          })
                        : [];
                    var work = fixes.map(function (f) {
                        return update(f.key, function (cur) {
                            return cur.state === 'sending' ? Object.assign(cur, { state: f.state, sending_kind: null, sending_at: null }) : null;
                        });
                    });
                    resets.forEach(function (e) {
                        work.push(update(e.client_key, resetBackoff));
                    });
                    // Send now on an entry that needs you tries it once more from the start.
                    only.forEach(function (e) {
                        work.push(
                            update(e.client_key, function (cur) {
                                var back = cur.server_id || cur.sent_at ? (hasPhotoBytes(cur) ? 'photo_pending' : 'sent') : 'waiting';
                                return Object.assign(cur, { state: back, attempts: 0, next_at: 0, mismatches: 0, last_error: null });
                            }),
                        );
                    });
                    return Promise.all(work);
                })
                .then(list)
                .then(function (all) {
                    var due = all.filter(function (e) {
                        return isDue(e, now);
                    });
                    if (!due.length) return null;
                    return getToken(ctx.fetch)
                        .then(function (token) {
                            if (token === 'signed_out') {
                                summary.signedOut = true;
                                return setMeta('signed_out', true).then(function () {
                                    return null;
                                });
                            }
                            if (!token) {
                                summary.offline = true;
                                // Nothing reached Expend: count a failed try (for the waits), but not a send.
                                return Promise.all(
                                    due.map(function (e) {
                                        return update(e.client_key, function (cur) {
                                            if (!isDue(cur, Date.now())) return null;
                                            var a = (cur.attempts | 0) + 1;
                                            return Object.assign(cur, { attempts: a, next_at: Date.now() + backoff(a), last_error: 'No signal' });
                                        });
                                    }),
                                ).then(function () {
                                    return null;
                                });
                            }
                            ctx.token = token;
                            return setMeta('signed_out', false).then(function () {
                                return token;
                            });
                        })
                        .then(function (token) {
                            if (!token) return null;
                            var order = function (state) {
                                return due.filter(function (e) {
                                    return has(state, e.state);
                                });
                            };
                            var queue = order(['withdraw_pending'])
                                .map(function (e) {
                                    return [e.client_key, 'withdraw'];
                                })
                                .concat(
                                    order(FIELD_STATES).map(function (e) {
                                        return [e.client_key, 'fields'];
                                    }),
                                );
                            var step = function (i) {
                                if (i >= queue.length || timeUp()) return Promise.resolve(false);
                                return sendOne(queue[i][0], queue[i][1], ctx).then(function (r) {
                                    if (r.stop) return true;
                                    return step(i + 1);
                                });
                            };
                            return step(0).then(function (stopped) {
                                if (stopped) return token;
                                // Then the photos: the records are in even on a short burst of signal.
                                return list().then(function (fresh) {
                                    var photos = fresh.filter(function (e) {
                                        return e.state === 'photo_pending' && (e.next_at || 0) <= Date.now();
                                    });
                                    var go = function (i) {
                                        if (i >= photos.length || timeUp()) return Promise.resolve(token);
                                        return sendOne(photos[i].client_key, 'photo', ctx).then(function (r) {
                                            return r.stop ? token : go(i + 1);
                                        });
                                    };
                                    return go(0);
                                });
                            });
                        });
                })
                .then(function (token) {
                    if (token && opts.pocket !== false && !timeUp()) {
                        return refreshPocket({ etag: token.pocket_etag, fetch: ctx.fetch }).catch(function () {
                            return null;
                        });
                    }
                    return null;
                })
                .then(function () {
                    return Promise.all([list(), getMeta('signed_out')]);
                })
                .then(function (r) {
                    summary.sent = ctx.sent;
                    summary.left = r[0].filter(function (e) {
                        return has(UNSENT, e.state) && e.state !== 'needs_you';
                    }).length;
                    summary.signedOut = summary.signedOut || (!!r[1] && summary.left > 0);
                    return summary;
                });
        }).then(function (out) {
            notify();
            if (out && out.busy) summary.busy = true;
            return out && out.busy ? summary : out;
        });
    }

    /**
     * Refresh the pocket when it's over 10 minutes old (or the token says it changed), with If-None-Match, so a
     * day's data that hasn't changed costs a 304. o: {force, etag, fetch}. Resolves the pocket as kept.
     */
    function refreshPocket(o) {
        o = o || {};
        return getPocket().then(function (cur) {
            var age = cur ? Date.now() - (cur.fetched_at || 0) : Infinity;
            // The token's pocket_etag and the ETag header may differ only in quotes or a weak W/.
            var bare = function (tag) {
                return String(tag || '')
                    .replace(/^W\//, '')
                    .replace(/"/g, '');
            };
            var changed = o.etag && cur && cur.etag && bare(o.etag) !== bare(cur.etag);
            if (cur && !o.force && age < POCKET_MAX_AGE && !changed) return cur;
            var headers = jsonHeaders(null);
            if (cur && cur.etag) headers['If-None-Match'] = cur.etag;
            return request('/quick/pocket', { method: 'GET', headers: headers }, FIELDS_TIMEOUT, o.fetch).then(function (res) {
                if (res.status === 304 && cur) {
                    var same = Object.assign({}, cur, { fetched_at: Date.now() });
                    return Promise.all([setPocket(same), markSignedOut(false)]).then(function () {
                        return same;
                    });
                }
                if (res.status === 200 && res.body && typeof res.body === 'object') {
                    var data = res.body.pocket || res.body;
                    var kept = { data: data, etag: (res.headers && res.headers.get('ETag')) || data.etag || null, fetched_at: Date.now() };
                    return Promise.all([setPocket(kept), markSignedOut(false)]).then(function () {
                        notify();
                        return kept;
                    });
                }
                if (res.status === 401)
                    return markSignedOut(true).then(function () {
                        return cur;
                    });
                return cur;
            });
        });
    }

    /**
     * "Keep it without the photo": an entry Expend has already (it has a server id) whose photo can't be sent (over
     * the server's limit, say). The photo's bytes go from the phone and the entry counts as sent; Expend keeps the
     * entry as it is, still expecting a photo. Resolves true when it did.
     */
    function keepWithoutPhoto(key) {
        return update(key, function (cur) {
            if (!cur.server_id || !hasPhotoBytes(cur)) return null;
            return Object.assign(cur, {
                photo: Object.assign({}, cur.photo, { bytes: null }),
                state: 'sent',
                last_error: null,
                photo_left: true,
                sent_at: cur.sent_at || Date.now(),
            });
        }).then(function (done) {
            notify();
            return !!done;
        });
    }

    /**
     * Undo or Delete. Resolves 'deleted' (never reached Expend), 'pending' (a withdraw is on its way) or 'gone'. With
     * {wait: true} it waits for this send and resolves 'withdrawn' or 'kept' (a locked year) when Expend answered.
     */
    function withdraw(key, opts) {
        return tx('entries', 'readwrite', function (s, done) {
            var r = s.get(key);
            r.onsuccess = function () {
                var e = r.result;
                var rule = withdrawRule(e);
                if (rule === 'gone') {
                    done('gone');
                } else if (rule === 'delete') {
                    s.delete(key);
                    done('deleted');
                } else {
                    s.put(Object.assign({}, e, { state: 'withdraw_pending', attempts: 0, next_at: 0, last_error: null }));
                    done('pending');
                }
            };
        }).then(function (result) {
            notify();
            if (result !== 'pending') return result;
            var sending = flush({ from: 'withdraw', pocket: false }).catch(function () {
                return null;
            });
            if (!opts || !opts.wait) return result;
            return sending
                .then(function () {
                    return get(key);
                })
                .then(function (e) {
                    if (!e || e.state === 'withdrawn') return 'withdrawn';
                    if (e.kept) return 'kept';
                    return 'pending';
                });
        });
    }

    /** Ask the worker for Background Sync (Android Chrome); harmless elsewhere. */
    function registerSync() {
        var nav = root.navigator;
        if (!nav || !nav.serviceWorker || !nav.serviceWorker.ready) return Promise.resolve(false);
        return nav.serviceWorker.ready
            .then(function (reg) {
                return reg && reg.sync
                    ? reg.sync.register('expend-send').then(function () {
                          return true;
                      })
                    : false;
            })
            .catch(function () {
                return false;
            });
    }

    /** Remove sent entries over 14 days old and withdrawn ones. Shared files are only counted for asking. */
    function tidy() {
        return Promise.all([list(), listShared()]).then(function (r) {
            var plan = prune(r[0], r[1], Date.now());
            return Promise.all(plan.remove.map(remove)).then(function () {
                return plan;
            });
        });
    }

    var booted = false;

    /**
     * Every page: register the worker after load, send on open, on coming online, on coming back to the app, and
     * every 30 s while something waits. Called by the inline script in app.blade.php.
     */
    function boot() {
        if (booted || typeof root.document === 'undefined') return;
        booted = true;
        var nav = root.navigator;
        var go = function (reset) {
            return flush({ reset: reset, from: 'page' }).catch(function () {
                return null;
            });
        };
        if (nav.serviceWorker) {
            nav.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
        }
        registerSync();
        tidy().catch(function () {});
        go(true);
        root.addEventListener('online', function () {
            go(true);
        });
        root.document.addEventListener('visibilitychange', function () {
            if (root.document.visibilityState === 'visible') go(true);
        });
        setInterval(function () {
            if (root.document.visibilityState !== 'visible') return;
            counts()
                .then(function (c) {
                    if (c.waiting > 0) go(false);
                })
                .catch(function () {});
        }, 30 * 1000);
    }

    root.ExpendQuick = {
        // Pure rules
        backoff: backoff,
        resetBackoff: resetBackoff,
        keypad: keypad,
        keypadMinor: keypadMinor,
        keypadAmount: keypadAmount,
        keypadText: keypadText,
        nextState: nextState,
        applyPatch: applyPatch,
        withdrawRule: withdrawRule,
        recoverStale: recoverStale,
        isDue: isDue,
        stateWords: stateWords,
        prune: prune,
        parseText: parseText,
        defaultJob: defaultJob,
        suggestOffline: suggestOffline,
        sameReceipt: sameReceipt,
        canonical: canonical,
        addDays: addDays,
        daysBetween: daysBetween,
        london: london,
        taxYear: taxYear,
        uuid: uuid,
        sha256: sha256,
        // Storage
        open: open,
        list: list,
        get: get,
        put: put,
        update: update,
        remove: remove,
        getMeta: getMeta,
        setMeta: setMeta,
        getPocket: getPocket,
        setPocket: setPocket,
        addShared: addShared,
        listShared: listShared,
        removeShared: removeShared,
        counts: counts,
        notify: notify,
        // Sending
        flush: flush,
        checkSignIn: checkSignIn,
        refreshPocket: refreshPocket,
        withdraw: withdraw,
        keepWithoutPhoto: keepWithoutPhoto,
        registerSync: registerSync,
        tidy: tidy,
        boot: boot,
        UNSENT: UNSENT,
    };
})(typeof self !== 'undefined' ? self : globalThis);
