<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\PoliceCrimeIngestionService;

class IngestPoliceCrimes implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $yearMonth) {}

    public function handle(PoliceCrimeIngestionService $service): void {
        $service->ingest($this->yearMonth);
    }
}
