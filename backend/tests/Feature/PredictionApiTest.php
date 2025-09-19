<?php

namespace Tests\Feature;

use App\Jobs\GenerateHeatmapJob;
use App\Models\PredictiveModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PredictionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_prediction_request_dispatches_job(): void
    {
        Bus::fake();

        $model = PredictiveModel::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer test-token')->postJson('/api/v1/predictions', [
            'model_id' => $model->id,
            'dataset_id' => $model->dataset_id,
            'parameters' => ['horizon_days' => 7],
            'generate_tiles' => true,
        ]);

        $response->assertAccepted();

        Bus::assertDispatched(GenerateHeatmapJob::class);

        $this->assertDatabaseHas('predictions', [
            'model_id' => $model->id,
            'status' => 'queued',
        ]);
    }
}
