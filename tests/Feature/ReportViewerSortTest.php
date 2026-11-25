<?php

namespace Tests\Feature;

use App\Livewire\Reports\ReportViewer;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * sort() is a public, directly Livewire-callable method, not just a UI sort
 * arrow — $column used to flow straight into orderBy() with no check that
 * it's a real, sortable column. Laravel's query grammar backtick-quotes the
 * identifier so this was never SQL-injectable, but a bogus column still
 * threw an uncaught QueryException (a 500, potentially leaking schema
 * details in debug mode) for nothing more than a crafted or typo'd call.
 */
class ReportViewerSortTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_sort_ignores_an_unknown_column(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'product_performance')
            ->call('sort', 'nonexistent_column; DROP TABLE users')
            ->assertSet('sortBy', 'total_revenue')
            ->assertHasNoErrors();
    }

    public function test_sort_accepts_a_real_sortable_column(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'product_performance')
            ->call('sort', 'total_qty_sold')
            ->assertSet('sortBy', 'total_qty_sold')
            ->assertSet('sortDirection', 'desc');
    }

    public function test_sort_ignores_a_column_that_belongs_to_a_different_report(): void
    {
        $admin = User::factory()->create();

        // qty_on_hand is a real column, just not one of product_performance's
        // own sortable ones — it must not be accepted just because it's a
        // real identifier somewhere in the schema.
        Livewire::actingAs($admin)->test(ReportViewer::class)
            ->call('selectReport', 'product_performance')
            ->call('sort', 'qty_on_hand')
            ->assertSet('sortBy', 'total_revenue');
    }
}
