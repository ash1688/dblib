# Deploying dblib

dblib is a PHP 8.2 / Apache app backed by MariaDB, bundled as a self-contained
`docker compose` stack. **The `deploy` branch is the one to point deployments
at.** Its compose file has no Dokploy-specific content, so the same branch moves
unchanged from the Dokploy box to a VM inside the college network.

Two things are true everywhere:

- The app listens on **container port 80**. A host port
  (`DBLIB_HTTP_PORT`, default `8083`) is also published so the stack works with
  no proxy in front.
- **MariaDB publishes no port.** It stays internal to the compose network.

The compose file is already production-shaped (DB unpublished, secrets
env-driven, debug off, idempotent first-boot entrypoint, app health check), so
there is no separate prod compose file.

---

## Environment variables (all targets)

The compose file has working defaults for everything, but change these before
exposing the app to anyone:

| Variable | Why |
|----------|-----|
| `DBLIB_HTTP_PORT` | Host port. `8083` is dblib's slot in `../port-registry.md` on the Dokploy box. On a VM use whatever is free (`80` if nothing else listens). |
| `DBLIB_DB_ROOT_PASS` | MariaDB root password. Change off the `rootpass` default. |
| `DBLIB_DB_ADMIN_PASS` | App provisioning-account password. Change off the default. |
| `DBLIB_TEACHER_EMAIL` | The first teacher login, created on first boot. |
| `DBLIB_TEACHER_PASSWORD` | Change off `changeme`. |
| `DBLIB_TEACHER_NAME` | Display name for that teacher. |
| `DBLIB_TRUSTED_PROXIES` | Reverse proxies allowed to set `X-Forwarded-For`. Default `172.16.0.0/12` (Docker bridge ranges, which is where Dokploy's Traefik lives). Set to empty when clients hit the port directly. |

`DBLIB_DEBUG` stays `false` (never show stack traces on a shared box).

> `DBLIB_DB_ADMIN_PASS` is read by both the DB container (to create the
> provisioning account on first init) and the app (to connect). If you change it
> after the data volume exists, the account in MariaDB won't update. Rotate it in
> the DB, or re-init with `down -v` (which destroys all data, see below).

---

## Target A: Dokploy (subdomain via Traefik)

1. **Create the service.** New project → **Compose** (not Application). Provider:
   this repo, branch **`deploy`**, compose path `docker-compose.yml`.
2. **Environment.** Paste the variables above into the Environment tab. Dokploy
   writes them to a `.env` beside the compose file, which is exactly what the
   `${VAR:-default}` references expect.
3. **Domain.** Domains tab → Add Domain. Host = your subdomain, **service =
   `app`, container port = `80`**. Turn HTTPS on only if the subdomain resolves
   publicly (Let's Encrypt needs to reach it); for an internal-only DNS name
   leave it HTTP or attach your own certificate.
   Dokploy attaches its `dokploy-network` to every service in the compose file
   at deploy time, which is how Traefik reaches `app`. Leave the **Isolated
   Deployment** toggle off, or that attachment doesn't happen.
4. **Deploy.** Traefik starts routing once the `app` health check passes, which
   is after the entrypoint has finished the first-boot steps below.
5. **Optional: firewall the host port.** The `DBLIB_HTTP_PORT` mapping still
   exists, so `http://<server-ip>:8083/` works alongside the domain. If you want
   the domain to be the only route, set `DBLIB_HTTP_PORT=127.0.0.1:8083`.

Behind Traefik with HTTPS the app sees `X-Forwarded-Proto: https` and switches
the session cookie to `Secure` automatically. No code change needed.

## Target B: a VM with Docker (college network)

Same branch, same file, no edits:

```bash
git clone -b deploy <repo-url> dblib && cd dblib
cp .env.example .env        # set passwords, teacher, and DBLIB_HTTP_PORT
docker compose up -d --build
sudo ufw allow 8083/tcp     # or whatever DBLIB_HTTP_PORT you chose
```

Browse to `http://<vm-ip>:8083/`. If no reverse proxy sits in front, set
`DBLIB_TRUSTED_PROXIES=` (empty) in `.env` so LAN clients can't spoof their
address in the Apache logs. If you later front it with Caddy/nginx/Traefik on
the same VM, put that proxy's address range in `DBLIB_TRUSTED_PROXIES` and
have it forward to `127.0.0.1:8083`.

To update: `git pull && docker compose up -d --build`. Code is baked into the
image, so a rebuild is required; the schema migration is idempotent.

## Target C: XAMPP VM (no Docker)

The app code is identical, only the runtime differs. Follow the README's XAMPP
setup: copy the tree to `htdocs/dblib`, create `config/config.php` from
`config/config.example.php`, run `cli/genkey.php` and `cli/migrate.php`. To
carry data across from a Docker deployment, dump `dblib_meta` plus every
`dblib_stu_*` database with `mariadb-dump --all-databases` from the `db`
container, and copy `credential.key` off the `secrets` volume to the path in
`config.php`. Without that key file every stored student credential is lost.

---

## What happens on first boot

The `app` entrypoint, on every start:

1. waits for MariaDB and the `dblib_admin` provisioning user from
   `docker/initdb`,
2. generates the AES-256-GCM credential key onto the `secrets` volume (once),
3. applies the metadata schema and creates the first teacher (idempotent),
4. starts Apache. Only now does the container report healthy.

## Verify

Sign in as the teacher, then provision a student from inside the app container:

```bash
docker compose exec app php cli/provision_student.php \
  --student-id=S1234567 --password=changeme --name="Alice Smith"
```

(On Dokploy, use the service's Terminal tab or `docker exec` into the `app`
container.)

## Gotchas

- **Persistent volumes:** `db-data` (MariaDB) and `secrets` (the encryption
  key). **Do not** `docker compose down -v` on a server. It destroys all student
  databases *and* the key that decrypts their stored credentials. Dokploy
  preserves named volumes across normal redeploys.
- **The key is irreplaceable:** back up the `secrets` volume. Moving hosts
  means moving both volumes (or a dump plus the key file).
- **HTTP-only deployments** (IP + port, or an internal subdomain without TLS)
  serve the session cookie without `Secure`. Fine on the LAN; revisit if the
  app ever becomes reachable from outside.
