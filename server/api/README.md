# PHP API and web UI

This directory is the tracked source for the PHP 8/MySQL application deployed
on the server at `/trail_cam`.

Tracked files:

- `index.php` — API routes, schema installation, and the rendered UI
- `app.js` and `app.css` — browser assets

Not tracked:

- `mysql` — per-server MySQL credentials (`host`, `user`, `pass`, and
  `trailcamdb` or `db`)
- `storage/` — downloaded and generated media

For a deployment, place the credentials file beside `index.php`, ensure PHP 8
has PDO MySQL enabled, and configure the web server document root/mount to this
directory. The schema is created idempotently during the first database-backed
request.
