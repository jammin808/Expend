<?php

namespace App\Http\Controllers;

use App\Domain\Ledger\RuleLearner;
use App\Domain\Todo\TodoList;
use App\Models\MerchantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RuleLearningController extends Controller
{
    public function accept(Request $request, RuleLearner $learner): RedirectResponse
    {
        $key = $request->validate(['merchant_key' => 'required|string|max:120'])['merchant_key'];
        try {
            ['rule' => $rule, 'applied' => $applied, 'client' => $client, 'message' => $message] = $learner->accept($key);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        TodoList::forget();

        // Turnover from a payer with one client is filled in for you to accept, never confirmed on its own: say so.
        return back()->with('success', $client !== null ? $message : 'Done: it’ll happen automatically from now on'.($applied ? ", and it’s applied to {$applied} waiting" : '').'.')
            ->with('rule_undo', ['id' => $rule->id, 'applied' => $applied]);
    }

    public function decline(Request $request, RuleLearner $learner): RedirectResponse
    {
        $learner->decline($request->validate(['merchant_key' => 'required|string|max:120'])['merchant_key']);
        TodoList::forget();

        return back()->with('success', 'OK, it won’t ask about that one again.');
    }

    public function undo(MerchantRule $rule, RuleLearner $learner): RedirectResponse
    {
        $n = $learner->undo($rule);
        TodoList::forget();

        return back()->with('success', 'Rule removed'.($n ? " and {$n} ".($n === 1 ? 'payment' : 'payments').' put back in the Inbox' : '').'.');
    }
}
