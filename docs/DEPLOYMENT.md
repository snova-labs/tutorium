# Deployment: staging and production on the Oracle server (Portainer, Cloudflare Tunnel or NPM)

_Applies to the Oracle Ampere A1 server (2 OCPU, 12 GB, ARM) described in the server notes: Docker,
Portainer-managed stacks, a shared `mysql` stack on the external `db` network, and the domain
`jayshyampatel.com.np` on Cloudflare._

## What runs where

```
                    Cloudflare (TLS, Access)                         Oracle server, no open ports for this app
 browser ──https──▶ tutorium-staging.jayshyampatel.com.np     ──tunnel──▶ tutorium-staging-tunnel ─▶ tutorium-staging-web:3000  (Next.js)
 browser ──https──▶ tutorium-staging-api.jayshyampatel.com.np ──tunnel──▶ tutorium-staging-tunnel ─▶ tutorium-staging-api:8080  (Laravel)
                                                       web ──http──▶ tutorium-staging-api:8080/api/v1  (server to server)
                                                       api, queue, scheduler ──▶ mysql  (db network)
```

**Two ways in, chosen per stack** (same file, one variable):

| | Cloudflare Tunnel (default) | Nginx Proxy Manager |
|---|---|---|
| Variable | `COMPOSE_PROFILES=tunnel` and `CLOUDFLARE_TUNNEL_TOKEN` | leave both out |
| Certificates | Cloudflare | NPM (Let's Encrypt) |
| Open ports | none | the server's existing 80/443 |
| Keep strangers out of staging | Cloudflare Access (email login) | an NPM Access List (password or IP) |
| Setup | "One-time setup: staging", steps 4–5 | "Alternative: Nginx Proxy Manager" below |

Either way the targets are the same two containers, `<slug>-web:3000` and `<slug>-api:8080`. The
`api` and `web` containers always join NPM's shared network (`web`), so switching is only a change
of variables and a redeploy.

One Portainer stack per environment, all from the same file, `deploy/portainer/stack.yml`:

| Container | What it is | Memory limit |
|---|---|---|
| `<slug>-migrate` | Runs `php artisan migrate --force`, then exits. The others wait for it. | 256 MB |
| `<slug>-api` | Laravel: API, admin panel (`/admin`), Livewire screens, payment webhooks | 512 MB |
| `<slug>-queue` | Laravel queue worker | 256 MB |
| `<slug>-scheduler` | Laravel scheduler (trials, dunning, metering, clean-up) | 192 MB |
| `<slug>-web` | Next.js staff client | 384 MB |
| `<slug>-tunnel` | Cloudflare Tunnel connector (tunnel mode only) | 128 MB |

`<slug>` is `tutorium-staging` for staging and `tutorium` for production. Measured idle use is about
250 MB for the whole stack.

**Switching between staging and production** changes nothing in the files: it is a second stack,
from the same repository path, with the production variables
(`deploy/portainer/production.env.example`) and its own database, tunnel and `APP_KEY`. Production
runs the image tag `production`, which only moves when you promote an image staging has already
run.

## Images

GitHub Actions builds `linux/arm64` images after CI passes on `main` (workflow **Images**) and pushes
them to GitHub Container Registry:

- `ghcr.io/snova-labs/tutorium-api:staging` and `:sha-<7 chars>`
- `ghcr.io/snova-labs/tutorium-web:staging` and `:sha-<7 chars>`

The workflow **Promote to production** (Actions → Promote to production → Run workflow) points
`:production` at `staging` or at any `sha-…` tag, without rebuilding.

Nothing is built on the server.

---

## One-time setup: staging

### 1. Check the images exist

After this is merged and the **Images** workflow is green: GitHub → snova-labs → **Packages** →
`tutorium-api` and `tutorium-web` each have a `staging` tag.

The first build takes longer (the PHP extensions compile under ARM emulation); later builds use the
cache. If native ARM runners are available to the repository, set the repository variable
`IMAGE_RUNNER` to `ubuntu-24.04-arm` (Settings → Secrets and variables → Actions → Variables).

### 2. Let Portainer pull from GHCR

The packages are private. In GitHub, create a classic personal access token with only
`read:packages` (and authorise it for the `snova-labs` organisation if SSO asks), then:

Portainer → **Registries** → **Add registry** → **Custom registry**

- Name: `ghcr`
- Registry URL: `ghcr.io`
- Authentication: on. Username: your GitHub username. Password: the token.

### 3. Create the database

**Server**
```bash
~/scripts/my-create-db.sh tutorium_staging
```
Save the printed password in your password manager. The app reaches MySQL as `mysql:3306` over the
`db` network, and the database is never exposed outside Docker.

### 4. Create the Cloudflare Tunnel

Cloudflare dashboard → **Zero Trust** → **Networks** → **Tunnels** → **Create a tunnel** →
**Cloudflared** → name `tutorium-staging` → Save.

On the install screen, pick **Docker**, and copy only the token (the long string after `--token`).
Don't run the command; the stack runs the connector.

**Public hostnames** (tab on the tunnel), add two:

| Subdomain | Domain | Service type | URL |
|---|---|---|---|
| `tutorium-staging` | `jayshyampatel.com.np` | HTTP | `tutorium-staging-web:3000` |
| `tutorium-staging-api` | `jayshyampatel.com.np` | HTTP | `tutorium-staging-api:8080` |

Cloudflare creates the DNS records itself. The names are one level deep (`tutorium-staging-api`, not
`api.tutorium-staging`) so Cloudflare's free certificate covers them.

Use the container names exactly as above (with the `tutorium-staging-` prefix), not `web` or `app`:
the prefix is what keeps the staging tunnel on staging when production runs next to it.

### 5. Put staging behind Cloudflare Access

Zero Trust → **Access** → **Applications** → **Add an application** → **Self-hosted**

- Name: `Tutorium staging`
- Application domains: `tutorium-staging.jayshyampatel.com.np` and
  `tutorium-staging-api.jayshyampatel.com.np`
- Policy: **Allow**, Include → **Emails** → your address (and any testers)

Anyone else gets Cloudflare's login page before reaching the app. The app's own sign-in still
applies after that.

If you test payment-provider webhooks on staging, add a second application for
`tutorium-staging-api.jayshyampatel.com.np/webhooks` with a **Bypass** policy (Include: Everyone). The
webhook checks its own signature.

### 6. Create the stack in Portainer

Generate the application key once, and save it in your password manager. Changing it later breaks
stored secrets and signs everyone out.

**Server**
```bash
echo "base64:$(openssl rand -base64 32)"
```

Portainer → **Stacks** → **Add stack**

- Name: `tutorium-staging`
- Build method: **Repository**
  - Repository URL: `https://github.com/snova-labs/tutorium`
  - Repository reference: `refs/heads/main`
  - Compose path: `deploy/portainer/stack.yml`
  - Authentication: on, with a GitHub token that can read the repository
- **Environment variables** → **Advanced mode** → paste
  `deploy/portainer/staging.env.example`, then fill in `CLOUDFLARE_TUNNEL_TOKEN`, `APP_KEY` and
  `DB_PASSWORD`. Keep `COMPOSE_PROFILES=tunnel`: it is what starts the tunnel container.
- **Deploy the stack**.

The variables are substituted into the stack file, so nothing needs creating on the server. If a
required one (`APP_KEY`, `APP_URL`, `WEB_URL`, `DB_*`, `APP_SLUG`) is missing, the deploy stops with a message
naming it. A missing tunnel token doesn't stop the deploy (NPM stacks have none); the tunnel
container exits instead and its log says why.

The first start pulls the images, runs the migrations (`tutorium-staging-migrate` shows **exited**,
which is correct), then starts the rest. In Portainer → Containers they should all turn
**healthy** within a minute.

### 7. Create an academy and sign in

Portainer → Containers → `tutorium-staging-api` → **Console** → Connect (`/bin/sh`), then:

```bash
php artisan platform:provision-academy "Test Academy" you@example.com "Your Name" --timezone=Asia/Kathmandu
```

It prints a temporary password once. Open `https://tutorium-staging.jayshyampatel.com.np`, pass
Cloudflare Access, then sign in.

Owners, managers and accountants confirm each sign-in with an emailed code. Staging doesn't send
mail (`MAIL_MAILER=log`), so the code is in Portainer → Containers → `tutorium-staging-api` → **Logs**:
search for `sign-in code`. To send real mail instead, set the `MAIL_*` SMTP variables and update the
stack.

Or sign up like a customer would, at `https://tutorium-staging.jayshyampatel.com.np/sign-up`. The
confirmation link goes to the same log (search for `sign-up/confirm`). Production keeps
`SIGNUP_OPEN=false` until you open it; see `SIGN-UP.md`.

For demo data instead: `php artisan db:seed --force` creates two sample academies whose owners sign
in with `owner@sample-one.test` / `password`. **Staging only**: never run it on production.

The admin panel is at `https://tutorium-staging-api.jayshyampatel.com.np/admin`.

---

## Alternative: Nginx Proxy Manager instead of the tunnel

Use this when you'd rather serve Tutorium like your other public sites, through NPM on ports 80/443.
Everything else (images, database, Portainer stack, updates) stays the same.

1. **DNS**: in Cloudflare, add two A records pointing at the server, **DNS only** (grey cloud),
   because NPM's certificate challenge runs over HTTP:
   `tutorium-staging` and `tutorium-staging-api`. If the tunnel had these names, delete its public
   hostnames first (Zero Trust → Tunnels → the tunnel → Public hostnames), and the DNS records that
   pointed at the tunnel.
2. **Stack variables**: delete `COMPOSE_PROFILES` and `CLOUDFLARE_TUNNEL_TOKEN`. If NPM's network
   isn't called `web`, set `PROXY_NETWORK` to its name. **Update the stack**. If a `tutorium-staging-tunnel` container is
   left over from tunnel mode, remove it in Portainer → Containers.
3. **NPM** → Hosts → Proxy Hosts → **Add Proxy Host**, twice:

   | Domain | Scheme | Forward hostname | Port |
   |---|---|---|---|
   | `tutorium-staging.jayshyampatel.com.np` | http | `tutorium-staging-web` | `3000` |
   | `tutorium-staging-api.jayshyampatel.com.np` | http | `tutorium-staging-api` | `8080` |

   On each, tick **Block Common Exploits**. SSL tab: **Request a new SSL Certificate**, **Force SSL**,
   **HTTP/2 Support**.
4. **Keep staging private**: NPM → Access Lists → add one (a username and password, and/or your IP
   under Access), then select it on both proxy hosts. Skip it for production. If you test payment
   webhooks on staging, the provider can't pass the password; use the tunnel mode for that.

Check: `docker network inspect web` lists `npm`, `tutorium-staging-api` and `tutorium-staging-web`.

`TRUSTED_PROXIES` needs no change: NPM sits on a private Docker range, so the app sees HTTPS and the
visitor's address. Keep the records grey: with an orange (proxied) record the app would see
Cloudflare's address instead of the visitor's.

**Switching back to the tunnel**: put the two variables back, update the stack, then delete the two
proxy hosts in NPM and the two A records (the tunnel creates its own DNS records).

---

## Updating staging

1. Merge to `main`. Wait for **CI**, then **Images**, to go green.
2. Portainer → Stacks → `tutorium-staging` → **Pull and redeploy**, with **Re-pull image** ticked.

Migrations run on every redeploy, before the app starts. If one fails, `tutorium-staging-migrate`
shows **exited (1)** and the app doesn't start on a half-migrated database; its **Logs** show why.

Variable changes: Stacks → `tutorium-staging` → Environment variables → edit → **Update the stack**.

---

## Production: the same stack, promoted

### First time

Repeat the staging setup with production values:

1. Database: `~/scripts/my-create-db.sh tutorium`.
2. Tunnel `tutorium`, with public hostnames `tutorium` → `tutorium-web:3000` and `tutorium-api` →
   `tutorium-api:8080`. Or, with Nginx Proxy Manager, proxy hosts to the same two targets.
3. Access: production is for customers, so leave the app public. If you want, protect only
   `tutorium-api.jayshyampatel.com.np/admin` (Allow: your email).
4. A **new** `APP_KEY`: never reuse staging's.
5. Real SMTP settings: production must deliver sign-in codes.
6. GitHub → Actions → **Promote to production** → Run workflow with tag `staging`.
7. Portainer stack `tutorium`: same repository and compose path, variables from
   `deploy/portainer/production.env.example`.
8. Create the first academy with `platform:provision-academy` in the `tutorium-api` container's console.

### Each release

1. Check the release on staging.
2. Take a database dump before the migration runs:

   **Server**
   ```bash
   docker exec mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --single-transaction --routines tutorium' \
     | gzip > ~/backups/mysql-tutorium_predeploy_$(date +%F_%H-%M).sql.gz
   ```
3. Actions → **Promote to production** → tag `staging` (or the exact `sha-…` you tested).
4. Portainer → Stacks → `tutorium` → **Pull and redeploy** with **Re-pull image**.

### Rolling back

Promote the previous `sha-…` tag (GitHub → Packages → `tutorium-api` → versions), then **Pull and
redeploy**.

If the bad release changed the database, restore the pre-deploy dump first ("Restoring one MySQL
database" in the server operations notes).

---

## File storage

Uploads and generated files go to the `<slug>_storage` volume on the server (`FILESYSTEM_DISK=local`),
so the stack needs no object storage. Local development runs **Silo** (`pgsty/silo`), Pigsty's
maintained fork of MinIO, because MinIO no longer publishes community images. If you later move files
to S3-compatible storage on the server, use Silo too: it reads MinIO's variables and data format.

## Backups

A backup nobody has restored is a hope (SL-415). The stack backs itself up every night and proves
once a week that the backup restores.

| When (server time, UTC) | What | Command |
|---|---|---|
| Every night, 01:30 | Database dump + uploaded files + manifest, into the `<slug>_backups` volume | `platform:backup` |
| Sundays, 03:00 | Restores the newest backup into the drill database and checks it | `platform:restore-drill` |
| Any time | When the last backup and drill ran, and whether they are recent enough | `platform:backups` |

Each night writes one folder, for example `2026-10-05_013000/`, containing `database.sql.gz`,
`storage.tar.gz` and `manifest.json`. The manifest records each table's row count and a hash of its
contents, the number and total size of the files, and a checksum of both archives. The last 14 are
kept (`BACKUP_KEEP`).

**What the drill checks:** both archives match their checksums; every table comes back with the
same rows and the same contents; the files unpack to the same count and size. It empties the drill
database afterwards. Any difference fails the drill and emails `BACKUP_ALERT_TO` (or every active
operator), as does a failed backup. `platform:backups` exits non-zero when the last backup is older
than 26 hours or the last good drill older than 8 days, so a monitor can run it.

### One-time setup: the drill database

The drill restores into its own database, never the live one (it refuses if they are the same).
Create it and let the app's user use it:

**Server**
```bash
docker exec -i mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root' <<'SQL'
CREATE DATABASE IF NOT EXISTS `tutorium_staging_drill`;
GRANT ALL PRIVILEGES ON `tutorium_staging_drill`.* TO 'tutorium_staging'@'%';
SQL
```

For production, `tutorium_drill` and the user `tutorium`. The stack variable is
`BACKUP_DRILL_DATABASE`. Then prove it once by hand: Portainer → Containers → `<slug>-api` →
Console:

```bash
php artisan platform:backup && php artisan platform:restore-drill && php artisan platform:backups
```

### Off the machine

The backups volume is on the same disk as the database. Add it to `~/scripts/backup.sh`, before the
clean-up line, so the server's nightly copy takes the newest backup with it:

```bash
for vol in tutorium-staging_backups tutorium_backups; do
  docker volume inspect "$vol" >/dev/null 2>&1 || continue
  docker run --rm -v "$vol":/data:ro -v "$BACKUP_DIR":/backup alpine sh -c \
    'latest=$(ls -1 /data | sort | tail -n 1); [ -n "$latest" ] && tar cf "/backup/'"$vol"'_${latest}.tar" -C /data "$latest"'
done
```

### Restoring for real

1. Stop the stack's `app`, `queue` and `scheduler` (Portainer → Stacks → `<slug>` → Stop).
2. Restore the database from the folder you want (the dump replays with the `mysql` client):

   **Server**
   ```bash
   docker run --rm -v tutorium_backups:/b:ro alpine ls /b
   docker run --rm -v tutorium_backups:/b:ro alpine cat /b/<folder>/database.sql.gz \
     | gunzip | docker exec -i mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root tutorium'
   ```
3. Restore the files into the storage volume:

   **Server**
   ```bash
   docker run --rm -v tutorium_backups:/b:ro -v tutorium_storage:/data alpine \
     sh -c 'find /data -mindepth 1 -delete && tar xzf /b/<folder>/storage.tar.gz -C /data'
   ```
4. Start the stack. Migrations newer than the backup run first.

Never run `docker compose down -v` or delete these volumes without a backup.

---

## Everyday commands

| Task | How |
|---|---|
| Logs | Portainer → Containers → `<slug>-api` (or `-web`, `-queue`, `-scheduler`, `-tunnel`) → Logs |
| Artisan | Portainer → Containers → `<slug>-api` → Console, then `php artisan …` |
| Health | `https://<api host>/up` (alive) and `/ready` (database and cache) |
| Memory | `docker stats --no-stream \| grep tutorium` |
| Backups | `php artisan platform:backups` in `<slug>-api` |

## Troubleshooting

| Symptom | Likely cause → fix |
|---|---|
| `<slug>-migrate` exited (1), app not started | Logs of `<slug>-migrate`. Usually wrong `DB_PASSWORD`, the database not created, or `mysql` not running (`docker ps \| grep mysql`). |
| Cloudflare error 1033 / 502 | The tunnel container isn't running (logs of `<slug>-tunnel`), the token is wrong, or a public hostname points at the wrong service. It must be HTTP to `<slug>-web:3000` or `<slug>-api:8080`, not `localhost`. |
| No `<slug>-tunnel` container at all | `COMPOSE_PROFILES=tunnel` is missing from the stack's variables. |
| NPM shows 502 | The proxy host's forward hostname or port is wrong, or the stack isn't on NPM's network: `docker network inspect web` must list `<slug>-api` and `<slug>-web`. |
| Deploy fails: `network web declared as external, but could not be found` | NPM's network has another name; set `PROXY_NETWORK`. On a server without NPM: `docker network create web`. |
| Pull fails: `denied` / `unauthorized` | Portainer's `ghcr` registry is missing or its token expired (step 2). |
| `exec format error` | An image not built for arm64; check the Images workflow ran for this tag. |
| Links in emails or the admin panel are `http://` | `TRUSTED_PROXIES` overridden or empty. The stack default trusts Docker's private ranges. |
| No sign-in code in the logs | `MAIL_MAILER` must be `log` and `LOG_LEVEL` `debug` (the log mailer writes at debug level). |
| Backup or drill failure email | `php artisan platform:backups`, then the `<slug>-scheduler` logs. "BACKUP_DRILL_DATABASE is not set" or "Access denied" means the drill database setup above is missing. |
| Signed out on every request | `APP_KEY` changed, or `SESSION_SECURE_COOKIE=true` without HTTPS. Behind the tunnel, HTTPS is always on. |
