# SyncOrc — Claude Context

This is a minimal Laravel-based Synchronization Orchestration Server. Devices announce change **metadata** to the server and fetch actual payloads peer-to-peer — the orchestration server never sees synced content.

## Project structure

- `app/Models/` — `Device`, `ChangeEntry`, `SyncCursor` (UUID primary keys, Eloquent)
- `app/Http/Controllers/` — `DeviceRegisterController`, `DeviceController`, `SyncAnnounceController`, `SyncPollController`, `SyncAckController`
- `app/Http/Middleware/EnsureDeviceToken.php` — Bearer token auth, attaches device to request attributes
- `app/Sync/SyncClient.php` — Example PHP device-side client (register/announce/poll/ack/sync)
- `routes/api.php` — All sync API routes
- `database/migrations/` — `devices`, `change_entries`, `sync_cursors`
- `tests/Feature/` — Feature tests with `RefreshDatabase`
- `tests/Unit/` — Unit tests

## Key conventions

- **UUIDs**: All models use string UUID keys (`$keyType = 'string'; public $incrementing = false;`). Boot hooks auto-assign `Str::uuid()` and `Str::random(48)` for `api_token`.
- **Auth**: `EnsureDeviceToken` middleware validates Bearer token against `Device::api_token`. Controllers get the device via `$request->attributes->get('sync_device')`.
- **Secrets**: `api_token` is in `$hidden` on `Device` and excluded from serialization.
- **Validation**: All request input validated via `$request->validate()` before DB writes.
- **Tests**: Feature tests use `RefreshDatabase` (in-memory SQLite). Method names prefixed with `test_`.

## API endpoints

- `POST /api/devices` — register device, returns `device_id` + `sync_token`
- `GET /api/devices/me` — authenticated device profile
- `POST /api/sync/announce` — announce change metadata (content_hash, change_type, version, etc.)
- `GET /api/sync/changes` — poll changes from other devices since cursor
- `POST /api/sync/ack` — advance sync cursor

## Commands

```bash
# Setup
cp .env.example .env
php artisan migrate
php artisan serve --port=8099

# Test
vendor/bin/phpunit

# Route check
php artisan route:list --path=api
```

## Requirements

- PHP 8.5+
- Composer
- SQLite (pdo_sqlite)
