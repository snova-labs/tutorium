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
| Variable | `COMPOSE_PROFILES=tunnel` (plus `,mailpit` on staging) and `CLOUDFLARE_TUNNEL_TOKEN` | no `tunnel` in `COMPOSE_PROFILES`, no token |
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
| `<slug>-migrate` | Runs `php artisan platform:deploy` (migrations, then the first academy and demo data if their variables ask), then exits. The others wait for it. | 256 MB |
| `<slug>-api` | Laravel: API, admin panel (`/admin`), Livewire screens, payment webhooks | 512 MB |
| `<slug>-queue` | Laravel queue worker | 256 MB |
| `<slug>-scheduler` | Laravel scheduler (trials, dunning, metering, clean-up) | 192 MB |
| `<slug>-web` | Next.js staff client | 384 MB |
| `<slug>-tunnel` | Cloudflare Tunnel connector (tunnel mode only) | 128 MB |
| `<slug>-mailpit` | Staging mail inbox: catches every email ("Mailpit: the staging inbox") | 96 MB |

`<slug>` is `tutorium-staging` for staging and `tutorium` for production. Measured idle use is about
250 MB for the whole stack.

**Switching between staging and production** changes nothing in the files: it is a second stack,
from the same repository path, with the production variables
(`deploy/portainer/production.env.example`) and its own database, tunnel and `APP_KEY`. Production
runs the image tag `production`, which only moves when you promote an image staging has already
run.

## Images: built by CI, pulled by Portainer

```
 merge to main ──▶ CI green ──▶ Images builds :staging and :sha-abc1234 ──▶ ghcr.io
                                                                              │
             Portainer → Pull and redeploy (or a poll with Re-pull image) ◀────┘
             Promote to production ──▶ :production
```

There is no deploy branch and no file to edit to ship. Two moving tags, one per environment:

- `ghcr.io/snova-labs/tutorium-api:staging` and `-web:staging` — whatever CI last built from `main`.
- `:production` — whatever **Promote to production** last moved there, always an image staging has
  already run. Nothing is rebuilt for production, so it runs the bytes that were tested.
- Every build also gets `:sha-<7 chars>`, which is how you pin or roll back: set `IMAGE_TAG` to it
  in the stack's variables and redeploy.

**Images** (`.github/workflows/images.yml`) runs itself when CI passes on `main`, so every merged
commit has an image waiting and a red CI never gets one. Run it by hand (Actions → **Images** → Run
workflow, on any branch or tag, or `make deploy REF=…`) to build an older or unmerged commit; that
moves the `staging` tag too.

A deploy, then, is Portainer pulling the tag again — see "Shipping a change".

Nothing is built on the server.

---

## One-time setup: staging

### 1. Build the first images

GitHub → **Actions** → **Images** → **Run workflow** (on `main`), or `make deploy`. When it is
green, GitHub → snova-labs → **Packages** → `tutorium-api` and `tutorium-web` each have a `sha-…`
and a `staging` tag. After this first one you need not come back here: CI passing on `main` builds
them from then on.

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

**Public hostnames** (tab on the tunnel), add three:

| Subdomain | Domain | Service type | URL |
|---|---|---|---|
| `tutorium-staging` | `jayshyampatel.com.np` | HTTP | `tutorium-staging-web:3000` |
| `tutorium-staging-api` | `jayshyampatel.com.np` | HTTP | `tutorium-staging-api:8080` |
| `tutorium-staging-mail` | `jayshyampatel.com.np` | HTTP | `tutorium-staging-mailpit:8025` |

The third is the staging mail inbox ("Mailpit: the staging inbox" below).

Cloudflare creates the DNS records itself. The names are one level deep (`tutorium-staging-api`, not
`api.tutorium-staging`) so Cloudflare's free certificate covers them.

Use the container names exactly as above (with the `tutorium-staging-` prefix), not `web` or `app`:
the prefix is what keeps the staging tunnel on staging when production runs next to it.

### 5. Put staging behind Cloudflare Access

Zero Trust → **Access** → **Applications** → **Add an application** → **Self-hosted**

- Name: `Tutorium staging`
- Application domains: `tutorium-staging.jayshyampatel.com.np`,
  `tutorium-staging-api.jayshyampatel.com.np` and `tutorium-staging-mail.jayshyampatel.com.np`
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
  - Repository reference: `refs/heads/main` (production too: one branch, and the image tag in the
    variables is what differs)
  - Compose path: `deploy/portainer/stack.yml`
  - Authentication: on, with a GitHub token that can read the repository
  - **GitOps updates**: leave off, and ship with **Pull and redeploy** (see "Shipping a change").
    Turn it on — **Polling**, `5m`, with **Re-pull image** and **Force redeployment** — if you want
    staging to follow `:staging` with no press at all.
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

A new environment has no academy, and the only way to make one is to be signed in to one — so the
stack makes the first. In Portainer → **Stacks** → `tutorium-staging` → **Editor** →
**Environment variables**, fill in:

```
FIRST_RUN_ACADEMY=Sunrise Academy
FIRST_RUN_OWNER_NAME=Jay Shyam Patel
FIRST_RUN_OWNER_EMAIL=you@example.com
FIRST_RUN_TIMEZONE=Asia/Kathmandu
```

**Update the stack**. The migrate step runs the migrations and then `platform:first-run`, which
creates the academy, its first brand and location, and the owner. Open Portainer → Containers →
`tutorium-staging-migrate` → **Logs** to read the temporary password; it is printed once and
nowhere else. Set `FIRST_RUN_PASSWORD` instead if you would rather choose it (and clear it again
afterwards).

Then empty `FIRST_RUN_ACADEMY`. Leaving it set is harmless — the command does nothing once that
academy exists, which is why it can run on every deploy — but an empty variable says plainly that
this environment is past its first run. A *different* name creates another academy.

Nothing here needs a clone of the repository, a shell, or a typed command. If you do have a clone,
`make staging academy` creates further academies interactively, and so does
`platform:provision-academy` in Portainer → Containers → `tutorium-staging-api` → **Console**:

```bash
php artisan platform:provision-academy "Second Academy" owner@example.com "Owner Name" --timezone=Asia/Kathmandu
```

Either way it prints a temporary password once. Open `https://tutorium-staging.jayshyampatel.com.np`, pass
Cloudflare Access, then sign in.

Owners, managers and accountants confirm each sign-in with an emailed code. Staging doesn't send
mail anywhere real: every email lands in the staging inbox,
`https://tutorium-staging-mail.jayshyampatel.com.np` (or `make staging codes`). To send real mail instead, set the `MAIL_*` SMTP variables and update the
stack.

Or sign up like a customer would, at `https://tutorium-staging.jayshyampatel.com.np/sign-up`. The
confirmation link goes to the same log (search for `sign-up/confirm`). Production keeps
`SIGNUP_OPEN=false` until you open it; see `SIGN-UP.md`.

Or load the demo academies at `https://tutorium-staging.jayshyampatel.com.np/demo` ("Demo academies" below).

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

## Shipping a change

1. Merge to `main`. CI runs; when it goes green, **Images** builds and pushes `:staging` by itself
   (about 10 minutes on the emulated ARM runner). Watch it in Actions, or run `make deploy` to
   kick a build off and wait for it.
2. Portainer → **Stacks** → `tutorium-staging` → **Pull and redeploy**, with **Re-pull image**
   ticked. That is the deploy: it fetches `:staging` again and recreates the containers.

   To do without even that press, turn on GitOps updates for the stack (**Polling**, every 5
   minutes) with **Re-pull image** and **Force redeployment**: the stack then pulls the tag on
   every poll and comes up on whatever CI last built. The cost is that it recreates the containers
   on each poll whether or not anything changed, so it suits staging rather than production.

3. `make staging verify` once it is up. It checks the things a deploy can break without anything
   looking wrong: the database and whether a migration is still pending, the cache, the queue and
   any failed jobs, that the mail server actually answers, that the PDF engine produces a PDF, and
   that each storage disk can be written to and read back. It exits non-zero if any check fails, so
   a monitor can run it too (`make staging verify` on a timer, or
   `php artisan platform:verify --json`). A yellow **look** is worth reading and does not fail:
   a queue running in `sync` mode, or failed jobs waiting.

Migrations run on every redeploy, before the app starts. If one fails, `tutorium-staging-migrate`
shows **exited (1)** and the app doesn't start on a half-migrated database; its **Logs** show why.

Variable changes: Stacks → `tutorium-staging` → Environment variables → edit → **Update the stack**.

## Mailpit: the staging inbox

Staging never sends real email. Its stack runs its own **Mailpit**, a mail catcher: the app hands
every email to it (sign-in codes, signup confirmations, invitations, reports with their PDFs) and
you read them in a web inbox. Nothing goes further, so the demo academies' made-up addresses and
any real address are equally safe. Production never runs it: the service sits behind the
`mailpit` profile, which only the staging variables turn on.

```
 tutorium-staging-api ──SMTP :1025──▶ tutorium-staging-mailpit ◀──https── you
                         (stack network)        inbox :8025 ◀── tunnel or NPM
```

### Step by step

1. **Turn it on in the stack's variables.** Portainer → **Stacks** → `tutorium-staging` →
   **Editor** → **Environment variables** (Advanced mode). Set:

   ```
   COMPOSE_PROFILES=tunnel,mailpit
   MAIL_MAILER=smtp
   MAIL_HOST=mailpit
   MAIL_PORT=1025
   MAIL_SCHEME=
   MAIL_USERNAME=
   MAIL_PASSWORD=
   ```

   With Nginx Proxy Manager instead of the tunnel, `COMPOSE_PROFILES=mailpit`.
   Optional: `MAILPIT_UI_AUTH=user:password` puts a password on the inbox (useful with NPM; with
   Cloudflare Access in front, leave it empty). `MAILPIT_MAX_MESSAGES` (default 5000) is how many
   it keeps; older ones are deleted, so it cannot fill the disk.

2. **Update the stack** (button at the bottom), with **Re-pull image** ticked. A new container,
   `tutorium-staging-mailpit`, starts and turns **healthy** within half a minute. The app, queue
   and scheduler restart with the new mail settings.

3. **Give the inbox an address.**
   - *Cloudflare Tunnel*: Zero Trust → **Networks** → **Tunnels** → `tutorium-staging` →
     **Public hostnames** → **Add**: subdomain `tutorium-staging-mail`, domain
     `jayshyampatel.com.np`, type **HTTP**, URL `tutorium-staging-mailpit:8025`.
   - *Nginx Proxy Manager*: **Hosts** → **Proxy hosts** → **Add**: domain
     `tutorium-staging-mail.jayshyampatel.com.np`, scheme `http`, forward hostname
     `tutorium-staging-mailpit`, port `8025`, **Websockets support** on (the inbox updates live
     through it). SSL tab: request a certificate, Force SSL on.

4. **Keep strangers out.**
   - *Cloudflare*: Zero Trust → **Access** → **Applications** → `Tutorium staging` → **Edit** →
     add `tutorium-staging-mail.jayshyampatel.com.np` to its domains → Save. Same people, same
     login as the rest of staging.
   - *NPM*: give the proxy host the same **Access List** as staging, or set `MAILPIT_UI_AUTH`
     (step 1).

5. **Check it.** Open `https://tutorium-staging.jayshyampatel.com.np/sign-in` and sign in as an
   owner (the demo's `sunita@himalayan-scholars.example`, for example). The code arrives at
   `https://tutorium-staging-mail.jayshyampatel.com.np` within a second or two. From a terminal,
   `make staging codes` lists the latest messages and their codes.

### Day to day

- Every staging email is in the inbox, newest first, with HTML, text and attachments (report
  PDFs open in the browser). Search by recipient, for example `to:megan@summitlearning.example`.
- Clear it any time: the inbox's **Delete all**. Removing the demo academies doesn't clear their
  mail; do it here.
- To go back to the log mailer: `MAIL_MAILER=log`, remove `mailpit` from `COMPOSE_PROFILES`,
  update the stack. `make staging codes` reads the logs again by itself.

## Commands on the server: `make`

A clone on the server is a convenience, not a requirement. Nothing in setup needs one: the stack
file comes from GitHub through Portainer, the settings are the stack's environment variables, and
the first academy is created by the deploy (step 7 above). Every command below can also be typed in
Portainer → Containers → `<slug>-api` → **Console** as `php artisan …`.

With that said, every routine command has a `make` target that runs it in the right container,
which is shorter to type and harder to get wrong. On the server, clone the repository once
(read-only use; a GitHub token that can read it):

**Server**
```bash
git clone https://github.com/snova-labs/tutorium.git ~/tutorium
```

Then, from `~/tutorium` (`git pull` now and then for new targets), name the environment first:
`make staging <task>` or `make production <task>`. Without it, the same targets run against the
local docker compose setup. Anything a target needs and you didn't give, it asks for.

| Task | Command |
|---|---|
| List the commands | `make help` |
| Check a release is fit to serve | `make staging verify` (after every deploy) |
| Build an image now | `make deploy` (any machine with `gh auth login`; `REF=…` for another commit). Merging to `main` does it by itself |
| Deploy what was built | Portainer → Stacks → the stack → **Pull and redeploy** |
| Promote staging to production | `make promote` (asks first; `TAG=sha-…` for an older build) |
| Create the first academy | the `FIRST_RUN_*` stack variables (step 7); no clone needed |
| Create another academy | `make production academy` (asks for the name, owner and email) |
| Load or remove the demo academies | the `/demo` page, or `make staging demo` / `make staging demo-remove` |
| Sign-in codes and confirmation links | `make staging codes` |
| Backups and drills | `make production backups`, `make production backup`, `make production drill` |
| Dump the database before a release | `make production db-dump` |
| Containers, health and memory | `make staging status` |
| Follow the logs | `make staging logs` |
| A shell in the API container | `make staging shell` |
| Any other artisan command | `make staging artisan` (asks which) |

Without a clone, run the underlying command in Portainer → Containers → `<slug>-api` → **Console**
(`php artisan platform:verify`, `php artisan platform:demo`, `php artisan platform:backup`, and so
on). `make -n <target> …` on any machine with the repository prints exactly what a target runs.

## Demo academies

Four example academies, one of each kind of customer the platform serves, each two months into a
term: registers taken (late arrivals, excused absences, a few still open), work marked in that
academy's own scheme, end-of-period notes, a withdrawal, a transfer and a pause with their reasons,
cancelled sessions, a closure day ahead, and the last finished period's reports, sent for some
classes and waiting for others.

| Academy | Kind (preset) | What it shows |
|---|---|---|
| Himalayan Scholars Academy, Kathmandu | Kids tutoring | Students and parents (siblings share them), monthly reports, points and rubrics |
| Lingua Bridge Language Centre, Dubai | Language school | Adult learners who receive their own reports, CEFR levels, 8-week terms, Friday–Saturday weekend, an online group |
| CodeCraft Institute, Lalitpur | IT and skills institute | Trainees in cohorts, 4-week blocks, labs and block projects, employers as sponsors |
| Summit Corporate Learning, Toronto | Corporate training | Participants sent by client companies whose contacts receive the reports, a virtual classroom, another timezone |

Every name is invented, every email is on a reserved domain (`example.com`, `*.example`) and every
phone number is in an unused or reserved block, so nothing reaches a real person.

### Loading and removing them

1. Set `DEMO_PASSWORD` in the staging stack's variables (Portainer → Stacks → `tutorium-staging` →
   Environment variables → **Update the stack**). Without it the page doesn't exist; on production
   it never does.
2. Open `https://tutorium-staging.jayshyampatel.com.np/demo`, enter the password and press
   **Load demo data**. It takes a minute or two (the page updates itself), then lists every account.
3. Sign in with any of them and the same password. Owners, managers and accountants confirm with an
   emailed code: `make staging codes`.
4. When you're done: the same page → **Remove demo data**. It removes the four demo academies and
   nothing else.

From a terminal instead: `make staging demo` and `make staging demo-remove` (locally: `make demo`).

Or have the deploy do it: set `SEED_DEMO=true` in the stack's variables and redeploy. It builds them
if they are not there and says so if they are, so it is safe to leave on — but it costs a couple of
minutes on every deploy, so set it back to `false` once you have them. Like the `/demo` page, it
never runs with `APP_ENV=production`.

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
7. Portainer stack `tutorium`: same repository and compose path, reference `refs/heads/main`, no
   GitOps updates (production moves only when you promote), variables from
   `deploy/portainer/production.env.example`.
8. Create the first academy: fill in `FIRST_RUN_ACADEMY`, `FIRST_RUN_OWNER_NAME`,
   `FIRST_RUN_OWNER_EMAIL` and `FIRST_RUN_TIMEZONE` and update the stack, exactly as in staging's
   step 7. The password is in the `tutorium-migrate` container's log. Empty `FIRST_RUN_ACADEMY`
   afterwards.

### Each release

1. Check the release on staging.
2. Take a database dump before the migration runs:

   **Server**
   ```bash
   make production db-dump
   ```
3. `make promote` (or Actions → **Promote to production**) with tag `staging`, or `TAG=sha-…` for
   the exact build you tested.
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
`BACKUP_DRILL_DATABASE`. Then prove it once by hand:

```bash
make staging backup && make staging drill && make staging backups
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
| Logs | `make staging logs`, or Portainer → Containers → `<slug>-api` (or `-web`, `-queue`, `-scheduler`, `-tunnel`) → Logs |
| Artisan | `make staging artisan` |
| Health | `https://<api host>/up` (alive) and `/ready` (database and cache) |
| Containers and memory | `make staging status` |
| Backups | `make production backups` |

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
| No email in Mailpit | `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, and `mailpit` in `COMPOSE_PROFILES`; then `make staging status` should list `tutorium-staging-mailpit` as healthy. Failed sends are in `make staging logs`. |
| Mailpit inbox shows Cloudflare 502 | The tunnel hostname must point at `tutorium-staging-mailpit:8025` (HTTP). |
| No sign-in code in the logs (log mailer) | `MAIL_MAILER` must be `log` and `LOG_LEVEL` `debug` (the log mailer writes at debug level). |
| Backup or drill failure email | `make production backups`, then the `<slug>-scheduler` logs. "BACKUP_DRILL_DATABASE is not set" or "Access denied" means the drill database setup above is missing. |
| Signed out on every request | `APP_KEY` changed, or `SESSION_SECURE_COOKIE=true` without HTTPS. Behind the tunnel, HTTPS is always on. |
