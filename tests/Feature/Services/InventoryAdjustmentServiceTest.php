<?php

namespace Tests\Feature\Services;

use App\Models\Batch;
use App\Models\User;
use App\Services\InventoryAdjustmentService;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class InventoryAdjustmentServiceTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private InventoryAdjustmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InventoryAdjustmentService::class);
    }

    public function test_correction_add_increases_the_batch_quantity(): void
    {
        $batch = Batch::factory()->remaining(10)->create();
        $user = User::factory()->create();

        $this->service->adjust($batch, InventoryAdjustmentService::TYPE_CORRECTION_ADD, 5, 'recount found extra stock', $user);

        $this->assertEquals(15, $batch->fresh()->qty_remaining);
        $this->assertDatabaseHas('inventory_movements', [
            'batch_id' => $batch->id,
            'movement_type' => 'adjustment',
            'quantity' => 5,
        ]);
    }

    public function test_correction_remove_decreases_the_batch_quantity(): void
    {
        $batch = Batch::factory()->remaining(10)->create();
        $user = User::factory()->create();

        $this->service->adjust($batch, InventoryAdjustmentService::TYPE_CORRECTION_REMOVE, 4, 'recount found shortfall', $user);

        $this->assertEquals(6, $batch->fresh()->qty_remaining);
        $this->assertDatabaseHas('inventory_movements', [
            'batch_id' => $batch->id,
            'movement_type' => 'adjustment',
            'quantity' => -4,
        ]);
    }

    public function test_damaged_records_its_own_movement_type(): void
    {
        $batch = Batch::factory()->remaining(10)->create();
        $user = User::factory()->create();

        $this->service->adjust($batch, InventoryAdjustmentService::TYPE_DAMAGED, 3, 'dropped a case', $user);

        $this->assertEquals(7, $batch->fresh()->qty_remaining);
        $this->assertDatabaseHas('inventory_movements', [
            'batch_id' => $batch->id,
            'movement_type' => 'damaged',
            'quantity' => -3,
        ]);
    }

    public function test_expired_records_its_own_movement_type(): void
    {
        $batch = Batch::factory()->remaining(10)->create();
        $user = User::factory()->create();

        $this->service->adjust($batch, InventoryAdjustmentService::TYPE_EXPIRED, 2, 'past expiry, discarded', $user);

        $this->assertEquals(8, $batch->fresh()->qty_remaining);
        $this->assertDatabaseHas('inventory_movements', [
            'batch_id' => $batch->id,
            'movement_type' => 'expired',
            'quantity' => -2,
        ]);
    }

    public function test_cannot_adjust_a_batch_below_zero(): void
    {
        $batch = Batch::factory()->remaining(5)->create();
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('below zero');

        $this->service->adjust($batch, InventoryAdjustmentService::TYPE_CORRECTION_REMOVE, 10, 'too much', $user);

        $this->assertEquals(5, $batch->fresh()->qty_remaining);
    }

    public function test_adjustment_writes_an_audit_log_entry(): void
    {
        $batch = Batch::factory()->remaining(10)->create();
        $user = User::factory()->create();

        $this->service->adjust($batch, InventoryAdjustmentService::TYPE_CORRECTION_ADD, 5, 'restock correction', $user);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'inventory',
            'record_type' => 'Batch',
            'record_id' => $batch->id,
            'action' => 'adjustment',
        ]);
    }
}
