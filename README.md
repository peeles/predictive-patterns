# Crime Pattern – Full-stack (Docker) with Laravel + MCP + Vue/Leaflet

## Quick start
```bash
cp .env.example .env
make up
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --seed
```

- API: http://localhost:8080
- Frontend (dev): http://localhost:5173

### MCP in ChatGPT (Dev Mode)
- Add **Local (stdio)** MCP connection:
  - Command: `php`
  - Args: `["artisan","mcp:serve"]`
  - Working dir: `backend/` (inside the repo)


## If you don't see a backend/composer.json
That's expected in this starter: the container creates a fresh Laravel app into `backend/` the first time it runs.

- Script: `scripts/init-backend.sh`
- Triggered automatically by the backend service command.
- After `make up`, your local `backend/` will now contain `composer.json`, `artisan`, etc.
