<?php

namespace Tests\Feature\Invoices;

use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\JobCloseout;
use App\Domain\Intel\FindingDraft;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Golden pin (build slice S0, the client-area design): JobCloseout v2's verdicts today (pasted from running HEAD
 * 2d54ad6): what it says for each finished job, from its PO, its invoices (made from a PO or typed), the income recorded
 * for it and the bookings decided as recharges, and the to-do items it says instead of the list (subsumed()).
 *
 * The first test's jobs are what the design calls covered: invoiced in Expend before the release, or billed by income
 * recorded with no invoice issued. S6 keeps exactly these verdicts for them (v3 asks about their costs rather than
 * adding them). The second test's jobs (no invoice yet, or only a draft) are today's output too; S6 replaces those with
 * v3's for jobs that aren't covered, and says so where it changes them.
 */
class GoldenJobCloseoutTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function job(string $title, string $on, ?string $to = null): WorkJob
    {
        $client = Client::query()->firstOrCreate(['name' => 'Acme Events']);

        return WorkJob::query()->create(['title' => $title, 'client_id' => $client->id, 'starts_on' => $on, 'ends_on' => $to ?? $on, 'status' => 'booked', 'po_number' => 'PO-'.$title]);
    }

    /** A PO read from its document. */
    private function po(WorkJob $job, int $total, bool $checked = true, string $linkedBy = 'you', string $currency = 'GBP'): Document
    {
        return Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po '.$job->title), 'stored_path' => 'x', 'kind' => 'po',
            'status' => 'extracted', 'work_job_id' => $job->id, 'job_linked_by' => $linkedBy, 'email_subject' => 'PO for '.$job->title,
            'extraction' => ['po' => ['po_number' => 'PO-'.$job->title, 'currency' => $currency, 'stated_total_minor' => $total, 'total_verified' => $checked, 'bill_to_name' => 'Acme Events',
                'bill_to_address' => null, 'bill_to_email' => null, 'lines' => [['description' => 'Crew', 'quantity' => 1, 'unit_price_minor' => $total, 'amount_minor' => $total, 'quote' => 'Crew']]]]]);
    }

    /**
     * An invoice as an existing ledger holds it (issued, or a draft), with its lines.
     *
     * @param  array<string, int>  $lines  description => amount
     */
    private function invoice(WorkJob $job, ?string $number, array $lines, ?Document $po = null, ?int $poTotal = null, string $currency = 'GBP', ?string $issued = '2026-06-05'): Invoice
    {
        // Its lines are written while it's a draft (lines of an issued invoice can't change), then it's issued.
        $invoice = Invoice::query()->create(['status' => 'draft', 'number' => $number, 'client_id' => $job->client_id, 'work_job_id' => $job->id,
            'document_id' => $po?->id, 'po_number' => $po ? 'PO-'.$job->title : null, 'po_total_minor' => $poTotal ?? ($po ? $po->extraction['po']['stated_total_minor'] : null),
            'currency' => $currency, 'total_minor' => array_sum($lines), 'bill_to_name' => 'Acme Events', 'issue_date' => $issued,
            'due_date' => $issued ? CarbonImmutable::parse($issued)->addDays(30)->format('Y-m-d') : null, 'issued_at' => $issued ? $issued.' 09:00:00' : null]);
        $sort = 0;
        foreach ($lines as $description => $amount) {
            $invoice->allLines()->create(['description' => $description, 'quantity' => 1, 'unit_price_minor' => $amount, 'amount_minor' => $amount, 'sort' => $sort++]);
        }
        if ($issued) {
            $invoice->update(['status' => 'issued']);
        }

        return $invoice;
    }

    private function recharge(WorkJob $job, ?int $amount, string $provider, string $on, string $kind = 'hotel', string $currency = 'GBP', string $status = 'booked'): TravelItem
    {
        return TravelItem::query()->create(['kind' => $kind, 'provider' => $provider, 'place' => $kind === 'hotel' ? $provider.' '.explode(' ', $job->title)[0] : null, 'status' => $status,
            'starts_on' => $on, 'ends_on' => $on, 'amount_minor' => $amount, 'currency' => $currency, 'payment' => 'paid', 'source' => 'manual', 'evidence' => [],
            'work_job_id' => $job->id, 'decision' => 'recharge', 'decided_at' => now()]);
    }

    /** Decided by you for the job: income (money in) or a cost (money out). */
    private function ledger(WorkJob $job, int $pence, string $category): void
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->account->id, 'original_filename' => 'june.csv'],
            ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;
        $tx = BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-06-08', 'tax_year' => UkTaxYear::fromDate('2026-06-08')->label(),
            'description' => 'Line '.$this->n, 'counterparty' => 'Line '.$this->n, 'merchant_key' => 'LINE '.$this->n, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => 'golden-close-'.$this->n]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $pence > 0 ? 'business_income' : 'business_expense',
            (int) Category::query()->where('code', $category)->value('id'), $job->id));
    }

    private static function verdict(FindingDraft $d): array
    {
        return ['key' => $d->key, 'code' => $d->code, 'group' => $d->group, 'priority' => $d->priority, 'title' => $d->title, 'detail' => $d->detail, 'href' => $d->href,
            'subject' => $d->subjectRef, 'scope' => $d->scopeRef, 'evidence' => $d->evidence, 'amount' => $d->amountPence, 'currency' => $d->currency, 'direction' => $d->direction,
            'grade' => $d->grade, 'actions' => $d->actions, 'hidden_under' => $d->hiddenUnder, 'dismiss_key' => $d->dismissKey, 'meta' => $d->meta];
    }

    private static function doc(Document $d): string
    {
        return 'doc:'.$d->id.'#f=po_total@'.substr($d->sha256, 0, 8);
    }

    public function test_verdicts_for_jobs_invoiced_before_the_release_or_billed_by_income(): void
    {
        // PO £1,200, invoiced £1,050 from the PO, and a £180 hotel to recharge that isn't on it; £500 in, £45 of costs.
        $arena = $this->job('Arena load-in', '2026-06-03');
        $arenaPo = $this->po($arena, 120000);
        $arenaInv = $this->invoice($arena, 'INV-0001', ['Crew day' => 105000], $arenaPo);
        $arenaHotel = $this->recharge($arena, 18000, 'Premier Inn', '2026-06-02');
        $this->ledger($arena, 50000, 'turnover');
        $this->ledger($arena, -4500, 'travel_taxi');

        // Invoiced in full from the PO, the hotel on its own line: nothing owed.
        $gala = $this->job('Gala dinner', '2026-06-02');
        $galaInv = $this->invoice($gala, 'INV-0002', ['Crew' => 62000, 'Hotel' => 18000], $this->po($gala, 80000));
        $galaHotel = $this->recharge($gala, 18000, 'Hilton', '2026-06-01');

        // A train and a hotel on one line, "at cost": nothing owed.
        $leeds = $this->job('Leeds conference', '2026-06-01');
        $this->invoice($leeds, 'INV-0003', ['Crew' => 50000, 'Travel and hotel, at cost' => 25500]);
        $leedsTrain = $this->recharge($leeds, 7500, 'LNER', '2026-05-31', 'rail');
        $leedsHotel = $this->recharge($leeds, 18000, 'Novotel', '2026-05-31');

        // A hotel at an amount no line has, with a line for travel that may hold it; and bookings it leaves alone:
        // cancelled, with no amount, in another currency.
        $cardiff = $this->job('Cardiff tour', '2026-05-28');
        $cardiffInv = $this->invoice($cardiff, 'INV-0004', ['Crew' => 60000, 'Travel expenses' => 9000]);
        $cardiffHotel = $this->recharge($cardiff, 12000, 'Travelodge', '2026-05-27');
        $cancelled = $this->recharge($cardiff, 5000, 'Ibis', '2026-05-27', status: 'cancelled');
        $noAmount = $this->recharge($cardiff, null, 'Holiday Inn', '2026-05-27');
        $euros = $this->recharge($cardiff, 10000, 'Accor', '2026-05-27', currency: 'EUR');

        // A euro invoice short of its PO (the PO's total only on the invoice).
        $vienna = $this->job('Vienna gala', '2026-05-30');
        $viennaInv = $this->invoice($vienna, 'INV-0005', ['Crew' => 100000], poTotal: 120000, currency: 'EUR');

        // No invoice issued in Expend, but £800 recorded for the job: billed by income, against its £1,000 PO.
        $bristol = $this->job('Bristol fair', '2026-05-25');
        $bristolPo = $this->po($bristol, 100000);
        $this->ledger($bristol, 80000, 'turnover');
        $bristolHotel = $this->recharge($bristol, 9000, 'Ibis', '2026-05-24');

        // A recharge you marked not relevant on the to-do list stays as you left it.
        $bath = $this->job('Bath festival', '2026-05-20');
        $this->invoice($bath, 'INV-0006', ['Crew' => 40000]);
        $bathHotel = $this->recharge($bath, 7000, 'Royal Crescent', '2026-05-19');
        TodoDismissal::query()->create(['key' => 'recharge:'.$bathHotel->id, 'note' => 'Already sorted']);

        // Still under way: not looked at yet.
        $liverpool = $this->job('Liverpool tour', '2026-06-08', '2026-06-15');
        $this->po($liverpool, 50000);
        $this->recharge($liverpool, 6000, 'Premier Inn', '2026-06-08');

        $closeout = new JobCloseout;
        $drafts = collect($closeout->detect(new CycleContext))->keyBy('key');
        $this->assertSame([7, Coverage::Complete], [$closeout->checked(), $closeout->coverage()]);
        $this->assertEqualsCanonicalizing(['closeout:'.$arena->id, 'closeout:'.$cardiff->id, 'closeout:'.$vienna->id, 'closeout:'.$bristol->id], $drafts->keys()->all());
        $draftAction = fn (WorkJob $j) => [['code' => 'job_invoice', 'id' => (string) $j->id, 'label' => 'Draft the invoice', 'style' => 'default']];

        $this->assertSame([
            'key' => 'closeout:'.$arena->id, 'code' => 'job.closeout', 'group' => 'closeout', 'priority' => 1, 'title' => 'Arena load-in: £330.00 not invoiced',
            'detail' => 'PO £1,200.00, invoiced £1,050.00: £150.00 short. To recharge and not on the invoice: Hotel Premier Inn Arena 2 Jun £180.00. £500.00 received so far. '
                .'Costs recorded for the job: £45.00. Invoice the rest separately, or void the invoice and issue it again with everything on it.',
            'href' => '/invoices/'.$arenaInv->id, 'subject' => 'job:'.$arena->id, 'scope' => 'job:'.$arena->id,
            'evidence' => ['job:'.$arena->id, self::doc($arenaPo), 'inv:'.$arenaInv->id, 'travel:'.$arenaHotel->id],
            'amount' => 33000, 'currency' => 'GBP', 'direction' => 'owed_to_you', 'grade' => 'strong', 'actions' => [], 'hidden_under' => [],
            'dismiss_key' => 'closeout:'.$arena->id.':sGBP15000.r'.$arenaHotel->id, 'meta' => ['subsumes' => ['underbilled:'.$arenaInv->id, 'recharge:'.$arenaHotel->id]],
        ], self::verdict($drafts['closeout:'.$arena->id]));

        $this->assertSame([
            'key' => 'closeout:'.$cardiff->id, 'code' => 'job.closeout', 'group' => 'closeout', 'priority' => 1, 'title' => 'Cardiff tour: £120.00 may not be invoiced',
            'detail' => 'To recharge, and no line of the invoice is for that amount: Hotel Travelodge Cardiff 27 May £120.00. Check it’s on the invoice (“Travel expenses” may include it). '
                .'If it isn’t on it, invoice it separately.',
            'href' => '/invoices/'.$cardiffInv->id, 'subject' => 'job:'.$cardiff->id, 'scope' => 'job:'.$cardiff->id,
            'evidence' => ['job:'.$cardiff->id, 'inv:'.$cardiffInv->id, 'travel:'.$cardiffHotel->id],
            'amount' => 12000, 'currency' => 'GBP', 'direction' => 'owed_to_you', 'grade' => 'likely', 'actions' => [], 'hidden_under' => [],
            'dismiss_key' => 'closeout:'.$cardiff->id.':r'.$cardiffHotel->id, 'meta' => ['subsumes' => ['recharge:'.$cardiffHotel->id]],
        ], self::verdict($drafts['closeout:'.$cardiff->id]));

        $this->assertSame([
            'key' => 'closeout:'.$vienna->id, 'code' => 'job.closeout', 'group' => 'closeout', 'priority' => 1, 'title' => 'Vienna gala: €200.00 not invoiced',
            'detail' => 'PO €1,200.00, invoiced €1,000.00: €200.00 short. The PO’s total wasn’t found word for word in it: check it before you invoice. '
                .'Invoice the rest separately, or void the invoice and issue it again with everything on it.',
            'href' => '/invoices/'.$viennaInv->id, 'subject' => 'job:'.$vienna->id, 'scope' => 'job:'.$vienna->id, 'evidence' => ['job:'.$vienna->id, 'inv:'.$viennaInv->id],
            'amount' => 20000, 'currency' => 'EUR', 'direction' => 'owed_to_you', 'grade' => 'likely', 'actions' => [], 'hidden_under' => [],
            'dismiss_key' => 'closeout:'.$vienna->id.':sEUR20000', 'meta' => ['subsumes' => ['underbilled:'.$viennaInv->id]],
        ], self::verdict($drafts['closeout:'.$vienna->id]));

        $this->assertSame([
            'key' => 'closeout:'.$bristol->id, 'code' => 'job.closeout', 'group' => 'closeout', 'priority' => 1, 'title' => 'Bristol fair: £290.00 not invoiced',
            'detail' => 'PO £1,000.00, and £800.00 received for the job with no invoice issued in Expend: £200.00 short. To recharge: Hotel Ibis Bristol 24 May £90.00. '
                .'The job was invoiced outside Expend: check it was on that invoice. If the rest hasn’t been invoiced, draft an invoice for it.',
            'href' => '/jobs/'.$bristol->id, 'subject' => 'job:'.$bristol->id, 'scope' => 'job:'.$bristol->id,
            'evidence' => ['job:'.$bristol->id, self::doc($bristolPo), 'travel:'.$bristolHotel->id],
            'amount' => 29000, 'currency' => 'GBP', 'direction' => 'owed_to_you', 'grade' => 'likely', 'actions' => $draftAction($bristol), 'hidden_under' => [],
            'dismiss_key' => 'closeout:'.$bristol->id.':sGBP20000.r'.$bristolHotel->id, 'meta' => ['subsumes' => ['recharge:'.$bristolHotel->id]],
        ], self::verdict($drafts['closeout:'.$bristol->id]));

        // What it says instead of the to-do list (null: nothing owed for that job any more). The cancelled, no-amount,
        // euro and dismissed bookings stay as they were.
        $subsumed = $closeout->subsumed();
        ksort($subsumed);
        $expected = [
            'underbilled:'.$arenaInv->id => 'closeout:'.$arena->id, 'recharge:'.$arenaHotel->id => 'closeout:'.$arena->id,
            'recharge:'.$galaHotel->id => null,
            'recharge:'.$leedsTrain->id => null, 'recharge:'.$leedsHotel->id => null,
            'recharge:'.$cardiffHotel->id => 'closeout:'.$cardiff->id,
            'underbilled:'.$viennaInv->id => 'closeout:'.$vienna->id,
            'recharge:'.$bristolHotel->id => 'closeout:'.$bristol->id,
        ];
        ksort($expected);
        $this->assertSame($expected, $subsumed);
        foreach ([$cancelled, $noAmount, $euros, $bathHotel] as $left) {
            $this->assertArrayNotHasKey('recharge:'.$left->id, $subsumed);
        }
        $this->assertArrayNotHasKey('underbilled:'.$galaInv->id, $subsumed, 'Not less than its PO');
    }

    public function test_verdicts_for_jobs_not_invoiced_yet_or_only_drafted(): void
    {
        // No invoice, a PO linked automatically (so not checked word for word), and a hotel to recharge.
        $glasgow = $this->job('Glasgow tour', '2026-06-05');
        $glasgowPo = $this->po($glasgow, 90000, true, 'matcher');
        $glasgowHotel = $this->recharge($glasgow, 15000, 'Hilton', '2026-06-04');

        // A draft from the PO, short of it.
        $edinburgh = $this->job('Edinburgh fringe', '2026-06-04');
        $edinburghPo = $this->po($edinburgh, 100000);
        $draft = $this->invoice($edinburgh, null, ['Crew' => 70000], $edinburghPo, issued: null);

        // Finished with nothing to say.
        $this->job('Quiet day', '2026-06-06');

        $closeout = new JobCloseout;
        $drafts = collect($closeout->detect(new CycleContext))->keyBy('key');
        $this->assertSame(3, $closeout->checked());
        $this->assertEqualsCanonicalizing(['closeout:'.$glasgow->id, 'closeout:'.$edinburgh->id], $drafts->keys()->all());

        $this->assertSame([
            'key' => 'closeout:'.$glasgow->id, 'code' => 'job.closeout', 'group' => 'closeout', 'priority' => 1, 'title' => 'Glasgow tour: £1,050.00 not invoiced',
            'detail' => 'PO £900.00 and no invoice yet. The PO’s total wasn’t found word for word in it: check it before you invoice. '
                .'To recharge and not on an invoice yet: Hotel Hilton Glasgow 4 Jun £150.00. Draft the invoice and add the recharges.',
            'href' => '/jobs/'.$glasgow->id, 'subject' => 'job:'.$glasgow->id, 'scope' => 'job:'.$glasgow->id,
            'evidence' => ['job:'.$glasgow->id, self::doc($glasgowPo), 'travel:'.$glasgowHotel->id],
            'amount' => 105000, 'currency' => 'GBP', 'direction' => 'owed_to_you', 'grade' => 'likely',
            'actions' => [['code' => 'job_invoice', 'id' => (string) $glasgow->id, 'label' => 'Draft the invoice', 'style' => 'default']], 'hidden_under' => [],
            'dismiss_key' => 'closeout:'.$glasgow->id.':sGBP90000.r'.$glasgowHotel->id, 'meta' => ['subsumes' => ['recharge:'.$glasgowHotel->id]],
        ], self::verdict($drafts['closeout:'.$glasgow->id]));

        $this->assertSame([
            'key' => 'closeout:'.$edinburgh->id, 'code' => 'job.closeout', 'group' => 'closeout', 'priority' => 1, 'title' => 'Edinburgh fringe: £300.00 not invoiced',
            'detail' => 'PO £1,000.00, draft invoice £700.00: £300.00 short. Add it to the draft invoice before you issue it.',
            'href' => '/invoices/'.$draft->id, 'subject' => 'job:'.$edinburgh->id, 'scope' => 'job:'.$edinburgh->id,
            'evidence' => ['job:'.$edinburgh->id, self::doc($edinburghPo), 'inv:'.$draft->id],
            'amount' => 30000, 'currency' => 'GBP', 'direction' => 'owed_to_you', 'grade' => 'strong', 'actions' => [], 'hidden_under' => [],
            'dismiss_key' => 'closeout:'.$edinburgh->id.':sGBP30000', 'meta' => ['subsumes' => ['underbilled:'.$draft->id]],
        ], self::verdict($drafts['closeout:'.$edinburgh->id]));

        $this->assertSame(collect(['recharge:'.$glasgowHotel->id => 'closeout:'.$glasgow->id, 'underbilled:'.$draft->id => 'closeout:'.$edinburgh->id])->sortKeys()->all(),
            collect($closeout->subsumed())->sortKeys()->all());
    }
}
