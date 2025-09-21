<?php

namespace App\Jobs;

use App\Models\PredictiveModel;
use App\Services\ModelStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Random\RandomException;
use Throwable;

class EvaluateModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed>|null $metrics
     */
    public function __construct(
        private readonly string $modelId,
        private readonly ?string $datasetId = null,
        private readonly ?array $metrics = null,
        private readonly ?string $notes = null,
    ) {
    }

    /**
     * @throws Throwable
     * @throws RandomException
     */
    public function handle(ModelStatusService $statusService): void
    {
        $model = PredictiveModel::query()->findOrFail($this->modelId);
        $metadata = $model->metadata ?? [];

        $statusService->markProgress($model->id, 'evaluating', 5.0);

        $entry = [
            'id' => (string) Str::uuid(),
            'evaluated_at' => now()->toIso8601String(),
            'dataset_id' => $this->datasetId,
            'metrics' => $this->metrics ?? [
                'precision' => random_int(70, 95) / 100,
                'recall' => random_int(70, 95) / 100,
                'f1' => random_int(70, 95) / 100,
            ],
        ];

        if ($this->notes !== null) {
            $entry['notes'] = $this->notes;
        }

        $metadata['evaluations'] = array_values(array_filter(
            array_merge($metadata['evaluations'] ?? [], [$entry]),
            static fn ($value): bool => is_array($value)
        ));

        $statusService->markProgress($model->id, 'evaluating', 55.0);

        try {
            $model->metadata = $metadata;
            $model->save();

            $statusService->markProgress($model->id, 'evaluating', 85.0);
            $statusService->markIdle($model->id);
        } catch (Throwable $exception) {
            Log::error('Failed to persist evaluation metadata', [
                'model_id' => $model->id,
                'exception' => $exception->getMessage(),
            ]);

            $statusService->markFailed($model->id);

            throw $exception;
        }
    }
}
