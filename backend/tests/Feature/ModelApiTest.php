<?php

namespace Tests\Feature;

use App\Jobs\EvaluateModelJob;
use App\Jobs\TrainModelJob;
use App\Models\PredictiveModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ModelApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_request_dispatches_job(): void
    {
        Bus::fake();

        $model = PredictiveModel::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer test-token')->postJson('/api/v1/models/train', [
            'model_id' => $model->id,
            'hyperparameters' => ['learning_rate' => 0.2],
        ]);

        $response->assertAccepted();

        Bus::assertDispatched(TrainModelJob::class);

        $this->assertDatabaseHas('training_runs', [
            'model_id' => $model->id,
            'status' => 'queued',
        ]);
    }

    public function test_evaluation_request_dispatches_job(): void
    {
        Bus::fake();

        $model = PredictiveModel::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer test-token')->postJson("/api/v1/models/{$model->id}/evaluate", [
            'dataset_id' => $model->dataset_id,
            'metrics' => ['f1' => 0.82],
            'notes' => 'Smoke test',
        ]);

        $response->assertAccepted();

        Bus::assertDispatched(EvaluateModelJob::class);
    }
}
