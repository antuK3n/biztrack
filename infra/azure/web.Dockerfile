# The web app for biztrack.page: the React build, served by nginx, which also
# hands /api to the php-fpm container.
#
# Built on the server itself (1 GB RAM + 2 GB swap), so the build skips
# `tsc -b`: types are checked on a developer machine before anything is
# pushed, and the full type-check does not fit in this server's memory.
# Node 24 = npm 11, the same npm that writes package-lock.json on the
# developer machines; npm 10 reads optional wasm packages differently and
# refuses the lock.
FROM node:24-alpine AS build
WORKDIR /web
COPY web/package.json web/package-lock.json ./
# --legacy-peer-deps: the lock names @napi-rs/wasm-runtime (a WebAssembly
# fallback for rolldown/Tailwind, unused where their native builds run) but
# not the @emnapi peers it asks for, and a clean Linux `npm ci` refuses that.
# This installs exactly what the lock lists and skips the peer check.
RUN npm ci --no-audit --no-fund --legacy-peer-deps
COPY web/ ./
# Public by design: Turnstile's site key is printed into every page anyway.
# Empty = no captcha box, and the API skips the check when its secret is empty.
ARG VITE_TURNSTILE_SITE_KEY=""
ENV VITE_TURNSTILE_SITE_KEY=$VITE_TURNSTILE_SITE_KEY
ENV NODE_OPTIONS=--max-old-space-size=1536
RUN npx vite build

FROM nginx:1.27-alpine
COPY infra/azure/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /web/dist /var/www/web
