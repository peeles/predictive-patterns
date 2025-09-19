# Backend (Laravel API)

The backend is a Laravel 10 application responsible for serving predictive risk analytics and orchestrating crime ingestion pipelines.

## Key features

- **Hex aggregation API** – `/api/hexes` and `/api/hexes/geojson` expose validated aggregation responses with PSR-12 compliant controllers and DTO-backed services.
- **H3 integration** – services gracefully resolve either the PHP H3 extension or compatible FFI bindings at runtime.
- **Police archive ingestion** – resilient downloader normalises, deduplicates, and bulk inserts police records with H3 enrichment.
- **MCP support** – `php artisan mcp:serve` exposes the API’s capabilities to Model Context Protocol compatible clients.

## Project conventions

- Strict types and [PSR-12](https://www.php-fig.org/psr/psr-12/) formatting across the codebase.
- Request validation lives in `App\Http\Requests`; spatial constraints are handled via dedicated `Rule` classes.
- Services return typed Data Transfer Objects to keep controllers lean and serialisation explicit.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Run the automated test suite:

```bash
php artisan test
```

## Available commands

| Command | Description |
|---------|-------------|
| `php artisan crimes:ingest 2024-03` | Download and import a police archive for March 2024. |
| `php artisan schedule:run` | Trigger scheduled ingestion or housekeeping tasks. |
| `php artisan mcp:serve` | Start the Model Context Protocol bridge for the Predictive Patterns API. |

## Environment variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `API_TOKENS` | Comma-separated list of allowed API tokens for authenticating requests. | _(empty)_ |
| `API_RATE_LIMIT` | Requests per minute allowed for each client IP when using the API. | `60` |
| `POLICE_ARCHIVE_TIMEOUT` | Override HTTP timeout (seconds) for police data downloads. | `120` |
| `POLICE_ARCHIVE_RETRIES` | Number of retry attempts for failed archive downloads. | `3` |
| `QUEUE_CONNECTION` | Queue backend for ingestion jobs (`sync`, `database`, etc.). | `sync` |

Keep secrets such as database credentials and API keys in the `.env` file and never commit them to version control.
