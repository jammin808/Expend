<?php

namespace App\Http\Controllers;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\QuickEntryChecks;
use App\Domain\Jobs\JobDays;
use App\Domain\Ledger\ManualExpenses;
use App\Domain\Matching\Categoriser;
use App\Domain\Quick\CardLineFits;
use App\Domain\Quick\QuickEntries;
use App\Domain\Quick\QuickPocket;
use App\Domain\Quick\QuickReadCheck;
use App\Domain\Quick\QuickText;
use App\Domain\Quick\QuickWorker;
use App\Domain\Quick\ReceiptAttacher;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoList;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Vite;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Adding expenses on the phone: the quick add screen and its cached copy, the JSON the phone sends entries and photos
 * to, the entry page, the service worker, Android's share fallback and the bounce page for Telegram's links. What an
 * entry does is decided in Quick\QuickEntries; this only checks a request is well formed and says what happened.
 *
 * 422 is only for a malformed request (no client key, an amount that doesn't parse, a date that isn't one). Anything
 * well formed is kept, and what Expend can't add is held and listed in To do, so nothing stays only on the phone.
 */
class QuickController extends Controller
{
    public function __construct(private QuickEntries $entries, private ReceiptAttacher $attacher) {}

    /** GET /quick: the quick add screen. Its day's data comes from the pocket (IndexedDB on the phone), not from here. */
    public function show(Request $request): InertiaResponse
    {
        // A bot paired before quick add: its @username, once, for the note on iPhone ("share it to @…").
        if (Setting::get('telegram_chat_id') && ! Setting::get('telegram_bot_username') && Cache::add('quick:bot-username', true, now()->addDay())) {
            Telegram::rememberUsername();
        }

        return Inertia::render('quick/index', self::screen(false));
    }

    /**
     * GET /quick/shell: the same screen as the service worker keeps it for offline use: nothing particular to this
     * request (no flash message, no badge), marked with X-Expend-Shell, never stored by the browser itself.
     */
    public function shell(Request $request): SymfonyResponse
    {
        if ($request->header('X-Inertia')) {
            // Only the service worker asks for the shell. A visit inside the app (the address kept from before a
            // sign-in, say) goes to the screen itself.
            return Inertia::location('/quick');
        }
        $response = Inertia::render('quick/index', self::screen(true))->toResponse($request);
        $response->headers->set('X-Expend-Shell', '1');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @return array<string, mixed> */
    private static function screen(bool $shell): array
    {
        return ['shell' => $shell, 'max_upload_bytes' => QuickWorker::maxUploadBytes(), 'telegram_bot' => Setting::get('telegram_bot_username'),
            'store_version' => QuickWorker::storeVersion()];
    }

    /**
     * GET /quick/token: a fresh CSRF token before sending, and the server's upload limit. 401 when signed out; a
     * remember-me cookie signs you back in here.
     */
    public function token(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->json(['error' => 'signed_out', 'message' => 'Sign in to send'], 401);
        }

        return response()->json(['csrf' => csrf_token(), 'max_upload_bytes' => QuickWorker::maxUploadBytes(), 'pocket_etag' => QuickPocket::etag(),
            'now' => now()->toIso8601String()])->header('Cache-Control', 'no-store');
    }

    /** GET /quick/pocket: the day's data, with an ETag (304 when the phone's copy is current). */
    public function pocket(Request $request): JsonResponse|Response
    {
        if (! $request->user()) {
            return response()->json(['error' => 'signed_out', 'message' => 'Sign in to send'], 401);
        }
        $pocket = QuickPocket::build();
        $sent = array_map('trim', explode(',', (string) $request->header('If-None-Match')));
        if (in_array($pocket['etag'], $sent, true) || in_array('W/'.$pocket['etag'], $sent, true)) {
            return response('', 304)->header('ETag', $pocket['etag'])->header('Cache-Control', 'no-cache, private');
        }

        return response()->json($pocket['data'])->header('ETag', $pocket['etag'])->header('Cache-Control', 'no-cache, private');
    }

    /**
     * GET /quick/suggest?what=NCP+Leeds&amount=12.50&date=2026-11-25: the category Expend would suggest (your own past
     * decisions first), the job you're working on that day for a job's own costs, or a note that you usually mark it
     * personal. Only reads.
     */
    public function suggest(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->json(['error' => 'signed_out', 'message' => 'Sign in to send'], 401);
        }
        $what = mb_substr(trim((string) $request->query('what', '')), 0, 200);
        try {
            $date = $request->query('date') ? CarbonImmutable::parse((string) $request->query('date')) : CarbonImmutable::now('Europe/London')->startOfDay();
            $pence = abs((int) (Money::parse((string) ($request->query('amount') ?: '1')) ?? 100));
        } catch (Throwable) {
            return response()->json(['error' => 'malformed', 'message' => 'Check the amount and date.'], 422);
        }
        $p = $what !== '' ? app(Categoriser::class)->preview($what, max(1, $pence), $date) : null;
        $category = $p && $p['category_id'] ? Category::query()->find($p['category_id']) : null;
        $jobCost = $category && in_array($category->code, Categoriser::JOB_COSTS, true);
        $job = null;
        if ($jobCost) {
            $job = $p['job_id'] ? WorkJob::query()->find($p['job_id']) : (QuickText::jobFor('', $date)['job'] ?? null);
        }
        $name = $what !== '' ? $what : 'it';

        return response()->json([
            // The reason as the phone shows it: a job by its title, never its number ("Leeds Arena load-in (job 11)").
            'category_id' => $category?->id, 'category_name' => $category?->name,
            'reason' => isset($p['reason']) ? trim((string) preg_replace('/ \(job \d+\)/', '', (string) $p['reason'])) : null, 'source' => $p['source'] ?? null,
            'job_id' => $job?->id, 'job_title' => $job?->title,
            'personal_note' => ($p['personal'] ?? false) ? 'You usually mark '.$name.' as personal: add it anyway?' : null,
        ]);
    }

    /** POST /quick/entries: an entry's fields (the photo follows on its own). */
    public function store(Request $request): JsonResponse
    {
        try {
            $fields = self::wellFormed($request->all());
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'malformed', 'message' => $e->getMessage()], 422);
        }
        $out = QuickEntries::asYou(fn () => $this->entries->submit($fields), 'web');

        return response()->json($out['body'], $out['status']);
    }

    /**
     * The fields as QuickEntries takes them, or why the request is malformed.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function wellFormed(array $in): array
    {
        $key = (string) ($in['client_key'] ?? '');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            throw new InvalidArgumentException('Missing the entry’s key: update Expend on your phone and save it again.');
        }
        $currency = strtoupper(trim((string) ($in['currency'] ?? 'GBP')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('The currency should be three letters, like GBP.');
        }
        $photo = $in['photo'] ?? null;
        if ($photo !== null && (! is_array($photo) || ! preg_match('/^[0-9a-f]{64}$/i', (string) ($photo['sha256'] ?? '')) || ! is_numeric($photo['size'] ?? null))) {
            throw new InvalidArgumentException('The photo’s details are missing: take it again.');
        }
        $minor = null;
        if (isset($in['amount']) && trim((string) $in['amount']) !== '') {
            $minor = self::amountIn((string) $in['amount'], $currency);
            if ($minor === null || $minor <= 0) {
                throw new InvalidArgumentException('Enter the amount.');
            }
        }
        if ($minor === null && $photo === null) {
            throw new InvalidArgumentException('Enter the amount or take a photo.');
        }
        $sterling = null;
        if (isset($in['sterling']) && trim((string) $in['sterling']) !== '') {
            try {
                $sterling = Money::parse((string) $in['sterling']);
            } catch (Throwable) {
                throw new InvalidArgumentException('The sterling figure doesn’t read as an amount.');
            }
            if ($sterling !== null && abs($sterling) > QuickText::MAX_HUNDREDTHS) {
                throw new InvalidArgumentException('That’s too big to add from your phone: add it in Expend.');
            }
        }
        foreach (['spent_on', 'captured_at'] as $d) {
            if (! empty($in[$d])) {
                try {
                    CarbonImmutable::parse((string) $in[$d]);
                } catch (Throwable) {
                    throw new InvalidArgumentException('That date isn’t a date.');
                }
            }
        }
        if (! empty($in['spent_on'])) {
            // A day that exists, written as one (2027-02-29 and 2026-02-30 don't).
            $on = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in['spent_on']) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $in['spent_on']) : false;
            if (! $on || $on->format('Y-m-d') !== (string) $in['spent_on']) {
                throw new InvalidArgumentException('That date isn’t a date.');
            }
        }
        foreach (['category_id', 'work_job_id', 'account_id', 'business_pct'] as $n) {
            if (isset($in[$n]) && $in[$n] !== '' && ! ctype_digit((string) $in[$n])) {
                throw new InvalidArgumentException('Something on the entry didn’t read as a number.');
            }
        }

        return [
            'client_key' => strtolower($key), 'channel' => in_array($in['channel'] ?? null, ['phone', 'share'], true) ? $in['channel'] : 'phone',
            'paid_with' => $in['paid_with'] ?? null, 'account_id' => $in['account_id'] ?? null, 'spent_on' => $in['spent_on'] ?? null, 'spent_at' => $in['spent_at'] ?? null,
            'captured_at' => $in['captured_at'] ?? null, 'amount_minor' => $minor, 'currency' => $currency, 'sterling_pence' => $sterling,
            'what' => isset($in['what']) ? mb_substr((string) $in['what'], 0, 500) : null, 'what_source' => $in['what_source'] ?? null,
            'category_id' => $in['category_id'] ?? null, 'work_job_id' => $in['work_job_id'] ?? null, 'job_source' => $in['job_source'] ?? null,
            'business_pct' => $in['business_pct'] ?? null, 'note' => isset($in['note']) ? mb_substr((string) $in['note'], 0, 1000) : null,
            'photo' => $photo ? ['sha256' => strtolower((string) $photo['sha256']), 'size' => (int) $photo['size'], 'mime' => $photo['mime'] ?? null, 'name' => $photo['name'] ?? null,
                'original' => (bool) ($photo['original'] ?? false)] : null,
            'payload_sha' => isset($in['payload_sha']) && preg_match('/^[0-9a-f]{64}$/i', (string) $in['payload_sha']) ? strtolower((string) $in['payload_sha']) : null,
        ];
    }

    /**
     * An amount as typed, in the currency's own minor units: null when there's none. Refused (with plain words, never
     * PHP's) when it doesn't read as an amount, or is over the keypad's limit (QuickText::MAX_HUNDREDTHS).
     *
     * @throws InvalidArgumentException
     */
    private static function amountIn(string $typed, string $currency): ?int
    {
        try {
            $minor = Money::parseIn($typed, $currency);
        } catch (InvalidArgumentException $e) {
            throw $e; // Money's own words: more decimals than the currency has, a decimal comma
        } catch (Throwable) {
            throw new InvalidArgumentException('That amount can’t be read.');
        }
        if ($minor !== null && Money::toHundredths(abs($minor), $currency) > QuickText::MAX_HUNDREDTHS) {
            throw new InvalidArgumentException('That’s too big to add from your phone: add it in Expend.');
        }

        return $minor;
    }

    /**
     * POST (or PUT) /quick/entries/{key}/photo: the photo or PDF for an entry (multipart field "photo"). 413 when it's
     * over the server's limit; 422 photo_mismatch when it isn't what the fields said; 404 before the fields arrive.
     */
    public function photo(Request $request, string $key): JsonResponse
    {
        $file = $request->file('photo');
        $tooBig = ['error' => 'too_big', 'message' => 'Too big to send from your phone (over '.self::megabytes(QuickWorker::maxUploadBytes()).'). Upload it from a computer.'];
        if (! $file) {
            if ((int) $request->server('CONTENT_LENGTH') > QuickWorker::maxUploadBytes() + 64 * 1024) {
                return response()->json($tooBig, 413);
            }

            return response()->json(['error' => 'photo_error', 'message' => 'The photo didn’t arrive. Sending it again.'], 422);
        }
        if (! $file->isValid()) {
            return in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? response()->json($tooBig, 413)
                : response()->json(['error' => 'photo_error', 'message' => 'The photo didn’t arrive whole. Sending it again.'], 422);
        }
        $out = QuickEntries::asYou(fn () => $this->entries->storePhoto(strtolower($key), $file), 'web');

        return response()->json($out['body'], $out['status']);
    }

    /** POST /quick/entries/{key}/withdraw: Undo or Delete on the phone, by key (a tombstone for a key not seen yet). */
    public function withdraw(Request $request, string $key): JsonResponse
    {
        $out = QuickEntries::asYou(fn () => $this->entries->withdraw(strtolower($key)), 'web');

        return response()->json($out['body'], $out['status']);
    }

    /** GET /quick/entries/{entry}: one entry, its photo, what happened, the photo's check, its lines and what you can do. */
    public function entry(QuickEntry $entry): InertiaResponse
    {
        $entry->load(['category', 'job', 'account', 'document', 'manualExpense', 'bankTransaction.account', 'duplicateOf']);
        $label = $entry->spent_on ? UkTaxYear::fromDate($entry->spent_on)->label() : null;
        $lines = collect();
        if ($entry->isWaiting()) {
            $fits = $this->attacher->candidates($entry)->map(fn (BankTransaction $t) => self::line($t) + ['fits' => true]);
            $near = $this->attacher->nearMisses($entry)->map(fn (BankTransaction $t) => self::line($t) + ['fits' => false, 'near' => true]);
            $hand = $this->attacher->handLines($entry)->map(fn (BankTransaction $t) => self::line($t) + ['fits' => false]);
            $lines = $fits->concat($near)->concat($hand)->unique('id')->values();
        }
        $lockedMatch = $entry->isWaiting() ? $this->attacher->candidates($entry, true)->isEmpty() && self::lockedFit($entry) : false;
        $actions = [];
        try {
            $checks = app(QuickEntryChecks::class)->detect(new CycleContext);
            foreach ($checks as $d) {
                if ($d->href === '/quick/entries/'.$entry->id) {
                    $actions = array_merge($actions, $d->actions);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
        foreach (self::standingActions($entry) as $a) {
            if (! collect($actions)->contains(fn ($x) => $x['id'] === $a['id'])) {
                $actions[] = $a;
            }
        }

        return Inertia::render('quick/entry', [
            'entry' => [
                'id' => $entry->id, 'client_key' => $entry->client_key, 'state' => $entry->status, 'state_words' => QuickEntry::STATUSES[$entry->status] ?? $entry->status,
                'hold_reason' => $entry->hold_reason, 'message' => QuickEntries::outcome($entry), 'words' => $entry->words(),
                'amount_minor' => $entry->amount_minor, 'currency' => $entry->currency, 'sterling_pence' => $entry->sterling_pence, 'what' => $entry->what, 'what_source' => $entry->what_source,
                'category' => $entry->category ? ['id' => $entry->category->id, 'name' => $entry->category->name] : null,
                'job' => $entry->job ? ['id' => $entry->job->id, 'title' => $entry->job->title] : null, 'job_source' => $entry->job_source,
                'paid_with' => $entry->paid_with, 'paid_with_words' => QuickEntry::PAID_WITH[$entry->paid_with ?? ''] ?? null,
                'account' => $entry->account ? ['id' => $entry->account->id, 'name' => $entry->account->name] : null,
                'spent_on' => $entry->spent_on?->format('Y-m-d'), 'spent_at' => $entry->spent_at ? substr((string) $entry->spent_at, 0, 5) : null,
                'captured_at' => $entry->captured_at?->toIso8601String(), 'tax_year' => $label, 'business_pct' => $entry->business_pct, 'note' => $entry->note, 'channel' => $entry->channel,
                'photo' => $entry->document ? ['document_id' => $entry->document->id, 'href' => '/documents/'.$entry->document->id, 'file' => '/documents/'.$entry->document->id.'/file',
                    'status' => $entry->photo_status, 'name' => $entry->document->original_name, 'mime' => $entry->document->mime, 'read' => $entry->document->status] : null,
                'photo_status' => $entry->photo_status,
                'read_check' => $entry->read_check ? ['status' => $entry->read_check['status'] ?? null, 'note' => $entry->read_check['note'] ?? null,
                    'agrees' => ($entry->read_check['status'] ?? null) === 'agrees', 'questions' => QuickReadCheck::questions($entry)] : null,
                'line' => $entry->bankTransaction ? self::line($entry->bankTransaction) + ['attached_by' => $entry->attached_by, 'active' => $entry->bankTransaction->state === 'active'] : null,
                'expense' => $entry->manualExpense ? ['id' => $entry->manualExpense->id, 'href' => '/transactions?year='.$entry->manualExpense->tax_year.'&q='.urlencode((string) $entry->manualExpense->description),
                    'amount_pence' => (int) $entry->manualExpense->amount_pence] : null,
                'duplicate_of' => $entry->duplicateOf ? ['id' => $entry->duplicateOf->id, 'words' => $entry->duplicateOf->words(), 'href' => '/quick/entries/'.$entry->duplicateOf->id] : null,
                'locked_note' => $lockedMatch && $label ? 'Matches a line in a locked year: nothing changed' : null,
                'locked' => $label !== null && ManualExpenses::locked($label),
            ],
            'lines' => $lines->all(),
            'actions' => Fingerprints::attach($actions),
            'categories' => Category::query()->where('kind', 'expense')->orderBy('sort')->get(['id', 'name', 'code'])->toArray(),
            'jobs' => $entry->spent_on ? WorkJob::query()->where('status', '!=', 'cancelled')
                ->whereDate('starts_on', '<=', $entry->spent_on->copy()->addDays(14)->format('Y-m-d'))->whereDate('ends_on', '>=', $entry->spent_on->copy()->subDays(14)->format('Y-m-d'))
                ->orderBy('starts_on')->get(['id', 'title', 'starts_on'])->map(fn (WorkJob $j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d')])->all() : [],
            'can_edit' => in_array($entry->status, ['draft', 'held', 'waiting'], true),
            'can_detach' => $entry->status === 'attached',
        ]);
    }

    /** What an entry always offers on its page, beyond what To do asks (the qx actions, with their fingerprints). */
    private static function standingActions(QuickEntry $e): array
    {
        $a = fn (string $verb, string $label, string $style = 'default') => TodoList::action('qx', $e->id.':'.$verb, $label, $style);

        return array_values(array_filter(match (true) {
            $e->isWaiting() => [$a('paid_personal', 'Paid by personal card'), $a('paid_cash', 'Paid in cash'), $a('aside', 'Not a business cost'), $a('cancel', 'Remove')],
            $e->status === 'added' => [$a('to_card', 'It was the business card'), $a('cancel', 'Remove this expense')],
            $e->status === 'held' => [$e->hold_reason !== 'needs_details' ? $a('add_held', 'Add it now', 'primary') : null, $a('cancel', 'Remove')],
            $e->status === 'draft' => [$a('card', 'Business card'), $a('personal', 'Personal card'), $a('cash', 'Cash'), $a('cancel', 'Not an expense')],
            default => [],
        }, fn ($x) => $x !== null));
    }

    /** @return array<string, mixed> a line as the entry page shows it */
    private static function line(BankTransaction $t): array
    {
        return ['id' => $t->id, 'words' => CardLineFits::describe($t), 'account' => $t->account?->name, 'date' => $t->effectiveDate()->format('Y-m-d'),
            'amount_pence' => (int) $t->amount_pence, 'description' => $t->counterparty ?: $t->description,
            'original' => $t->original_currency ? Money::formatMinor(abs((int) $t->original_amount_minor), $t->original_currency, false) : null,
            'href' => '/items/bank/'.$t->id];
    }

    /** Whether a line in a locked year fits a waiting receipt ("Matches a line in 2024-25, which is locked: nothing changed"). */
    private static function lockedFit(QuickEntry $e): bool
    {
        $f = ReceiptAttacher::figuresOf($e);
        if ($f === null) {
            return false;
        }

        return BankTransaction::query()->where('amount_pence', '<', 0)->whereDate('posted_on', '>=', $f['date']->subDays(8)->format('Y-m-d'))
            ->whereDate('posted_on', '<=', $f['date']->addDays(8)->format('Y-m-d'))->get()
            ->contains(fn (BankTransaction $t) => ReceiptAttacher::fitsFigures($f, $t) && ManualExpenses::locked(UkTaxYear::fromDate($t->effectiveDate())->label()));
    }

    /** PATCH /quick/entries/{entry}: change a waiting card receipt, a held entry or a draft. */
    public function update(Request $request, QuickEntry $entry): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'amount' => 'nullable|string|max:30', 'currency' => 'nullable|string|size:3', 'sterling' => 'nullable|string|max:30', 'what' => 'nullable|string|max:500',
            'category_id' => 'nullable|integer', 'work_job_id' => 'nullable|integer', 'account_id' => 'nullable|integer', 'spent_on' => 'nullable|date_format:Y-m-d',
            'business_pct' => 'nullable|integer|min:1|max:100', 'note' => 'nullable|string|max:1000', 'paid_with' => 'nullable|in:cash,personal_card,business',
        ]);
        $changes = [];
        try {
            $currency = strtoupper((string) ($data['currency'] ?? $entry->currency ?? 'GBP'));
            if (array_key_exists('amount', $data) && $data['amount'] !== null) {
                $changes['amount_minor'] = Money::parseIn($data['amount'], $currency);
                $changes['currency'] = $currency;
            } elseif (isset($data['currency'])) {
                $changes['currency'] = $currency;
            }
            if (array_key_exists('sterling', $data)) {
                $changes['sterling_pence'] = $data['sterling'] !== null && $data['sterling'] !== '' ? Money::parse($data['sterling']) : null;
            }
            foreach (['what', 'category_id', 'work_job_id', 'account_id', 'spent_on', 'business_pct', 'note', 'paid_with'] as $k) {
                if ($request->has($k)) {
                    $changes[$k] = $data[$k] ?? null;
                }
            }
            $row = QuickEntries::asYou(fn () => $this->entries->correctFields($entry, $changes), 'web');
        } catch (InvalidArgumentException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        }
        $message = 'Saved: '.$row->words().'. '.QuickEntries::outcome($row).'.';

        return $request->expectsJson() ? response()->json(['entry' => QuickEntries::summary($row), 'message' => $message]) : back()->with('success', $message);
    }

    /** POST /quick/entries/{entry}/attach {line_id}: this is the payment (a line you picked: attached by you). */
    public function attach(Request $request, QuickEntry $entry): RedirectResponse
    {
        $data = $request->validate(['line_id' => 'required|integer']);

        return $this->run($entry->id.':attach:'.$data['line_id']);
    }

    /** POST /quick/entries/{entry}/detach: "Not this payment's receipt". */
    public function detach(QuickEntry $entry): RedirectResponse
    {
        if ($entry->status !== 'attached') {
            return back()->with('success', 'It isn’t on a bank line.');
        }
        QuickEntries::asYou(fn () => $this->attacher->detach($entry), 'web');

        return back()->with('success', 'Not this payment’s receipt: it waits for its own bank line, and won’t go on that one again.');
    }

    /** POST /quick/entries/{entry}/read: read the photo with AI again. */
    public function readAgain(QuickEntry $entry): RedirectResponse
    {
        return $this->run($entry->id.':read_again');
    }

    private function run(string $id): RedirectResponse
    {
        try {
            $message = QuickEntries::asYou(fn () => $this->entries->act($id), 'web');
        } catch (CardLineFits $e) {
            return back()->with('error', $e->getMessage());
        } catch (ModelNotFoundException) {
            return back()->with('success', 'Already dealt with.');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message !== '' ? $message : 'Done.');
    }

    /** GET /sw.js: the service worker (no session, no cookies; never cached without asking). */
    public function serviceWorker(): Response
    {
        return response(QuickWorker::script(), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/',
        ]);
    }

    /**
     * POST /quick/share: Android's share sheet, when the service worker wasn't there to take it (it keeps shares on the
     * phone itself). Nothing is stored: back to the screen, which says to open Expend once and share again.
     */
    public function shareFallback(): RedirectResponse
    {
        return redirect('/quick?share_failed=1', 303);
    }

    /**
     * GET /go?to=/quick/entries/12: a page Telegram's in-app browser opens, which goes on to the page in the same site,
     * so the browser sends your sign-in. Only an entry page or To do.
     */
    public function go(Request $request): Response
    {
        $to = (string) $request->query('to', '/quick');
        if (! preg_match('#^/(quick/entries/\d{1,10}|todo)(\?[A-Za-z0-9=&_\-]*)?$#', $to)) {
            $to = '/quick';
        }
        $nonce = Vite::cspNonce();
        $url = e($to);
        $js = json_encode($to, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html = '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Expend</title></head>'
            .'<body style="font-family:system-ui,sans-serif;padding:24px"><p><a href="'.$url.'">Open in Expend</a></p>'
            .'<script'.($nonce ? ' nonce="'.e($nonce).'"' : '').'>location.replace('.$js.');</script></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** A size as the phone says it too (lib/quick-image.ts megabytes): one decimal place, "1.9 MB", "30 MB". */
    public static function megabytes(int $bytes): string
    {
        $mb = max(0.1, round($bytes / 1048576, 1));

        return rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.').' MB';
    }

    /** The day a job is "on today" from, before OVERNIGHT_UNTIL yesterday's job still counts (for the screen's notes). */
    public static function overnightUntil(): string
    {
        return JobDays::OVERNIGHT_UNTIL;
    }
}
