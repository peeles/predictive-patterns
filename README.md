# Predictive Patterns
## Full-stack (Docker) with Laravel + MCP + Vue/Leaflet

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

## Backend
The `backend/` directory now ships with a full Laravel 12 installation.
The Docker setup mounts this folder directly into the PHP containers so no
scaffolding is generated at runtime. 

The `scripts/init-backend.sh` script only ensures dependencies are installed 
and an application key exists.
