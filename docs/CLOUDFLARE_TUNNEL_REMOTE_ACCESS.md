# Remote Access for Direct HTTPS Runners via Cloudflare Tunnel

Design for onboarding laptops at remote locations that have no technical staff and no access to the internal CA. The public hostname is `inventory.tvripapua.web.id` (DNS for `tvripapua.web.id` is on Cloudflare). Status: design only, nothing here has been applied to any server.

## Goal

- A remote laptop installs the runner with one click and needs no certificate step: Windows already trusts the Cloudflare edge certificate.
- The server is not opened to the internet by port forwarding. `cloudflared` makes an outbound connection.
- Only the runner endpoints are reachable from the internet. The portal, login, and legacy collector/read APIs stay internal.

## Architecture

```
Remote laptop (runner, Direct HTTPS)
        |  HTTPS, publicly trusted certificate (Cloudflare edge)
        v
Cloudflare edge  --- WAF / rate limit ---
        |  outbound tunnel (opened by cloudflared)
        v
cloudflared service on Supermicro (loopback)
        |  http://localhost:8000   (path-filtered)
        v
Laravel (php artisan serve, port 8000)

Internal PCs keep using https://inventory-pilot.internal.lan (Caddy), unchanged.
```

## What is exposed

Only these paths are forwarded; every other path returns 404 at the tunnel:

| Path | Purpose |
|---|---|
| `POST /api/direct-runner/heartbeat` | runner heartbeat |
| `POST /api/direct-runner/scans` | scan upload |
| `POST /api/direct-runner/commands/poll` | command poll |
| `POST /api/direct-runner/commands/ack` | command ACK |
| `GET /health` | installer health check |

Path regex for the tunnel rule:

```
^/(api/direct-runner/(heartbeat|scans|commands/(poll|ack))|health)$
```

Not exposed: `/login`, the admin portal, `/api/devices`, `/api/runners`, `/api/collector/*`, `/api/intake/*`.

## Prerequisites

- A Cloudflare account that owns the `tvripapua.web.id` zone, with 2-step verification enabled.
- Administrator access on Supermicro (`SRV-SERVER01`).
- Application changes already merged: signed updates, site-token enforcement, upload size limit, login and API throttling (waves 1 to 6).
- This change set, which trusts the loopback proxy so the real client IP is used (see "Application settings").

Cost: Cloudflare Tunnel and the Free plan are free. The 50-user limit applies to Zero Trust user seats (Access/WARP), which this design does not use because runners authenticate with site tokens. Confirm current pricing on Cloudflare's own pricing page before committing.

## Steps

### 1. Create the tunnel (Cloudflare dashboard)

1. Zero Trust, Networks, Tunnels, Create a tunnel, type Cloudflared. Name it `inventory-supermicro`.
2. Choose the Windows connector. The dashboard shows an install command that contains a **tunnel token**. The token is a secret: do not paste it in chat, tickets, or Git. Use it only in step 2.
3. Add a public hostname:
   - Subdomain `inventory`, domain `tvripapua.web.id`
   - Path: the regex above
   - Service: `HTTP`, `localhost:8000`
4. Leave the default catch-all as `http_status:404` so unmatched paths are refused.

Cloudflare creates the DNS record for `inventory` automatically.

### 2. Install the connector on Supermicro

Run in an elevated PowerShell on Supermicro, using the command from the dashboard (it installs `cloudflared` as a Windows service that starts at boot). After install:

```powershell
Get-Service cloudflared | Format-Table Name,Status,StartType
```

Expected: `Running` and `Automatic`. The service runs outbound only; no firewall inbound rule is needed.

### 3. Application settings (`.env` on Supermicro)

```
INVENTORY_REQUIRE_SITE_TOKENS=true
INVENTORY_ALLOW_QUERY_SITE_TOKEN=false
```

- `INVENTORY_ALLOW_QUERY_SITE_TOKEN=false` matters once the API is public: tokens in URLs end up in edge and proxy logs. The runner sends tokens in headers (`X-Site-Id`, `X-Site-Token`).
- `TrustProxies` now trusts `127.0.0.1` in addition to the Caddy address. `cloudflared` connects over loopback and forwards the real client address in `X-Forwarded-For`. Without this, every remote runner would appear as `127.0.0.1` and share one 60 requests/minute throttle bucket.
- After editing `.env`: `C:\php\php.exe artisan config:clear`.

Rotate the site token (`inventory:rotate-site-token`) after the public hostname is live if the old token was ever exposed in a place you do not control, and rebuild kits afterwards.

### 4. Cloudflare protections (dashboard)

- Security, WAF, Rate limiting: add one rule for host `inventory.tvripapua.web.id` (for example 100 requests per 10 seconds per IP, action Block). Free plans have a small number of rules; one is enough.
- Keep Bot Fight Mode off for this hostname if it blocks the runner (the runner is a script, not a browser); verify in step 6.
- Optionally restrict by country if all remote sites are in one country.

### 5. Build the kit for a remote site

1. Copy `deployment/profiles/site_remote_direct_https.sample.json` to a real profile on Supermicro, set the real `site_id` and `site_name`, and keep `server_base_url` at `https://inventory.tvripapua.web.id`.
2. Register a direct runner token for that site: `inventory:register-site-token <SITE> --type=direct_runner --reveal`.
3. Build on Supermicro only: `inventory:build-site-kit --profile=<profile> --strict --exe`.
4. Deliver the exe to the remote site. It embeds the site token: treat it as a secret and use a channel you control (not a public link).

### 6. Verification (before any remote laptop)

From any internet-connected machine that is not on the internal network:

```
curl -s -o NUL -w "%{http_code}" https://inventory.tvripapua.web.id/health            -> 200
curl -s -o NUL -w "%{http_code}" https://inventory.tvripapua.web.id/login             -> 404
curl -s -o NUL -w "%{http_code}" https://inventory.tvripapua.web.id/api/devices       -> 404
curl -s -o NUL -w "%{http_code}" -X POST https://inventory.tvripapua.web.id/api/direct-runner/heartbeat   -> 401 or 422 (reaches the app, rejected without a token)
```

Any other result means the path rule is wrong. Then install the exe on one pilot remote laptop and confirm, in the portal, a Direct HTTPS runner with a recent heartbeat, an upload, and a successful manual scan.

## Operations

- `cloudflared` is a single point of failure for remote runners. If it stops, runners keep their data in the local outbox and upload with backoff after recovery (up to about 6 hours of delay after a long outage). Add `cloudflared` to the stale-runner alert follow-up and check the service in the Supermicro health routine.
- Updates: keep `cloudflared` current; it self-reports version in the dashboard.
- `php artisan serve` handles one request at a time. Public traffic from many sites increases queueing; plan a production web server (for example behind the existing Caddy) before onboarding many remote sites.

## Rollback

1. Stop exposure immediately: in the dashboard, disable or delete the public hostname (or pause the tunnel). Internal runners are unaffected.
2. On Supermicro: `Stop-Service cloudflared` (and `sc.exe delete cloudflared` to remove).
3. Rotate the direct runner token for any site whose kit was distributed, and rebuild kits.

## Risks and open items

- The API becomes reachable from the internet. Defence is the site token, throttling, upload limit, WAF rate limit, and the path allow-list. A leaked kit exe leaks a working token for that site.
- Per-runner tokens (instead of one token per site) are not implemented; one leaked kit affects the whole site until the token is rotated.
- Runner update signatures protect update packages, not the initial install. Deliver kits only through trusted channels.
- If remote sites later need the portal, use Cloudflare Access (Zero Trust) in front of a separate hostname; do not add `/login` to the public path rule.
