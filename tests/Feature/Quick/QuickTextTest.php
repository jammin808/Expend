<?php

namespace Tests\Feature\Quick;

use App\Domain\Quick\QuickText;
use App\Models\Client;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cost in a few words, read by fixed rules: every case in tests/fixtures/quick-text.json (which the phone's
 * parseText is checked against too), and the job a word names near the day.
 */
class QuickTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_shared_case_reads_as_the_fixture_says(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/quick-text.json')), true);
        $this->assertNotEmpty($fixture['cases']);
        foreach ($fixture['cases'] as $case) {
            $accounts = [];
            foreach ($case['accounts'] ?? [] as $a) {
                $accounts[mb_strtolower($a['name'])] = $a['id'];
            }
            $got = QuickText::parse($case['text'], CarbonImmutable::parse($case['today']), $accounts);
            $this->assertSame($case['expect'], $got, 'Reading “'.$case['text'].'”');
        }
    }

    public function test_a_word_naming_a_job_near_the_day_picks_it_and_otherwise_the_job_on_that_day(): void
    {
        $leeds = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked', 'location' => 'First Direct Arena']);
        $client = Client::query()->create(['name' => 'Acme Events']);
        WorkJob::query()->create(['title' => 'Conference', 'starts_on' => '2026-11-26', 'ends_on' => '2026-11-26', 'status' => 'booked', 'client_id' => $client->id]);
        $day = CarbonImmutable::parse('2026-11-25');

        $this->assertSame([$leeds->id, 'words'], [QuickText::jobFor('parking Leeds', $day)['job']->id, QuickText::jobFor('parking Leeds', $day)['source']]);
        $this->assertSame('Conference', QuickText::jobFor('taxi Acme', $day)['job']->title, 'A client’s name picks its job');
        $this->assertSame([$leeds->id, 'today'], [QuickText::jobFor('parking', $day)['job']->id, QuickText::jobFor('parking', $day)['source']], 'The one job on the day');
        $this->assertNull(QuickText::jobFor('parking', CarbonImmutable::parse('2026-12-20')));
    }
}
