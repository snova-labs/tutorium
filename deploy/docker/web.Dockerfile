# syntax=docker/dockerfile:1.7
#
# The Next.js staff client (web/), as a standalone Node server on port 3000.
# Build context: web/. Built for linux/arm64 in CI.
#
# `next build` runs on the build machine's own architecture: its output is JavaScript, and running
# the compiler under emulation would take many times longer.

FROM --platform=$BUILDPLATFORM node:22-alpine AS build
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

FROM node:22-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

COPY --from=build --chown=node:node /app/.next/standalone ./
COPY --from=build --chown=node:node /app/.next/static ./.next/static

USER node
EXPOSE 3000

# API_BASE_URL is read at runtime (server side only), so one image serves staging and production.
CMD ["node", "server.js"]
