<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** One box for everything: payments, jobs, documents, invoices and stays, by name, amount, PO number or reference. */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $amount = self::amount($q);
        $out = [];

        $jobs = WorkJob::query()->with('client')
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('location', 'like', $like)->orWhere('postcode', 'like', $like)
                ->orWhere('po_number', 'like', $like)->orWhereHas('client', fn ($c) => $c->where('name', 'like', $like)))
            ->orderByDesc('starts_on')->limit(6)->get();
        foreach ($jobs as $j) {
            $out[] = ['type' => 'Job', 'label' => $j->title, 'sub' => $j->starts_on->format('j M Y').($j->client ? ' · '.$j->client->name : '').($j->po_number ? ' · PO '.$j->po_number : ''), 'href' => '/jobs/'.$j->id];
        }

        $txs = BankTransaction::query()->with('account')
            ->where(fn ($w) => $w->where('description', 'like', $like)->orWhere('counterparty', 'like', $like)
                ->when($amount, fn ($w) => $w->orWhere('amount_pence', $amount)->orWhere('amount_pence', -$amount)))
            ->orderByDesc('posted_on')->limit(8)->get();
        foreach ($txs as $t) {
            $out[] = ['type' => 'Payment', 'label' => $t->counterparty ?: $t->description, 'sub' => $t->effectiveDate()->format('j M Y').' · '.Money::format($t->amount_pence).' · '.$t->account?->name,
                'href' => '/transactions?year='.$t->tax_year.'&q='.urlencode($t->counterparty ?: $t->description)];
        }

        $invoices = Invoice::query()
            ->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('po_number', 'like', $like)->orWhere('bill_to_name', 'like', $like)
                ->when($amount, fn ($w) => $w->orWhere('total_minor', $amount)))
            ->latest('id')->limit(5)->get();
        foreach ($invoices as $i) {
            $out[] = ['type' => 'Invoice', 'label' => $i->number ?? 'Draft invoice', 'sub' => ($i->bill_to_name ?? '').' · '.Money::format($i->total_minor, $i->currency, false).($i->po_number ? ' · PO '.$i->po_number : '').' · '.$i->status, 'href' => '/invoices/'.$i->id];
        }

        $docs = Document::query()->whereNull('parent_id')
            ->where(fn ($w) => $w->where('email_subject', 'like', $like)->orWhere('original_name', 'like', $like)->orWhere('summary', 'like', $like)
                ->orWhere('email_from', 'like', $like)->orWhereHas('facts', fn ($f) => $f->where('value', 'like', $like))
                ->orWhereHas('children', fn ($c) => $c->where('original_name', 'like', $like)->orWhereHas('facts', fn ($f) => $f->where('value', 'like', $like))))
            ->latest('received_at')->limit(6)->get();
        foreach ($docs as $d) {
            $out[] = ['type' => 'Document', 'label' => $d->email_subject ?: $d->original_name, 'sub' => ($d->received_at?->format('j M Y') ?? '').' · '.(Document::KINDS[$d->kind] ?? $d->kind), 'href' => '/documents/'.$d->id];
        }

        $stays = TravelItem::query()
            ->where(fn ($w) => $w->where('provider', 'like', $like)->orWhere('place', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('destination', 'like', $like)
                ->when($amount, fn ($w) => $w->orWhere('amount_minor', $amount)))
            ->orderByDesc('starts_on')->limit(5)->get();
        foreach ($stays as $s) {
            $out[] = ['type' => 'Stay or journey', 'label' => $s->provider.($s->place ? ', '.$s->place : ''), 'sub' => $s->starts_on->format('j M Y').($s->reference ? ' · ref '.$s->reference : '').($s->amount_minor ? ' · '.Money::format($s->amount_minor, $s->currency, false) : ''),
                'href' => '/travel?year='.UkTaxYear::fromDate($s->starts_on)->label()];
        }

        return response()->json(['results' => $out]);
    }

    /**
     * The amount in pence when the search looks like one, with or without '£', a sign, or thousands commas, the way
     * the app shows amounts ("−£1,200.00", "1,200", "1200", "56.98"). A comma before one or two final digits is read
     * as a decimal point ("56,98").
     */
    private static function amount(string $q): ?int
    {
        $s = preg_replace('/^[-−+]|£|\s/u', '', $q) ?? '';
        if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/', $s)) {
            $s = str_replace(',', '', $s);
        }
        if (! preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $s, $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
