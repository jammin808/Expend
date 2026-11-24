<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only. Rows are never updated or deleted; each row's hash covers the previous row's hash (AuditLog::hashFor).
 * channel, correlation and run_id say where the event came from (AuditContext), once recording them is turned on
 * (AuditLog::recordsContext()); when any is set, the hash covers them.
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }
}
