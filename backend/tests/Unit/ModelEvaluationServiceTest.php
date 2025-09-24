<?php

namespace Tests\Unit;

use App\Enums\ModelStatus;
use App\Enums\TrainingStatus;
use App\Models\Dataset;
use App\Models\PredictiveModel;
use App\Models\TrainingRun;
use App\Services\ModelEvaluationService;
use App\Services\ModelTrainingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluate_computes_metrics_from_dataset_artifact(): void
    {
        Storage::fake('local');

        $dataset = Dataset::factory()->create([
            'source_type' => 'file',
            'file_path' => 'datasets/evaluation.csv',
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

        $trainingService = app(ModelTrainingService::class);
        $trainingResult = $trainingService->train($run, $model);

        $model->update([
            'metadata' => array_merge($model->metadata ?? [], ['artifact_path' => $trainingResult['artifact_path']]),
        ]);

        $evaluationService = app(ModelEvaluationService::class);
        $metrics = $evaluationService->evaluate($model->fresh(), $dataset);

        $this->assertEquals(['accuracy', 'precision', 'recall', 'f1'], array_keys($metrics));
        $this->assertEquals(1.0, $metrics['accuracy']);
        $this->assertEquals(1.0, $metrics['precision']);
        $this->assertEquals(1.0, $metrics['recall']);
        $this->assertEquals(1.0, $metrics['f1']);
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
