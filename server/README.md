# Server

Placeholder for the server side, which is maintained separately from the bridge.

What runs on `192.168.1.42` today:

| Part | Live location | In this repo |
|---|---|---|
| PHP API + web UI (`/trail_cam/api/...`, MySQL) | `/var/www/html/trail_cam` | Not yet imported (`server/api/` suggested). |
| GPRT tunnel receiver (`gardepro-tunnel.service`, TCP 6000) | `/var/www/html/camera/gardepro_tunnel_server.py` | `tunnel/gardepro_tunnel_server.py` (from the old repo; the live copy has an uncommitted change). |

The bridge's tests import `tunnel/gardepro_tunnel_server.py` as the far end of
the tunnel, so keep it at that path or update `bridge/tests/test_desktop_bridge.py`.

Don't commit secrets: the API's `mysql` credentials file and `storage/` media
directory should stay out of the repo.
