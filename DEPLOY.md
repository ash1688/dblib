# Deploying dblib to Dokploy

dblib is a PHP 8.2 / Apache app backed by MariaDB, bundled as a self-contained
`docker compose` stack. It runs on the physical Ubuntu/Dokploy box in
**IP + port mode** — reached at `http://<server-ip>:8083/`, not via a domain.

- **Host port: `8083`** (dblib's allocation in `../port-registry.md`), set via the
  `DBLIB_HTTP_PORT` env var.
- **Container-internal port: `80`** (Apache — no change needed).
- The mapping is `${DBLIB_HTTP_PORT}:80`. **MariaDB publishes no port** — it stays
  internal-only on the compose network.

Unlike the other apps, dblib's existing `docker-compose.yml` is already
production-shaped (DB unpublished, secrets env-driven, debug off, idempotent
first-boot entrypoint), so it deploys **as-is** — no separate prod compose file.

The app image has been verified locally (`docker build`, exit 0).

---

## What you must do

### 1. Set the environment variables in Dokploy

The compose file has working defaults for everything, but several **must** be
changed for a shared deployment:

| Variable | Why |
|----------|-----|
| `DBLIB_HTTP_PORT` | **Set to `8083`** — dblib's registry slot (default is `8088`). |
| `DBLIB_DB_ROOT_PASS` | MariaDB root password — change off the `rootpass` default. |
| `DBLIB_DB_ADMIN_PASS` | App provisioning-account password — change off the default. |
| `DBLIB_TEACHER_EMAIL` | The first teacher login created on first boot. |
| `DBLIB_TEACHER_PASSWORD` | **Change off `changeme`** before exposing on the LAN. |
| `DBLIB_TEACHER_NAME` | Display name for that teacher. |

`DBLIB_DEBUG` stays `false` (never show stack traces on a shared box).

> Note: `DBLIB_DB_ADMIN_PASS` is read by both the DB container (to create the
> provisioning account on first init) and the app (to connect). If you ever change
> it after the data volume already exists, the account in MariaDB won't update —
> you'd need to rotate it in the DB or re-init with `down -v`.

### 2. Create the app in Dokploy

- New project → **Compose** deployment type.
- Point it at this repo / branch, Compose file `docker-compose.yml`.
- Add the variables from step 1.
- Deploy. On first boot the entrypoint automatically:
  1. waits for MariaDB (and the `dblib_admin` provisioning user from
     `docker/initdb`),
  2. generates the AES-256-GCM credential key onto the `secrets` volume (once),
  3. applies the metadata schema and creates the first teacher (idempotent).

### 3. Open the host firewall

```bash
sudo ufw allow 8083/tcp
```

### 4. Verify

Browse to `http://<server-ip>:8083/` and sign in as the teacher from step 1.
Provision a student from inside the app container:

```bash
docker compose exec app php cli/provision_student.php \
  --student-id=S1234567 --password=changeme --name="Alice Smith"
```

---

## Notes / gotchas

- **Persistent volumes:** `db-data` (MariaDB) and `secrets` (the encryption key).
  **Do not** `docker compose down -v` on the server — it destroys all student
  databases *and* the key that decrypts their stored credentials. Dokploy
  preserves named volumes across normal redeploys.
- **The key is irreplaceable:** if the `secrets` volume is lost, every encrypted
  per-student MySQL credential becomes undecryptable. Back this volume up.
- **HTTPS / Secure cookies:** in IP+port mode the app is served over plain HTTP,
  so the session cookie's `Secure` flag stays off (it auto-enables under HTTPS or
  a trusted `X-Forwarded-Proto`). Fine for the LAN; revisit if you ever front it
  with TLS.
- **Updating:** redeploy in Dokploy. Code is baked into the image via `COPY`, so a
  rebuild picks up changes; the entrypoint's schema migration is idempotent.
