<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings that remember (M3 of docs/intelligence-cycle.md): what each finding is about in the shared vocabulary
 * (its code, the evidence it rests on, the money's direction, its grade and severity, your reason for hiding it),
 * and an append-only history of every change to it (finding_events).
 *
 * Additive only (section 3.1): nullable columns with no default, appended without ->after() so MariaDB adds them
 * instantly; text, never json(), on the existing table; the indexes in their own statements. Nothing is filled in
 * here: `php artisan ledger:intel-backfill findings` does that, in chunks, on the hourly schedule.
 *
 * MariaDB adds each column, index and foreign key in a statement of its own, and none of them can be undone if a
 * later one fails. So each is checked on its own: a run stopped part way is finished by running it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'code' => fn (Blueprint $t) => $t->string('code', 40)->nullable(),
            'detector_version' => fn (Blueprint $t) => $t->unsignedSmallInteger('detector_version')->nullable(),
            'subject_ref' => fn (Blueprint $t) => $t->string('subject_ref', 191)->nullable(),
            'scope_ref' => fn (Blueprint $t) => $t->string('scope_ref', 191)->nullable(),
            'evidence' => fn (Blueprint $t) => $t->text('evidence')->nullable(),
            'direction' => fn (Blueprint $t) => $t->string('direction', 12)->nullable(),
            'grade' => fn (Blueprint $t) => $t->string('grade', 10)->nullable(),
            'severity' => fn (Blueprint $t) => $t->string('severity', 10)->nullable(),
            'due_on' => fn (Blueprint $t) => $t->date('due_on')->nullable(),
            'state' => fn (Blueprint $t) => $t->string('state', 10)->nullable(),
            'note' => fn (Blueprint $t) => $t->string('note', 255)->nullable(),
            'resolution_ref' => fn (Blueprint $t) => $t->string('resolution_ref', 191)->nullable(),
            'last_run_id' => fn (Blueprint $t) => $t->unsignedBigInteger('last_run_id')->nullable(),
        ];
        $missing = array_filter($columns, fn (string $column) => ! Schema::hasColumn('findings', $column), ARRAY_FILTER_USE_KEY);
        if ($missing !== []) {
            Schema::table('findings', function (Blueprint $table) use ($missing) {
                foreach ($missing as $add) {
                    $add($table);
                }
            });
        }
        foreach (['code', 'subject_ref', 'scope_ref'] as $column) {
            if (! Schema::hasIndex('findings', 'findings_'.$column.'_index')) {
                Schema::table('findings', fn (Blueprint $table) => $table->index($column));
            }
        }

        if (! Schema::hasTable('finding_events')) {
            Schema::create('finding_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('finding_id')->constrained('findings')->restrictOnDelete();
                $table->string('event', 16);
                $table->bigInteger('amount_pence')->nullable();
                $table->char('currency', 3)->nullable();
                $table->string('cause_ref', 191)->nullable();
                $table->unsignedBigInteger('run_id')->nullable();
                $table->string('actor', 10);
                $table->string('note', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['finding_id', 'id']);
            });
        }
        // On MariaDB the foreign key and the index are statements of their own after the table's.
        if (! Schema::hasIndex('finding_events', 'finding_events_finding_id_id_index')) {
            Schema::table('finding_events', fn (Blueprint $table) => $table->index(['finding_id', 'id']));
        }
        if (! collect(Schema::getForeignKeys('finding_events'))->contains(fn (array $fk) => $fk['columns'] === ['finding_id'])) {
            Schema::table('finding_events', fn (Blueprint $table) => $table->foreign('finding_id')->references('id')->on('findings')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finding_events');
        foreach (['code', 'subject_ref', 'scope_ref'] as $column) {
            if (Schema::hasIndex('findings', 'findings_'.$column.'_index')) {
                Schema::table('findings', fn (Blueprint $table) => $table->dropIndex([$column]));
            }
        }
        $columns = array_values(array_filter(['code', 'detector_version', 'subject_ref', 'scope_ref', 'evidence', 'direction', 'grade', 'severity', 'due_on', 'state', 'note',
            'resolution_ref', 'last_run_id'], fn (string $column) => Schema::hasColumn('findings', $column)));
        if ($columns !== []) {
            Schema::table('findings', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
