<?php

namespace Tests\Feature\Intel;

use App\Domain\Ai\AssistantTools;
use App\Domain\Todo\FindingsRegistry;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\Finding;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The assistant's findings and evidence_for tools (section 7.7): what's open for a year, job or account, and what a ref
 * points at, as labels and check results. Never a document's or an email's text.
 */
class AssistantFindingsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'PRIVATE BODY TEXT 7731';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** A finished job with a PO whose text (and an email around it) the tools must never return. */
    private function job(): array
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'status' => 'done']);
        $email = Document::query()->create(['source' => 'email', 'original_name' => 'email.eml', 'sha256' => hash('sha256', 'e'), 'stored_path' => 'x', 'kind' => 'email', 'status' => 'extracted',
            'email_subject' => 'PO for the Arena', 'email_from' => 'alex@acme.example', 'text' => self::SECRET, 'work_job_id' => $job->id]);
        $po = Document::query()->create(['source' => 'email', 'parent_id' => $email->id, 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po'), 'stored_path' => 'x', 'kind' => 'po',
            'status' => 'extracted', 'work_job_id' => $job->id, 'job_linked_by' => 'you', 'text' => self::SECRET.' Total £1,200.00', 'summary' => self::SECRET,
            'extraction' => ['po' => ['stated_total_minor' => 120000, 'currency' => 'GBP', 'total_verified' => true, 'lines' => [['description' => self::SECRET, 'amount_minor' => 120000, 'quote' => self::SECRET]]]]]);
        DocumentFact::query()->create(['document_id' => $po->id, 'work_job_id' => $job->id, 'field' => 'notes', 'label' => 'Notes', 'value' => self::SECRET, 'quote' => self::SECRET, 'verified' => true]);
        $hotel = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2026-06-02', 'ends_on' => '2026-06-03', 'amount_minor' => 18000,
            'source' => 'manual', 'evidence' => ['quote' => self::SECRET], 'note' => self::SECRET, 'work_job_id' => $job->id, 'decision' => 'recharge', 'decided_at' => now()]);

        return [$job, $po, $hotel];
    }

    public function test_findings_for_a_job_return_findings_refs_and_labels_but_no_document_text(): void
    {
        [$job, $po, $hotel] = $this->job();
        FindingsRegistry::sync();
        $tools = app(AssistantTools::class);
        $out = $tools->run('findings', ['job_id' => $job->id]);

        $this->assertSame('job:'.$job->id, $out['scope']['ref']);
        $closeout = collect($out['findings'])->firstWhere('code', 'job.closeout');
        $this->assertSame('Arena load-in: £1,380.00 not invoiced', $closeout['title']);
        $this->assertSame(['pence' => 138000, 'formatted' => '£1,380.00', 'currency' => 'GBP'], $closeout['money']);
        $this->assertSame(['owed_to_you', 'strong', 'open'], [$closeout['direction'], $closeout['grade'], $closeout['state']]);
        $this->assertSame('fnd:'.Finding::query()->where('key', 'closeout:'.$job->id)->value('id'), $closeout['ref']);
        $this->assertSame(['doc:'.$po->id.'#f=po_total@'.substr($po->sha256, 0, 8), 'travel:'.$hotel->id], array_values(array_intersect(array_column($closeout['evidence'], 'ref'),
            ['doc:'.$po->id.'#f=po_total@'.substr($po->sha256, 0, 8), 'travel:'.$hotel->id])));
        $this->assertArrayNotHasKey('detail', $closeout, 'A finding’s detail can quote an email: never sent');

        // What one ref points at: its label and check, and for a finding, its evidence.
        $doc = $tools->run('evidence_for', ['ref' => 'doc:'.$po->id.'#f=po_total@'.substr($po->sha256, 0, 8)]);
        $this->assertSame([true, 'as it was'], [$doc['exists'], $doc['state']]);
        $this->assertSame('closeout:'.$job->id, Finding::query()->find((int) substr($doc['open_findings'][0]['ref'], 4))->key);
        $finding = $tools->run('evidence_for', ['ref' => $closeout['ref']]);
        $this->assertSame('job.closeout', $finding['finding']['code']);
        $this->assertContains('travel:'.$hotel->id, array_column($finding['finding']['evidence'], 'ref'));
        $this->assertSame(['error' => 'Not a ref Expend knows. Refs look like tx:381, inv:12, doc:55 or fnd:7.'], $tools->run('evidence_for', ['ref' => 'nonsense']));

        // None of it carries the document's text, its facts' quotes or the booking's notes.
        foreach ([$out, $doc, $finding, $tools->run('findings', []), $tools->run('findings', ['state' => 'all', 'tax_year' => '2026-27'])] as $answer) {
            $this->assertStringNotContainsString(self::SECRET, json_encode($answer, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_the_tools_are_offered_and_their_results_are_others_words(): void
    {
        $names = array_column(AssistantTools::definitions(), 'name');
        $this->assertContains('findings', $names);
        $this->assertContains('evidence_for', $names);
        // Labels can be an email's subject: what they return is never an instruction (no job is added on it).
        $this->assertContains('findings', AssistantTools::UNTRUSTED);
        $this->assertContains('evidence_for', AssistantTools::UNTRUSTED);
    }

    public function test_a_year_s_findings_include_its_jobs_and_payments_and_a_document_is_found_by_its_plain_ref(): void
    {
        [$job, $po] = $this->job();
        FindingsRegistry::sync();
        $tools = app(AssistantTools::class);
        $closeout = Finding::query()->where('key', 'closeout:'.$job->id)->sole();
        $this->assertSame('job:'.$job->id, $closeout->scope_ref, 'Kept by its job, not the year');

        $year = $tools->run('findings', ['tax_year' => '2026-27']);
        $this->assertSame('year:2026-27', $year['scope']['ref']);
        $this->assertContains('fnd:'.$closeout->id, array_column($year['findings'], 'ref'), 'A job of the year is in the year');
        $this->assertSame(0, $tools->run('findings', ['tax_year' => '2025-26'])['count'], 'Nothing from another year');

        // A document by its plain ref finds the findings that cite a part of it.
        $plain = $tools->run('evidence_for', ['ref' => 'doc:'.$po->id]);
        $this->assertSame(['fnd:'.$closeout->id], array_column($plain['open_findings'], 'ref'));
        $this->assertStringNotContainsString(self::SECRET, json_encode([$year, $plain], JSON_UNESCAPED_UNICODE));
    }
}
