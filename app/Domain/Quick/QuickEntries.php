<?php

namespace App\Domain\Quick;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Fx\RateUnavailable;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Detectors\QuickEntryChecks;
use App\Domain\Intel\Methods;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Intel\Vetoes;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\ManualExpenses;
use App\Domain\Todo\TodoList;
use App\Jobs\ReadDocument;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Document;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Expenses added on the phone, shared from Android or texted to the Telegram bot: the one way a quick entry is taken in,
 * changed and taken back. Every step is safe to repeat (the phone sends again on poor signal, Telegram delivers an
 * update twice, a button is pressed twice) and locks the entry while it changes it.
 *
 * Taking one in (submit()) goes in steps, so nothing is ever only on the phone and nothing is ever added twice:
 *  1. claim: the client key is written first, in its own short insert (status receiving). A repeat finds it and is
 *     told what happened (or, while the first is still arriving, to try again shortly);
 *  2. a duplicate photo (the same bytes as a live entry's) is kept as a duplicate, with nothing else written;
 *  3. another currency is converted outside any transaction (it may ask HMRC), and any failure holds the entry for a
 *     rate rather than failing;
 *  4. in one transaction, with the entry locked: cash or a personal card add the expense (ManualExpenses, your
 *     decision); a business card adds nothing (status waiting); what can't be added is held (locked_year,
 *     needs_rate, needs_details) and listed in To do;
 *  5. after it commits, a card receipt is matched to its bank line if that's in already (ReceiptAttacher).
 *
 * The photo arrives on its own (storePhoto()), checked against the size and sha256 the fields said, stored once per
 * sha256, and read with AI on the 'reads' queue. A withdraw for a key not seen yet leaves a tombstone, so a send that
 * arrives later writes nothing.
 */
final class QuickEntries
{
    /** A claim still arriving after this long is taken to have died (the request crashed) and is taken over. */
    public const RECEIVING_STALE_SECONDS = 120;

    /** The queue phone and share photos are read on, after Telegram presses and imports on 'default'. */
    public const READ_QUEUE = 'reads';

    public function __construct(private ManualExpenses $expenses, private DocumentIntake $intake, private ReceiptAttacher $attacher, private DecisionService $decisions) {}

    // ---- taking an entry in --------------------------------------------------------------------------------------

    /**
     * Take in an entry's fields (QuickController::store has checked they're well formed).
     *
     * @param  array<string, mixed>  $f  columns: client_key, channel, paid_with, account_id, spent_on, spent_at, captured_at, amount_minor, currency,
     *                                   sterling_pence, what, what_source, category_id, work_job_id, job_source, business_pct, note, photo (array|null),
     *                                   payload_sha, telegram_chat_id, telegram_message_id
     * @return array{status:int, body:array<string, mixed>}
     */
    public function submit(array $f): array
    {
        $key = (string) $f['client_key'];
        $payload = (string) ($f['payload_sha'] ?? self::payloadSha($f));
        try {
            // 1. The claim, committed on its own: the first thing written.
            $entry = QuickEntry::query()->create(['client_key' => $key, 'status' => 'receiving', 'payload_sha' => $payload, 'channel' => $f['channel'] ?? 'phone']);
        } catch (UniqueConstraintViolationException) {
            $existing = QuickEntry::query()->where('client_key', $key)->first();
            if ($existing === null) {
                return ['status' => 202, 'body' => ['state' => 'receiving', 'retry_after' => 30]];
            }
            $entry = $this->repeat($existing, $payload, $answer);
            if ($entry === null) {
                return $answer;
            }
        }

        return $this->take($entry, $f);
    }

    /**
     * A key sent before: what to answer, or (a claim whose request died) the entry to finish now.
     *
     * @param-out array{status:int, body:array<string, mixed>} $answer
     */
    private function repeat(QuickEntry $existing, string $payload, ?array &$answer): ?QuickEntry
    {
        if ($existing->status === 'withdrawn') {
            // Taken back (or a tombstone left by a withdraw that came first): nothing is written.
            $answer = ['status' => 200, 'body' => ['state' => 'withdrawn', 'entry' => self::summary($existing)]];

            return null;
        }
        if ($existing->status === 'receiving') {
            $took = DB::transaction(function () use ($existing) {
                $row = QuickEntry::query()->whereKey($existing->id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'receiving' || $row->updated_at === null || $row->updated_at->gt(now()->subSeconds(self::RECEIVING_STALE_SECONDS))) {
                    return null;
                }
                $row->touch(); // taken over: another repeat now waits for this one

                return $row;
            });
            if ($took === null) {
                $answer = ['status' => 202, 'body' => ['state' => 'receiving', 'retry_after' => 30]];

                return null;
            }

            return $took;
        }
        $same = $existing->payload_sha === null || hash_equals((string) $existing->payload_sha, $payload);
        $answer = ['status' => 200, 'body' => ['repeat' => true, 'entry' => self::summary($existing)]
            + ($same ? [] : ['changed' => true, 'message' => 'This was sent before your change, so nothing changed. Open it to change it.'])];

        return null;
    }

    /**
     * Steps 2 to 5 for a claimed entry (status receiving).
     *
     * @return array{status:int, body:array<string, mixed>}
     */
    private function take(QuickEntry $entry, array $f, ?Document $doc = null): array
    {
        $fields = $this->fields($f);
        if ($doc !== null) {
            // A Telegram draft’s photo, kept already: the entry holds it from the start.
            $fields['document_id'] = $doc->id;
            $fields['photo_status'] = 'stored';
        }
        $hold = $this->holdFor($fields, $f);

        // 2. The same photo as a live entry's: kept as a duplicate of it, nothing else written. With other figures
        // (another amount, currency or way of paying: the wrong photo picked, or the same receipt typed differently),
        // it's held and To do asks which is right.
        $twin = $fields['photo_sha256'] ? QuickEntry::query()->live()->where('photo_sha256', $fields['photo_sha256'])->where('id', '!=', $entry->id)->orderBy('id')->first() : null;
        if ($twin && $fields['amount_minor'] !== null && ((int) $twin->amount_minor !== (int) $fields['amount_minor'] || $twin->currency !== $fields['currency']
            || $twin->paid_with !== $fields['paid_with'])) {
            $hold ??= 'same_photo';
            $fields['answers'] = ['held_message' => 'Same photo as '.$twin->words().' ('.(QuickEntry::PAID_WITH[$twin->paid_with ?? ''] ?? 'added').', '
                .($twin->spent_on?->format('j M') ?? '').'), but '.Money::formatMinor((int) $fields['amount_minor'], $fields['currency'], false).' '
                .mb_strtolower(QuickEntry::PAID_WITH[$fields['paid_with'] ?? ''] ?? '').' here: which is right?', 'twin_id' => $twin->id];
            $twin = null;
        }

        // 3. Another currency, outside any transaction (HMRC may be asked): any failure holds it for a rate.
        $converted = null;
        if (! $twin && $hold === null && in_array($fields['paid_with'], ['cash', 'personal_card'], true)) {
            try {
                $converted = $this->expenses->convert(-abs((int) $fields['amount_minor']), $fields['currency'], CarbonImmutable::parse($fields['spent_on']), $fields['sterling_pence']);
            } catch (RateUnavailable $e) {
                $hold = 'needs_rate';
                $fields['answers'] = ['held_message' => $e->getMessage()];
            }
        }

        // 4. One transaction, the entry locked.
        $entry = DB::transaction(function () use ($entry, $fields, $hold, $converted, $twin) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'receiving') {
                return $row; // withdrawn meanwhile: nothing more
            }
            $row->forceFill($fields);
            if ($twin) {
                $row->forceFill(['status' => 'duplicate', 'duplicate_of_id' => $twin->id, 'photo_status' => null]);
            } elseif ($hold !== null) {
                $row->forceFill(['status' => 'held', 'hold_reason' => $hold]);
            } elseif ($row->paid_with === 'business') {
                $row->forceFill(['status' => 'waiting']);
            } else {
                $this->addExpense($row, $converted);
            }
            $row->save();
            AuditLog::record('quick_entry.received', $row, null, self::snapshot($row) + ['photo_status' => $row->photo_status], self::channelWords($row));

            return $row;
        });
        TodoList::forget();
        if (! $entry || $entry->status === 'withdrawn') {
            return ['status' => 200, 'body' => ['state' => 'withdrawn', 'entry' => $entry ? self::summary($entry) : null]];
        }

        // 5. After it's in: a card receipt goes on its bank line if that's in already.
        if ($entry->status === 'waiting') {
            try {
                $this->attacher->forEntry($entry);
                $entry->refresh();
            } catch (Throwable $e) {
                report($e); // matched tonight instead
            }
        }

        return ['status' => 201, 'body' => ['entry' => self::summary($entry), 'warnings' => self::warnings($entry)]];
    }

    /**
     * Add the expense for a cash or personal-card entry (inside the entry's transaction, the entry locked). One that
     * can't be added (a category of the wrong kind, a year locked meanwhile) is held with the reason.
     */
    private function addExpense(QuickEntry $row, ?array $converted): void
    {
        if ($row->manual_expense_id !== null && ManualExpense::query()->whereKey($row->manual_expense_id)->exists()) {
            $row->forceFill(['status' => 'added', 'hold_reason' => null]);

            return; // added already
        }
        try {
            $converted ??= $this->expenses->convert(-abs((int) $row->amount_minor), $row->currency, CarbonImmutable::parse($row->spent_on->format('Y-m-d')), $row->sterling_pence);
            $expense = $this->expenses->create([
                'spent_on' => $row->spent_on->format('Y-m-d'), 'description' => self::description($row), 'paid_with' => $row->paid_with, 'kind' => 'business_expense',
                'category_id' => $row->category_id, 'work_job_id' => $row->work_job_id, 'business_pct' => $row->business_pct ?? 100, 'note' => $row->note,
                'reason' => $row->channel === 'telegram' ? 'Added in Telegram' : 'Added on your phone',
            ], $converted);
            $row->forceFill(['status' => 'added', 'hold_reason' => null, 'manual_expense_id' => $expense->id]);
        } catch (RateUnavailable $e) {
            $row->forceFill(['status' => 'held', 'hold_reason' => 'needs_rate', 'answers' => array_merge($row->answers ?? [], ['held_message' => $e->getMessage()])]);
        } catch (InvalidArgumentException $e) {
            $locked = ManualExpenses::locked(UkTaxYear::fromDate($row->spent_on)->label());
            $row->forceFill(['status' => 'held', 'hold_reason' => $locked ? 'locked_year' : 'needs_details', 'answers' => array_merge($row->answers ?? [], ['held_message' => $e->getMessage()])]);
        }
    }

    /**
     * The entry's columns from what was sent: ids that no longer exist are left out (and it's held for details).
     *
     * @return array<string, mixed>
     */
    private function fields(array $f): array
    {
        $date = ! empty($f['spent_on']) ? CarbonImmutable::parse($f['spent_on']) : CarbonImmutable::now('Europe/London')->startOfDay();
        $photo = is_array($f['photo'] ?? null) ? $f['photo'] : null;
        $currency = strtoupper((string) ($f['currency'] ?? 'GBP')) ?: 'GBP';
        $exists = fn (string $model, mixed $id) => $id !== null && $id !== '' && $model::query()->whereKey((int) $id)->exists() ? (int) $id : null;
        $what = isset($f['what']) && trim((string) $f['what']) !== '' ? mb_substr(trim((string) $f['what']), 0, 500) : null;

        return [
            'channel' => in_array($f['channel'] ?? null, QuickEntry::CHANNELS, true) ? $f['channel'] : 'phone',
            'paid_with' => isset(QuickEntry::PAID_WITH[$f['paid_with'] ?? '']) ? $f['paid_with'] : null,
            'account_id' => $exists(Account::class, $f['account_id'] ?? null),
            'spent_on' => $date->format('Y-m-d'),
            'spent_at' => ! empty($f['spent_at']) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $f['spent_at']) ? substr((string) $f['spent_at'], 0, 5).':00' : null,
            'captured_at' => ! empty($f['captured_at']) ? CarbonImmutable::parse($f['captured_at']) : now(),
            'tax_year' => UkTaxYear::fromDate($date)->label(),
            'amount_minor' => isset($f['amount_minor']) ? abs((int) $f['amount_minor']) : null,
            'currency' => $currency,
            'sterling_pence' => isset($f['sterling_pence']) && (int) $f['sterling_pence'] !== 0 ? abs((int) $f['sterling_pence']) : null,
            'what' => $what,
            'what_source' => $what === null ? (($f['what_source'] ?? null) === 'chip' ? 'chip' : null) : (in_array($f['what_source'] ?? null, QuickEntry::WHAT_SOURCES, true) ? $f['what_source'] : 'typed'),
            'category_id' => $exists(Category::class, $f['category_id'] ?? null),
            'work_job_id' => $exists(WorkJob::class, $f['work_job_id'] ?? null),
            'job_source' => in_array($f['job_source'] ?? null, QuickEntry::JOB_SOURCES, true) ? $f['job_source'] : null,
            'business_pct' => isset($f['business_pct']) && (int) $f['business_pct'] >= 1 && (int) $f['business_pct'] <= 100 ? (int) $f['business_pct'] : 100,
            'note' => isset($f['note']) && trim((string) $f['note']) !== '' ? mb_substr(trim((string) $f['note']), 0, 1000) : null,
            'photo_sha256' => $photo && preg_match('/^[0-9a-f]{64}$/', strtolower((string) ($photo['sha256'] ?? ''))) ? strtolower((string) $photo['sha256']) : null,
            'photo_size' => $photo && isset($photo['size']) ? (int) $photo['size'] : null,
            'photo_status' => $photo ? 'expected' : null,
            'telegram_chat_id' => $f['telegram_chat_id'] ?? null,
            'telegram_message_id' => $f['telegram_message_id'] ?? null,
        ];
    }

    /** Why it can't be added as it is: locked_year, needs_details, or null (needs_rate is found when converting). */
    private function holdFor(array $fields, array $sent): ?string
    {
        $missing = fn (string $k) => ! empty($sent[$k]) && $fields[$k] === null; // an id that no longer exists
        if ($fields['paid_with'] === null || $missing('category_id') || $missing('work_job_id') || $missing('account_id') || ! self::knownCurrency($fields['currency'])) {
            return 'needs_details';
        }
        if ($fields['category_id'] !== null && Category::query()->whereKey($fields['category_id'])->value('kind') !== 'expense') {
            return 'needs_details';
        }
        if ($fields['paid_with'] === 'business') {
            return null; // waits for its bank line (never attached into a locked year)
        }
        if ($fields['amount_minor'] === null || $fields['amount_minor'] === 0 || $fields['category_id'] === null) {
            return 'needs_details';
        }

        return ManualExpenses::locked($fields['tax_year']) ? 'locked_year' : null;
    }

    // ---- the photo ------------------------------------------------------------------------------------------------

    /**
     * Keep the photo (or PDF) sent for an entry: checked against the size and sha256 its fields said, stored once per
     * sha256 (concurrent stores of one photo wait for each other), checked to be on the disk, and read with AI on the
     * 'reads' queue. A copy Expend already kept (from Gmail, say) is used as it is, with your job on it.
     *
     * @return array{status:int, body:array<string, mixed>}
     */
    public function storePhoto(string $key, UploadedFile $file): array
    {
        $entry = QuickEntry::query()->where('client_key', $key)->first();
        if (! $entry || $entry->status === 'receiving') {
            return ['status' => 404, 'body' => ['error' => 'not_found', 'message' => 'Send the entry first.']];
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $sha = hash('sha256', $bytes);
        $size = strlen($bytes);
        if ($entry->photo_sha256 !== null && (! hash_equals($entry->photo_sha256, $sha) || ($entry->photo_size !== null && $entry->photo_size !== $size))) {
            return ['status' => 422, 'body' => ['error' => 'photo_mismatch', 'message' => 'The photo didn’t arrive whole. Sending it again.', 'sha256' => $sha]];
        }
        if ($entry->document_id !== null && ($doc = Document::query()->find($entry->document_id)) && $doc->sha256 === $sha) {
            return ['status' => 200, 'body' => ['sha256' => $sha, 'document_id' => $doc->id, 'repeat' => true]];
        }
        if ($entry->status === 'duplicate') {
            return ['status' => 200, 'body' => ['sha256' => $sha, 'document_id' => null, 'duplicate' => true]];
        }

        $mime = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';
        $ext = strtolower($file->getClientOriginalExtension() ?: (str_contains($mime, 'pdf') ? 'pdf' : 'jpg'));
        $name = 'receipt-'.($entry->captured_at ?? now())->format('Ymd-Hi').'.'.preg_replace('/[^a-z0-9]/', '', $ext);
        $source = $entry->channel === 'share' ? 'share' : ($entry->channel === 'telegram' ? 'telegram' : 'quick');

        try {
            [$doc, $existed] = Cache::lock('doc:'.$sha, 30)->block(20, function () use ($bytes, $name, $mime, $source, $entry, $sha) {
                $before = Document::query()->where('sha256', $sha)->first();
                $doc = $before ?? $this->intake->store($bytes, $name, $mime, $source, [
                    'kind' => 'receipt', 'read' => false, 'work_job_id' => $entry->work_job_id, 'job_linked_by' => 'you', 'received_at' => $entry->captured_at ?? now(),
                    'subject' => $entry->what,
                ]);

                return [$doc, $before !== null];
            });
        } catch (Throwable $e) {
            report($e);

            return ['status' => 500, 'body' => ['error' => 'store_failed', 'message' => 'The photo couldn’t be saved just now. Sending it again.']];
        }
        // The local disk doesn't throw when a write fails: make sure it's really there.
        $disk = Storage::disk('local');
        if (! $disk->exists($doc->stored_path) || $disk->size($doc->stored_path) !== $size) {
            return ['status' => 500, 'body' => ['error' => 'store_failed', 'message' => 'The photo couldn’t be saved just now. Sending it again.']];
        }

        $readNow = false;
        DB::transaction(function () use ($entry, $doc, $existed, $sha, $size, &$readNow) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $before = ['document_id' => $row->document_id, 'photo_status' => $row->photo_status];
            $row->forceFill(['document_id' => $doc->id, 'photo_sha256' => $sha, 'photo_size' => $size,
                'photo_status' => $doc->isImage() || $doc->isPdf() ? 'stored' : 'unreadable'])->save();
            if ($existed) {
                // A copy kept before (the Gmail scan's, say): your details go on it. Your job link wins.
                $d = Document::query()->whereKey($doc->id)->lockForUpdate()->first();
                if ($row->work_job_id !== null && ((int) $d->work_job_id !== (int) $row->work_job_id || $d->job_linked_by !== 'you')) {
                    $was = $d->only(['work_job_id', 'job_linked_by']);
                    $d->forceFill(['work_job_id' => $row->work_job_id, 'job_linked_by' => 'you'])->save();
                    AuditLog::record('document.job_linked', $d, $was, $d->only(['work_job_id', 'job_linked_by']), 'From your receipt photo on the phone');
                }
                if (in_array($d->kind, ['unknown', 'other'], true)) {
                    $was = ['kind' => $d->kind];
                    $d->forceFill(['kind' => 'receipt'])->save();
                    AuditLog::record('document.updated', $d, $was, ['kind' => 'receipt'], 'Your receipt photo from the phone');
                }
                $readNow = $d->extraction !== null;
            }
            AuditLog::record('quick_entry.photo', $row, $before, ['document_id' => $doc->id, 'photo_status' => $row->photo_status]);
        });

        $doc->refresh();
        if ($readNow) {
            app(QuickReadCheck::class)->afterRead($doc); // read already: checked against what you typed now
        } elseif ($doc->extraction === null && ($doc->isImage() || $doc->isPdf())) {
            self::queueRead($doc);
        }
        TodoList::forget();

        return ['status' => 200, 'body' => ['sha256' => $sha, 'document_id' => $doc->id]];
    }

    /** Read a phone photo with AI on the 'reads' queue, once this transaction commits, when reading is on. */
    public static function queueRead(Document $doc): bool
    {
        if (Setting::get('ai_extraction') !== 'on' || ! AiConfig::ready() || in_array($doc->status, ['held', 'queued'], true)) {
            return false;
        }
        $doc->update(['status' => 'queued', 'error' => null]);
        ReadDocument::dispatch($doc->id)->onQueue(self::READ_QUEUE)->afterCommit();

        return true;
    }

    /**
     * Read again the phone's photos whose read was refused at the AI spending cap (the cycle off marks them failed):
     * ledger:receipts, once a night. With the cycle on they wait and acquire.retry_reads reads them.
     *
     * @return int photos queued
     */
    public static function retryCappedReads(int $max = 20): int
    {
        $ids = QuickEntry::query()->whereNotNull('document_id')->whereNotIn('status', ['withdrawn', 'dismissed', 'duplicate'])->pluck('document_id')->unique()->all();
        $n = 0;
        foreach (Document::query()->whereIn('id', $ids ?: [0])->where('status', 'failed')->whereNull('extraction')
            ->where(fn ($q) => $q->where('error', 'like', '%spending cap%')->orWhere('error', 'like', '%AI allowance%'))->orderBy('id')->limit($max)->get() as $doc) {
            $n += self::queueRead($doc) ? 1 : 0;
        }

        return $n;
    }

    // ---- taking it back ---------------------------------------------------------------------------------------------

    /**
     * Undo or Delete from the phone, by its key. A cash or personal expense is taken back (ManualExpenses::withdraw); a
     * card receipt stops waiting, and its suggestion on a line goes while it's still the matcher's and unconfirmed. A
     * key not seen yet leaves a tombstone, so a send that arrives later writes nothing.
     *
     * @return array{status:int, body:array<string, mixed>}
     */
    public function withdraw(string $key, ?string $reason = null): array
    {
        $reason ??= 'Removed on your phone';
        $entry = QuickEntry::query()->where('client_key', $key)->first();
        if (! $entry) {
            try {
                $entry = QuickEntry::query()->create(['client_key' => $key, 'status' => 'withdrawn', 'channel' => 'phone']);
                AuditLog::record('quick_entry.withdrawn', $entry, null, ['status' => 'withdrawn', 'tombstone' => true], $reason);

                return ['status' => 200, 'body' => ['state' => 'withdrawn', 'entry' => self::summary($entry)]];
            } catch (UniqueConstraintViolationException) {
                $entry = QuickEntry::query()->where('client_key', $key)->firstOrFail(); // the send arrived just now
            }
        }
        try {
            $entry = $this->takeBack($entry, $reason);
        } catch (InvalidArgumentException $e) {
            return ['status' => 409, 'body' => ['state' => $entry->status, 'message' => $e->getMessage(), 'entry' => self::summary($entry)]];
        }

        return ['status' => 200, 'body' => ['state' => $entry->status, 'entry' => self::summary($entry)]];
    }

    /**
     * Take an entry back: whatever it added goes, and it counts nowhere. Done already says so.
     *
     * @throws InvalidArgumentException an expense in a locked year
     */
    public function takeBack(QuickEntry $entry, string $reason): QuickEntry
    {
        $done = DB::transaction(function () use ($entry, $reason) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (in_array($row->status, ['withdrawn', 'dismissed'], true)) {
                return $row;
            }
            $before = self::snapshot($row);
            if ($row->manual_expense_id !== null && ($expense = ManualExpense::query()->find($row->manual_expense_id))) {
                $this->expenses->withdraw($expense, $reason);
            }
            if ($row->bank_transaction_id !== null) {
                $this->attacher->takeBackSuggestion($row);
            }
            $row->forceFill(['status' => $row->status === 'draft' ? 'dismissed' : 'withdrawn', 'manual_expense_id' => null, 'bank_transaction_id' => null, 'attached_by' => null])->save();
            AuditLog::record('quick_entry.withdrawn', $row, $before, self::snapshot($row), $reason);

            return $row;
        });
        TodoList::forget();

        return $done;
    }

    // ---- changing it --------------------------------------------------------------------------------------------------

    /**
     * Change a card receipt that's waiting, an entry that's held or a Telegram draft (the entry page, a reply in
     * Telegram, the draft's Change… buttons). A held one is added once nothing holds it any more; a waiting receipt is
     * matched again. An added expense is changed in the item drawer instead.
     *
     * @param  array<string, mixed>  $changes  amount_minor, currency, sterling_pence, what, category_id, work_job_id, spent_on, business_pct, note, paid_with, account_id
     *
     * @throws InvalidArgumentException when it can't be changed here
     */
    public function correctFields(QuickEntry $entry, array $changes, string $why = 'Changed by you'): QuickEntry
    {
        $row = DB::transaction(function () use ($entry, $changes, $why) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (! in_array($row->status, ['draft', 'held', 'waiting'], true)) {
                throw new InvalidArgumentException($row->status === 'added' ? 'It’s added as an expense: change it in Transactions.' : 'It can’t be changed now.');
            }
            $before = self::snapshot($row);
            $fill = [];
            foreach (['amount_minor', 'sterling_pence', 'business_pct'] as $k) {
                if (array_key_exists($k, $changes)) {
                    $fill[$k] = $changes[$k] === null ? null : abs((int) $changes[$k]);
                }
            }
            if (isset($changes['currency'])) {
                $fill['currency'] = strtoupper((string) $changes['currency']);
            }
            if (array_key_exists('what', $changes)) {
                $fill['what'] = trim((string) $changes['what']) !== '' ? mb_substr(trim((string) $changes['what']), 0, 500) : null;
                $fill['what_source'] = $changes['what_source'] ?? 'typed';
            }
            foreach (['category_id' => Category::class, 'work_job_id' => WorkJob::class, 'account_id' => Account::class] as $k => $model) {
                if (array_key_exists($k, $changes)) {
                    $fill[$k] = $changes[$k] ? ($model::query()->whereKey((int) $changes[$k])->exists() ? (int) $changes[$k] : throw new InvalidArgumentException('That no longer exists: choose again.')) : null;
                }
            }
            if (array_key_exists('work_job_id', $changes)) {
                $fill['job_source'] = $changes['job_source'] ?? 'you';
            }
            if (isset($changes['spent_on'])) {
                $on = CarbonImmutable::parse($changes['spent_on']);
                $fill['spent_on'] = $on->format('Y-m-d');
                $fill['tax_year'] = UkTaxYear::fromDate($on)->label();
            }
            if (isset($changes['paid_with'])) {
                if (! isset(QuickEntry::PAID_WITH[$changes['paid_with']])) {
                    throw new InvalidArgumentException('Choose how you paid.');
                }
                if ($row->status !== 'draft' && $row->status !== 'held') {
                    throw new InvalidArgumentException('Use “Paid in cash” or “Paid by personal card” for that.');
                }
                $fill['paid_with'] = $changes['paid_with'];
            }
            if (array_key_exists('note', $changes)) {
                $fill['note'] = trim((string) $changes['note']) !== '' ? mb_substr(trim((string) $changes['note']), 0, 1000) : null;
            }
            $row->forceFill($fill);
            if (! $row->isDirty()) {
                return $row;
            }
            $row->save();
            AuditLog::record('quick_entry.changed', $row, $before, self::snapshot($row), $why);

            return $row;
        });
        if ($row->status === 'held') {
            try {
                $row = $this->addHeld($row);
            } catch (InvalidArgumentException) {
                // still held: the page says why
            }
        } elseif ($row->isWaiting()) {
            $this->attacher->forEntry($row);
        }
        TodoList::forget();

        return $row->fresh();
    }

    /**
     * Add a held entry now ("Add it now"): refused while its year is locked; tries the rate again; once its details
     * are there, a cash or personal entry adds its expense once, a card receipt starts waiting for its line.
     *
     * @throws InvalidArgumentException still locked, or still missing what it needs
     */
    public function addHeld(QuickEntry $entry): QuickEntry
    {
        $entry->refresh();
        if ($entry->status !== 'held') {
            throw (new ModelNotFoundException)->setModel(QuickEntry::class, [$entry->id]);
        }
        $label = UkTaxYear::fromDate($entry->spent_on ?? now())->label();
        if (in_array($entry->paid_with, ['cash', 'personal_card'], true) && ManualExpenses::locked($label)) {
            throw new InvalidArgumentException("Unlock {$label} with a reason first.");
        }
        $fields = $entry->only(['paid_with', 'category_id', 'work_job_id', 'account_id', 'amount_minor', 'currency', 'tax_year']);
        $hold = $this->holdFor($fields + ['tax_year' => $label], []);
        if ($hold !== null) {
            throw new InvalidArgumentException($hold === 'needs_details' ? 'Choose what it was and how you paid first.' : "Unlock {$label} with a reason first.");
        }
        $converted = null;
        if ($entry->paid_with !== 'business') {
            try {
                $converted = $this->expenses->convert(-abs((int) $entry->amount_minor), $entry->currency, CarbonImmutable::parse($entry->spent_on->format('Y-m-d')), $entry->sterling_pence);
            } catch (RateUnavailable $e) {
                throw new InvalidArgumentException($e->getMessage());
            }
        }
        $row = DB::transaction(function () use ($entry, $converted) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'held') {
                return $row;
            }
            $before = self::snapshot($row);
            if ($row->paid_with === 'business') {
                $row->forceFill(['status' => 'waiting', 'hold_reason' => null]);
            } else {
                $this->addExpense($row, $converted);
            }
            $row->save();
            AuditLog::record('quick_entry.changed', $row, $before, self::snapshot($row), 'Added it now');

            return $row;
        });
        if ($row->status === 'held') {
            throw new InvalidArgumentException((string) (($row->answers ?? [])['held_message'] ?? 'It still can’t be added.'));
        }
        if ($row->status === 'waiting') {
            $this->attacher->forEntry($row);
        }
        TodoList::forget();

        return $row->fresh();
    }

    /**
     * A card receipt that was really paid in cash or by a personal card: the expense is added once. Refused while the
     * receipt is on a bank line, and while a card payment that fits it hasn't been turned down (it may be this one).
     *
     * @throws InvalidArgumentException
     * @throws CardLineFits
     */
    public function convertToExpense(QuickEntry $entry, string $paidWith): QuickEntry
    {
        if (! in_array($paidWith, ['cash', 'personal_card'], true)) {
            throw new InvalidArgumentException('Choose cash or a personal card.');
        }
        $entry->refresh();
        if ($entry->status === 'added') {
            throw (new ModelNotFoundException)->setModel(QuickEntry::class, [$entry->id]); // added already
        }
        $this->guard($entry);
        $label = UkTaxYear::fromDate($entry->spent_on ?? now())->label();
        if (ManualExpenses::locked($label)) {
            throw new InvalidArgumentException("Not added: {$label} is locked. Unlock it in Expend first.");
        }
        if ($entry->amount_minor === null || $entry->category_id === null) {
            throw new InvalidArgumentException('Enter the amount and what it was first: '.url('/quick/entries/'.$entry->id));
        }
        try {
            $converted = $this->expenses->convert(-abs((int) $entry->amount_minor), $entry->currency, CarbonImmutable::parse($entry->spent_on->format('Y-m-d')), $entry->sterling_pence);
        } catch (RateUnavailable $e) {
            throw new InvalidArgumentException($e->getMessage());
        }
        $row = DB::transaction(function () use ($entry, $paidWith, $converted) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (! in_array($row->status, ['waiting', 'attached', 'held', 'set_aside'], true) || $row->manual_expense_id !== null) {
                return $row; // added (or taken back) meanwhile
            }
            if ($row->status === 'attached' && BankTransaction::query()->whereKey($row->bank_transaction_id)->exists()) {
                throw new InvalidArgumentException('It’s on a bank line: say “Not this payment’s receipt” first.');
            }
            $before = self::snapshot($row);
            $row->forceFill(['paid_with' => $paidWith, 'bank_transaction_id' => null, 'attached_by' => null, 'attached_at' => null]);
            $this->addExpense($row, $converted);
            $row->save();
            AuditLog::record('quick_entry.changed', $row, $before, self::snapshot($row), $paidWith === 'cash' ? 'Paid in cash' : 'Paid by personal card');

            return $row;
        });
        TodoList::forget();
        if ($row->status === 'held') {
            throw new InvalidArgumentException((string) (($row->answers ?? [])['held_message'] ?? 'It couldn’t be added.'));
        }

        return $row;
    }

    /**
     * Cash or a personal card that was really the business card: the expense is taken back, and the receipt goes on
     * the card line named ("It was the card", decided as yours with the receipt's category, job and share) or waits for
     * one.
     *
     * @throws InvalidArgumentException a locked year
     */
    public function convertToCard(QuickEntry $entry, ?int $lineId = null, string $why = 'It was the card'): QuickEntry
    {
        $line = $lineId !== null ? BankTransaction::query()->findOrFail($lineId) : null;
        $row = DB::transaction(function () use ($entry, $line, $why) {
            if ($line) {
                BankTransaction::query()->whereKey($line->id)->lockForUpdate()->first();
            }
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'added') {
                return $row;
            }
            $before = self::snapshot($row);
            if ($row->manual_expense_id !== null && ($expense = ManualExpense::query()->find($row->manual_expense_id))) {
                $this->expenses->withdraw($expense, $why);
            }
            $row->forceFill(['paid_with' => 'business', 'status' => 'waiting', 'manual_expense_id' => null, 'account_id' => $line?->account_id ?? $row->account_id])->save();
            AuditLog::record('quick_entry.changed', $row, $before, self::snapshot($row), $why);

            return $row;
        });
        if ($row->status === 'waiting') {
            if ($line) {
                $this->attacher->attach($row, $line, 'you');
                $row->refresh();
                if ($row->status === 'attached' && (int) $row->bank_transaction_id === $line->id) {
                    $this->decideLine($row, $line->fresh());
                }
            } else {
                $this->attacher->forEntry($row);
            }
        }
        TodoList::forget();

        return $row->fresh();
    }

    /**
     * The receipt's choices on its line, as your decision ("It was the card", "Use the receipt's"): its category, job
     * and business share. A line you decided before another way is left for quick.line_differs to ask about, unless
     * $over says you chose the receipt's.
     */
    public function decideLine(QuickEntry $entry, BankTransaction $line, bool $over = false): bool
    {
        if ($entry->category_id === null) {
            return false;
        }
        $mine = $line->allocations()->get()->contains(fn ($a) => $a->is_manual || $a->confirmed_at !== null);
        if ($mine && ! $over) {
            return false;
        }
        $r = $this->decisions->apply(['kind' => 'business_expense', 'category_id' => $entry->category_id, 'work_job_id' => $entry->work_job_id,
            'business_pct' => $entry->business_pct ?? 100, 'reason' => 'From your receipt photo'], collect([$line]));
        if ($r['errors']) {
            throw new InvalidArgumentException($r['errors'][0]);
        }

        return $r['saved'] > 0;
    }

    /**
     * Turn a draft from Telegram into an entry: how you paid, then the same steps as the phone's. A draft that isn't a
     * draft any more does nothing ("Already added").
     *
     * @return array{status:int, body:array<string, mixed>}
     *
     * @throws CardLineFits cash or a personal card while a card payment fits
     */
    public function submitDraft(QuickEntry $entry, ?string $paidWith): array
    {
        $entry->refresh();
        if ($entry->status !== 'draft') {
            throw (new ModelNotFoundException)->setModel(QuickEntry::class, [$entry->id]);
        }
        $paidWith ??= $entry->paid_with;
        if (! isset(QuickEntry::PAID_WITH[(string) $paidWith])) {
            throw new InvalidArgumentException('Choose how you paid.');
        }
        if ($paidWith !== 'business') {
            $this->guard($entry->forceFill(['paid_with' => $paidWith]));
        }
        $claimed = DB::transaction(function () use ($entry) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'draft') {
                return null;
            }
            $row->forceFill(['status' => 'receiving'])->save();

            return $row;
        });
        if (! $claimed) {
            throw (new ModelNotFoundException)->setModel(QuickEntry::class, [$entry->id]);
        }
        $f = $claimed->only(['channel', 'account_id', 'spent_at', 'captured_at', 'amount_minor', 'currency', 'sterling_pence', 'what', 'what_source', 'category_id',
            'work_job_id', 'job_source', 'business_pct', 'note', 'telegram_chat_id', 'telegram_message_id']);
        $f['paid_with'] = $paidWith;
        $f['spent_on'] = $claimed->spent_on?->format('Y-m-d');
        // A photo the draft holds is the entry's (the same photo as another entry's makes it a duplicate of that one).
        $doc = $claimed->document_id ? Document::query()->find($claimed->document_id, ['id', 'sha256', 'size']) : null;
        $f['photo'] = $doc ? ['sha256' => $doc->sha256, 'size' => (int) $doc->size] : null;

        return $this->take($claimed, $f, $doc);
    }

    /**
     * Not a business cost: kept, set aside, counted nowhere (a card receipt with no line, a held entry).
     */
    public function setAside(QuickEntry $entry): QuickEntry
    {
        $row = DB::transaction(function () use ($entry) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (! in_array($row->status, ['waiting', 'held'], true) && ! $row->isWaiting()) {
                return $row;
            }
            $before = self::snapshot($row);
            if ($row->bank_transaction_id !== null) {
                $this->attacher->takeBackSuggestion($row);
            }
            $row->forceFill(['status' => 'set_aside', 'bank_transaction_id' => null, 'attached_by' => null])->save();
            AuditLog::record('quick_entry.changed', $row, $before, self::snapshot($row), 'Not a business cost');

            return $row;
        });
        TodoList::forget();

        return $row;
    }

    /**
     * Refuse cash or a personal card for a receipt that's on a card line, or that a card line fits and you haven't
     * turned down (it may be this one: "Is it this one?").
     *
     * @throws InvalidArgumentException
     * @throws CardLineFits
     */
    public function guard(QuickEntry $entry): void
    {
        if ($entry->status === 'attached' && BankTransaction::query()->whereKey($entry->bank_transaction_id)->exists()) {
            throw new InvalidArgumentException('It’s on a bank line: say “Not this payment’s receipt” first.');
        }
        if ($entry->amount_minor === null) {
            return;
        }
        $line = $this->attacher->cardCandidates($entry, ReceiptAttacher::DATE_DAYS)->first();
        if ($line) {
            throw new CardLineFits($line);
        }
    }

    /** Record one of your answers on the entry (answers.$key gets $value), audited. */
    public function answer(QuickEntry $entry, string $key, mixed $value, string $why): QuickEntry
    {
        $row = DB::transaction(function () use ($entry, $key, $value, $why) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $answers = $row->answers ?? [];
            $list = array_values((array) ($answers[$key] ?? []));
            foreach ((array) $value as $v) {
                if (! in_array($v, $list, true)) {
                    $list[] = $v;
                }
            }
            if ($list === array_values((array) ($answers[$key] ?? []))) {
                return $row;
            }
            $before = ['answers' => $answers];
            $answers[$key] = $list;
            $row->forceFill(['answers' => $answers])->save();
            AuditLog::record('quick_entry.answered', $row, $before, ['answers' => $answers], $why);

            return $row;
        });
        TodoList::forget();

        return $row;
    }

    // ---- one-tap answers (to-do action qx) ------------------------------------------------------------------------

    /** The verbs a qx button can carry, each with what the entry must still be for it to act (Fingerprints::settled()). */
    public const VERBS = [
        'card' => ['draft'], 'personal' => ['draft'], 'cash' => ['draft'], 'add' => ['draft'], 'edit' => ['draft'], 'back' => ['draft'], 'cat' => ['draft'],
        'job' => ['draft'], 'day' => ['draft'], 'claim_photo' => ['draft'], 'not_that_photo' => ['draft'],
        'cancel' => ['draft', 'held', 'waiting', 'added', 'attached', 'set_aside'],
        'attach' => ['waiting', 'attached'], 'none' => ['waiting', 'attached'], 'paid_cash' => ['waiting', 'attached'], 'paid_personal' => ['waiting', 'attached'],
        'aside' => ['waiting', 'attached', 'held'], 'same_copy' => ['waiting', 'attached'], 'other_payment' => ['waiting', 'attached'],
        'to_card' => ['added'], 'not_card' => ['added', 'waiting', 'attached', 'draft'],
        'same' => ['draft', 'added', 'waiting', 'attached', 'held'], 'not_same' => ['draft', 'added', 'waiting', 'attached', 'held'],
        'use_read' => ['added', 'waiting', 'attached', 'held'], 'keep_mine' => ['added', 'waiting', 'attached', 'held'], 'read_again' => ['draft', 'added', 'waiting', 'attached', 'held'],
        'use_receipt' => ['attached'], 'keep_line' => ['attached'], 'detach' => ['attached'], 'add_held' => ['held'],
    ];

    /**
     * Parse a qx id: "<entry>:<verb>" or "<entry>:<verb>:<argument>" (12:cash, 12:attach:381, 12:same:exp:4).
     *
     * @return array{0:int, 1:string, 2:?string}|null
     */
    public static function parseAction(string $id): ?array
    {
        if (! preg_match('/^(\d{1,10}):([a-z_]{2,20})(?::([A-Za-z0-9_:,]{1,40}))?$/', $id, $m) || ! isset(self::VERBS[$m[2]])) {
            return null;
        }

        return [(int) $m[1], $m[2], ($m[3] ?? '') !== '' ? $m[3] : null];
    }

    /**
     * Do what a qx button says (from To do, the entry page or Telegram), as your decision. Done already, or the entry
     * no longer in a state for it, says so (ModelNotFoundException: "Already dealt with") and changes nothing.
     *
     * @return string what happened, for a message
     *
     * @throws InvalidArgumentException refused, with why
     * @throws CardLineFits cash or a personal card while a card payment fits
     */
    public function act(string $id): string
    {
        $parsed = self::parseAction($id);
        if ($parsed === null) {
            throw new InvalidArgumentException('Unknown action.');
        }
        [$entryId, $verb, $arg] = $parsed;
        $entry = QuickEntry::query()->findOrFail($entryId);
        if (! in_array($entry->status, self::VERBS[$verb], true) && ! ($verb === 'attach' && $entry->isWaiting())) {
            throw (new ModelNotFoundException)->setModel(QuickEntry::class, [$entryId]);
        }
        $int = fn () => ctype_digit((string) $arg) ? (int) $arg : throw new InvalidArgumentException('Unknown action.');

        return match ($verb) {
            'card' => $this->draftPaid($entry, 'business'),
            'personal' => $this->draftPaid($entry, 'personal_card'),
            'cash' => $this->draftPaid($entry, 'cash'),
            'add' => $this->draftPaid($entry, null),
            'edit', 'back' => '',
            'cat' => $this->draftSet($entry, ['category_id' => $int()], 'Category chosen'),
            'job' => $this->draftSet($entry, ['work_job_id' => $int() ?: null], 'Job chosen'),
            'day' => $this->draftSet($entry, ['spent_on' => self::dayArg((string) $arg)], 'Day chosen'),
            'claim_photo' => $this->claimPhoto($entry, $int()),
            'not_that_photo' => $this->dropPhoto($entry),
            'cancel' => $this->cancel($entry),
            'attach' => $this->attachByYou($entry, $int()),
            'none' => $this->answer($entry, 'none_of_these', 'all', 'None of these') ? 'Then how did you pay, or wasn’t it a business cost?' : '',
            'paid_cash', 'paid_personal' => $this->paidAnotherWay($entry, $verb === 'paid_cash' ? 'cash' : 'personal_card'),
            'aside' => $this->setAside($entry)->status === 'set_aside' ? 'Kept as not a business cost: '.$entry->words().'. Nothing is added.' : '',
            'same_copy' => $this->takeBack($entry, 'Same receipt: removed this copy') ? 'Removed this copy: '.$entry->words().'. Nothing else changed.' : '',
            'other_payment' => $this->answer($entry, 'other_payment', $int(), 'A different payment') ? 'Kept waiting for its own bank line.' : '',
            'to_card' => $this->toCard($entry, $arg !== null ? $int() : null),
            'not_card' => $this->twoPayments($entry, $arg),
            'same' => $this->sameReceipt($entry, (string) $arg),
            'not_same' => $this->notSame($entry, (string) $arg),
            'use_read', 'keep_mine' => app(QuickReadCheck::class)->answer($entry, $verb === 'use_read' ? 'use' : 'keep', $arg),
            'read_again' => $this->readAgain($entry),
            'use_receipt' => $this->useReceipt($entry),
            'keep_line' => $this->answer($entry, 'keep_line', (int) $entry->bank_transaction_id, 'Keep the line as it is') ? 'Kept the line as it is. It won’t ask again.' : '',
            'detach' => $this->attacher->detach($entry)->status === 'waiting' ? 'Taken off that payment: it waits for its own bank line.' : '',
            'add_held' => $this->addHeldWords($entry),
        };
    }

    /**
     * The day a Change… button means: the date it carries (day:20261124), so a button offered before midnight and
     * pressed after it still means the day it said; "today" and "yesterday" from older messages are read now.
     */
    private static function dayArg(string $arg): string
    {
        $now = CarbonImmutable::now('Europe/London')->startOfDay();
        if (preg_match('/^\d{8}$/', $arg)) {
            $on = CarbonImmutable::createFromFormat('!Ymd', $arg, 'Europe/London');
            if (! $on || $on->format('Ymd') !== $arg || $on->gt($now)) {
                throw new InvalidArgumentException('Unknown action.');
            }

            return $on->format('Y-m-d');
        }

        return match ($arg) {
            'today' => $now->format('Y-m-d'),
            'yesterday' => $now->subDay()->format('Y-m-d'),
            default => throw new InvalidArgumentException('Unknown action.'),
        };
    }

    private function draftPaid(QuickEntry $entry, ?string $paidWith): string
    {
        $out = $this->submitDraft($entry, $paidWith);
        $row = QuickEntry::query()->with(['job', 'category'])->findOrFail($entry->id);
        // What the phone would warn about (a card payment of the same amount, the same receipt added already).
        $warnings = implode('', array_map(fn (array $w) => "\n⚠ ".$w['message'], (array) ($out['body']['warnings'] ?? [])));

        return match ($row->status) {
            'added' => 'Added as a '.($row->paid_with === 'cash' ? 'cash' : 'personal card').' expense: '.$row->words().($row->job ? ', '.$row->job->title : '').$warnings,
            'waiting', 'attached' => 'Photo kept for the card payment. Nothing is added until the bank line comes in.'.$warnings,
            'held' => $row->hold_reason === 'locked_year' ? 'Not added: '.UkTaxYear::fromDate($row->spent_on)->label().' is locked. Unlock it in Expend first' : self::outcome($row),
            'duplicate' => self::outcome($row),
            default => (string) ($out['body']['entry']['message'] ?? self::outcome($row)),
        };
    }

    /** @param array<string, mixed> $changes */
    private function draftSet(QuickEntry $entry, array $changes, string $why): string
    {
        $this->correctFields($entry, $changes, $why);

        return '';
    }

    private function cancel(QuickEntry $entry): string
    {
        $row = $this->takeBack($entry, $entry->status === 'draft' ? 'Not an expense' : 'Removed by you');

        return $row->status === 'dismissed' ? 'Not added: it isn’t an expense.' : 'Removed: '.$entry->words().'.';
    }

    private function attachByYou(QuickEntry $entry, int $lineId): string
    {
        $line = BankTransaction::query()->findOrFail($lineId);

        return match ($this->attacher->attach($entry, $line, 'you')) {
            'attached' => 'On its bank line: '.CardLineFits::describe($line).'. Its category and job are offered there: confirm them in the Inbox.',
            'second_copy' => throw new InvalidArgumentException('That payment already has another receipt of yours.'),
            'locked' => throw new InvalidArgumentException('That payment is in a locked year: nothing changed.'),
            default => throw (new ModelNotFoundException)->setModel(QuickEntry::class, [$entry->id]),
        };
    }

    private function paidAnotherWay(QuickEntry $entry, string $paidWith): string
    {
        $row = $this->convertToExpense($entry, $paidWith);

        return $row->status === 'added' ? 'Added as a '.($paidWith === 'cash' ? 'cash' : 'personal card').' expense: '.$row->words().'.' : self::outcome($row);
    }

    private function toCard(QuickEntry $entry, ?int $lineId): string
    {
        $row = $this->convertToCard($entry, $lineId);
        $line = $lineId !== null ? BankTransaction::query()->find($lineId) : $row->bankTransaction;

        return $row->status === 'attached' && $line && (int) $row->bank_transaction_id === $line->id
            ? 'It was the card: the expense you added is gone and the receipt is on '.($line ? CardLineFits::describe($line) : 'the card line').'.'
            : 'It was the card: the expense you added is gone; the receipt waits for its card line.';
    }

    /** "Two payments": the card lines shown aren't this payment, never asked about again. */
    private function twoPayments(QuickEntry $entry, ?string $arg): string
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $arg))));
        if ($ids === []) {
            throw new InvalidArgumentException('Unknown action.');
        }
        $this->answer($entry, 'not_card', $ids, 'Two payments');
        if (ReceiptAttacher::cites() && Methods::has('quick.maybe_card')) {
            foreach ($ids as $id) {
                Vetoes::record('receipt_payment', $entry->ref(), 'tx:'.$id, null, 'quick.maybe_card', 'block', 'other', 'Two payments');
            }
        }

        return 'Two payments: both stay. It won’t ask about '.(count($ids) === 1 ? 'that card payment' : 'those card payments').' again.';
    }

    /**
     * "Same receipt": a draft (a photo sent in Telegram) becomes a copy of the other, keeping its photo; otherwise the
     * later of the two is removed (refused in a locked year).
     */
    private function sameReceipt(QuickEntry $entry, string $ref): string
    {
        if (! preg_match('/^(quick|exp):(\d+)$/', $ref, $m)) {
            throw new InvalidArgumentException('Unknown action.');
        }
        if ($entry->status === 'draft') {
            // A draft (a text or a photo in Telegram) that's a receipt added already: nothing is added; a photo is kept
            // with the first.
            $first = $m[1] === 'quick' ? QuickEntry::query()->findOrFail((int) $m[2]) : null;
            $hand = $m[1] === 'exp' ? ManualExpense::query()->findOrFail((int) $m[2]) : null;
            $words = $first?->words() ?? Money::format(abs((int) $hand?->amount_pence), 'GBP', false).' '.$hand?->description;
            $why = $entry->document_id ? 'Same receipt: kept this photo with it' : 'Same receipt: not added again';
            DB::transaction(function () use ($entry, $first, $why) {
                $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                if ($row->status !== 'draft') {
                    return;
                }
                $before = self::snapshot($row);
                $row->forceFill(['status' => 'duplicate', 'duplicate_of_id' => $first?->id])->save();
                AuditLog::record('quick_entry.changed', $row, $before, self::snapshot($row), $why);
            });
            TodoList::forget();

            return ($entry->document_id ? 'Kept this photo with ' : 'Not added again: it’s ').$words.'. Nothing else was added.';
        }
        if ($m[1] === 'exp') {
            $expense = ManualExpense::query()->find((int) $m[2]);
            if (! $expense) {
                throw (new ModelNotFoundException)->setModel(ManualExpense::class, [(int) $m[2]]);
            }
            $this->expenses->withdraw($expense, 'Same receipt: removed the later one');
            TodoList::forget();

            return 'Removed the later one: '.$expense->description.', '.Money::format(abs((int) $expense->amount_pence), 'GBP', false).'.';
        }
        $later = QuickEntry::query()->findOrFail((int) $m[2]);
        $this->takeBack($later, 'Same receipt: removed the later one');

        return 'Removed the later one: '.$later->words().'.';
    }

    private function notSame(QuickEntry $entry, string $ref): string
    {
        if (! preg_match('/^(quick|exp):(\d+)$/', $ref, $m)) {
            throw new InvalidArgumentException('Unknown action.');
        }
        $this->answer($entry, 'not_same', $ref, 'Two payments');
        if ($m[1] === 'quick' && ($other = QuickEntry::query()->find((int) $m[2]))) {
            $this->answer($other, 'not_same', $entry->ref(), 'Two payments');
        }

        return 'Two payments: both stay. It won’t ask again.';
    }

    private function readAgain(QuickEntry $entry): string
    {
        $doc = $entry->document_id ? Document::query()->find($entry->document_id) : null;
        if (! $doc) {
            throw new InvalidArgumentException('There’s no photo to read yet.');
        }
        if ($doc->status === 'queued') {
            return 'It’s waiting to be read.';
        }

        return self::queueRead($doc) ? 'Reading it again…' : throw new InvalidArgumentException('Reading with AI is switched off: switch it on in Settings first.');
    }

    private function useReceipt(QuickEntry $entry): string
    {
        $line = BankTransaction::query()->findOrFail((int) $entry->bank_transaction_id);
        $this->decideLine($entry, $line, true);

        return 'The line now says what the receipt says: '.($entry->category?->name ?? 'its category').($entry->job ? ', '.$entry->job->title : '').'.';
    }

    private function addHeldWords(QuickEntry $entry): string
    {
        $row = $this->addHeld($entry);

        return $row->status === 'added' ? 'Added: '.$row->words().'.' : self::outcome($row).'.';
    }

    /** A Telegram photo you sent before the amount: it goes with this draft. */
    private function claimPhoto(QuickEntry $entry, int $documentId): string
    {
        $doc = Document::query()->findOrFail($documentId);
        if (ReceiptMatch::owner($doc) !== null) {
            throw new InvalidArgumentException('That photo goes with another entry already.');
        }
        $this->setPhoto($entry, $doc->id, 'Added to the photo you sent');

        return 'Added to the photo you sent at '.$doc->created_at?->timezone('Europe/London')->format('H:i').'.';
    }

    private function dropPhoto(QuickEntry $entry): string
    {
        $this->setPhoto($entry, null, 'Not that photo');

        return 'Took the photo off it.';
    }

    private function setPhoto(QuickEntry $entry, ?int $documentId, string $why): void
    {
        DB::transaction(function () use ($entry, $documentId, $why) {
            $row = QuickEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $before = self::snapshot($row);
            $row->forceFill(['document_id' => $documentId, 'photo_status' => $documentId ? 'stored' : null])->save();
            AuditLog::record('quick_entry.changed', $row, $before, self::snapshot($row), $why);
        });
    }

    // ---- what it says ----------------------------------------------------------------------------------------------

    /**
     * The entry as the phone shows it after a send: its state, words and what happened.
     *
     * @return array<string, mixed>
     */
    public static function summary(QuickEntry $e): array
    {
        return [
            'id' => $e->id, 'client_key' => $e->client_key, 'state' => $e->status, 'hold_reason' => $e->hold_reason, 'words' => $e->words(),
            'photo_wanted' => $e->photo_status === 'expected' && in_array($e->status, ['added', 'waiting', 'attached', 'held', 'withdrawn', 'set_aside'], true),
            'message' => self::outcome($e), 'href' => '/quick/entries/'.$e->id,
        ];
    }

    /** What happened, in a few words ("Added as a cash expense"). */
    public static function outcome(QuickEntry $e): string
    {
        $label = $e->spent_on ? UkTaxYear::fromDate($e->spent_on)->label() : null;

        return match ($e->status) {
            'added' => 'Added as a '.($e->paid_with === 'cash' ? 'cash' : 'personal card').' expense',
            'waiting' => 'Photo kept for the card payment. Nothing is added until the bank line comes in.',
            'attached' => 'On its bank line: '.($e->bankTransaction ? Money::format(abs((int) $e->bankTransaction->amount_pence), 'GBP', false).', '.$e->bankTransaction->effectiveDate()->format('j M') : 'matched'),
            'held' => match ($e->hold_reason) {
                'locked_year' => "Kept, not added: {$label} is locked",
                'needs_rate' => 'Kept, not added: enter the rate or the sterling amount',
                'same_photo' => 'Kept, not added: the same photo as another entry',
                default => 'Kept, not added: details missing',
            },
            'duplicate' => 'Not added: the same photo as '.($e->duplicateOf ? $e->duplicateOf->words() : 'one you added')
                .($e->duplicateOf?->created_at ? ', added on '.$e->duplicateOf->created_at->timezone('Europe/London')->format('j M \a\t H:i') : ''),
            'set_aside' => 'Kept as not a business cost',
            'withdrawn' => 'Removed',
            'dismissed' => 'Not an expense',
            'draft' => 'Not added yet',
            default => 'Arriving',
        };
    }

    /**
     * Warnings for the reply: a card payment of the same amount, or the same receipt added already.
     *
     * @return list<array{code:string, message:string}>
     */
    public static function warnings(QuickEntry $entry): array
    {
        try {
            return app(QuickEntryChecks::class)->forEntry($entry);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** What the expense is called: what you typed, or the category. */
    private static function description(QuickEntry $e): string
    {
        $what = trim((string) $e->what);
        if ($what !== '') {
            return $what;
        }

        return (string) (Category::query()->whereKey($e->category_id)->value('name') ?? 'Expense from your phone');
    }

    private static function knownCurrency(string $code): bool
    {
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            return false;
        }
        try {
            Currency::of($code);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> what the history keeps of an entry */
    public static function snapshot(QuickEntry $e): array
    {
        return $e->only(['status', 'hold_reason', 'paid_with', 'account_id', 'amount_minor', 'currency', 'sterling_pence', 'what', 'what_source', 'category_id',
            'work_job_id', 'job_source', 'business_pct', 'document_id', 'manual_expense_id', 'bank_transaction_id', 'attached_by', 'duplicate_of_id', 'channel'])
            + ['spent_on' => $e->spent_on?->format('Y-m-d')];
    }

    private static function channelWords(QuickEntry $e): string
    {
        return match ($e->channel) {
            'telegram' => 'Sent in Telegram',
            'share' => 'Shared to Expend on your phone',
            default => 'Added on your phone',
        };
    }

    /** The sha256 of the fields as sent, when the phone didn't give one. */
    public static function payloadSha(array $f): string
    {
        $keep = array_intersect_key($f, array_flip(['client_key', 'paid_with', 'account_id', 'spent_on', 'spent_at', 'amount_minor', 'currency', 'sterling_pence', 'what',
            'category_id', 'work_job_id', 'business_pct', 'note']));
        $keep['photo'] = is_array($f['photo'] ?? null) ? ($f['photo']['sha256'] ?? null) : null;
        ksort($keep);

        return hash('sha256', (string) json_encode($keep));
    }

    /** Who acts on the phone's behalf in the audit trail: you, from the web or Telegram. */
    public static function asYou(callable $work, string $channel = 'web'): mixed
    {
        return AuditContext::with('you', $work, $channel);
    }
}
