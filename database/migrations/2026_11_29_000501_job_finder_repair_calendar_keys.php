<?php

use App\Domain\Calendar\CalendarSync;
use App\Models\CalendarEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each calendar entry keeps its key in a column of its own, so an entry can be found by its key wherever it has
 * moved to. Links and possible jobs whose key no longer matches its entry (the upgrade to keys that survive a move
 * got some wrong: moved and single occurrences of repeating entries, and possible jobs you had edited) are matched
 * to their entry again. The calendar sync does the same after each reload.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('calendar_events', 'event_key')) {
            Schema::table('calendar_events', function (Blueprint $table) {
                $table->char('event_key', 40)->nullable()->after('recurrence_id');
                $table->index('event_key');
            });
        }
        DB::table('calendar_events')->whereNull('event_key')->select(['id', 'uid', 'recurrence_id'])->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('calendar_events')->where('id', $row->id)->update(['event_key' => CalendarEvent::keyFor($row->uid, $row->recurrence_id)]);
                }
            });

        CalendarSync::repairKeys(allCandidates: true);
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropIndex(['event_key']);
            $table->dropColumn('event_key');
        });
    }
};
