# SyncOrc

**Privacy-first, application-agnostic device synchronization orchestration service**

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-11-red)](https://laravel.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-15%2B-blue)](https://www.postgresql.org/)

SyncOrc is a lightweight, privacy-first synchronization service that enables devices to coordinate peer‑to‑peer (P2P) data synchronization **without ever exposing payload data to the service**. It acts purely as a signaling and notification layer so devices can discover each other, establish secure connections, and stay aware of sync state changes.

---

## Key Features

- 🔒 **Zero-knowledge architecture** – Sync payloads never touch the service in plaintext.  
- 🤝 **P2P data transfer** – All sync data flows directly between devices.  
- 🔔 **Smart notifications** – Push notifications (iOS, Android, Web) for state changes and offline peers.  
- 🔐 **Secure handshakes** – QR-code based pairing, ECDH key exchange, end-to-end encryption by design.  
- 🔄 **Flexible topologies** – Pairs (A↔B), chains (A↔B↔C↔…↔N), and groups (all-to-all).  
- 🛡️ **Loop prevention** – Vector clocks / logical versioning to prevent infinite update loops.  
- 🧱 **Application-agnostic** – You define the data model and sync protocol on the client side.  
- 🧊 **Optional encrypted cache** – Store E2E encrypted payloads for offline peers (service cannot decrypt).

---

## Architecture Overview

```text
┌─────────────────────────────────────────────────────────────────┐
│                             SyncOrc                             │
│                     (Privacy-First Orchestration)               │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐  ┌───────────────┐  ┌────────────────────┐    │
│  │  Handshake   │  │ Sync State    │  │ Push Notification  │    │
│  │  Manager     │  │ Coordinator   │  │ Gateway            │    │
│  │  (QR Pairing)│  │ (Loop Prevent)│  │ (FCM/APNs/WebPush) │    │
│  └──────────────┘  └───────────────┘  └────────────────────┘    │
│                                                                 │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                 PostgreSQL + Redis Backend                │  │
│  │  -  Device registry & relationships                       │  │
│  │  -  Sync state versions (hashed acknowledgments)          │  │
│  │  -  Optional encrypted cache storage                      │  │
│  └───────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
           │                    │                    │
           ▼                    ▼                    ▼
    ┌───────────┐        ┌───────────┐        ┌───────────┐
    │  Device A │◄──────►│  Device B │◄──────►│  Device C │
    │  (Client) │  P2P   │  (Client) │  P2P   │  (Client) │
    └───────────┘  Data  └───────────┘  Data  └───────────┘
```

SyncOrc coordinates who should talk to whom and when, but never sees the actual synced content.

---

## Use Cases

- **Local‑first collaboration** – notes, documents, tasks where data stays on devices.  
- **IoT and edge** – device‑to‑device state sync for smart home and industrial setups.  
- **Offline‑first apps** – mobile apps that sync when peers or the network become available.  
- **Privacy‑sensitive domains** – health, finance, personal knowledge bases where central storage is undesirable.  

---

## Technology Stack

- **Backend:** Laravel 11 (PHP 8.2+)  
- **Database:** PostgreSQL 15+  
- **Cache / Queue:** Redis 7+  
- **Real‑time:** Laravel broadcasting (WebSockets)  
- **Push:** FCM (Android), APNs (iOS), Web Push API (Web/PWA)

The reference server is written in PHP/Laravel, but clients can be implemented in any language.

---

## Getting Started

### Prerequisites

- PHP 8.2+  
- Composer 2.x  
- PostgreSQL 15+  
- Redis 7+  
- Node.js 18+ (for optional frontend tooling)

### Installation

```bash
# Clone the repository
git clone https://github.com/your-org/syncorc.git
cd syncorc

# Install PHP dependencies
composer install

# (Optional) Install JS tooling if you plan to use it
npm install

# Environment
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure:

```env
APP_NAME=SyncOrc
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=syncorc
DB_USERNAME=postgres
DB_PASSWORD=postgres

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
QUEUE_CONNECTION=redis

# Push notification credentials (fill as needed)
FCM_SERVER_KEY=
FCM_SENDER_ID=
APNS_KEY_ID=
APNS_TEAM_ID=
APNS_CERTIFICATE_PATH=
APNS_ENVIRONMENT=sandbox
```

Run migrations:

```bash
php artisan migrate
```

Start the services:

```bash
php artisan serve          # HTTP API
php artisan queue:work     # Background jobs
php artisan reverb:start   # WebSocket server (or your broadcast server)
```

SyncOrc will now be available at `http://localhost:8000`.

---

## Core Concepts

### Devices

- Each device registers once and gets:
  - `device_id` – a UUID used to identify it to SyncOrc.
  - `api_token` – a bearer token for authenticating API calls.
- Devices may update their push tokens as they change (e.g., FCM token refresh).

### Groups & Topologies

- **pair** – two devices sync with each other only.  
- **chain** – devices arranged in order; each device syncs with neighbors.  
- **group** – all devices sync with all others (full mesh on the client side).

SyncOrc tracks membership and decides which devices to notify when a state change occurs.

### Sync State & Loop Prevention

- Each device maintains its own versioning (vector clocks or similar) and sends a **state version** plus a **hashed acknowledgment token** to SyncOrc.
- SyncOrc:
  - Stores the metadata.
  - Uses vector clock comparisons to avoid redundant or looping updates.
  - Notifies relevant peers that “you need to sync”, but does not carry the payload.

### Optional Encrypted Cache

- When a peer is offline, a device can upload **E2E encrypted** payloads to the cache:
  - SyncOrc stores opaque blobs and cannot decrypt them.
  - The offline device pulls them when it comes back online.
  - Payloads are bounded by size and TTL.

---

## Example Flows

### 1. Device Registration

```http
POST /api/v1/devices/register
Content-Type: application/json

{
  "public_key": "base64-encoded-public-key",
  "platform": "ios|android|web",
  "push_token": "optional-push-token"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "device_id": "uuid-v4",
    "api_token": "random-bearer-token",
    "expires_at": "2026-02-20T22:00:00Z"
  }
}
```

### 2. Pairing via QR Code

On device A:

```http
POST /api/v1/pairing/initiate
Authorization: Bearer {api_token}
Content-Type: application/json

{
  "device_id": "uuid-device-a",
  "public_key": "base64-encoded-key"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "pairing_code": "ABC123",
    "qr_data": "base64-encoded-json",
    "expires_in": 300
  }
}
```

Device A displays `qr_data` as a QR code. Device B scans it and calls:

```http
POST /api/v1/pairing/accept
Authorization: Bearer {api_token}
Content-Type: application/json

{
  "pairing_code": "ABC123",
  "device_id": "uuid-device-b",
  "public_key": "base64-encoded-key",
  "group_type": "pair"
}
```

SyncOrc returns group info; both devices derive shared secrets client‑side (ECDH).

### 3. Notifying a State Change

After device A changes local state:

```http
POST /api/v1/sync/state-changed
Authorization: Bearer {api_token}
Content-Type: application/json

{
  "device_id": "uuid-device-a",
  "group_id": "uuid-group",
  "state_version": "v123",
  "ack_token_hash": "64-char-sha256-hex",
  "vector_clock": {
    "uuid-device-a": 5,
    "uuid-device-b": 3
  }
}
```

SyncOrc stores this and notifies peers via:

- WebSocket (for online devices)
- Push notifications (for offline devices)

Peers then initiate P2P sync using their own protocol.

---

## Client Responsibilities

SyncOrc deliberately stays “dumb” about your actual data. Clients must:

- Implement end‑to‑end encryption:
  - Use the exchanged public keys and derive shared secrets.
  - Encrypt all sync payloads before sending P2P or to the cache.
- Implement conflict resolution:
  - CRDTs, OT, or your own merging strategy.
- Implement P2P connectivity:
  - WebRTC, direct sockets, local network, or any other channel.
- Track versions:
  - Maintain local version clocks and pass them to SyncOrc.

---

## Configuration Highlights

Key `.env` fields:

```env
APP_NAME=SyncOrc
APP_ENV=production
APP_DEBUG=false
APP_URL=https://syncorc.example.com

DB_CONNECTION=pgsql
DB_DATABASE=syncorc

REDIS_HOST=redis
QUEUE_CONNECTION=redis

# Rate limits
RATE_LIMIT_PAIRING=5
RATE_LIMIT_SYNC=1000
CACHE_TTL_MAX=604800       # 7 days
CACHE_SIZE_LIMIT=10485760  # 10MB per payload
```

---

## Development

Run tests:

```bash
php artisan test
```

Code style:

```bash
./vendor/bin/pint
```

Static analysis (if configured):

```bash
./vendor/bin/phpstan analyse
```

---

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for:

- Workflow and branching strategy  
- Coding and security guidelines  
- Testing expectations  

---

## Security

SyncOrc is designed to be:

- **Zero‑knowledge** – the server never sees plaintext sync content.  
- **SOC 2–friendly** – the security model aligns with common SOC 2 controls (security, availability, confidentiality).

For details or to report a security issue, see [SECURITY_POLICY.md](SECURITY_POLICY.md).

---

## License

SyncOrc is released under the [MIT License](LICENSE).
```
