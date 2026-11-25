<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Unlocking a year and undoing, accepting, discarding or restoring an import each ask for a reason in a prompt, and
 * the import page takes a statement file. A refusal shows as a toast with the server's own words, so they're plain.
 */
class LockAndImportReasonMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
        $this->bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
    }

    /** router.post(url, { reason }) as the pages send it: JSON, from Inertia. */
    private function sendJson(string $from, string $url, array $body): TestResponse
    {
        return $this->from($from)->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    /** The upload as the import page sends it: { file, account_id } as form data, from Inertia. */
    private function upload(UploadedFile $file): TestResponse
    {
        return $this->from('/imports')->post('/imports/preview', ['file' => $file, 'account_id' => $this->bank->id], [
            'X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml',
        ]);
    }

    public function test_unlocking_with_too_short_or_too_long_a_reason_says_so_plainly(): void
    {
        TaxYear::track('2024-25', 'filed');
        $this->post('/years/2024-25/lock')->assertSessionHas('success');

        $this->sendJson('/years/2024-25', '/years/2024-25/lock', ['reason' => 'ok'])->assertRedirect('/years/2024-25')
            ->assertSessionHasErrors(['reason' => 'Say why you are unlocking this year in a few more words (3 characters at least).']);
        $this->sendJson('/years/2024-25', '/years/2024-25/lock', ['reason' => str_repeat('a', 251)])
            ->assertSessionHasErrors(['reason' => 'The reason is too long (250 characters at most).']);
        $this->assertTrue(TaxYear::query()->where('label', '2024-25')->first()->isLocked());

        $this->sendJson('/years/2024-25', '/years/2024-25/lock', ['reason' => 'Amending the return'])->assertSessionHas('success', '2024-25 unlocked.');
    }

    public function test_an_import_action_with_too_short_a_reason_says_so_plainly(): void
    {
        $batch = ImportBatch::query()->create(['account_id' => $this->bank->id, 'original_filename' => 'statement.csv', 'file_sha256' => str_repeat('a', 64),
            'stored_path' => 'x', 'status' => 'quarantined']);

        foreach (['revert', 'accept', 'discard', 'restore'] as $action) {
            $this->sendJson('/imports', "/imports/{$batch->id}/{$action}", ['reason' => 'no'])->assertRedirect('/imports')
                ->assertSessionHasErrors(['reason' => 'Say why in a few more words (3 characters at least). It’s kept in the audit log.']);
            // A prompt answered with spaces only reaches the server empty.
            $this->sendJson('/imports', "/imports/{$batch->id}/{$action}", ['reason' => '   '])
                ->assertSessionHasErrors(['reason' => 'Say why, in a few words. It’s kept in the audit log.']);
            $this->sendJson('/imports', "/imports/{$batch->id}/{$action}", ['reason' => str_repeat('a', 251)])
                ->assertSessionHasErrors(['reason' => 'The reason is too long (250 characters at most).']);
        }
        $this->assertSame('quarantined', $batch->fresh()->status);
    }

    public function test_a_file_that_isnt_a_statement_or_is_too_big_is_refused_plainly(): void
    {
        $this->upload(UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf'))->assertRedirect('/imports')
            ->assertSessionHasErrors(['file' => 'Expend can’t read that kind of file. Choose a statement saved as CSV or Excel (.xlsx).']);
        $this->upload(UploadedFile::fake()->create('statement.csv', 20481, 'text/csv'))
            ->assertSessionHasErrors(['file' => 'That file is too big (20 MB at most).']);

        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "Date,Amount\n");
        $this->upload(new UploadedFile($path, 'statement.csv', 'text/csv', UPLOAD_ERR_INI_SIZE, true))
            ->assertSessionHasErrors(['file' => 'That file didn’t upload. Try again, or choose a smaller file.']);

        $this->assertNull(session('import'));
    }
}
