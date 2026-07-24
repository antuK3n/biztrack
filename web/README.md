# BizTrack Web

React SPA for BizTrack (master plan §2: Vite + React 18 + TypeScript + Tailwind + React Router).

## Run it

```bash
npm install
npm run dev      # http://localhost:5173
npm run build    # type-checks + production build to dist/
```

## API and demo mode

All requests go through one axios client (`src/lib/api.ts`) against `/api/v1` with a Bearer token.
While `api/` (Laravel) doesn't exist yet, `.env.development` sets `VITE_USE_MOCK_API=true`, which
serves the sprint 1 §E1 auth contract in-memory (`src/lib/mock.ts`) — same envelopes, status codes,
and lockout behavior. Flip the env var to point at the real API; no screen changes needed.

Demo sign-in (mock): `owner@biztrack.local` / `biztrack1` (also `bplo@biztrack.local`, and
`inactive@biztrack.local` for the deactivated state). Standing demo links:

- Reset: `/reset-password?token=demo-reset-token&email=owner@biztrack.local`
- Verify: `/verify-email?id=100&hash=demo-hash`

Mock state is in-memory: a page reload resets registrations, lockouts, and used tokens.

## Accepted tradeoffs

- **Bearer token in localStorage** (sprint 1 §G): accepted capstone tradeoff; a 401 purges the
  token and returns to `/login`.

## Dependencies beyond the approved list

- `@fontsource-variable/source-sans-3` — self-hosted UI font; the defense-day fallback runs on a
  LAN with zero internet, so font CDNs are not an option.
