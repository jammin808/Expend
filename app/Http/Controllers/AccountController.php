<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Ledger\Reconciler;
use App\Models\Account;
use App\Models\StatementBalance;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('accounts/index', [
            'accounts' => Account::query()->withCount('transactions')->orderBy('name')->get()->map(fn (Account $a) => [
                'id' => $a->id, 'name' => $a->name, 'kind' => $a->kind, 'provider' => $a->provider, 'last4' => $a->last4,
                'is_active' => $a->is_active, 'transactions' => $a->transactions_count, 'preset' => $a->import_profile['preset'] ?? null,
                'currency' => strtoupper($a->currency ?: 'GBP'), 'parent' => $a->parent?->name,
                'reconcile' => Reconciler::account($a),
                'balances' => StatementBalance::query()->where('account_id', $a->id)->orderByDesc('as_of')->limit(24)->get()
                    ->map(fn (StatementBalance $b) => ['id' => $b->id, 'as_of' => $b->as_of->format('Y-m-d'), 'balance_pence' => $b->balance_pence, 'note' => $b->note]),
            ]),
        ]);
    }

    /** A balance copied from a statement, to check the account against. */
    public function addBalance(Request $request, Account $account): RedirectResponse
    {
        $data = $request->validate(['as_of' => 'required|date|before_or_equal:today', 'balance' => 'required|string|max:20', 'note' => 'nullable|string|max:200']);
        $pence = Money::parse($data['balance']);
        if ($pence === null) {
            return back()->withErrors(['balance' => 'Enter the balance as it appears on the statement, like 1,234.56.']);
        }
        $row = StatementBalance::query()->updateOrCreate(['account_id' => $account->id, 'as_of' => $data['as_of']], ['balance_pence' => $pence, 'note' => $data['note'] ?? null]);
        AuditLog::record('account.balance_added', $account, null, $row->only(['as_of', 'balance_pence', 'note']));
        $check = Reconciler::account($account->fresh());

        return back()->with($check['gaps'] ? 'error' : 'success', 'Saved. '.$check['text']);
    }

    public function removeBalance(Account $account, StatementBalance $balance): RedirectResponse
    {
        abort_unless($balance->account_id === $account->id, 404);
        AuditLog::record('account.balance_removed', $account, $balance->only(['as_of', 'balance_pence', 'note']), null);
        $balance->delete();

        return back()->with('success', 'Removed.');
    }

    public function store(Request $request): RedirectResponse
    {
        $account = Account::query()->create($this->validated($request));
        AuditLog::record('account.created', $account, null, $account->toArray());

        return back()->with('success', "Added {$account->name}.");
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $before = $account->toArray();
        $account->update($this->validated($request));
        AuditLog::record('account.updated', $account, $before, $account->fresh()->toArray());

        return back()->with('success', 'Saved.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:80',
            'kind' => 'required|in:bank,credit_card',
            'provider' => 'nullable|in:starling,amex,capital_one,revolut,monzo,wise,paypal,other',
            'last4' => 'nullable|digits:4',
            'is_active' => 'boolean',
        ]);
    }
}
