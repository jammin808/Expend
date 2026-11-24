<?php

namespace App\Http\Controllers;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ImportProfile;
use App\Domain\Import\ProfileDetector;
use App\Domain\Import\RevolutImport;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ImportController extends Controller
{
    public function __construct(private Importer $importer) {}

    public function index(Request $request): Response
    {
        $pending = $request->session()->get('import');
        $preview = null;

        if ($pending && is_file($pending['path']) && ($pending['revolut'] ?? null)) {
            $account = Account::query()->find($pending['account_id']);
            try {
                $read = app(RevolutImport::class)->read($account, $pending['path']);
                $all = collect($read['pockets'])->flatMap(fn ($p) => $p['rows']);
                $dates = $all->map(fn ($r) => $r->effectiveDate()->format('Y-m-d'))->sort()->values();
                $failed = collect($read['pockets'])->first(fn ($p) => $p['balance']['status'] === 'failed');
                $preview = ['ok' => true, 'new' => collect($read['pockets'])->sum('new'), 'duplicates' => collect($read['pockets'])->sum('duplicates'),
                    'errors' => array_slice($read['errors'], 0, 20), 'error_count' => count($read['errors']), 'rows' => $all->count(),
                    'balance' => $failed ? ['status' => 'failed', 'notes' => $failed['label'].': '.$failed['balance']['notes']]
                        : (collect($read['pockets'])->every(fn ($p) => $p['balance']['status'] === 'passed') ? ['status' => 'passed', 'notes' => 'Each currency’s running balance adds up'] : ['status' => 'not_available', 'notes' => null]),
                    'first' => $dates->first(), 'last' => $dates->last(),
                    'pockets' => collect($read['pockets'])->map(fn ($p) => ['label' => $p['label'], 'currency' => $p['currency'], 'name' => $p['name'], 'exists' => (bool) $p['account'],
                        'new' => $p['new'], 'duplicates' => $p['duplicates'], 'balance' => $p['balance']['status'], 'first' => $p['first'], 'last' => $p['last']])->values(),
                    'skipped' => $read['skipped'], 'exchanges' => $read['exchanges'],
                    'sample' => $all->take(12)->map(fn ($r) => ['date' => $r->effectiveDate()->format('Y-m-d'), 'description' => $r->description, 'amount_pence' => $r->amountPence,
                        'balance_pence' => $r->statementBalance(), 'currency' => $r->originalCurrency, 'original_minor' => $r->originalAmountMinor, 'duplicate' => false])->values()];
            } catch (Throwable $e) {
                $preview = ['ok' => false, 'message' => $e->getMessage()];
            }
            $preview += ['filename' => $pending['filename'], 'account' => $account->only(['id', 'name', 'kind']), 'headers' => CsvReader::read($pending['path'])['headers'],
                'profile' => ['preset' => 'revolut_'.$pending['revolut']]];
        } elseif ($pending && is_file($pending['path'])) {
            $account = Account::query()->find($pending['account_id']);
            $headers = CsvReader::read($pending['path'])['headers'];
            $profile = ImportProfile::fromArray($pending['profile']);
            try {
                $result = $this->importer->preview($account, $pending['path'], $profile);
                $preview = [
                    'ok' => true, 'new' => $result['new'], 'duplicates' => $result['duplicates'], 'errors' => array_slice($result['errors'], 0, 20),
                    'error_count' => count($result['errors']), 'balance' => $result['balance'], 'first' => $result['first'], 'last' => $result['last'],
                    'sample' => $result['sample'], 'rows' => count($result['rows']),
                ];
            } catch (Throwable $e) {
                $preview = ['ok' => false, 'message' => $e->getMessage()];
            }
            $preview += ['filename' => $pending['filename'], 'account' => $account->only(['id', 'name', 'kind']), 'headers' => $headers, 'profile' => $profile->toArray()];
        }

        return Inertia::render('imports/index', [
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name', 'kind', 'provider']),
            'batches' => ImportBatch::query()->with('account')->latest()->limit(50)->get()->map(fn (ImportBatch $b) => [
                'id' => $b->id, 'account' => $b->account->name, 'file' => $b->original_filename, 'imported' => $b->imported_count,
                'duplicates' => $b->duplicate_count, 'first' => $b->first_date?->format('Y-m-d'), 'last' => $b->last_date?->format('Y-m-d'),
                'balance_check' => $b->balance_check, 'balance_notes' => $b->balance_check_notes, 'status' => $b->status,
                'review_note' => $b->review_note, 'at' => $b->created_at->format('Y-m-d H:i'),
                // The first rows of a held statement, so you can see what you'd be accepting.
                'held_rows' => $b->status === 'quarantined'
                    ? BankTransaction::withoutGlobalScope('active')->where('import_batch_id', $b->id)->orderBy('id')->limit(15)
                        ->get(['posted_on', 'description', 'amount_pence', 'balance_after_pence'])
                        ->map(fn ($t) => ['date' => $t->posted_on->format('Y-m-d'), 'description' => $t->description, 'amount_pence' => $t->amount_pence, 'balance_pence' => $t->balance_after_pence])
                    : [],
            ]),
            'preview' => $preview,
            'dateFormats' => ImportProfile::DATE_FORMATS,
        ]);
    }

    /** Upload a file (or change how it's read) and show what importing would do. */
    public function preview(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => 'nullable|file|mimes:csv,txt|max:20480',
            'account_id' => 'required|integer|exists:accounts,id',
            'profile' => 'nullable|array',
        ]);
        $account = Account::query()->findOrFail($data['account_id']);
        $pending = $request->session()->get('import');

        if ($request->hasFile('file')) {
            $dir = storage_path('app/private/uploads');
            @mkdir($dir, 0700, true);
            $path = $dir.'/'.Str::uuid().'.csv';
            $request->file('file')->move($dir, basename($path));
            $headers = CsvReader::read($path)['headers'];
            if ($revolut = RevolutImport::detect($headers)) {
                // Revolut files can hold several currencies; each goes into its own pocket of this account.
                $pending = ['path' => $path, 'filename' => $request->file('file')->getClientOriginalName(), 'account_id' => $account->id, 'revolut' => $revolut, 'profile' => null];
            } else {
                $saved = $account->import_profile && isset($account->import_profile['dateColumn']) ? ImportProfile::fromArray($account->import_profile) : null;
                $profile = $saved && ! $saved->problems($headers) ? $saved : ProfileDetector::detect($headers, $account->kind);
                $pending = ['path' => $path, 'filename' => $request->file('file')->getClientOriginalName(), 'account_id' => $account->id, 'profile' => $profile->toArray()];
            }
        } elseif ($pending) {
            $pending['account_id'] = $account->id;
            if (! empty($data['profile'])) {
                $pending['profile'] = ImportProfile::fromArray($data['profile'])->toArray();
            }
        } else {
            return back()->with('error', 'Choose a CSV file to import.');
        }

        $request->session()->put('import', $pending);

        return to_route('imports.index');
    }

    public function commit(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('import');
        if (! $pending || ! is_file($pending['path'])) {
            return to_route('imports.index')->with('error', 'Upload the file again.');
        }

        if ($pending['revolut'] ?? null) {
            try {
                $batches = app(RevolutImport::class)->commit(Account::query()->findOrFail($pending['account_id']), $pending['path'], $pending['filename']);
            } catch (Throwable $e) {
                return to_route('imports.index')->with('error', $e->getMessage());
            }
            @unlink($pending['path']);
            $request->session()->forget('import');
            $held = collect($batches)->where('status', 'quarantined');
            $parts = collect($batches)->where('status', '!=', 'quarantined')->map(fn ($b) => $b->imported_count.' into '.$b->account->name)->implode(', ');

            return to_route('imports.index')->with($held->isNotEmpty() ? 'error' : 'success', ($parts ? "Imported {$parts}." : 'Nothing new.')
                .($held->isNotEmpty() ? ' Held for review: '.$held->map(fn ($b) => $b->account->name)->implode(', ').' (the running balance doesn’t add up).' : ''));
        }

        try {
            $batch = $this->importer->commit(Account::query()->findOrFail($pending['account_id']), $pending['path'], $pending['filename'], ImportProfile::fromArray($pending['profile']));
        } catch (Throwable $e) {
            return to_route('imports.index')->with('error', $e->getMessage());
        }

        @unlink($pending['path']);
        $request->session()->forget('import');

        if ($batch->status === 'quarantined') {
            return to_route('imports.index')->with('error', "Held for review, not imported: {$batch->balance_check_notes} Check the statement below, then accept it with a reason or discard it.");
        }
        $message = "Imported {$batch->imported_count} transactions".($batch->duplicate_count ? ", skipped {$batch->duplicate_count} already imported" : '').'.';

        return to_route('imports.index')->with('success', $message);
    }

    public function cancel(Request $request): RedirectResponse
    {
        if ($pending = $request->session()->pull('import')) {
            @unlink($pending['path']);
        }

        return to_route('imports.index');
    }

    public function revert(Request $request, ImportBatch $batch): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:250']);
        try {
            $this->importer->revert($batch, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Undone: the {$batch->imported_count} transactions from {$batch->original_filename} no longer count. They're kept, and the import can be restored.");
    }

    public function accept(Request $request, ImportBatch $batch): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:250']);
        try {
            $count = $this->importer->accept($batch, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Accepted: {$count} transactions imported.");
    }

    public function discard(Request $request, ImportBatch $batch): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:250']);
        try {
            $this->importer->discard($batch, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Discarded. Nothing from it counts; the rows are kept for the record.');
    }

    public function restore(Request $request, ImportBatch $batch): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:250']);
        try {
            $count = $this->importer->restore($batch, $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Restored {$count} transactions, with your decisions on them.");
    }
}
