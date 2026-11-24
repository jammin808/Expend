<?php

namespace App\Domain\Gmail;

use App\Domain\Audit\AuditLog;
use App\Domain\Jobs\PoStatus;
use App\Models\Document;
use App\Models\GmailLabel;
use App\Models\GmailMessage;
use App\Models\Setting;
use App\Models\WorkJob;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Puts a green "✓ Invoiced" label on the PO email in Gmail once the job is invoiced (issued or paid), and
 * takes it off again if the invoice is voided. It only ever adds or removes this one label. It follows the invoice's
 * status on its own, so its changes are recorded as the system's, never as yours.
 *
 * The PO email is found, most certain first:
 *   1. the email itself came from a Gmail search;
 *   2. a forwarded or uploaded email: its original in Gmail, by its unique Message-ID;
 *   3. the PO number: a Gmail search, used only when every result is in one conversation, so an unrelated
 *      email that happens to mention the number is never labelled.
 */
final class GmailLabeller
{
    public const LABEL = '✓ Invoiced';

    public static function enabled(): bool
    {
        return GmailClient::canModify() && Setting::get('gmail_label_invoiced') !== false;
    }

    /**
     * Make Gmail match the app: PO emails of invoiced jobs have the label, others don't.
     *
     * @return array{added:int, removed:int, missing:list<string>}
     */
    public function sync(?int $jobId = null): array
    {
        if (! self::enabled()) {
            return ['added' => 0, 'removed' => 0, 'missing' => []];
        }
        $jobs = WorkJob::query()->when($jobId, fn ($q) => $q->whereKey($jobId))->whereHas('invoices', fn ($q) => $q->whereIn('status', ['issued', 'paid']))->get();
        $status = PoStatus::forJobs($jobs);
        $labelId = $this->labelId();
        $added = $removed = 0;
        $missing = [];

        foreach ($jobs as $job) {
            if (GmailLabel::query()->where('work_job_id', $job->id)->exists()) {
                continue; // already labelled (and if you took the label off in Gmail yourself, it stays off)
            }
            $found = $this->find($job, $status[$job->id]['po']['number'] ?? null);
            if (! $found['ids']) {
                $missing[] = $job->title;

                continue;
            }
            $labelId = $this->modify($found['ids'], 'addLabelIds', $labelId);
            foreach ($found['ids'] as $id) {
                GmailLabel::query()->firstOrCreate(['message_id' => $id], [
                    'work_job_id' => $job->id, 'invoice_id' => $status[$job->id]['invoice']['id'] ?? null,
                    'document_id' => $found['document_id'], 'found_by' => $found['how'],
                ]);
            }
            $added += count($found['ids']);
            AuditLog::record('gmail.labelled', $job, null, ['label' => self::LABEL, 'messages' => count($found['ids']), 'found_by' => $found['how']], null, 'system');
        }

        // Take the label off where the job is no longer invoiced (its invoice was voided).
        $stale = GmailLabel::query()->when($jobId, fn ($q) => $q->where('work_job_id', $jobId))
            ->where(fn ($q) => $q->whereNull('work_job_id')->orWhereDoesntHave('job', fn ($j) => $j->whereHas('invoices', fn ($i) => $i->whereIn('status', ['issued', 'paid']))))
            ->get();
        if ($stale->isNotEmpty()) {
            $this->modify($stale->pluck('message_id')->all(), 'removeLabelIds', $labelId);
            $removed = $stale->count();
            GmailLabel::query()->whereKey($stale->pluck('id'))->delete();
            AuditLog::record('gmail.unlabelled', 'Settings', null, ['label' => self::LABEL, 'messages' => $removed], null, 'system');
        }

        if (! $jobId) {
            Setting::put('gmail_label_status', ['at' => now()->toIso8601String(), 'labelled_jobs' => GmailLabel::query()->distinct()->count('work_job_id'), 'missing' => array_slice($missing, 0, 20)]);
        }

        return ['added' => $added, 'removed' => $removed, 'missing' => $missing];
    }

    /** @return array{ids: list<string>, how: ?string, document_id: ?int} */
    private function find(WorkJob $job, ?string $poNumber): array
    {
        $emails = Document::query()->where('work_job_id', $job->id)->get()
            ->filter(fn (Document $d) => PoStatus::isPo($d))
            ->map(fn (Document $d) => $d->parent_id ? Document::query()->find($d->parent_id) : $d)
            ->filter()->unique('id');

        foreach ($emails as $email) {
            $ids = GmailMessage::query()->where('document_id', $email->id)->pluck('message_id')->all();
            if ($ids) {
                return ['ids' => $ids, 'how' => 'gmail_search', 'document_id' => $email->id];
            }
            if ($messageId = self::messageIdOf($email)) {
                $ids = GmailClient::search('rfc822msgid:'.$messageId, null, 10)['ids'];
                if ($ids) {
                    return ['ids' => $ids, 'how' => 'message_id', 'document_id' => $email->id];
                }
            }
        }

        if ($poNumber && mb_strlen(trim($poNumber)) >= 4) {
            $ids = GmailClient::search('"'.str_replace('"', '', trim($poNumber)).'" -in:sent -in:drafts -in:chats', null, 20)['ids'];
            $threads = collect($ids)->map(fn ($id) => GmailClient::get('messages/'.rawurlencode($id), ['format' => 'minimal'])['threadId'] ?? $id)->unique();
            if ($ids && $threads->count() === 1) {
                return ['ids' => $ids, 'how' => 'po_number', 'document_id' => $emails->first()?->id];
            }
        }

        return ['ids' => [], 'how' => null, 'document_id' => null];
    }

    private static function messageIdOf(Document $email): ?string
    {
        if ($email->kind !== 'email' && $email->mime !== 'message/rfc822') {
            return null;
        }
        try {
            $raw = (string) Storage::disk('local')->get($email->stored_path);
        } catch (Throwable) {
            return null;
        }
        $head = preg_replace('/\r?\n[ \t]+/', ' ', strstr($raw, "\r\n\r\n", true) ?: (strstr($raw, "\n\n", true) ?: ''));

        return preg_match('/^Message-ID:\s*<([^>\s]+)>/mi', $head, $m) ? $m[1] : null;
    }

    /** Add or remove the label; if it was deleted in Gmail, make it again once and retry. */
    private function modify(array $ids, string $field, string $labelId): string
    {
        try {
            GmailClient::post('messages/batchModify', ['ids' => $ids, $field => [$labelId]]);
        } catch (\RuntimeException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'label')) {
                throw $e;
            }
            Setting::put('gmail_label_id', null);
            $labelId = $this->labelId();
            GmailClient::post('messages/batchModify', ['ids' => $ids, $field => [$labelId]]);
        }

        return $labelId;
    }

    /** The label's id, creating it (green, with a tick) the first time. */
    private function labelId(): string
    {
        if ($id = Setting::get('gmail_label_id')) {
            return $id;
        }
        $existing = collect(GmailClient::get('labels')['labels'] ?? [])->firstWhere('name', self::LABEL);
        $id = $existing['id'] ?? GmailClient::post('labels', [
            'name' => self::LABEL, 'labelListVisibility' => 'labelShow', 'messageListVisibility' => 'show',
            'color' => ['backgroundColor' => '#16a766', 'textColor' => '#ffffff'],
        ])['id'];
        Setting::put('gmail_label_id', $id);

        return $id;
    }
}
