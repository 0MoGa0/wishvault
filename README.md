# WishVault

A game wishlist with voting for [GameVault](https://gamevau.lt) servers.
Players search a game (Steam store, with a fallback to the GameVault metadata
provider), wish for it and vote. Admins set a status, and wishes turn
*available* automatically once the game shows up in the GameVault library.

> WishVault is an independent project. It is **not affiliated with or
> endorsed by Phalcode or GameVault.**

## Features

- Wish for games via Steam search (name, AppID or store link), fallback search through the GameVault metadata provider (e.g. IGDB) with a switch between both
- Voting, sorting by votes or date, tabs for open / available / my votes / rejected / all, undo when your last vote removed a wish
- Status workflow `new → queued / paused / rejected → available`, reason and admin note
- Login with your GameVault account; rights follow your GameVault role (configurable thresholds)
- Optional anonymous wishes and votes for guests, moved to the account on login. Guests only see "available / not available"
- Automatic library sync: wishes become *available* when the game lands in GameVault (match by provider id, Steam AppID or title)
- Per-account notifications ("your wish is now available") for native clients, JSON API (`openapi.yaml`)
- Full admin functions in the web UI: status, reason, note, delete, Steam tags, sync now, service status
- Optional automatic cleanup of old wishes
- Web UI with themes (`default`, `gamevault`) and translations (English, German), custom CSS without a fork
- One container (`serversideup/php`, nginx + PHP-FPM), SQLite, no cron, no external database, daily backups (7 kept)

## Quick start

1. In GameVault, create a bot account (e.g. `wishvault`) and give it the role
   **Editor**. WishVault uses it read-only for the library sync and the
   provider search (GameVault requires Editor for the provider search).
2. Copy [`compose.example.yml`](compose.example.yml), set `GAMEVAULT_URL`,
   the bot credentials and `PUBLIC_URL`, then `docker compose up -d`.
3. Put it behind a reverse proxy with HTTPS (the login cookie is `Secure`).
   The container listens on port `8080` and has a health check on
   `/api/v1/info`.

Data (SQLite database, backups, optional `custom.css`) lives in the volume
`/var/www/data`. Back it up like any other volume.

## Configuration

All settings are environment variables. Secrets are only read from the
environment, never baked into the image.

| Variable | Default | Description |
| --- | --- | --- |
| `GAMEVAULT_URL` | – | **Required.** Base URL of the GameVault server as WishVault reaches it (can be an internal address) |
| `GAMEVAULT_PUBLIC_URL` | `GAMEVAULT_URL` | GameVault URL shown to users (links) |
| `GAMEVAULT_USERNAME` / `GAMEVAULT_PASSWORD` | – | Bot account with the role **Editor**. Without it there is no library sync and no provider search |
| `PUBLIC_URL` | – | Public URL of WishVault, e.g. `https://wishes.example.com` (allowed origin, `Secure` cookies) |
| `TRUSTED_PROXIES` | – | Comma-separated IPs/CIDRs of your reverse proxy, so client IPs and HTTPS are detected |
| `WISH_MIN_ROLE` | `1` | Minimum GameVault role to wish and vote with an account (0 guest, 1 user, 2 editor, 3 admin) |
| `ADMIN_MIN_ROLE` | `3` | Minimum GameVault role for admin functions |
| `ANON_ENABLED` | `true` | Allow anonymous wishes and votes without login |
| `ANON_WISH_LIMIT_PER_HOUR` | `10` | New wishes per hour per anonymous visitor and IP |
| `AUTO_AVAILABLE` | `true` | Set wishes to *available* automatically when the game is found in GameVault |
| `SYNC_SECONDS` | `900` | Library sync interval (minimum 60) |
| `STEAM_RECHECK_HOURS` | `24` | Interval for re-checking release dates of upcoming games |
| `PURGE_AVAILABLE_DAYS` | `0` | Delete available wishes this many days after they became available (`0` = never) |
| `PURGE_REJECTED_DAYS` | `0` | Delete rejected wishes this many days after the rejection (`0` = never) |
| `PURGE_OPEN_DAYS` | `0` | Delete open wishes (`new`, `paused`) without activity (vote, status change) for this many days (`0` = never) |
| `PURGE_QUEUED_DAYS` | `0` | Same for `queued` wishes (`0` = never) |
| `STEAM_COUNTRY` | `US` | Store region for search and release dates |
| `STEAM_LANGUAGE` | `english` | Language of Steam titles and categories |
| `STEAM_TAGS_LANGUAGE` | `english` | Language of Steam user tags (admin) |
| `DEFAULT_LOCALE` | `en` | Web UI language when the browser language is not available (`en`, `de`) |
| `SITE_NAME` | `WishVault` | Name in the header and page title |
| `SITE_LOGO_URL` | – | Logo in the header instead of the built-in one |
| `THEME` | `default` | Web UI theme: `default` or `gamevault` (see `web/src/themes/`) |
| `CLIENT_DOWNLOAD_URL` | – | Shows a "get the desktop client" hint linking here (empty = hidden) |
| `TZ` | `UTC` | Time zone of the container |

Custom styling: put a `custom.css` into the data volume; it is loaded after
the theme.

### Upgrading

Pull the new image and restart. The database schema is migrated on start.
The worker keeps the last 7 daily backups in `/var/www/data/backups`.

## Development

Requirements: PHP ≥ 8.4 (pdo_sqlite, curl, mbstring, intl), Composer, Node 24, pnpm.

```bash
composer install
cp .env.example .env                 # point it to a GameVault server
composer serve                       # API on http://127.0.0.1:8080
php bin/worker.php                   # background jobs (separate terminal)

cd web && pnpm install
pnpm dev                             # web UI with proxy to :8080
pnpm mock                            # or: mock API from openapi.yaml on :4010
WV_API=http://127.0.0.1:4010 pnpm dev

composer lint && composer stan && composer test
pnpm --dir web check:i18n && pnpm --dir web build
```

`openapi.yaml` is the contract between the service, the web UI and other
clients. Change it first, then the code.

## License

[AGPL-3.0-only](LICENSE). If you run a modified version as a service, you must
offer its source code to the users of that service.
