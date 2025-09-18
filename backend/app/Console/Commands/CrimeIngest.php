<?php

namespace App\Console\Commands;

use App\Jobs\IngestPoliceCrimes;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class CrimeIngest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crimes:ingest {ym : Target year-month in YYYY-MM format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch an ingestion job for police crimes data for the given year and month';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $ym = (string) $this->argument('ym');

        try {
            $normalizedYm = $this->normalizeYearMonth($ym);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        IngestPoliceCrimes::dispatch($normalizedYm);

        $this->info(sprintf('Dispatched IngestPoliceCrimes job for %s', $normalizedYm));

        return self::SUCCESS;
    }

    /**
     * Validate and normalize the supplied year-month argument.
     */
    private function normalizeYearMonth(string $ym): string
    {
        if (!preg_match('/^\\d{4}-\\d{2}$/', $ym)) {
            throw new InvalidArgumentException('The ym argument must be in YYYY-MM format.');
        }

        $date = Carbon::createFromFormat('Y-m-d', $ym . '-01');
        if ($date === false) {
            throw new InvalidArgumentException('Unable to parse the provided ym argument.');
        }

        return $date->format('Y-m');
    }
}
