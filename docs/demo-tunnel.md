# Running the tester demo

## Why it is set up this way

The tunnel used to point at the Vite dev server, which reads the working tree
and hot-reloads it. So every save while we were coding landed in front of
whoever was mid-application — testers watched fields move under them and said
so. That is the problem this layout exists to solve.

Testers now get a **built bundle from a pinned branch in a separate worktree**.
It changes when someone deliberately rebuilds, and not before. Our editing,
branch switching, rebasing and test runs cannot touch it.

```
main tree   ~/Documents/GitHub/biztrack          any working branch    ← we edit here
demo tree   ~/Documents/GitHub/biztrack-demo     branch: demo          ← testers see this

  :5180  vite preview   serves biztrack-demo/web/dist   (no HMR)
  :8082  php artisan serve  from biztrack-demo/api
  :8080  php artisan serve  from the main tree           ← our own work
  :5173  vite dev           from the main tree           ← our own work
  :8787  R / plumber        localhost only, never tunnelled

cloudflared --url http://localhost:5180
```

Both API processes point at the **same** `api/database/database.sqlite`, so
tester data is continuous and we can see what they filed. Only the *code* is
isolated, which is the part that was hurting them.

## Publishing an update to testers

Deliberate, and the only way their world changes:

```bash
cd ~/Documents/GitHub/biztrack-demo
git merge --ff-only <branch-you-want-live>   # or: git merge origin/main
cd web && npm run build
```

`vite preview` serves `dist/` off disk, so the rebuild is live the moment it
finishes. No restart needed.

If the API changed too, restart the demo API:

```bash
kill $(lsof -ti tcp:8082); cd ~/Documents/GitHub/biztrack-demo/api
DB_DATABASE=~/Documents/GitHub/biztrack/api/database/database.sqlite \
  APP_DEBUG=false nohup php artisan serve --port=8082 &
```

## Starting from cold

```bash
scripts/demo-up.sh          # from the main tree
```

It prints the tunnel URL. Stop everything with `scripts/demo-down.sh`.

## Before you hand out the URL

- `APP_DEBUG` must be **false** — a stack trace on a public URL leaks paths,
  environment and query fragments.
- Plumber (:8787) must stay bound to `127.0.0.1`. It has no authentication of
  its own, so anything that can reach it can read the register.
- Only :5180 is tunnelled. Never tunnel the API or the R service directly.
- Demo accounts all share one password. That is acceptable only because the
  data is seeded; never reuse those credentials anywhere real.

## Known gap

`FRONTEND_URL` defaults to `http://localhost:5173`, and the permit certificate
prints a verify link built from it. While the tunnel is live, every permit a
tester downloads tells them to verify at an address only we can reach. Set
`FRONTEND_URL` in `api/.env` to the current tunnel URL before a session that
involves printing permits.

Quick tunnels also get a new random hostname every restart, so that value has
to be updated each time. A named tunnel would fix both.

## The worktree

Created once with:

```bash
git worktree add ../biztrack-demo demo
```

`node_modules` and `vendor` are symlinked to the main tree's, and `.env` files
were copied in — none of those are tracked, so a fresh worktree has neither.
`git worktree list` shows it; `git worktree remove ../biztrack-demo` undoes it.

Note that a branch checked out in a worktree **cannot** be checked out in the
main tree at the same time. That is the safety property, not an obstacle: it is
what stops someone accidentally committing to `demo` from the tree they are
developing in.
