# Trail Camera

Bridge and server for GardePro trail cameras (E6+, E6). The bridge wakes a
camera over Bluetooth, joins its Wi-Fi hotspot, downloads and deletes media,
and uploads it to the server. The server stores the media and data, and runs
the web UI.

```
camera  <-- BLE wake / camera Wi-Fi -->  bridge  <-- LAN (HTTP + GPRT tunnel) -->  server
```

## Layout

| Path | What it is |
|---|---|
| [`bridge/`](bridge/README.md) | Desktop bridge service (`python -m desktop_bridge`), the job/radio tools it uses, tests, and the systemd unit. Runs on the bridge machine. |
| [`server/`](server/README.md) | Server side (PHP API, MySQL, GPRT tunnel receiver) at `192.168.1.42`. |
| `docs/` | Camera protocol findings, plans, and handoff notes. |
| `legacy/esp32/` | The original ESP32 (HT-HC33) bridge firmware, board experiments, and the early Python server. Kept for reference; not deployed. |

## Bridge quick start

```bash
cd bridge
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
.venv/bin/python -m unittest discover -s tests      # no radios needed
```

Deployment and operation: [`bridge/README.md`](bridge/README.md).

## History

Moved from `aboxfulloftide/esp332_camera_bridge` with full history; files were
reorganized into the layout above (`git log --follow <path>` traces them back).
