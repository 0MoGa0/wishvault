# WishVault

A game wishlist with voting for [GameVault](https://gamevau.lt) servers.
Players search a game (Steam store, with a fallback to the GameVault metadata
provider), wish for it and vote. Admins set a status, and wishes turn
*available* automatically once the game shows up in the GameVault library.

> **Status:** early development, not ready for use yet.
>
> WishVault is an independent project. It is **not affiliated with or
> endorsed by Phalcode or GameVault.**

## Features (planned for 1.0)

- Wish for games via Steam search (AppID or store link works too), fallback search through the GameVault metadata provider
- Voting, sorting by votes or date, status workflow `new → queued / paused / rejected → available`
- Login with your GameVault account; admin rights follow your GameVault role
- Optional anonymous wishes and votes for guests (moved to the account on login)
- Automatic library sync: wishes become *available* when the game lands in GameVault
- Per-account notifications ("your wish is now available") for native clients
- Web UI with themes and translations, JSON API (`openapi.yaml`) for other clients
- One container (`serversideup/php`, nginx + PHP-FPM), SQLite, no cron, no external database

## Quick start

See [`compose.example.yml`](compose.example.yml). WishVault needs a GameVault
bot account with the role **Editor** (used read-only for the library sync and
the provider search). Run it behind a reverse proxy with HTTPS.

The full configuration table follows with the first release.

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
