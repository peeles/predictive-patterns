<?php

namespace App\Jobs;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Services\DatasetProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function explode;
use function is_string;
use function parse_url;
use function pathinfo;
use function strtolower;
use function trim;
use const PATHINFO_EXTENSION;
use const PHP_URL_PATH;

class IngestRemoteDataset implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $datasetId)
    {
    }

    public function handle(DatasetProcessingService $processingService): void
    {
        $dataset = Dataset::query()->find($this->datasetId);

        if (! $dataset instanceof Dataset) {
            return;
        }

        if ($dataset->source_type !== 'url' || $dataset->source_uri === null) {
            return;
        }

        $dataset->status = DatasetStatus::Processing;
        $dataset->save();

        $path = null;

        try {
            $response = Http::timeout(60)->retry(2, 1000)->get($dataset->source_uri);

            if (! $response->successful()) {
                throw new RuntimeException(sprintf('Unable to download dataset (HTTP %d).', $response->status()));
            }

            $body = (string) $response->body();

            if ($body === '') {
                throw new RuntimeException('Dataset download returned an empty response.');
            }

            $mimeType = $this->normaliseMimeType($response->header('Content-Type'));
            $extension = $this->resolveExtension($dataset->source_uri, $mimeType);
            $fileName = $extension !== ''
                ? sprintf('%s.%s', Str::uuid(), $extension)
                : (string) Str::uuid();

            $path = 'datasets/' . $fileName;
            Storage::disk('local')->put($path, $body);

            $dataset->file_path = $path;
            $dataset->mime_type = $mimeType;
            $dataset->checksum = hash('sha256', $body);
            $dataset->save();

            $schemaMapping = is_array($dataset->schema_mapping) ? $dataset->schema_mapping : [];
            $processingService->finalise($dataset, $schemaMapping);
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            $dataset->refresh();
            $dataset->file_path = null;
            $dataset->checksum = null;
            $dataset->mime_type = null;
            $dataset->ingested_at = null;
            $dataset->status = DatasetStatus::Failed;
            $dataset->metadata = $processingService->mergeMetadata($dataset->metadata, [
                'ingest_error' => $exception->getMessage(),
            ]);
            $dataset->save();

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Remote dataset ingestion failed', [
            'dataset_id' => $this->datasetId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function resolveExtension(string $uri, ?string $mimeType): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if (is_string($path)) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            if (is_string($extension) && $extension !== '') {
                return strtolower($extension);
            }
        }

        if ($mimeType !== null) {
            $map = [
                'text/csv' => 'csv',
                'application/csv' => 'csv',
                'application/json' => 'json',
                'application/geo+json' => 'geojson',
            ];

            $normalised = strtolower($mimeType);

            if (array_key_exists($normalised, $map)) {
                return $map[$normalised];
            }
        }

        return '';
    }

    private function normaliseMimeType(?string $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $parts = explode(';', $raw);
        $mime = trim($parts[0]);

        return $mime !== '' ? strtolower($mime) : null;
    }
}
