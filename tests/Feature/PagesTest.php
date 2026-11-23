<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkJob;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every screen renders with realistic data. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_renders_with_demo_data(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('email', 'demo@example.com')->firstOrFail());

        foreach (['/dashboard', '/inbox', '/inbox?year=2024-25', '/years', '/years/2024-25', '/years/2025-26', '/transactions', '/transactions?year=2024-25&status=suggested',
            '/imports', '/accounts', '/jobs', '/jobs/finder', '/connections', '/connections?year=2024-25', '/jobs/'.WorkJob::query()->value('id'), '/mileage?year=2024-25', '/settings/ledger', '/settings/rules', '/settings/security', '/setup',
            '/documents', '/documents/'.Document::query()->value('id'), '/invoices', '/invoices/'.Invoice::query()->value('id'),
            '/assistant', '/settings/integrations', '/travel', '/travel?year=2024-25'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/documents/'.Document::query()->where('kind', 'po')->value('id'))->assertInertia(fn ($page) => $page->has('document.children')->has('document.facts', 8));
        $this->get('/documents/'.Document::query()->where('kind', 'po')->value('id'))
            ->assertInertia(fn ($page) => $page->has('document.children', 0)->has('document.facts', 8)->etc());
        $this->get('/years/2024-25/export')->assertOk()->assertDownload('expense-ledger-2024-25.xlsx');
        $this->getJson('/items/bank/'.BankTransaction::query()->value('id'))->assertOk()->assertJsonStructure(['item', 'history', 'events', 'jobs_near']);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
