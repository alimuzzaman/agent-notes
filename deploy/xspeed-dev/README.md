# Agent Notes on xspeed-dev (fork only)

This is the runbook for the `alimuzzaman/agent-notes` fork on the Sandbox remote `xspeed-dev`. It is not part of the upstream PR: the upstream-ready, placeholder version is [`deploy/README.md`](../README.md). Nothing here has been applied yet.

| Name | Scope | How it is served |
|------|-------|------------------|
| `share.xspeedcdn.online` | Public host: public notes, assets, share links | Sandbox front door (`sandbox.hosting.yml`) → loopback edge port → `web:8080` |
| `notes.xspeedcdn.online` | Full app: MCP, dashboard, every note | Hand-made nginx block on the tailnet IP only → `127.0.0.1:8091` → `web:8081` |

The scope comes from the listener (see `deploy/README.md`), so the only thing keeping the full app private is that `8091` is bound to `127.0.0.1` and the `notes` server block listens only on `100.64.181.113`.

Server facts used below are from `xspeed-hub/test-stacks/server-inventory/sandbox-ops.md` (2026-11-10): tailnet IP `100.64.181.113`, public IP `212.95.33.238`, wildcard `*.xspeedcdn.online` → public IP, edge lock `/run/lock/sandbox-edge-nginx.lock`, `sudo nginx -t` before every front-door change.

## 1. Before applying

- `8091` must be free on the server: `ss -ltn 'sport = :8091'` prints only the header. Sandbox's managed edge ports are 18000–18999 and stack Mailpits use 18100–18199, so 8091 doesn't collide with them. If it is taken, change `AGENT_NOTES_TAILNET_PORT` in `sandbox.hosting.yml` and the `proxy_pass` below together.
- The branch must be pushed: the manifest's `allowed_branches` are `main` and `feat/html-notes-images-visibility`, and `require_clean: true`.

## 2. Secrets (names only)

From the Mac, in the fork checkout. Every host command builds a Cloudflare client, so wrap it as the ops doc says (`sb secrets run --source personal --secret XSPEED_CF_API_TOKEN=CLOUDFLARE_API_TOKEN -- …`).

| Container key | Sandbox secret | How |
|---------------|----------------|-----|
| `DB_PASSWORD` | `AGENT_NOTES_DB_PASSWORD` | generated: `sb host secrets --project-dir <fork> --environment sandbox --generate` |
| `DB_ROOT_PASSWORD` | `AGENT_NOTES_DB_ROOT_PASSWORD` | generated (same command) |
| `ADMIN_EMAIL` | `AGENT_NOTES_ADMIN_EMAIL` | `--set AGENT_NOTES_ADMIN_EMAIL`: the seeded admin's sign-in address (it signs in by emailed code) |
| `MAIL_PRIMARY_HOST` | `AGENT_NOTES_MAIL_HOST` | `--set` |
| `MAIL_PRIMARY_USERNAME` | `AGENT_NOTES_MAIL_USERNAME` | `--set` |
| `MAIL_PRIMARY_PASSWORD` | `AGENT_NOTES_MAIL_PASSWORD` | `--set` |
| `MAIL_PRIMARY_FROM_ADDRESS` | `AGENT_NOTES_MAIL_FROM_ADDRESS` | `--set` |

`MAIL_PRIMARY_PORT` (587) and `MAIL_PRIMARY_ENCRYPTION` (tls) are plain values in the manifest; change them there if the SMTP server needs others. The failover SMTP block is left empty. `ADMIN_EMAIL` only applies when `migrate` creates a fresh database.

## 3. Apply (public host)

```
sb host validate --project-dir <fork> --environment sandbox
sb host plan     --project-dir <fork> --environment sandbox --remote xspeed-dev
sb host apply    --project-dir <fork> --environment sandbox --remote xspeed-dev --confirm
```

Wrap `apply` in `sb job-start` as the hosting doc describes, and check the JSON for `ok:true` (migrate can print success with `ok:false`). Apply creates the DNS-only A record `share.xspeedcdn.online` → `212.95.33.238` (`cloudflare.dns: exact`), the ACME certificate and `conf.d/sandbox-host-agent-notes-sandbox.conf`. It runs `migrate` once, which imports `schema.sql` into the empty database.

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

1. On a tailnet device, open `https://notes.xspeedcdn.online/login`, choose the email code and use `ADMIN_EMAIL`. The first sign-in shows an API token once.
2. Connect an agent: `claude mcp add --transport http --scope user agent-notes https://notes.xspeedcdn.online/mcp --header "Authorization: Bearer <token>"`.
3. From a machine **off** the tailnet, each of these must return the same `404 Not found.`: `https://share.xspeedcdn.online/`, `/dashboard`, `/login`, `POST /mcp`, and `/n/<slug>` of a tailnet note. `/robots.txt` returns 200.
4. Off the tailnet, `https://notes.xspeedcdn.online` must not connect (the name resolves to the tailnet IP).
5. Create a share link with `create_share_link`, open it off the tailnet: it lands on `/n/<slug>` with no token in the address bar. Revoke it; a reload returns 404.

## 8. Rollback

`sb host destroy --project-dir <fork> --environment sandbox --remote xspeed-dev` (dry run first, then `--confirm`). It removes the containers, the `db_data` and `assets` volumes, and the front-door route. Remove `conf.d/agent-notes-tailnet.conf` (under the edge lock, `nginx -t`, reload), its certificate directory and the `notes` A record by hand.
