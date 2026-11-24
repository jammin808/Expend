<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Why an automatic link that isn't an allocation was made (docs/intelligence-cycle.md 3.2): one row per link and
 * subject, written with the link. Only metadata: it counts only while object_ref equals the link as it is now
 * (see current()), so it can never become a second record of the link.
 *
 * @property string $link calendar_job | document_job | travel_job | travel_payment | invoice_job | receipt_payment
 * @property string $subject_ref the linked thing: cal:<event key>, doc:12, travel:4, inv:9
 * @property string|null $object_ref what it was linked to (job:14, tx:381), or null for an automatic unlink
 */
class LinkProvenance extends Model
{
    public const LINKS = ['calendar_job', 'document_job', 'travel_job', 'travel_payment', 'invoice_job', 'receipt_payment'];

    protected $table = 'link_provenance';

    protected $guarded = ['id'];

    protected $casts = ['method_version' => 'integer', 'score' => 'integer', 'evidence' => 'array', 'alternatives' => 'array', 'run_id' => 'integer'];

    /**
     * The row for this link and subject, only if it still describes the link as it is now: $native is the ref of what
     * the subject is linked to now (job:14), or null when it's linked to nothing.
     */
    public static function current(string $link, string $subjectRef, ?string $native): ?self
    {
        $row = self::query()->where('link', $link)->where('subject_ref', $subjectRef)->first();

        return $row && $row->object_ref === $native ? $row : null;
    }
}
