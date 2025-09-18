<?php

namespace App\Services;

use App\Models\Crime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

class PoliceCrimeIngestionService {
    private const ARCHIVE_URL = 'https://data.police.uk/data/archive/%s.zip';
    private const CHUNK_SIZE = 500;

    public function __construct(private readonly H3IndexService $h3IndexService) {}

    public function ingest(string $yearMonth): int {
        Log::info('Starting police crime ingestion', ['month' => $yearMonth]);

        $archivePath = null;

        try {
            $archivePath = $this->downloadArchive($yearMonth);
            $inserted = $this->importArchive($archivePath);

            Log::info('Completed police crime ingestion', [
                'month' => $yearMonth,
                'inserted' => $inserted,
            ]);

            return $inserted;
        } catch (Throwable $e) {
            Log::error('Failed to ingest police crimes', [
                'month' => $yearMonth,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if ($archivePath && file_exists($archivePath)) {
                @unlink($archivePath);
            }
        }
    }

    private function downloadArchive(string $yearMonth): string {
        $url = sprintf(self::ARCHIVE_URL, $yearMonth);

        $response = Http::timeout(120)->retry(3, 1000)->withHeaders([
            'User-Agent' => 'PredictivePatternsBot/1.0',
            'Accept' => 'application/zip',
        ])->get($url);

        if (!$response->successful()) {
            throw new RuntimeException("Unable to download police archive ({$response->status()}): $url");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'crimes_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to create temporary file for police archive');
        }

        if (file_put_contents($tmp, $response->body()) === false) {
            throw new RuntimeException('Unable to write police archive to temporary file');
        }

        return $tmp;
    }

    private function importArchive(string $archivePath): int {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Unable to open police archive: '.$archivePath);
        }

        $toH3 = [$this->h3IndexService, 'toH3'];
        $inserted = 0;
        $buffer = [];
        $seen = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) {
                    continue;
                }

                $name = $stat['name'] ?? '';
                if (!str_ends_with(strtolower($name), '.csv')) {
                    continue;
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    Log::warning('Unable to read CSV from police archive', ['file' => $name]);
                    continue;
                }

                $headers = null;
                while (($row = fgetcsv($stream)) !== false) {
                    if ($headers === null) {
                        $headers = $this->normaliseHeaders($row);
                        continue;
                    }

                    $assoc = $this->combineRow($headers, $row);
                    if ($assoc === null) {
                        continue;
                    }

                    $record = $this->transformRow($assoc, $toH3, $seen);
                    if ($record === null) {
                        continue;
                    }

                    $buffer[] = $record;

                    if (count($buffer) >= self::CHUNK_SIZE) {
                        $inserted += $this->flushBuffer($buffer);
                    }
                }

                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        if ($buffer) {
            $inserted += $this->flushBuffer($buffer);
        }

        return $inserted;
    }

    private function normaliseHeaders(array $headers): array {
        return array_map(static function (?string $value) {
            $value = $value ?? '';
            $value = preg_replace('/^\xEF\xBB\xBF/', '', $value ?? '');
            return trim($value);
        }, $headers);
    }

    private function combineRow(array $headers, array $values): ?array {
        if (empty($headers)) {
            return null;
        }

        $row = [];
        foreach ($headers as $idx => $header) {
            $row[$header] = $values[$idx] ?? null;
        }

        return $row;
    }

    private function transformRow(array $row, callable $toH3, array &$seen): ?array {
        $crimeId = trim((string) ($row['Crime ID'] ?? ''));
        if ($crimeId === '' || isset($seen[$crimeId])) {
            return null;
        }

        $month = trim((string) ($row['Month'] ?? ''));
        if ($month === '') {
            return null;
        }

        try {
            $occurredAt = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (Throwable) {
            return null;
        }

        $lat = $this->parseCoordinate($row['Latitude'] ?? null);
        $lng = $this->parseCoordinate($row['Longitude'] ?? null);
        if ($lat === null || $lng === null) {
            return null;
        }

        $category = Str::slug((string) ($row['Crime type'] ?? ''), '-');
        if ($category === '') {
            $category = 'unknown';
        }

        try {
            $h3Res6 = $toH3($lat, $lng, 6);
            $h3Res7 = $toH3($lat, $lng, 7);
            $h3Res8 = $toH3($lat, $lng, 8);
        } catch (Throwable $e) {
            Log::warning('Failed to compute H3 index for crime', [
                'crime_id' => $crimeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            $raw = json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            $raw = null;
        }

        $seen[$crimeId] = true;

        $now = Carbon::now();

        return [
            'id' => $crimeId,
            'category' => $category,
            'occurred_at' => $occurredAt->toDateTimeString(),
            'lat' => $lat,
            'lng' => $lng,
            'h3_res6' => $h3Res6,
            'h3_res7' => $h3Res7,
            'h3_res8' => $h3Res8,
            'raw' => $raw,
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ];
    }

    private function parseCoordinate(mixed $value): ?float {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 6);
    }

    private function flushBuffer(array &$buffer): int {
        if (!$buffer) {
            return 0;
        }

        $ids = array_column($buffer, 'id');
        $existing = Crime::query()->whereIn('id', $ids)->pluck('id')->all();

        if ($existing) {
            $existing = array_flip($existing);
            $buffer = array_values(array_filter($buffer, static fn(array $row): bool => !isset($existing[$row['id']])));
        }

        $count = count($buffer);

        if ($count > 0) {
            Crime::query()->insert($buffer);
        }

        $buffer = [];

        return $count;
    }
}
