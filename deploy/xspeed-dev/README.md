# Agent Notes on xspeed-dev (fork only)

This is the runbook for the continued source, `alimuzzaman/agent-notes`, on the Sandbox remote `xspeed-dev`. Upstream PR #38 is outside this deployment path. [`deploy/README.md`](../README.md) describes the generic listener setup.

The server inventory records an earlier deployment at `eb3345e` on 2026-11-10. This is historical evidence, not confirmation of the current runtime. Observe the recorded and running revisions before selecting an upgrade or recovery. The root owns integration, short checks, the personal source-review gate and release authorization; these steps grant no authority to deploy.

| Name | Scope | How it is served |
|------|-------|------------------|
| `share.xspeedcdn.online` | Public host: public notes, assets, share links | Sandbox front door (`sandbox.hosting.yml`) → loopback edge port → `web:8080` |
| `notes.xspeedcdn.online` | Full app: MCP, dashboard, every note | Hand-made nginx block on the tailnet IP only → `127.0.0.1:8091` → `web:8081` |

The scope comes from the listener (see `deploy/README.md`), so the only thing keeping the full app private is that `8091` is bound to `127.0.0.1` and the `notes` server block listens only on `100.64.181.113`.

Server facts used below are from `xspeed-hub/test-stacks/server-inventory/sandbox-ops.md` (2026-11-10): tailnet IP `100.64.181.113`, public IP `212.95.33.238`, wildcard `*.xspeedcdn.online` → public IP, edge lock `/run/lock/sandbox-edge-nginx.lock`, `sudo nginx -t` before every front-door change.

## 1. Before applying

- Confirm the existing target owns loopback port `8091`. An existing Agent Notes listener is expected on an upgrade; do not remove it to make the port free. On a fresh installation the port must be free. If another workload owns it, choose a free port and change `AGENT_NOTES_TAILNET_PORT` in `sandbox.hosting.yml` and the `proxy_pass` below together.
- The branch must be pushed: the manifest's `allowed_branches` are `main` and `feat/html-notes-images-visibility`, and `require_clean: true`.
- Use `/Users/alim/Sites/git/.scratch/sandbox/controller-e46777b/sb`, with `SANDBOX_AUTO_UPDATE=0`. Its local revision is `e46777bf0868d4ecc38bffdbf8f228828790ddbf`; root must confirm the remote runtime matches. Do not migrate the controller as part of an app deployment.
- Root must serialize host work, confirm no competing jobs and check the edge-nginx, hosting-caddy and docker-pool locks using the server ops procedure. Preserve unrelated workloads.
- Before migrations, arrange a verified, access-controlled database backup and matching asset backup, including a demonstrated restore into an isolated target. Keep backup contents and credentials out of logs. Record the existing compose project, volume identities, source revision and images. Preserve the permanent target `agent-notes/sandbox` and its volumes throughout the upgrade.

## 2. Secrets (names only)

Only the database passwords, both generated. Preserve existing values on an upgrade. Before any credential use, load the Sandbox secret-inspection procedure. Where a host operation needs the registered Cloudflare credential, the ops procedure uses `sb secrets run --source personal --key XSPEED_CF_API_TOKEN --destination CLOUDFLARE_API_TOKEN -- …`. The older `--secret A=B` form is refused. Never inspect or source secret files.

| Container key | Sandbox secret | How |
|---------------|----------------|-----|
| `DB_PASSWORD` | `AGENT_NOTES_DB_PASSWORD` | generated: `sb host secrets --project-dir <fork> --environment sandbox --generate` |
| `DB_ROOT_PASSWORD` | `AGENT_NOTES_DB_ROOT_PASSWORD` | generated (same command) |

No mail. The owner decided everyone on the tailnet is trusted, so `FEATURE_TAILNET_TRUST` signs tailnet visitors in as `notes-admin@example.test` (a placeholder identifier that is never mailed). It is created as admin on first use.

## 3. Plan and approved apply (public host)

Command templates for the root, not evidence of execution:

```sh
SB=/Users/alim/Sites/git/.scratch/sandbox/controller-e46777b/sb
export SANDBOX_AUTO_UPDATE=0
# Set FORK to the clean, reviewed fork checkout and DEPLOY_SHA to its full commit SHA.
"$SB" host validate --project-dir "$FORK" --environment sandbox --json
"$SB" host status --project-dir "$FORK" --environment sandbox --remote xspeed-dev --json
"$SB" host plan --project-dir "$FORK" --environment sandbox --remote xspeed-dev --json
```

After root-run checks, independent personal source review and explicit release authorization, submit one durable local job. Wrap the entire `job-start` in the approved secret broker when needed, with a bounded broker timeout of at most 1800 seconds. `host apply` must be its direct executable child, not a shell wrapper:

```sh
"$SB" job-start --local --project-dir "$FORK" \
  --source-commit "$DEPLOY_SHA" --request-id "$APPLY_REQUEST" --timeout 1500 -- \
  "$SB" host apply --project-dir "$FORK" --environment sandbox \
  --remote xspeed-dev --lock-wait 60 --confirm --json
```

Use one unique request ID and retain the job ID and complete result. Observe at intervals of at least 30 seconds. Check terminal job success and JSON `ok:true`; shell success alone is insufficient. After a timeout, inspect that original attempt before any retry.

Apply converges the DNS-only A record `share.xspeedcdn.online` → `212.95.33.238`, its ACME certificate and the managed public front door. Do not hardcode its allocated loopback edge port. Both app and web images must rebuild. `migrate` imports `schema.sql` only into an empty database; existing installations receive unapplied migrations before the app starts. Migration 002 widens slugs, changes their comparison to case-sensitive and invalidates cached PDFs. It does not delete notes or assets.

`deploy.derived_environment.APP_SOURCE_REVISION: pushed_commit_sha` binds deployment evidence to the selected commit. Compose passes it to all persistent services (`web`, `app`, `db`) and the initializer. Root must compare requested, recorded and observed revisions. This metadata is not a substitute for image trust or runtime acceptance. Earlier deployments without this declaration can refuse same-revision replay as unproven; use the recorded recovery procedure below instead of blind retries.

Sections 4–6 describe initial tailnet provisioning. On an existing installation, verify and preserve its DNS, certificate and nginx block; repair only a confirmed mismatch under separate authorization.

## 4. DNS (tailnet name, by hand)

In zone `xspeedcdn.online` (token `XSPEED_CF_API_TOKEN`): an **A record `notes` → `100.64.181.113`, DNS only (not proxied)**, like `miu`, `mail-dev` and `mail-setup-plan`. It overrides the public wildcard for that name. Check: `dig +short notes.xspeedcdn.online @1.1.1.1` prints `100.64.181.113`.

## 5. Certificate (tailnet name, DNS-01 with lego on the Mac)

HTTP-01 can't reach a tailnet-only name, so use the same lego procedure as the other tailnet names:

```
./sb secrets run --source personal --key XSPEED_CF_API_TOKEN --destination CLOUDFLARE_DNS_API_TOKEN \
  --timeout-seconds 300 --project-dir /Users/alim/Sites/git/sandbox -- \
  lego run --path /Users/alim/Sites/git/.scratch/miu/tls --email admin@xspeedcdn.online \
  --dns cloudflare --domains notes.xspeedcdn.online --key-type ec256 --accept-tos
```

Copy `certificates/notes.xspeedcdn.online.crt` and `.key` to the server over SSH as:

- `/etc/nginx/agent-notes-tailnet/notes.xspeedcdn.online.fullchain.pem`, mode 644 root
- `/etc/nginx/agent-notes-tailnet/notes.xspeedcdn.online.key`, mode 600 root

Expiry is 90 days. Add it to the renewal list next to the other tailnet names (renew with `lego renew` through the same wrapper).

## 6. nginx block (tailnet name, by hand)

`/etc/nginx/conf.d/agent-notes-tailnet.conf`, edited under the edge lock: `sudo flock /run/lock/sandbox-edge-nginx.lock sh -c 'sudo nginx -t && sudo systemctl reload nginx'` after writing it, and `sudo nginx -t` before.

```nginx
# Agent Notes full app, tailnet only: matched only on xspeed-dev's Tailscale address.
# Public connections to 212.95.33.238 never reach this server block.
server {
    listen 100.64.181.113:80;
    server_name notes.xspeedcdn.online;
    return 308 https://$host$request_uri;
}
server {
    listen 100.64.181.113:443 ssl;
    http2 on;
    server_name notes.xspeedcdn.online;
    ssl_certificate     /etc/nginx/agent-notes-tailnet/notes.xspeedcdn.online.fullchain.pem;
    ssl_certificate_key /etc/nginx/agent-notes-tailnet/notes.xspeedcdn.online.key;
    client_max_body_size 16m;   # upload_asset bodies (5 MB image ~ 7 MB base64)
    location / {
        proxy_pass http://127.0.0.1:8091;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;   # overwrite, never append
        proxy_set_header X-Forwarded-Proto https;
        proxy_read_timeout 120s;
    }
}
```

## 7. First sign-in and checks

The manifest sets `SLUG_BYTES=16` (new notes get 22-character random slugs; old 8-character links keep working) and `ROBOTS_NOINDEX=true`. The `migrate` service applies migration 002 before the app starts. It widens `notes.slug` and clears cached PDFs once.


1. On a tailnet device, open `https://notes.xspeedcdn.online/dashboard`. You are signed in as `notes-admin@example.test` with no code. Create an API token there.
2. Connect an agent: `claude mcp add --transport http --scope user agent-notes https://notes.xspeedcdn.online/mcp --header "Authorization: Bearer <token>"`.
3. From a machine **off** the tailnet, each of these must return the same `404 Not found.` with no `Set-Cookie`: `https://share.xspeedcdn.online/`, `/dashboard`, `/login`, `POST /mcp`, and `/n/<slug>` of a tailnet note. `/robots.txt` returns 200.
4. Off the tailnet, `https://notes.xspeedcdn.online` must not connect (the name resolves to the tailnet IP).
5. Every response on both hosts carries `X-Robots-Tag: noindex, nofollow, noarchive`: `curl -sI https://share.xspeedcdn.online/ | grep -i x-robots` (the 404 too).
6. Create a share link from the dashboard or with `create_share_link`, then open it off the tailnet. It lands on `/n/<slug>` with no token in the address bar. Revoke it, and a reload returns 404.

## 8. Recovery that preserves data

`host destroy` is teardown, not rollback: it deletes the hosted data volumes. Do not run it, `compose down -v`, volume removal, or DNS/certificate deletion to recover this permanent service.

1. Keep the original job ID, request ID, candidate SHA and attempt evidence. Root observes `host status` and `delivery inspect` before deciding what changed. Do not retry apply merely to discover the outcome. Keep existing database/assets volumes and credentials intact.
2. For a failed current-contract job whose exact runtime is already ready, use the installed controller's observation-only receipt recovery. It refuses legacy or mismatched evidence; it does not rebuild images, rerun migrations or change routes:

   ```sh
   "$SB" host recover --project-dir "$FORK" --environment sandbox --remote xspeed-dev \
     --job-id "$JOB_ID" --original-request-id "$APPLY_REQUEST" \
     --request-id "$RECOVERY_REQUEST" --expected-generation "$GENERATION" --json
   ```

   Resolve these identifiers from the original attempt and current target evidence. A refusal is not permission to discard a fence, edit receipts or retire a delivery automatically.
3. If only the managed public front door needs repair, review the plan from `host refresh-front-door --remote xspeed-dev --target agent-notes/sandbox --json`. A confirmed refresh needs separate authorization and preserves the application and volumes. The hand-managed tailnet block still requires its own locked, reviewed procedure.
4. If code or schema recovery is required, stop for a concrete owner-reviewed plan. Prefer a forward fix on the fork using the same target and volumes. Restoring older code does not undo migrations or establish schema compatibility. Migration 002 has no supplied down migration, and narrowing slugs could lose data. If restoration is necessary, validate the database and asset backup together in isolation before an authorized cutover. Do not replace live data with an unverified backup.

No destructive recovery or redeployment is authorized merely by following this runbook. Completion requires observed revision, health and behavior, not just a successful receipt update.
