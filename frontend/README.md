# Predictive Patterns Frontend

## Development

```bash
npm install
npm run dev
```

## Environment variables

| Variable | Description |
| --- | --- |
| `VITE_API_URL` | Optional base URL for API requests. When not provided the app targets the `/api` relative path, matching the development proxy configuration. |

Set the variable in a `.env` file if the frontend is served from a different origin than the API or when deploying to production.
