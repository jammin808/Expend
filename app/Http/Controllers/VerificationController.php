<?php

namespace App\Http\Controllers;

use App\Domain\Verification\Verifier;
use App\Models\BankTransaction;
use App\Models\CalendarJobLink;
use App\Models\Document;
use App\Models\JobCandidate;
use App\Models\TravelItem;
use App\Models\Verification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** "Re-check": double-check one AI finding against its source now. Scans and photos get a second, independent read. */
class VerificationController extends Controller
{
    public function store(string $type, int $id, Verifier $verifier): RedirectResponse
    {
        try {
            $v = match ($type) {
                'document' => $verifier->document(Document::query()->findOrFail($id), true),
                'travel_item' => $verifier->travelItem(TravelItem::query()->findOrFail($id), true),
                'job_candidate' => $verifier->candidate(JobCandidate::query()->findOrFail($id)),
                'calendar_link' => $verifier->calendarLink(CalendarJobLink::query()->findOrFail($id)),
                'transaction' => $verifier->transaction(BankTransaction::query()->findOrFail($id)),
                default => abort(404),
            };
        } catch (Throwable $e) {
            if ($e instanceof HttpExceptionInterface || $e instanceof ModelNotFoundException) {
                throw $e;
            }
            report($e);

            return back()->with('error', 'The check couldn’t be done: '.$e->getMessage());
        }

        return back()->with($v && in_array($v->status, Verification::WARN, true) ? 'error' : 'success', $v ? $v->summary : 'Nothing to check.');
    }
}
