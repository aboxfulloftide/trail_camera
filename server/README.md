# Server

Placeholder for the server side, which is maintained separately from the bridge.

What runs on `192.168.1.42` today:

| Part | Live location | In this repo |
|---|---|---|
| PHP API + web UI (`/trail_cam/api/...`, MySQL) | `/var/www/html/trail_cam` | `api/` |
| GPRT tunnel receiver (`gardepro-tunnel.service`, TCP 6000) | `/var/www/html/camera/gardepro_tunnel_server.py` | `tunnel/gardepro_tunnel_server.py` (from the old repo; the live copy has an uncommitted change). |

The bridge's tests import `tunnel/gardepro_tunnel_server.py` as the far end of
the tunnel, so keep it at that path or update `bridge/tests/test_desktop_bridge.py`.

Don't commit secrets: the API's `mysql` credentials file and `storage/` media
directory should stay out of the repo.

## PHP API deployment

`api/` is the deployable document root for the PHP 8 API and web UI. Configure
the web server to serve it at the desired mount point (the current server uses
`/trail_cam`). Copy the `mysql` credentials file beside `api/index.php` on the
server; it is intentionally not tracked. Runtime media is written below
`api/storage/`, which is also intentionally not tracked.

The API creates its MySQL tables, including `camera_connections`, on the first
database-backed request. See [`../docs/CAMERA_CONNECTIONS_SERVER_HANDOFF.md`](../docs/CAMERA_CONNECTIONS_SERVER_HANDOFF.md)
for the camera connection endpoint contract and verification steps.
