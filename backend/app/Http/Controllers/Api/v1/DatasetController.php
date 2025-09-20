<?php

namespace App\Http\Controllers\Api\v1;

use App\Enums\CrimeIngestionStatus;
use App\Enums\DatasetStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatasetIngestRequest;
use App\Models\CrimeIngestionRun;
use App\Models\Dataset;
use App\Models\User;
use App\Support\ResolvesRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DatasetController extends Controller
{
    use ResolvesRoles;

    public function __construct()
    {
        $this->middleware(['auth.api', 'throttle:api']);
    }

    /**
     * Ingest a new dataset.
     *
     * @param DatasetIngestRequest $request
     *
     * @return JsonResponse*
     * @throws AuthorizationException
     */
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

        return response()->json($this->transform($dataset), Response::HTTP_CREATED);
    }

    /**
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function runs(Request $request): JsonResponse
    {
        $role = $this->resolveRole($request->user());

        if ($role !== Role::Admin) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to view ingestion runs.');
        }

        $perPage = $this->resolvePerPage((int) $request->integer('per_page', 25));

        $runs = CrimeIngestionRun::query()
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $transformed = $runs->getCollection()->map(fn (CrimeIngestionRun $run): array => $this->transformRun($run));

        return response()->json([
            'data' => $transformed->all(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
            ],
            'links' => [
                'first' => $runs->url(1),
                'last' => $runs->url($runs->lastPage()),
                'prev' => $runs->previousPageUrl(),
                'next' => $runs->nextPageUrl(),
            ],
        ]);
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

    private function transformRun(CrimeIngestionRun $run): array
    {
        return [
            'id' => $run->id,
            'month' => $run->month,
            'dry_run' => (bool) $run->dry_run,
            'status' => $run->status instanceof CrimeIngestionStatus ? $run->status->value : (string) $run->status,
            'records_detected' => $run->records_detected,
            'records_expected' => $run->records_expected,
            'records_inserted' => $run->records_inserted,
            'records_existing' => $run->records_existing,
            'error_message' => $run->error_message,
            'archive_checksum' => $run->archive_checksum,
            'archive_url' => $run->archive_url,
            'started_at' => optional($run->started_at)->toIso8601String(),
            'finished_at' => optional($run->finished_at)->toIso8601String(),
            'created_at' => optional($run->created_at)->toIso8601String(),
            'updated_at' => optional($run->updated_at)->toIso8601String(),
        ];
    }

    private function resolvePerPage(int $perPage): int
    {
        return max(1, min($perPage, 100));
    }
}
