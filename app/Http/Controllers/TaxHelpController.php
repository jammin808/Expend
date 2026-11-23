<?php

namespace App\Http\Controllers;

use App\Domain\Tax\Explainer;
use App\Domain\Tax\TaxGuide;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ManualExpense;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The "?" next to payments and categories, and the tax guide page. */
class TaxHelpController extends Controller
{
    public function payment(string $source, int $id): JsonResponse
    {
        $item = $source === 'manual' ? ManualExpense::query()->findOrFail($id) : BankTransaction::query()->with('account')->findOrFail($id);

        return response()->json(Explainer::payment($item));
    }

    public function category(Category $category): JsonResponse
    {
        return response()->json(Explainer::category($category));
    }

    public function guide(): Response
    {
        $data = TaxGuide::data();

        return Inertia::render('tax-guide', [
            'researched_on' => TaxGuide::researchedOn(),
            'ways' => TaxGuide::ways(),
            'topics' => collect($data['topics'] ?? [])->map(fn ($t, $slug) => ['slug' => $slug] + $t)->values(),
            'categories' => collect($data['categories'] ?? [])->map(fn ($c, $code) => ['code' => $code] + $c)->values(),
        ]);
    }
}
