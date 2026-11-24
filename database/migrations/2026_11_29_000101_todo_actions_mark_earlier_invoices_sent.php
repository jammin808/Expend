<?php

use App\Domain\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Invoices issued before Expend could email them were sent some other way (the PDF, by hand), but the migration that
 * added emailing (2026_11_27_000001) left them all "not emailed yet". So each one showed up on the to-do list as
 * "Send invoice" with an "Email it" button, and one tap sent the client a second copy.
 *
 * Record them as sent when they were issued (sent_at = issued_at), with no address (sent_to stays empty: it wasn't
 * the app that sent them). Payments, reminders and nudges are left exactly as they are.
 *
 * "Before Expend could email them" means issued before the emailing feature existed at all (the commit that added
 * it, 89a8c34). Anything issued later may be waiting for you to email it from the app, so it's left alone.
 */
return new class extends Migration
{
    /** When invoice emailing was added (89a8c34, 23 Nov 2026 22:08:19 UTC): nothing issued before this was emailed by the app. */
    private const EMAILING_ADDED_UTC = '2026-11-23 22:08:19';

    public function up(): void
    {
        // Timestamps are stored in the app's time zone.
        $before = CarbonImmutable::parse(self::EMAILING_ADDED_UTC, 'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $earlier = DB::table('invoices')->whereIn('status', ['issued', 'paid'])->whereNull('sent_at')->whereNotNull('issued_at')->where('issued_at', '<', $before);
        $numbers = (clone $earlier)->orderBy('id')->pluck('number')->all();
        if ($numbers === []) {
            return;
        }
        $earlier->update(['sent_at' => DB::raw('issued_at'), 'sent_to' => null]);
        AuditLog::record('invoice.marked_sent', 'Invoice', null, ['numbers' => $numbers, 'sent_at' => 'issued_at'],
            'Issued before Expend could email invoices, so sent another way', 'system');
    }

    public function down(): void
    {
        // Nothing to undo safely: by then some of these may have been emailed from the app as well.
    }
};
