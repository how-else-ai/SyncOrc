# SyncOrc — Agent Context

This is a minimal Laravel-based Synchronization Orchestration Server. Devices announce change **metadata** to the server and fetch actual payloads peer-to-peer — the orchestration server never sees synced content.

## Domain model

| Model | Table | Key fields |
|-------|-------|-----------|
| `App\Models\Device` | `devices` | `id` (uuid, string key), `api_token` (sync token, hidden), `public_key`, `capabilities` (array cast), `last_sync_at` |
| `App\Models\ChangeEntry` | `change_entries` | `id` (uuid), `device_id` (FK), `entity_type`, `entity_id`, `change_type` (create/update/delete), `content_hash` (SHA-256 hex), `version`, `vector_clock` (array cast), `payload_location` |
| `App\Models\SyncCursor` | `sync_cursors` | `device_id` (FK, PK), `last_synced_at` |

All models use UUID primary keys: `$keyType = 'string'; public $incrementing = false;` with a `creating` boot hook that assigns `(string) Str::uuid()`.

## API conventions

Base path: `/api`

| Endpoint | Method | Auth | Purpose |
|----------|--------|------|---------|
| `/devices` | POST | none | Register device → returns `device_id` + `sync_token` |
| `/devices/me` | GET | Bearer token | Authenticated device profile (api_token hidden) |
| `/sync/announce` | POST | Bearer token | Announce change metadata (validated: content_hash 64 hex, change_type enum) |
| `/sync/changes` | GET | Bearer token | Poll changes from other devices since cursor; `?limit=` |
| `/sync/ack` | POST | Bearer token | Advance sync cursor via `last_change_id` |

Authentication: `App\Http\Middleware\EnsureDeviceToken` reads `$request->bearerToken()`, looks up `Device` by `api_token`, attaches via `$request->attributes->set('sync_device', $device)`. Controllers retrieve with `$request->attributes->get('sync_device')`.

Routes are in `routes/api.php` and registered in `bootstrap/app.php` via `api: __DIR__.'/../routes/api.php'` (Laravel 13 requires this explicit mapping).

## Test conventions

- Feature tests: `tests/Feature/` — extend `Tests\TestCase`, use `RefreshDatabase` (in-memory SQLite), methods prefixed with `test_`
- Unit tests: `tests/Unit/` — extend `Tests\TestCase`, use `RefreshDatabase` when touching the DB
- Run: `vendor/bin/phpunit` (28 tests, 69 assertions, all passing)

## Common tasks

**Add a new sync endpoint:**
1. Create controller in `app/Http/Controllers/`
2. Add route in `routes/api.php` inside the `EnsureDeviceToken` middleware group
3. Add feature test in `tests/Feature/`
4. Run `vendor/bin/phpunit`

**Modify a model:**
1. Edit the model class in `app/Models/`
2. Create a migration via `php artisan make:migration`
3. Run `php artisan migrate`
4. Update any affected tests

**Device-side client:**
`app/Sync/SyncClient.php` — register/announce/poll/ack/sync round-trip example.

## Requirements

- PHP 8.5+
- Composer
- SQLite (pdo_sqlite)
