<?php

namespace Cesa\Rekrutmen\Tests\Feature;

use Cesa\Rekrutmen\Models\RekrutmenPipeline;
use Cesa\Rekrutmen\Models\RekrutmenStage;
use Cesa\Rekrutmen\Tests\RekrutmenTestCase;
use Webkul\Security\Models\User;

class PipelineStageReorderTest extends RekrutmenTestCase
{
    public function test_can_reorder_stages_via_spa_api(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $pipeline = RekrutmenPipeline::query()->firstOrCreate(['id' => 1], ['name' => 'Standard Pipeline']);

        $stageA = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Stage Alpha',
            'order_column'          => 1,
        ]);

        $stageB = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Stage Beta',
            'order_column'          => 2,
        ]);

        $stageC = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Stage Gamma',
            'order_column'          => 3,
        ]);

        $stageHired = RekrutmenStage::query()->create([
            'rekrutmen_pipeline_id' => $pipeline->id,
            'name'                  => 'Hired',
            'order_column'          => 4,
        ]);

        // Reorder Beta -> Gamma -> Alpha
        $response = $this->postJson('/rekrutmen/api/stages/reorder', [
            'stage_ids' => [$stageB->id, $stageC->id, $stageA->id],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));

        $this->assertSame(1, (int) $stageB->fresh()->order_column);
        $this->assertSame(2, (int) $stageC->fresh()->order_column);
        $this->assertSame(3, (int) $stageA->fresh()->order_column);
        $this->assertSame(4, (int) $stageHired->fresh()->order_column);
    }

    public function test_reorder_stages_validates_stage_ids(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $response = $this->postJson('/rekrutmen/api/stages/reorder', [
            'stage_ids' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['stage_ids']);
    }
}
