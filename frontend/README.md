# Predictive Patterns Frontend

## Development

```bash
npm install
npm run dev
```

### Linting

```bash
npm run lint
```

## Environment variables

| Variable | Description |
| --- | --- |
| `VITE_API_URL` | Optional base URL for API requests. When not provided the app targets the `/api` relative path, matching the development proxy configuration. |
| `VITE_PROXY_TARGET` | Overrides the Vite dev-server proxy target. Defaults to `http://localhost:8080` when running locally and is set to `http://nginx` in Docker Compose so the frontend container can reach the backend. |

Set the variable in a `.env` file if the frontend is served from a different origin than the API or when deploying to production.
