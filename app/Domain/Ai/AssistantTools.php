<?php

namespace App\Domain\Ai;

use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\PoStatus;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Tax\BillPreview;
use App\Domain\Tax\Explainer;
use App\Domain\Tax\FilingDates;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\PenaltyRegime;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\TaxEstimator;
use App\Domain\Tax\TaxGuide;
use App\Domain\Tax\TaxLaw;
use App\Domain\Tax\TaxParameters;
use App\Domain\Tax\TaxSavings;
use App\Domain\Tax\YearChecks;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the assistant can see and do. All tools read the app's real records; the only write is
 * propose_decision, which creates an unconfirmed suggestion that you accept or change in the Inbox.
 * Money is returned both in pence and formatted, so every figure in an answer can be checked.
 */
final class AssistantTools
{
    public function __construct(private AllocationWriter $writer) {}

    public static function definitions(): array
    {
        $obj = fn (array $props, array $required = []) => ['type' => 'object', 'properties' => $props, 'required' => $required];
        $str = ['type' => 'string'];
        $int = ['type' => 'integer'];

        return [
            ['name' => 'search_transactions', 'description' => 'Find bank and card transactions with their decisions. Returns matching items (up to 50) and exact totals for everything matching.',
                'inputSchema' => $obj(['query' => $str + ['description' => 'Words in the description or merchant'], 'tax_year' => $str + ['description' => 'e.g. 2024-25'],
                    'date_from' => $str, 'date_to' => $str, 'status' => ['type' => 'string', 'enum' => ['unreviewed', 'suggested', 'confirmed']],
                    'kind' => ['type' => 'string', 'enum' => array_keys(Allocation::KINDS)], 'category_code' => $str, 'job_id' => $int,
                    'direction' => ['type' => 'string', 'enum' => ['in', 'out']], 'limit' => $int])],
            ['name' => 'tax_year_overview', 'description' => 'Self-employment figures for a tax year from confirmed decisions, items still pending, pre-filing checks, deadline, and a labelled ESTIMATE of tax and the likely HMRC bill.',
                'inputSchema' => $obj(['tax_year' => $str], ['tax_year'])],
            ['name' => 'list_jobs', 'description' => 'Jobs in a date range or matching words, with income, costs, mileage and profit from confirmed records, the PO number (or missing_po when a job has no PO) and whether it has been invoiced.',
                'inputSchema' => $obj(['date_from' => $str, 'date_to' => $str, 'query' => $str])],
            ['name' => 'job_sheet', 'description' => 'Everything about one job: dates, times, venue, travel, accommodation, contacts and other details read from documents (each marked verified or not, with its source), hotel stays and journeys found in Gmail with their booking references, linked costs and income, mileage, documents and invoices.',
                'inputSchema' => $obj(['job_id' => $int], ['job_id'])],
            ['name' => 'calendar', 'description' => 'Calendar entries and jobs between two dates.',
                'inputSchema' => $obj(['date_from' => $str, 'date_to' => $str], ['date_from', 'date_to'])],
            ['name' => 'search_documents', 'description' => 'Find documents (POs, job invites, production packs, call sheets, bookings, receipts, emails) by words, kind or job.',
                'inputSchema' => $obj(['query' => $str, 'kind' => ['type' => 'string', 'enum' => array_keys(Document::KINDS)], 'job_id' => $int, 'limit' => $int])],
            ['name' => 'read_document', 'description' => 'The text of one document, plus the details already read from it.',
                'inputSchema' => $obj(['document_id' => $int, 'max_chars' => $int], ['document_id'])],
            ['name' => 'invoices', 'description' => 'Invoices with totals, amounts paid, due dates and whether they are overdue.',
                'inputSchema' => $obj(['status' => ['type' => 'string', 'enum' => ['draft', 'issued', 'paid', 'void']]])],
            ['name' => 'deadlines', 'description' => 'What is coming up or overdue: tax return and payment deadlines, Making Tax Digital quarterly updates not recorded as sent (with what being late costs in points), overdue invoices, Making Tax Digital and VAT thresholds, jobs in the next 14 days.',
                'inputSchema' => $obj([])],
            ['name' => 'merchant_breakdown', 'description' => 'Spending or income grouped by merchant for a tax year, with how much is decided.',
                'inputSchema' => $obj(['tax_year' => $str, 'direction' => ['type' => 'string', 'enum' => ['in', 'out']]], ['tax_year'])],
            ['name' => 'tax_rules', 'description' => 'The tax rates, allowances, mileage rates and thresholds the app uses for a tax year, with GOV.UK sources.',
                'inputSchema' => $obj(['tax_year' => $str], ['tax_year'])],
            ['name' => 'tax_guide', 'description' => 'The UK tax guide (GOV.UK and HMRC manuals, with source links): what an expense category is and whether it is allowable, rules on topics such as travel, mileage, use of home, capital allowances, payments on account, pensions and VAT, and legal ways to reduce tax. Use it for any tax rule; cite its sources.',
                'inputSchema' => $obj(['category_code' => $str + ['description' => 'e.g. travel_accommodation, equipment, use_of_home'], 'topic' => ['type' => 'string', 'enum' => array_keys(TaxGuide::topicTitles())],
                    'query' => $str + ['description' => 'Words to search the guide for'], 'ways_to_reduce_tax' => ['type' => 'boolean']])],
            ['name' => 'penalties', 'description' => 'Fines and interest HMRC charges or will charge, per tax year, from what the owner has told Expend about HMRC\'s letters (notice to file and its date, how the return was filed) and the HMRC payments in the records: late filing penalties (£100, daily £10, 6- and 12-month; or, under the new rules, late submission points: late_submission_points says how many the owner has of the threshold (4 in Making Tax Digital, 2 otherwise), when each goes, when they would all go if everything is sent on time, the £200 penalties (£200 at the threshold and for each later late submission, at most one a month for each kind of submission), the next submission and what being late would cost; points are what the owner is liable to and HMRC give them by notice, and quarterly updates with no sent date are taken as not sent: say so; submissions lists the return and quarterly updates with their due and sent dates), the penalty for not telling HMRC by 5 October where no notice to file was sent (a range: say so), late payment penalties (on the year\'s tax less what was paid towards it, so unpaid payments on account count; extra tax from an amended return from its own later date), and late payment interest (each run of days at one balance and rate divided by 365, or 366 in a leap year, and rounded down to the penny, as in HMRC\'s published example: interest_method; an estimate). Each item has its date, amount, status (due, accruing, upcoming), the rule and a source link; estimate=true items rest on the assumption given (say it), on_filing is added when the return is filed, and time_limit says when a penalty only stands if HMRC charged it in time. notice_to_file says whether a notice is known or assumed. rules says which penalty rules apply; announced_new_rules, where present, are rules announced but NOT law: never present them as law. payment_plan says what the owner\'s payment plan does: the date they ASKED HMRC is what counts, and only if HMRC agree and it\'s kept; held_off are penalties it is holding off, at_risk_if_hmrc_charge_them are ones a broken plan held off that HMRC can now charge by notice (not yet charged: say so). HMRC won\'t agree a plan while the return is overdue. Answers "what if" questions: pass file_on and/or pay_all_on (YYYY-MM-DD) to see the effect of filing or paying on a date, plan_from for a payment plan asked for on that date (and agreed and kept). It is an estimate: say so, and that HMRC\'s statement is the final word.',
                'inputSchema' => $obj(['tax_year' => $str + ['description' => 'e.g. 2024-25; omit for every year'], 'file_on' => $str + ['description' => 'what if the return is filed on this date (YYYY-MM-DD)'],
                    'pay_all_on' => $str + ['description' => 'what if everything owed is paid on this date'], 'plan_from' => $str + ['description' => 'what if a payment plan is asked for on this date, HMRC agree it and it is kept']])],
            ['name' => 'tax_law', 'description' => 'The checked library of UK tax law for a sole trader: leading cases (what each decided and why it matters to a freelance technician), the legislation, HMRC manual pages, and recent or coming changes (not_law_yet when only announced). Each item has its official link to cite; never cite a case marked do_not_cite. Search by words, optionally by kind; or pass id for one item, including an id from tax_savings (that gives the idea\'s conditions, risks and the law behind it). Use it before stating a legal rule or naming a case.',
                'inputSchema' => $obj(['query' => $str + ['description' => 'e.g. "hotel meals away overnight", "travel to temporary workplace", "late filing reasonable excuse"'],
                    'kind' => ['type' => 'string', 'enum' => ['case', 'legislation', 'guidance', 'development']], 'id' => $str + ['description' => 'An item id from an earlier result'], 'limit' => $int])],
            ['name' => 'tax_savings', 'description' => 'Legal ways to pay less tax for a tax year, checked against the records: claims missing (use of home, phone, travel to venues, meals away), reliefs worth using at their rate of tax (pension contributions, losses), choices to review (vehicle method, trading allowance, VAT), payments on account to reduce, voluntary Class 2, deadlines and penalties. Each has what was found, an ESTIMATE of the saving where one can be worked out, conditions, risks, needs_accountant when one is needed, and the law with links (source_note says when a link opens something other than its title). total_saving_estimate is the headline (fines left out, the trading allowance counted once against new expense claims); fines_and_interest_so_far is not a saving. in_progress means the year isn\'t over, so figures are from the records so far. "more" lists other ideas to ask about.',
                'inputSchema' => $obj(['tax_year' => $str], ['tax_year'])],
            ['name' => 'explain_payment', 'description' => 'What one bank transaction is, how it has been treated, whether it is normally allowable and on what conditions, the evidence to keep, and notes from its context (jobs that day, bookings, vehicle mileage method).',
                'inputSchema' => $obj(['transaction_id' => $int], ['transaction_id'])],
            ['name' => 'propose_decision', 'description' => 'Suggest what one transaction is. It is saved as an unconfirmed AI suggestion for the owner to accept or change in the Inbox. Cannot change anything the owner has decided.',
                'inputSchema' => $obj(['transaction_id' => $int, 'kind' => ['type' => 'string', 'enum' => array_keys(Allocation::KINDS)], 'category_code' => $str,
                    'job_id' => $int, 'business_pct' => $int, 'reason' => $str], ['transaction_id', 'kind', 'reason'])],
        ];
    }

    public function run(string $name, array $input): array
    {
        return match ($name) {
            'search_transactions' => $this->searchTransactions($input),
            'tax_year_overview' => $this->taxYear($input['tax_year'] ?? ''),
            'list_jobs' => $this->jobs($input),
            'job_sheet' => $this->jobSheet((int) ($input['job_id'] ?? 0)),
            'calendar' => $this->calendar($input),
            'search_documents' => $this->documents($input),
            'read_document' => $this->readDocument((int) ($input['document_id'] ?? 0), (int) ($input['max_chars'] ?? 12000)),
            'invoices' => $this->invoices($input['status'] ?? null),
            'deadlines' => $this->deadlines(),
            'merchant_breakdown' => $this->merchants($input),
            'tax_rules' => $this->rules($input['tax_year'] ?? ''),
            'tax_guide' => $this->taxGuide($input),
            'penalties' => $this->penalties($input),
            'explain_payment' => ($t = BankTransaction::query()->with('account')->find((int) ($input['transaction_id'] ?? 0))) ? Explainer::payment($t) : ['error' => 'No such transaction'],
            'tax_law' => $this->taxLaw($input),
            'tax_savings' => $this->taxSavings($input['tax_year'] ?? ''),
            'propose_decision' => $this->propose($input),
            default => ['error' => "Unknown tool {$name}"],
        };
    }

    private function taxGuide(array $in): array
    {
        $out = ['researched_on' => TaxGuide::researchedOn(), 'note' => 'General guidance from GOV.UK and HMRC manuals, not personal advice. Items starting "Ask an accountant" need one.'];
        if (! empty($in['category_code'])) {
            $out['category'] = TaxGuide::category($in['category_code']) ?? ['error' => 'No such category code'];
        }
        if (! empty($in['topic'])) {
            $out['topic'] = TaxGuide::topic($in['topic']) ?? ['error' => 'No such topic'];
        }
        if (! empty($in['ways_to_reduce_tax'])) {
            $out['ways_to_reduce_tax'] = TaxGuide::ways();
        }
        if (! empty($in['query'])) {
            $out['matches'] = array_map(fn ($h) => $h + ['detail' => match ($h['type']) {
                'category' => TaxGuide::category($h['key']), 'topic' => TaxGuide::topic($h['key']), default => TaxGuide::ways()[(int) $h['key']] ?? null,
            }], array_slice(TaxGuide::search($in['query']), 0, 3));
        }

        return $out;
    }

    private function taxLaw(array $input): array
    {
        if (! empty($input['id'])) {
            $item = TaxLaw::find((string) $input['id']);
            if (! $item) {
                return ['error' => 'No item with that id.'];
            }
            // A tax_savings id gives that idea's conditions, risks and legal basis, not an empty item.
            $shown = $item['type'] === 'saving' ? TaxLaw::presentSaving($item) : TaxLaw::present($item, $item['type']);

            return ['item' => $shown, 'researched_on' => TaxLaw::researchedOn()];
        }
        $limit = max(1, min(15, (int) ($input['limit'] ?? 8)));
        $found = TaxLaw::search((string) ($input['query'] ?? ''), $input['kind'] ?? null, null, $limit);

        return ['researched_on' => TaxLaw::researchedOn(), 'results' => $found,
            'note' => 'Each item was checked against an official source on the date above. Cite the url. Items with do_not_cite must not be relied on; not_law_yet items are announcements, not law.'];
    }

    private function taxSavings(string $year): array
    {
        $review = TaxSavings::review($year ?: UkTaxYear::current()->label());
        $money = fn (?int $p) => $p === null ? null : self::money($p);
        // Every figure as {pence, formatted}, so the answer's figures can be checked against them (a bare integer can't be).
        $review['profit'] = self::money($review['profit_pence']);
        $review['total_saving_estimate'] = self::money($review['total_saving_pence']);
        $review['fines_and_interest_so_far'] = self::money($review['penalties_to_avoid_pence']);
        unset($review['profit_pence'], $review['total_saving_pence'], $review['penalties_to_avoid_pence']);
        $review['items'] = array_map(function ($i) use ($money) {
            $i['saving_estimate'] = $money($i['saving_pence']);
            $i['deduction'] = $money($i['deduction_pence']);
            unset($i['saving_pence'], $i['deduction_pence']);

            return array_filter($i, fn ($v) => $v !== null);
        }, $review['items']);

        return $review;
    }

    private static function money(int $pence, string $currency = 'GBP'): array
    {
        return ['pence' => $pence, 'formatted' => Money::format($pence, $currency)];
    }

    private function searchTransactions(array $f): array
    {
        $q = BankTransaction::query()->with(['account', 'allocations.category', 'allocations.job'])
            ->when($f['query'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('description', 'like', "%{$v}%")->orWhere('merchant_key', 'like', '%'.strtoupper($v).'%')))
            ->when($f['tax_year'] ?? null, fn ($q, $v) => $q->where('tax_year', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('posted_on', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('posted_on', '<=', $v.' 23:59:59'))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('review_status', $v))
            ->when($f['direction'] ?? null, fn ($q, $v) => $q->where('amount_pence', $v === 'in' ? '>' : '<', 0))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('kind', $v)))
            ->when($f['job_id'] ?? null, fn ($q, $v) => $q->whereHas('allocations', fn ($a) => $a->where('work_job_id', $v)))
            ->when($f['category_code'] ?? null, fn ($q, $v) => $q->whereHas('allocations.category', fn ($c) => $c->where('code', $v)));

        $sums = 'COUNT(*) n, COALESCE(SUM(CASE WHEN amount_pence > 0 THEN amount_pence END),0) i, COALESCE(SUM(CASE WHEN amount_pence < 0 THEN amount_pence END),0) o';
        $count = (clone $q)->count();
        $totals = OwnAccounts::scopeNotTransfers(clone $q)->selectRaw($sums)->first();
        $moved = OwnAccounts::scopeTransfers(clone $q)->selectRaw($sums)->first();
        $items = $q->orderByDesc('posted_on')->limit(min(50, max(1, (int) ($f['limit'] ?? 25))))->get();

        return [
            'count' => $count,
            'total_in' => self::money((int) $totals->i),
            'total_out' => self::money((int) $totals->o),
            'between_your_own_accounts' => ['count' => (int) $moved->n, 'in' => self::money((int) $moved->i), 'out' => self::money((int) $moved->o),
                'note' => 'Transfers between your own accounts (card bills, top-ups, savings) are not spending or income and are left out of total_in and total_out.'],
            'items' => $items->map(fn (BankTransaction $t) => [
                'transaction_id' => $t->id, 'date' => $t->effectiveDate()->format('Y-m-d'), 'description' => $t->description,
                'account' => $t->account->name, 'amount' => self::money($t->amount_pence), 'status' => $t->review_status,
                'decision' => $t->allocations->map(fn ($a) => [
                    'kind' => $a->kind, 'category' => $a->category?->name, 'job' => $a->job?->title, 'amount' => self::money($a->amount_pence),
                    'decided_by' => $a->decided_by, 'confirmed' => $a->confirmed_at !== null,
                ])->all(),
            ])->all(),
        ];
    }

    private function taxYear(string $label): array
    {
        $label = UkTaxYear::fromLabel($label)->label();
        $summary = ReturnSummary::for($label);
        $estimate = TaxEstimator::estimate($label, $summary['net_profit_pence']);
        $record = TaxYear::query()->where('label', $label)->first();

        return [
            'tax_year' => $label,
            'status' => $record?->status ?? 'not tracked',
            // From HMRC's notice to file where known; null when HMRC sent none (tell them by 5 October instead).
            'filing_deadline' => FilingDates::deadline($label, $record)?->format('Y-m-d'),
            'figures_from_confirmed_records' => [
                'turnover' => self::money($summary['turnover_pence']),
                'other_income' => self::money($summary['other_income_pence']),
                'allowable_expenses' => self::money($summary['allowable_expenses_pence']),
                'disallowable_expenses' => self::money($summary['disallowable_expenses_pence']),
                'mileage_included' => self::money($summary['mileage_pence']),
                'net_profit' => self::money($summary['net_profit_pence']),
                'expense_lines' => array_map(fn ($l) => ['box' => $l['box'], 'name' => $l['name'], 'allowable' => self::money($l['allowable_pence'])], $summary['expense_lines']),
            ],
            'not_yet_counted' => $summary['pending'],
            'checks' => array_map(fn ($c) => ['check' => $c['label'], 'status' => $c['status'], 'detail' => $c['detail']], YearChecks::checks($label, $summary)),
            'ESTIMATE_tax' => $estimate ? [
                'income_tax' => self::money($estimate['income_tax']['total_pence']), 'class4' => self::money($estimate['class4_pence']),
                'class2' => self::money($estimate['class2_pence']), 'total' => self::money($estimate['total_pence']), 'notes' => $estimate['notes'],
            ] : 'No estimate (no rates loaded, or Scottish taxpayer).',
            'ESTIMATE_bill' => ($bill = BillPreview::for($label, $estimate)) ? array_map(fn ($d) => ['date' => $d['date'], 'what' => $d['label'], 'amount' => self::money($d['pence'])], $bill['due']) : null,
        ];
    }

    private function jobs(array $f): array
    {
        $jobs = WorkJob::query()->with('client')
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('ends_on', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('starts_on', '<=', $v.' 23:59:59'))
            ->when($f['query'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('title', 'like', "%{$v}%")->orWhere('location', 'like', "%{$v}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$v}%"))))
            ->orderBy('starts_on')->limit(60)->get();

        return ['jobs' => $jobs->map(fn (WorkJob $j) => $this->jobSummary($j))->all()];
    }

    private function jobSummary(WorkJob $j): array
    {
        $alloc = Allocation::query()->where('work_job_id', $j->id)->whereNotNull('confirmed_at');
        $income = (int) (clone $alloc)->where('kind', 'business_income')->sum('amount_pence');
        $costs = -(int) (clone $alloc)->where('kind', 'business_expense')->sum('amount_pence');
        $mileage = collect([UkTaxYear::fromDate($j->starts_on)->label()])->sum(fn ($y) => MileageCalculator::forYear($y)['by_job'][$j->id] ?? 0);

        return [
            'job_id' => $j->id, 'title' => $j->title, 'client' => $j->client?->name, 'starts_on' => $j->starts_on->format('Y-m-d'),
            'ends_on' => $j->ends_on->format('Y-m-d'), 'start_time' => $j->start_time ? substr($j->start_time, 0, 5) : null,
            'end_time' => $j->end_time ? substr($j->end_time, 0, 5) : null, 'venue' => $j->location, 'postcode' => $j->postcode, 'status' => $j->status,
            'agreed_fee' => $j->fee_pence ? self::money($j->fee_pence) : null,
            'income_confirmed' => self::money($income), 'costs_confirmed' => self::money($costs), 'mileage_claim' => self::money($mileage),
            'profit' => self::money($income - $costs - $mileage),
        ] + self::poAndInvoice($j);
    }

    private static function poAndInvoice(WorkJob $j): array
    {
        $status = PoStatus::forJobs([$j])[$j->id];

        return [
            'po_number' => $status['po']['number'] ?? null,
            'has_po' => $status['po'] !== null,
            'po_not_needed' => (bool) $j->po_not_required,
            'missing_po' => $status['missing'],
            'invoiced' => $status['invoice'] ? $status['invoice']['number'].' ('.$status['invoice']['status'].')' : null,
        ];
    }

    private function jobSheet(int $id): array
    {
        $job = WorkJob::query()->with('client')->find($id);
        if (! $job) {
            return ['error' => "No job {$id}."];
        }
        $facts = DocumentFact::query()->with('document')->where('work_job_id', $id)->orderByDesc('id')->get();

        return $this->jobSummary($job) + [
            'notes' => $job->notes,
            'details_from_documents' => $facts->map(fn ($f) => [
                'what' => $f->label, 'value' => $f->value, 'date' => $f->on_date?->format('Y-m-d'), 'time' => $f->at_time,
                'verified_against_document' => $f->verified, 'source_document_id' => $f->document_id, 'source' => $f->document?->original_name,
                'received' => $f->document?->received_at?->format('Y-m-d'),
            ])->all(),
            'documents' => Document::query()->where('work_job_id', $id)->get(['id', 'original_name', 'kind', 'summary', 'received_at'])->toArray(),
            'invoices' => Invoice::query()->where('work_job_id', $id)->get()->map(fn ($i) => ['invoice_id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'total' => self::money($i->total_minor), 'due' => $i->due_date?->format('Y-m-d')])->all(),
            'stays_and_travel' => TravelItem::query()->where('work_job_id', $id)->orderBy('starts_on')->get()->map(fn ($t) => [
                'travel_item_id' => $t->id, 'kind' => $t->kind, 'provider' => $t->provider, 'booking_reference' => $t->reference, 'status' => $t->status,
                'from' => $t->starts_on->format('Y-m-d'), 'to' => $t->ends_on?->format('Y-m-d'), 'time' => $t->start_time, 'place' => $t->place, 'destination' => $t->destination,
                'amount' => $t->amount_minor !== null ? self::money($t->amount_minor, $t->currency) : null, 'payment' => $t->payment, 'booked_by' => $t->booker,
                'your_decision' => $t->decision, 'suggestion' => $t->suggestion['decision'] ?? null, 'details_not_verified' => $t->unverified_count,
                'source_document_id' => $t->document_id,
            ])->all(),
        ];
    }

    private function calendar(array $f): array
    {
        // Whole UK days: entries are stored in UTC (all-day ones as dates), so a day starts at UK midnight.
        $from = CarbonImmutable::parse(substr((string) ($f['date_from'] ?? CarbonImmutable::now(CalendarEvent::TZ)->format('Y-m-d')), 0, 10), CalendarEvent::TZ)->startOfDay();
        $to = isset($f['date_to']) ? CarbonImmutable::parse(substr((string) $f['date_to'], 0, 10), CalendarEvent::TZ)->startOfDay() : $from->addDays(7);
        if ($to->lt($from)) {
            $to = $from;
        }
        if ($to->diffInDays($from, true) > 120) {
            $to = $from->addDays(120);
        }
        $after = $to->addDay();

        return [
            'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
            'calendar' => CalendarEvent::query()
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('all_day', false)->where('starts_at', '>=', $from->utc()->format('Y-m-d H:i:s'))->where('starts_at', '<', $after->utc()->format('Y-m-d H:i:s')))
                    ->orWhere(fn ($q) => $q->where('all_day', true)->where('starts_at', '>=', $from->format('Y-m-d 00:00:00'))->where('starts_at', '<', $after->format('Y-m-d 00:00:00'))))
                ->orderBy('starts_at')->limit(100)->get()
                ->map(fn (CalendarEvent $e) => [
                    'when' => $e->all_day ? $e->day() : $e->localStart()->format('Y-m-d H:i'),
                    'until' => $e->all_day ? $e->lastDay()->format('Y-m-d') : $e->localEnd()?->format('Y-m-d H:i'),
                    'what' => $e->summary, 'where' => $e->location,
                ])->all(),
            'jobs' => WorkJob::query()->where('starts_on', '<=', $to->format('Y-m-d'))->where('ends_on', '>=', $from->format('Y-m-d'))->orderBy('starts_on')->get()
                ->map(fn ($j) => ['job_id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d'), 'ends_on' => $j->ends_on->format('Y-m-d'), 'venue' => $j->location])->all(),
        ];
    }

    private function documents(array $f): array
    {
        $docs = Document::query()
            ->when($f['query'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('original_name', 'like', "%{$v}%")->orWhere('email_subject', 'like', "%{$v}%")->orWhere('summary', 'like', "%{$v}%")->orWhere('text', 'like', "%{$v}%")))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['job_id'] ?? null, fn ($q, $v) => $q->where('work_job_id', $v))
            ->orderByDesc('received_at')->limit(min(30, max(1, (int) ($f['limit'] ?? 15))))->get();

        return ['documents' => $docs->map(fn (Document $d) => [
            'document_id' => $d->id, 'name' => $d->original_name, 'kind' => $d->kind, 'summary' => $d->summary, 'email_subject' => $d->email_subject,
            'received' => $d->received_at?->format('Y-m-d'), 'job_id' => $d->work_job_id, 'status' => $d->status,
        ])->all()];
    }

    private function readDocument(int $id, int $max): array
    {
        $d = Document::query()->with('facts')->find($id);
        if (! $d) {
            return ['error' => "No document {$id}."];
        }

        return [
            'document_id' => $d->id, 'name' => $d->original_name, 'kind' => $d->kind, 'summary' => $d->summary, 'job_id' => $d->work_job_id,
            'text_available' => $d->text_status === 'extracted',
            'text' => $d->text ? mb_substr($d->text, 0, min(30000, max(1000, $max))) : null,
            'details' => $d->facts->map(fn ($f) => ['what' => $f->label, 'value' => $f->value, 'verified' => $f->verified])->all(),
            'purchase_order' => $d->extraction['po'] ?? null,
        ];
    }

    private function invoices(?string $status): array
    {
        $invoices = Invoice::query()->with(['client', 'job'])->when($status, fn ($q, $v) => $q->where('status', $v))->orderByDesc('id')->limit(100)->get();
        // What has come in for each, in its own currency: payments linked to it, and income recorded for its job.
        $received = InvoicePayments::received($invoices);

        return ['invoices' => $invoices->map(function (Invoice $i) use ($received) {
            $paid = $received[$i->id]['paid'] ?? 0;
            $currency = $i->currency ?: 'GBP';

            return [
                'invoice_id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'client' => $i->client?->name ?? $i->bill_to_name,
                'job' => $i->job?->title, 'po_number' => $i->po_number, 'total' => self::money($i->total_minor, $currency), 'paid' => self::money($paid, $currency),
                'issued' => $i->issue_date?->format('Y-m-d'), 'due' => $i->due_date?->format('Y-m-d'),
                // Overdue from the day after it's due, as on the invoices page and the to-do list, and only while not paid in full.
                'overdue' => $i->status === 'issued' && $paid < $i->total_minor && (bool) $i->due_date?->lt(today()),
            ];
        })->all()];
    }

    private function deadlines(): array
    {
        $today = CarbonImmutable::today();
        // A return being amended has been filed: it isn't overdue, and the amendment is due 12 months after its filing
        // date (TMA 1970 s9ZA). One whose amendment window has closed is left out, like a filed one.
        // Filing dates follow HMRC's notice to file (FilingDates); with no notice, the duty is to tell HMRC by 5 October.
        $years = TaxYear::query()->where('status', '!=', 'filed')->get()
            ->reject(fn (TaxYear $y) => $y->status === 'amending' && $today->gt(FilingDates::amendBy($y->label, $y)))
            ->map(function (TaxYear $y) use ($today) {
                if ($y->status === 'amending') {
                    return ['tax_year' => $y->label, 'status' => $y->status, 'filed' => true, 'amend_by' => FilingDates::amendBy($y->label, $y)->format('Y-m-d'), 'overdue' => false];
                }
                $d = FilingDates::for($y->label, $y);
                if ($d['filing_date'] === null) {
                    return array_filter(['tax_year' => $y->label, 'status' => $y->status, 'no_notice_to_file' => true,
                        'tell_hmrc_by' => $d['missed_notify'] || $d['told_by_5_october'] !== true ? $d['notify_by']->format('Y-m-d') : null,
                        'told_hmrc_in_time' => $d['told_by_5_october'] === true ?: null,
                        'tax_due' => $d['balancing_due']->format('Y-m-d'), 'tax_due_estimated' => $d['balancing_basis'] === 'estimate' ?: null], fn ($v) => $v !== null);
                }
                $tooLate = $today->gt($d['own_return_last']);

                return array_filter([
                    'tax_year' => $y->label, 'status' => $y->status, 'return_due' => $d['filing_date']->format('Y-m-d'),
                    // An estimate from the notice's date, or resting on an assumed notice: the date on HMRC's notice counts.
                    'return_due_basis' => match (true) {
                        $d['filing_basis'] === 'estimate' => 'estimated from the notice date (plus 3 months and 7 days); the date printed on HMRC’s notice counts',
                        $d['notice'] === 'assumed' => 'assumes HMRC sent a notice to file by 31 July after the year',
                        default => null,
                    },
                    'tax_due' => $d['balancing_due']->format('Y-m-d'), 'overdue' => $today->gt($d['filing_date']),
                    // The last day you can file it yourself (s34A), or replace HMRC's determination with it (s28C(5)).
                    'own_return_last' => $d['own_return_last']->format('Y-m-d'), 'too_late_to_file' => $tooLate,
                    'too_late_note' => $tooLate ? ($d['own_return_basis'] === 'determination'
                        ? 'The time to replace HMRC’s determination with a return has ended: the determination stands. Only special relief can change it; get advice.'
                        : 'The time limit for filing your own return has ended: it can no longer be filed in the usual way. HMRC can still assess the tax; get advice.') : null,
                ], fn ($v) => $v !== null);
            })->values()->all();

        return [
            'today' => $today->format('Y-m-d'),
            'tax_returns' => $years,
            'overdue_invoices' => array_values(array_filter($this->invoices('issued')['invoices'], fn ($i) => $i['overdue'])),
            'jobs_next_14_days' => $this->calendar(['date_from' => $today->format('Y-m-d'), 'date_to' => $today->addDays(14)->format('Y-m-d')])['jobs'],
            // Making Tax Digital quarterly updates with no "sent on" date recorded (taken as not sent), and what being
            // late costs in late submission points.
            'quarterly_updates_not_recorded_as_sent' => rescue(fn () => collect(PenaltyEstimator::all()['years'])->flatMap(fn ($y) => collect($y['submissions'] ?? [])
                ->filter(fn ($s) => $s['kind'] === 'quarterly' && $s['submitted_on'] === null)
                ->map(fn ($s) => ['tax_year' => $y['tax_year'], 'what' => $s['what'], 'due' => $s['due_on'], 'overdue' => $s['status'] === 'not_sent', 'effect' => $s['effect']]))->values()->all(), [], false),
            // Whether you're in it each year, as the penalties work it out: income, an exemption (from its year) and
            // volunteering all count.
            'making_tax_digital' => Setting::remembering(function () {
                $summaries = [];
                $summary = function (string $label) use (&$summaries) {
                    return $summaries[$label] ??= ReturnSummary::for($label);
                };

                return collect(config('tax.mtd_thresholds'))->map(fn ($t) => [
                    'starts' => $t['starts'], 'based_on' => $t['based_on'], 'threshold' => self::money($t['turnover_over']),
                    'your_turnover_confirmed' => self::money($summary($t['based_on'])['turnover_pence']),
                    'applies' => PenaltyRegime::mandated(UkTaxYear::fromDate($t['starts'])->label(), $summary),
                ])->all();
            }),
            'making_tax_digital_exemption' => PenaltyRegime::exemptionText(),
        ];
    }

    private function merchants(array $f): array
    {
        $label = UkTaxYear::fromLabel($f['tax_year'] ?? '')->label();
        $rows = OwnAccounts::scopeNotTransfers(BankTransaction::query())->where('tax_year', $label)
            ->when(($f['direction'] ?? 'out') === 'in', fn ($q) => $q->where('amount_pence', '>', 0), fn ($q) => $q->where('amount_pence', '<', 0))
            ->selectRaw("merchant_key, COUNT(*) n, SUM(amount_pence) total, SUM(CASE WHEN review_status = 'confirmed' THEN 1 ELSE 0 END) confirmed")
            ->groupBy('merchant_key')->orderByRaw('ABS(SUM(amount_pence)) DESC')->limit(40)->get();

        return ['tax_year' => $label, 'merchants' => $rows->map(fn ($r) => [
            'merchant' => $r->merchant_key, 'transactions' => (int) $r->n, 'total' => self::money((int) $r->total), 'confirmed' => (int) $r->confirmed,
        ])->all()];
    }

    private function rules(string $label): array
    {
        $label = UkTaxYear::fromLabel($label)->label();
        $p = TaxParameters::for($label);

        return $p ? ['tax_year' => $label, 'parameters_in_pence_or_percent' => $p, 'note' => 'Amounts are in pence; rates are percentages.'] : ['tax_year' => $label, 'error' => 'No rates loaded for this year.'];
    }

    private function propose(array $in): array
    {
        $tx = BankTransaction::query()->find((int) ($in['transaction_id'] ?? 0));
        if (! $tx) {
            return ['error' => 'No such transaction.'];
        }
        $categoryId = isset($in['category_code']) ? Category::query()->where('code', $in['category_code'])->value('id') : null;
        if (in_array($in['kind'], Allocation::NEEDS_CATEGORY, true) && ! $categoryId) {
            return ['error' => 'A business item needs a valid category_code. Codes: '.Category::query()->pluck('code')->implode(', ')];
        }
        $jobId = isset($in['job_id']) && WorkJob::query()->whereKey($in['job_id'])->exists() ? (int) $in['job_id'] : null;

        try {
            $saved = $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, $in['kind'], $categoryId, $jobId, (int) ($in['business_pct'] ?? 100)),
                'ai', false, mb_substr((string) $in['reason'], 0, 240));
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        return $saved
            ? ['ok' => true, 'note' => 'Saved as an unconfirmed AI suggestion; the owner confirms or changes it in the Inbox.']
            : ['ok' => false, 'note' => 'Not changed: the owner has already decided or confirmed this transaction.'];
    }

    private function penalties(array $in): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $label = ! empty($in['tax_year']) ? UkTaxYear::fromLabel((string) $in['tax_year'])->label() : null;
        $whatIf = array_filter([
            'filed_on' => $label && $date($in['file_on'] ?? null) ? [$label => $in['file_on']] : null,
            'pay_all_on' => $date($in['pay_all_on'] ?? null),
            'plan_from' => $date($in['plan_from'] ?? null),
        ]);
        $all = PenaltyEstimator::all(null, $whatIf);
        $years = $label ? array_values(array_filter($all['years'], fn ($y) => $y['tax_year'] === $label)) : $all['years'];
        $money = fn (int $p) => self::money($p);
        // Assumptions every year shares go once, at the top, so the answer stays well inside what the model is sent; so
        // do the interest method and the points' assumptions, which are given at the top anyway.
        $shared = count($years) > 1 ? array_values(array_intersect(...array_map(fn ($y) => $y['assumptions'], $years))) : [];
        $atTop = array_merge($shared, [PenaltyEstimator::interestNote()], $all['points']['law']['assumptions'] ?? [], $all['points']['announced']['assumptions'] ?? []);
        // Asked about every year, a year with nothing owed, coming or to do is just listed.
        $quiet = fn (array $y) => ! $label && $y['penalties'] === [] && $y['upcoming'] === [] && $y['at_risk'] === [] && $y['unpaid_pence'] === 0
            && $y['interest_pence'] === 0 && $y['actions'] === [] && ! $y['notify_hmrc'] && ! ($y['announced']['penalties'] ?? []) && ! ($y['announced']['upcoming'] ?? []);

        return [
            'as_of' => $all['as_of'], 'what_if' => $whatIf ?: null, 'estimate' => true,
            'interest_rate_now_pct' => PenaltyEstimator::currentRate(),
            // How interest is worked out (a convention, not law, so the figure is an estimate).
            'interest_method' => PenaltyEstimator::interestNote(),
            'totals' => array_map($money, $all['totals']),
            'assumptions_for_every_year' => $shared ?: null,
            // Late submission points are counted across years: once here, for the rules that are law and the announced ones.
            'late_submission_points' => array_filter(['law' => self::points($all['points']['law'] ?? null, $money), 'announced' => self::points($all['points']['announced'] ?? null, $money)]) ?: null,
            'help' => PenaltyEstimator::rules()['mitigation'],
            'note' => $label ? null : 'Every year, oldest first. Ask with tax_year for one year on its own.',
            'years' => array_map(fn ($y) => $quiet($y) ? [
                'tax_year' => $y['tax_year'], 'nothing_owed_or_coming' => true, 'filed' => $y['filed'], 'return_due' => $y['deadline'], 'tax_due' => $y['balancing_due'],
            ] : [
                'tax_year' => $y['tax_year'], 'rules' => $y['regime_text'], 'regime' => $y['regime'],
                'notice_to_file' => $y['notice']['assumed'] ? 'not known: assumed sent by 31 July after the year' : ($y['notice']['notice_to_file'] === 'not_given' ? 'none sent' : 'sent'.($y['notice']['notice_date'] ? ' on '.$y['notice']['notice_date'] : '')),
                'return_due' => $y['deadline'], 'return_due_basis' => $y['deadline_basis'], 'tax_due' => $y['balancing_due'],
                'filed' => $y['filed'], 'filed_on' => $y['filed_on'], 'own_return_last_date' => $y['own_return_last_date'], 'too_late_to_file' => $y['too_late_to_file'],
                'must_tell_hmrc' => $y['notify_hmrc'] ? 'by '.$y['notify_by'] : null,
                'tax_for_year' => $y['estimable'] ? $money($y['liability_pence']) : 'not worked out ('.($y['unestimable'] === 'scottish' ? 'Scottish income tax' : 'no rates loaded for the year').')',
                'tax_based_on' => $y['basis'], 'unpaid' => $money($y['unpaid_pence']),
                'already_due' => array_map(fn ($p) => self::penaltyItem($p, $money) + ['status' => $p['status']], $y['penalties']),
                'coming_if_nothing_changes' => array_map(fn ($p) => self::penaltyItem($p, $money), $y['upcoming']),
                'added_when_filed' => $y['on_filing_pence'] ? $money($y['on_filing_pence']) : null,
                'at_risk_if_hmrc_charge_them' => array_map(fn ($p) => self::penaltyItem($p, $money), $y['at_risk']),
                'payment_plan' => $y['plan'] ? array_filter([
                    'asked_hmrc_on' => $y['plan']['requested_on'], 'counts_from' => $y['plan']['counts_from'],
                    'agreed' => match ($y['plan']['agreed']) {
                        true => 'yes', false => 'no, HMRC refused it', default => 'not yet, or not known'
                    },
                    'ends_on' => $y['plan']['ends_on'], 'broken_on' => $y['plan']['broken_on'],
                    'covers' => $y['plan']['amount_pence'] !== null ? $money($y['plan']['amount_pence']) : 'everything owed for the year',
                    'held_off' => $y['plan']['state'] === 'protected' ? $money($y['plan']['protected_pence']) : null,
                    'cancelled_if_agreed' => $y['plan']['state'] === 'pending' ? $money($y['plan']['pending_pence']) : null,
                    'what_if' => $y['plan']['what_if'] ?: null, 'notes' => $y['plan']['notes'],
                ], fn ($v) => $v !== null) : null,
                'amendment' => $y['amendment'] ? array_filter([
                    'hmrc_got_it_on' => $y['amendment']['amended_on'] ?? 'not sent yet (assumed today)',
                    'tax_on_original_return' => $y['amendment']['original_liability_pence'] !== null ? $money($y['amendment']['original_liability_pence']) : 'not given',
                    'extra_tax' => $y['amendment']['increase_pence'] ? $money($y['amendment']['increase_pence']) : null,
                    'extra_tax_due' => $y['amendment']['increase_due'],
                ], fn ($v) => $v !== null) : null,
                'announced_new_rules' => $y['announced'] ? array_filter(['status' => 'announced, not law: an estimate only, not in the totals', 'what' => $y['announced']['regime_text'],
                    'would_be_due' => array_map(fn ($p) => self::penaltyItem($p, $money), $y['announced']['penalties']),
                    'would_be_coming' => array_map(fn ($p) => self::penaltyItem($p, $money), $y['announced']['upcoming']),
                    'late_submission_points' => ($y['announced']['points'] ?? null) ? 'see late_submission_points.announced at the top' : null], fn ($v) => $v !== null) : null,
                'late_submission_points' => ($y['points'] ?? null) ? 'see late_submission_points.law at the top' : null,
                'submissions' => array_map(fn ($s) => array_filter([
                    'what' => $s['what'], 'due' => $s['due_on'], 'sent' => $s['submitted_on'] ?? ($s['made'] ? 'filed, date not recorded' : 'not recorded: taken as not sent'),
                    'status' => $s['status'], 'excused' => $s['excused'] ?: null, 'effect' => $s['effect'], 'note' => $s['note'],
                    'under_announced_rules' => $s['announced'] ?: null,
                ], fn ($v) => $v !== null), $y['submissions'] ?? []) ?: null,
                'interest_so_far' => $money($y['interest_pence']), 'interest_per_day' => $money($y['per_day_pence']),
                'what_to_do' => $y['actions'], 'assumptions' => array_values(array_diff($y['assumptions'], $atTop)),
            ], $years),
        ];
    }

    /** Late submission points for the assistant: an estimate, resting on the assumptions listed. */
    private static function points(?array $p, \Closure $money): ?array
    {
        return $p === null ? null : [
            'summary' => $p['text'], 'points' => $p['held'], 'threshold' => $p['threshold'], 'at_threshold' => $p['at_threshold'],
            'each_point' => array_map(fn ($x) => array_filter(['for' => $x['what'], 'failed_on' => $x['failure_on'], 'expires_end_of' => $x['stays'] ? null : $x['expires_on'],
                'stays_until_all_go' => $x['stays'] ?: null, 'goes_on' => $x['goes_on'], 'added_on_joining_mtd' => $x['added'] ?: null], fn ($v) => $v !== null), $p['points']),
            'all_go_on_if_everything_sent_on_time' => $p['reset_on'], 'send_first' => $p['reset_needs'] ?: null,
            'penalties_200' => array_map(fn ($f) => ['on' => $f['on'], 'for' => $f['what'], 'amount' => $money($f['pence']), 'status' => $f['projected'] ? 'if nothing changes' : 'due'], $p['penalties']),
            'next_submission' => $p['next'], 'estimate' => true, 'assumptions' => $p['assumptions'],
        ];
    }

    /** One penalty for the assistant: what it is and why, with its estimate, assumption and time-limit notes. */
    private static function penaltyItem(array $p, \Closure $money): array
    {
        return array_filter([
            'date' => $p['date'], 'what' => $p['label'], 'amount' => $money($p['amount_pence']),
            'range' => $p['range'] ? ['min' => $money($p['range']['min_pence']), 'max' => $money($p['range']['max_pence'])] : null,
            'amount_not_worked_out' => ! empty($p['amount_unknown']) ?: null,
            'not_charged_until_return_filed' => ! empty($p['awaits_return']) ?: null,
            'on_filing' => $p['on_filing_pence'] ? $money($p['on_filing_pence']) : null,
            'estimate' => $p['estimate'] ?: null, 'assumption' => $p['assumption'], 'time_limit' => $p['time_limit'],
            'why' => $p['why'], 'source' => $p['source'],
        ], fn ($v) => $v !== null);
    }
}
