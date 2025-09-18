# Predictive Patterns

An enterprise-grade predictive policing prototype that combines a Laravel API, Vue 3 single-page experience, and shared Docker tooling. The platform ingests UK Police crime archives, indexes events into H3 hexagons, and serves real-time risk heatmaps for geographic exploration.

## Architecture

| Layer     | Technology | Responsibilities |
|-----------|------------|------------------|
| API       | Laravel 10 | Exposes REST endpoints for H3 aggregation, GeoJSON export, NLQ answers, and data ingestion jobs. Enforces PSR-12 formatting and request validation. |
| Frontend  | Vue 3 + Vite | Interactive dashboard with Leaflet visualisation, NLQ console, and responsive layout. |
| Data      | MySQL / Postgres (Docker selectable) | Persists normalised crime records with pre-computed H3 indexes at multiple resolutions. |
| Ingestion | Artisan command + queued jobs | Downloads police archives, converts to H3 indexes, and bulk-ingests into the relational store. |

## Prerequisites

* Docker Desktop 4.26+
* Make (for the provided helper tasks)
* Node.js 20+ (for local frontend builds)
* PHP 8.2+ with Composer (only if running the API outside Docker)

## Quick start (Docker)

```bash
cp .env.example .env
make up
# Run migrations and seeders
docker compose exec backend php artisan migrate --seed
# Generate Laravel app key
docker compose exec backend php artisan key:generate
```

Services exposed locally:

* API: http://localhost:8080 (proxied to Laravel app)
* Frontend (Vite dev server): http://localhost:5173

To shut everything down:

```bash
make down
```

## Local development

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Run automated tests and static analysis:

```bash
# PHPUnit feature and unit tests
php artisan test
```

The backend adheres to the [PSR-12 coding style](https://www.php-fig.org/psr/psr-12/). All new PHP code must declare strict types, follow PSR-12 brace placement, and include typed signatures. Request validation lives in dedicated `FormRequest` classes with custom rules for spatial inputs.

### Frontend

```bash
cd frontend
npm install
npm run dev
```

A production build can be created with `npm run build`. The Vue application consumes the API via the `VITE_API_BASE` (or legacy `VITE_API_URL`) environment variable.

## Data ingestion workflow

The ingestion service (`App\Services\PoliceCrimeIngestionService`) downloads monthly CSV archives, deduplicates crime IDs, enriches each record with H3 indexes at resolutions 6–8, and bulk inserts in 500-record batches. Transient failures are retried with exponential backoff and every step is logged for observability.

Trigger ingestion for a specific month:

```bash
php artisan crimes:ingest 2024-03
```

## Testing

A comprehensive feature test suite verifies H3 aggregation, GeoJSON conversion, and request validation. To execute the suite:

```bash
cd backend
php artisan test
```

For frontend confidence, run the Vite build:

```bash
cd frontend
npm run build
```

## NLP Console

The NLQ console provides a lightweight natural language interface powered by the `/nlq` endpoint. The component debounces user interactions, shows inline loading states, and surfaces descriptive error feedback for operational transparency.

## Maintainers

* Data Engineering: ingestion reliability and H3 indexing.
* Platform Engineering: Docker, CI/CD pipelines, observability hooks.
* Product Engineering: frontend experience and NLQ capabilities.

Please raise issues or pull requests following the contribution template to ensure code remains production ready.
