<?php

namespace App\Http\Controllers;

use App\Domain\Ai\AiUsage;
use App\Domain\Ai\Assistant;
use App\Domain\Ai\TransactionAdvisor;
use App\Domain\Documents\AiConfig;
use App\Models\AssistantLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AssistantController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('assistant', [
            'history' => AssistantLog::query()->where('channel', 'app')->latest()->limit(20)->get()->reverse()->values()
                ->map(fn ($l) => ['id' => $l->id, 'question' => $l->question, 'answer' => $l->answer, 'unmatched' => $l->unmatched_figures ?? [], 'notes' => $l->notes ?? [], 'tools' => collect($l->tool_calls)->pluck('tool')->unique()->values()]),
            'aiReady' => AiConfig::ready(),
            'usage' => AiUsage::thisMonth(),
        ]);
    }

    public function ask(Request $request, Assistant $assistant): JsonResponse
    {
        $data = $request->validate(['question' => 'required|string|max:4000']);
        $history = AssistantLog::query()->where('channel', 'app')->latest()->limit(4)->get()->reverse()
            ->map(fn ($l) => ['question' => $l->question, 'answer' => (string) $l->answer])->values()->all();

        try {
            $result = $assistant->ask($data['question'], 'app', $history);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'answer' => $result['answer'], 'unmatched' => $result['unmatched'], 'notes' => $result['notes'], 'tools' => collect($result['tools'])->pluck('tool')->unique()->values(), 'id' => $result['log_id']]);
    }

    public function suggest(Request $request, TransactionAdvisor $advisor): RedirectResponse
    {
        $data = $request->validate(['tax_year' => 'nullable|string|max:7']);
        try {
            $result = $advisor->suggest($data['tax_year'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "AI suggested {$result['suggested']}".($result['skipped'] ? ", left {$result['skipped']} it wasn’t sure about" : '').'. They’re in your Inbox to accept or change.');
    }
}
