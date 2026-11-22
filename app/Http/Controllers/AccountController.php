<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Models\Account;
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
            ]),
        ]);
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
            'provider' => 'nullable|in:starling,amex,capital_one,other',
            'last4' => 'nullable|digits:4',
            'is_active' => 'boolean',
        ]);
    }
}
