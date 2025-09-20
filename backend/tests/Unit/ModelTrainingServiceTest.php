<?php

namespace Tests\Unit;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelTrainingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_train_builds_model_and_metrics_from_dataset(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/unit-test.csv',
            'mime_type' => 'text/csv',
        ]);

        Storage::disk('local')->put($dataset->file_path, $this->datasetCsv());

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => ModelStatus::Draft,
            'metadata' => [],
            'metrics' => null,
            'hyperparameters' => null,
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $service = app(ModelTrainingService::class);

        $result = $service->train($run, $model, [
            'learning_rate' => 0.25,
            'iterations' => 800,
            'validation_split' => 0.2,
        ]);

        $this->assertSame(['artifact_path'], array_keys($result['metadata']));
        $this->assertTrue(Storage::disk('local')->exists($result['artifact_path']));

        $this->assertEquals(1.0, $result['metrics']['accuracy']);
        $this->assertEquals(1.0, $result['metrics']['precision']);
        $this->assertEquals(1.0, $result['metrics']['recall']);
        $this->assertEquals(1.0, $result['metrics']['f1']);

        $this->assertNotEmpty($result['version']);
        $this->assertSame(0.25, $result['hyperparameters']['learning_rate']);
        $this->assertSame(800, $result['hyperparameters']['iterations']);
        $this->assertSame(0.2, $result['hyperparameters']['validation_split']);
    }

    public function test_train_throws_when_dataset_file_missing(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/missing.csv',
        ]);

        $model = PredictiveModel::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => ModelStatus::Draft,
            'metadata' => [],
        ]);

        $run = TrainingRun::query()->create([
            'model_id' => $model->id,
            'status' => TrainingStatus::Queued,
            'queued_at' => now(),
        ]);

        $this->expectExceptionMessage('Dataset file "datasets/missing.csv" was not found.');

        $service = app(ModelTrainingService::class);
        $service->train($run, $model);
    }

    private function datasetCsv(): string
    {
        return implode("\n", [
            'timestamp,latitude,longitude,category,risk_score,label',
            '2024-01-01T00:00:00Z,40.0,-73.9,burglary,0.10,0',
            '2024-01-02T00:00:00Z,40.0,-73.9,burglary,0.12,0',
            '2024-01-03T00:00:00Z,40.0,-73.9,burglary,0.14,0',
            '2024-01-04T00:00:00Z,40.0,-73.9,burglary,0.18,0',
            '2024-01-05T00:00:00Z,40.0,-73.9,assault,0.72,1',
            '2024-01-06T00:00:00Z,40.0,-73.9,assault,0.74,1',
            '2024-01-07T00:00:00Z,40.0,-73.9,assault,0.78,1',
            '2024-01-08T00:00:00Z,40.0,-73.9,assault,0.82,1',
            '2024-01-09T00:00:00Z,40.0,-73.9,burglary,0.28,0',
            '2024-01-10T00:00:00Z,40.0,-73.9,assault,0.88,1',
            '',
        ]);
    }
}
