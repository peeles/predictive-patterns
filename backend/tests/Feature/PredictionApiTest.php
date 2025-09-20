<?php

namespace Tests\Feature;

use App\Enums\Role;
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
        $tokens = $this->issueTokensForRole(Role::Admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$tokens['accessToken'])->postJson('/api/v1/predictions', [
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
