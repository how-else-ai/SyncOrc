# SyncOrc — Minimal Synchronization Service

Laravel-based Synchronization Orchestration Server. Devices announce change **metadata** to the server and fetch actual payloads peer-to-peer — the orchestration server never sees synced content.

## Design goals

- **Minimal server knowledge**: the server stores change metadata only (entity type/id, change type, SHA-256 content hash, version, optional vector clock, payload location). It never stores or transmits the synced payloads themselves.
- **Orchestration, not transport**: the server matches interested devices and tells them what changed; devices retrieve payloads directly from each other via `payload_location` (encrypted end-to-end).
- **Cursor-based sync**: each device tracks a sync cursor (`sync_cursors`) and polls `/api/sync/changes` since its last sync.
- **Device-scoped tokens**: every device registers and receives a `sync_token` used as a Bearer token on all sync endpoints.

## Core data model

| Table | Purpose |
|-------|---------|
| `devices` | Registered devices: `id` (uuid), `api_token` (sync token), `public_key`, `capabilities`, `last_sync_at` |
| `change_entries` | Announced changes: device, entity_type, entity_id, change_type (create/update/delete), `content_hash` (SHA-256 — never the payload), version, optional `vector_clock`, `payload_location` (P2P fetch URL) |
| `sync_cursors` | Per-device `last_synced_at` cursor for incremental polling |

## API

Base: `http://<server>/api`

### `POST /devices` — register a device

Returns the device's `device_id` and `sync_token`.

```bash
curl -X POST /api/devices \
  -H 'Content-Type: application/json' \
  -d '{"name":"laptop","public_key":"...","capabilities":["notes","contacts"]}'
# => {"device_id":"...","sync_token":"..."}
```

### `GET /devices/me` — authenticated device profile

`Authorization: Bearer <sync_token>`

### `POST /sync/announce` — announce local changes

`Authorization: Bearer <sync_token>`

Body:

```json
{
  "changes": [
    {
      "entity_type": "note",
      "entity_id": "note-42",
      "change_type": "update",
      "content_hash": "e3b0c442...",   // SHA-256 of the payload (hex, 64 chars)
      "version": 3,
      "vector_clock": {"laptop": 3},
      "payload_location": "http://device-a.local:9090/sync/note-42"
    }
  ]
}
```

The server persists only this metadata. The `content_hash` lets recipients verify payload integrity without the server ever seeing content.

### `GET /sync/changes` — poll for remote changes

`Authorization: Bearer <sync_token>`  `?limit=50`

Returns changes announced by **other** devices since this device's cursor:

```json
{
  "cursor": "2026-11-22T16:47:43+00:00",
  "count": 1,
  "has_more": false,
  "changes": [
    {
      "id": "...",
      "device_id": "...",
      "entity_type": "note",
      "entity_id": "note-42",
      "change_type": "update",
      "content_hash": "...",
      "version": 3,
      "vector_clock": {"laptop": 3},
      "payload_location": "...",
      "created_at": "..."
    }
  ]
}
```

The client then fetches the payload from `payload_location` peer-to-peer, verifies its SHA-256 against `content_hash`, applies it locally, and calls `/sync/ack`.

### `POST /sync/ack` — acknowledge applied changes

`Authorization: Bearer <sync_token>`

Body: `{"last_change_id": "..."}` — advances the device's sync cursor to that change's timestamp.

## Example device client

See `app/Sync/SyncClient.php` — a PHP device-side client implementing register/announce/poll/ack plus a full `sync()` round-trip.

## Running

```bash
cd syncorc-server
cp .env.example .env   # SQLite is the default driver
php artisan migrate
php artisan serve --port=8099
```

Database: `database/database.sqlite` (SQLite, file-based — zero config).

## Verified flows

- Device registration issues a scoped sync token.
- Announced changes are stored as metadata only; no payload content touches the server.
- Polling returns changes from other devices since the last cursor.
- Acknowledging advances the cursor; subsequent polls return nothing new until new changes arrive.
- Missing/invalid bearer tokens return `401`.

## Extending

- **Encryption**: store each device's `public_key` and encrypt payloads before placing them at `payload_location`; recipients decrypt out-of-band.
- **Conflict resolution**: use `vector_clock` with a CRDT or last-write-wins on the client when two devices announce the same `entity_type`/`entity_id`.
- **Push**: replace polling with real-time notifications (e.g. Laravel Reverb) fired from `SyncAnnounceController`.
- **Relay mode**: optionally store encrypted payload blobs on the server for devices that cannot accept P2P connections (still unreadable by the server).
