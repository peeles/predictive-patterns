<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\DatasetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatasetIngestRequest;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class DatasetController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    public function ingest(DatasetIngestRequest $request): JsonResponse
    {
        $this->authorize('create', Dataset::class);

        $validated = $request->validated();
        $user = $request->user();
        $createdBy = $user instanceof User ? $user->getKey() : null;

        $dataset = new Dataset([
            'id' => (string) Str::uuid(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'source_type' => $validated['source_type'],
            'source_uri' => $validated['source_uri'] ?? null,
            'metadata' => $validated['metadata'] ?? [],
            'status' => DatasetStatus::Processing,
            'created_by' => $createdBy,
        ]);

        if ($request->file('file') !== null) {
            $file = $request->file('file');
            $fileName = sprintf('%s.%s', Str::uuid(), $file->getClientOriginalExtension());
            $path = $file->storeAs('datasets', $fileName, 'local');
            $dataset->file_path = $path;
            $dataset->mime_type = $file->getMimeType();
            $dataset->checksum = hash_file('sha256', $file->getRealPath());
        }

        $dataset->save();
        $dataset->refresh();

        $dataset->status = DatasetStatus::Ready;
        $dataset->ingested_at = now();
        $dataset->save();

        return response()->json($this->transform($dataset), JsonResponse::HTTP_CREATED);
    }

    private function transform(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'source_type' => $dataset->source_type,
            'source_uri' => $dataset->source_uri,
            'file_path' => $dataset->file_path,
            'checksum' => $dataset->checksum,
            'mime_type' => $dataset->mime_type,
            'metadata' => $dataset->metadata,
            'status' => $dataset->status instanceof DatasetStatus ? $dataset->status->value : (string) $dataset->status,
            'ingested_at' => optional($dataset->ingested_at)->toIso8601String(),
            'created_at' => optional($dataset->created_at)->toIso8601String(),
        ];
    }
}
