# Backend (Laravel API)

The backend is a Laravel 10 application responsible for serving predictive risk analytics and orchestrating crime ingestion pipelines.

## Key features

- **Hex aggregation API** – `/api/hexes` and `/api/hexes/geojson` expose validated aggregation responses with PSR-12 compliant controllers and DTO-backed services.
- **H3 integration** – services gracefully resolve either the PHP H3 extension or compatible FFI bindings at runtime.
- **Police archive ingestion** – resilient downloader normalises, deduplicates, and bulk inserts police records with H3 enrichment.
- **MCP support** – `php artisan mcp:serve` exposes the API’s capabilities to Model Context Protocol compatible clients and now includes discovery helpers such as `get_categories`, `list_ingested_months`, and `get_top_cells`.


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

## HTTP endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/hexes` | Aggregated counts for H3 cells intersecting a bounding box. Supports `bbox`, `resolution`, `from`, `to`, and `crime_type` query parameters. |
| `GET` | `/api/v1/heatmap/{z}/{x}/{y}` | Tile-friendly aggregate payload for the requested XYZ tile. Supports optional `ts_start`, `ts_end`, and `horizon` filters. |
| `GET` | `/api/hexes/geojson` | GeoJSON feature collection for aggregated H3 cells. |
| `GET` | `/api/export` | Download aggregated data as CSV (default) or GeoJSON via `format=geojson`. Accepts the same filters as `/api/hexes`. |
| `POST` | `/api/nlq` | Ask a natural-language question and receive a structured answer describing the translated query. |

## MCP toolset

| Tool | Purpose |
|------|---------|
| `aggregate_hexes` | Aggregate crime counts for a bounding box. |
| `export_geojson` | Produce a GeoJSON feature collection for crime aggregates. |
| `get_categories` | List distinct crime categories available in the datastore. |
| `get_top_cells` | Return the highest ranking H3 cells for the supplied filters. |
| `ingest_crime_data` | Queue a background job to ingest a month of police crime data. |
| `list_ingested_months` | Summarise the months currently present in the relational store. |


## Environment variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `API_TOKENS` | Comma-separated list of allowed API tokens for authenticating requests. | _(empty)_ |
| `API_RATE_LIMIT` | Requests per minute allowed for each client IP when using the API. | `60` |
| `POLICE_ARCHIVE_TIMEOUT` | Override HTTP timeout (seconds) for police data downloads. | `120` |
| `POLICE_ARCHIVE_RETRIES` | Number of retry attempts for failed archive downloads. | `3` |
| `QUEUE_CONNECTION` | Queue backend for ingestion jobs (`sync`, `database`, etc.). | `sync` |

Keep secrets such as database credentials and API keys in the `.env` file and never commit them to version control.
