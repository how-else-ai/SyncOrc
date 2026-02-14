
# SyncOrc – Technical Specification for Agentic Coding

**Version:** 1.0.0  
**Date:** February 13, 2026  
**Status:** Draft for Implementation

---

## Table of Contents

1. Project Overview  
2. System Architecture  
3. Technology Stack  
4. Database Schema  
5. API Specifications  
6. WebSocket Protocol  
7. Push Notification System  
8. Security Implementation  
9. Core Business Logic  
10. Testing Requirements  
11. Deployment Configuration  
12. Performance Requirements  
13. Implementation Notes for Coding Agent  

---

## 1. Project Overview

### 1.1 Purpose

SyncOrc is a privacy-first synchronization orchestration service that coordinates peer‑to‑peer device synchronization without ever handling actual sync payload data. The service acts as a signaling server and notification coordinator.

### 1.2 Core Requirements

1. **Zero-knowledge architecture**: The service must never see plaintext sync data.  
2. **P2P coordination**: Devices communicate directly for data sync.  
3. **Multi-platform push notifications**: iOS (APNs), Android (FCM), Web (Web Push).  
4. **Flexible topologies**: Pairs (A↔B), chains (A↔B↔C↔…↔N), groups (all-to-all).  
5. **Loop prevention**: Prevent infinite bidirectional sync loops.  
6. **QR code pairing**: Secure device handshake via QR scanning.  
7. **Optional encrypted cache**: Store E2E encrypted payloads for offline devices.  
8. **Application agnostic**: Works with any application and data model.

### 1.3 Non-Requirements

- Actual sync data transmission (this is P2P between devices).  
- Conflict resolution logic (CRDT/OT handled by clients).  
- File storage beyond encrypted cache payloads.  
- User account system (SyncOrc is device-centric).

---

## 2. System Architecture

### 2.1 High-Level Components

```text
┌─────────────────────────────────────────────────────────────┐
│                    Laravel Application (SyncOrc)            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌──────────────────┐  ┌──────────────────┐  ┌───────────┐  │
│  │   API Layer      │  │  WebSocket Layer │  │   Queue   │  │
│  │  (REST + JSON)   │  │  (Broadcasting)  │  │  Workers  │  │
│  └──────────────────┘  └──────────────────┘  └───────────┘  │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │              Business Logic Layer                    │   │
│  │  -  DeviceService                                    │   │
│  │  -  PairingService                                   │   │
│  │  -  SyncCoordinatorService                           │   │
│  │  -  NotificationService                              │   │
│  │  -  CacheService                                     │   │
│  │  -  VectorClockService                               │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │              Data Access Layer                       │   │
│  │  -  Eloquent Models                                  │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
└────────────────────────────┬────────────────────────────────┘
                             │
         ┌───────────────────┴────────────────────┐
         │                                        │
  ┌──────▼─────┐                           ┌──────▼──────┐
  │ PostgreSQL │                           │   Redis     │
  │   Database │                           │   Cache     │
  └────────────┘                           └─────────────┘
```

### 2.2 Component Responsibilities

**API Layer**

- Exposes REST endpoints for:
  - Device registration and token refresh.
  - Pairing initiation and acceptance.
  - Sync state change notification and acknowledgment.
  - Optional encrypted cache store/retrieve.
  - Signaling (offers/answers) for P2P connections.
  - Group membership operations.
- Performs request validation, authentication, and rate limiting.

**WebSocket Layer**

- Maintains real-time connections for online devices.
- Sends:
  - Sync notifications.
  - Pairing accepted events.
  - Signaling offers and answers.
  - Group membership updates.

**Queue Workers**

- Asynchronous tasks:
  - Push notification delivery to offline devices.
  - Cache cleanup (expired entries).
  - Pairing/signaling cleanup.
  - Any heavy or retryable tasks.

**Business Logic Layer**

- `DeviceService`: Device registration, token handling, push token updates.  
- `PairingService`: Pairing code & QR generation, group creation on accept.  
- `SyncCoordinatorService`: Handles state changes, target selection, loop prevention, notifications.  
- `NotificationService`: WebSocket and push notification sending.  
- `CacheService`: Encrypted payload storage/retrieval and TTL logic.  
- `VectorClockService`: Loop detection and version comparison logic.

**Data Access Layer**

- Eloquent models for all tables with relationships and helpers.

---

## 3. Technology Stack

### 3.1 Backend

- **Framework**: Laravel 11  
- **Language**: PHP 8.2+  
- **Database**: PostgreSQL 15+  
- **Cache & Queue**: Redis 7+  
- **WebSockets**: Laravel broadcasting server  
- **Package manager**: Composer 2.x

### 3.2 Dependencies (Conceptual)

- Laravel framework  
- Redis client  
- QR code generation library  
- Firebase/FCM PHP SDK  
- APNs client (e.g., Pushok)  
- Web Push library  
- Testing and static analysis tools (PHPUnit, Pint, PHPStan, etc.)

---

## 4. Database Schema

All IDs for external references are exposed as UUID v4.

### 4.1 `devices` Table

Stores registered device information.

**Columns:**

- `id` (uuid, PK)  
- `device_id` (uuid, unique, public identifier)  
- `public_key` (text, required)  
- `api_token` (string, hashed token)  
- `push_token` (text, nullable)  
- `platform` (enum: `ios`, `android`, `web`)  
- `last_seen_at` (timestamp, nullable)  
- `token_expires_at` (timestamp, required)  
- `created_at`, `updated_at` (timestamps)

**Indexes:**

- `device_id`  
- `api_token`  
- `last_seen_at`

### 4.2 `sync_groups` Table

Represents logical sync groups.

**Columns:**

- `id` (uuid, PK)  
- `group_id` (uuid, unique, public identifier)  
- `group_type` (enum: `pair`, `chain`, `group`)  
- `created_at`, `updated_at` (timestamps)

**Indexes:**

- `group_id`  
- `group_type`

### 4.3 `group_members` Table

Many-to-many relationship between devices and groups.

**Columns:**

- `id` (uuid, PK)  
- `group_id` (uuid, FK → `sync_groups.id`)  
- `device_id` (uuid, FK → `devices.id`)  
- `position` (int, nullable – used in chain topology)  
- `joined_at` (timestamp, default now)

**Constraints and Indexes:**

- Unique (`group_id`, `device_id`)  
- Index on `group_id`  
- Index on `device_id`  
- Index on (`group_id`, `position`)

### 4.4 `sync_states` Table

Tracks sync state versions and loop-prevention metadata.

**Columns:**

- `id` (uuid, PK)  
- `group_id` (uuid, FK → `sync_groups.id`)  
- `device_id` (uuid, FK → `devices.id`)  
- `state_version` (string, up to 255)  
- `ack_token_hash` (string, length 64, SHA-256 hex)  
- `vector_clock` (json, optional; map `{device_id: int}`)  
- `is_acknowledged` (boolean, default false)  
- `created_at`, `updated_at` (timestamps)

**Indexes:**

- (`group_id`, `device_id`)  
- (`group_id`, `device_id`, `state_version`)  
- `is_acknowledged`

### 4.5 `pairing_requests` Table

Temporary storage for active pairing requests.

**Columns:**

- `id` (uuid, PK)  
- `pairing_code` (string, length ≤10, unique)  
- `initiator_device_id` (uuid, FK → `devices.id`)  
- `initiator_public_key` (text)  
- `qr_data` (text, base64-encoded JSON)  
- `expires_at` (timestamp)  
- `created_at` (timestamp, default now)

**Indexes:**

- `pairing_code`  
- `expires_at`

### 4.6 `signaling_offers` Table

WebRTC-style signaling offers.

**Columns:**

- `id` (uuid, PK)  
- `offer_id` (uuid, unique)  
- `from_device_id` (uuid, FK → `devices.id`)  
- `to_device_id` (uuid, FK → `devices.id`)  
- `offer_data` (text, typically encrypted SDP/connection info)  
- `expires_at` (timestamp)  
- `created_at` (timestamp, default now)

**Indexes:**

- (`to_device_id`, `expires_at`)  
- `from_device_id`

### 4.7 `cached_payloads` Table

Optional encrypted cache for offline peers.

**Columns:**

- `id` (uuid, PK)  
- `cache_id` (uuid, unique)  
- `from_device_id` (uuid, FK → `devices.id`)  
- `to_device_id` (uuid, FK → `devices.id`)  
- `group_id` (uuid, FK → `sync_groups.id`)  
- `encrypted_data` (binary / bytea)  
- `state_version` (string)  
- `size_bytes` (int)  
- `expires_at` (timestamp)  
- `created_at` (timestamp, default now)

**Indexes:**

- (`to_device_id`, `expires_at`)  
- `group_id`  
- `expires_at`

### 4.8 Redis Structures (Conceptual)

- `online:device:{device_id}`: string timestamp, TTL 5 minutes – used to track online devices.  
- `rate_limit:pairing:{device_id}`: request count, TTL 1 hour.  
- `rate_limit:sync:{device_id}`: request count, TTL 1 hour.

---

## 5. API Specifications

All examples use JSON over HTTPS. Errors use a consistent format.

### 5.1 Authentication

**Mechanism:** Bearer token per device.

**Header:**

```http
Authorization: Bearer {api_token}
```

### 5.2 Common Response Format

**Success:**

```json
{
  "success": true,
  "data": {},
  "message": "Operation successful"
}
```

**Error:**

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Validation failed",
    "details": {
      "field": ["Error message"]
    }
  }
}
```

### 5.3 Error Codes (Examples)

- `VALIDATION_ERROR`  
- `AUTHENTICATION_FAILED`  
- `NOT_FOUND`  
- `RATE_LIMIT_EXCEEDED`  
- `PAIRING_EXPIRED`  
- `PAIRING_INVALID`  
- `DEVICE_OFFLINE`  
- `CACHE_LIMIT_EXCEEDED`  
- `INTERNAL_ERROR`

### 5.4 Endpoints

#### 5.4.1 Device Registration

**POST** `/api/v1/devices/register`

**Body:**

```json
{
  "public_key": "base64-encoded-public-key",
  "platform": "ios|android|web",
  "push_token": "optional-fcm-or-apns-or-web-push-token"
}
```

**Response (201):**

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

#### 5.4.2 Refresh Device Token

**POST** `/api/v1/devices/refresh-token` (authenticated)

**Body:**

```json
{
  "device_id": "uuid-device-id"
}
```

**Response (200):**

```json
{
  "success": true,
  "data": {
    "api_token": "new-bearer-token",
    "expires_at": "2026-02-27T22:00:00Z"
  }
}
```

#### 5.4.3 Update Push Token

**PATCH** `/api/v1/devices/push-token` (authenticated)

**Body:**

```json
{
  "device_id": "uuid-device-id",
  "push_token": "new-fcm-or-apns-or-web-push-token"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Push token updated"
}
```

#### 5.4.4 Initiate Pairing

**POST** `/api/v1/pairing/initiate` (authenticated)

**Body:**

```json
{
  "device_id": "uuid-device-id",
  "public_key": "base64-encoded-key"
}
```

**Response (201):**

```json
{
  "success": true,
  "data": {
    "pairing_code": "ABC123",
    "qr_data": "base64-encoded-json-payload",
    "expires_in": 300,
    "expires_at": "2026-02-13T22:47:00Z"
  }
}
```

#### 5.4.5 Accept Pairing

**POST** `/api/v1/pairing/accept` (authenticated)

**Body:**

```json
{
  "pairing_code": "ABC123",
  "device_id": "uuid-device-b",
  "public_key": "base64-encoded-key",
  "group_type": "pair|chain|group"
}
```

**Response (201):**

```json
{
  "success": true,
  "data": {
    "group_id": "uuid-group",
    "group_type": "pair",
    "members": [
      { "device_id": "uuid-device-a", "position": 0 },
      { "device_id": "uuid-device-b", "position": 1 }
    ],
    "initiator_public_key": "base64-initiator-key",
    "acceptor_public_key": "base64-acceptor-key"
  }
}
```

#### 5.4.6 Notify State Change

**POST** `/api/v1/sync/state-changed` (authenticated)

**Body:**

```json
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

**Response (200):**

```json
{
  "success": true,
  "data": {
    "notified_devices": ["uuid-device-b"],
    "online_count": 1,
    "push_sent_count": 0
  }
}
```

If a loop is detected:

```json
{
  "success": true,
  "data": {
    "loop_detected": true
  }
}
```

#### 5.4.7 Acknowledge Sync

**POST** `/api/v1/sync/acknowledge` (authenticated)

**Body:**

```json
{
  "device_id": "uuid-device-b",
  "group_id": "uuid-group",
  "state_version": "v123",
  "ack_token_hash": "64-char-sha256-hex"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Sync acknowledged"
}
```

#### 5.4.8 Get Sync Status

**GET** `/api/v1/sync/status?device_id={id}&group_id={group_id}` (authenticated)

**Response:**

```json
{
  "success": true,
  "data": {
    "group_id": "uuid-group",
    "device_id": "uuid-device-id",
    "last_sync_version": "v123",
    "is_up_to_date": false,
    "pending_syncs": [
      {
        "from_device": "uuid-device-b",
        "state_version": "v125",
        "timestamp": "2026-02-13T22:30:00Z"
      }
    ],
    "vector_clock": {
      "uuid-device-a": 5,
      "uuid-device-b": 6
    }
  }
}
```

#### 5.4.9 Store Cached Payload

**POST** `/api/v1/cache/store` (authenticated)

**Body:**

```json
{
  "from_device_id": "uuid-device-a",
  "to_device_id": "uuid-device-b",
  "group_id": "uuid-group",
  "encrypted_payload": "base64-encrypted-data",
  "state_version": "v123",
  "ttl": 86400
}
```

**Response (201):**

```json
{
  "success": true,
  "data": {
    "cache_id": "uuid-cache",
    "expires_at": "2026-02-14T22:00:00Z",
    "size_bytes": 524288
  }
}
```

#### 5.4.10 Retrieve Cached Payloads

**GET** `/api/v1/cache/retrieve?device_id={id}` (authenticated)

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "cache_id": "uuid-cache",
      "from_device_id": "uuid-device-a",
      "group_id": "uuid-group",
      "encrypted_payload": "base64-encrypted-data",
      "state_version": "v123",
      "timestamp": "2026-02-13T22:00:00Z",
      "size_bytes": 524288
    }
  ]
}
```

#### 5.4.11 Delete Cached Payload

**DELETE** `/api/v1/cache/{cache_id}` (authenticated)

**Response (204):** Empty body.

#### 5.4.12 Create Signaling Offer

**POST** `/api/v1/signaling/offer` (authenticated)

**Body:**

```json
{
  "from_device_id": "uuid-device-a",
  "to_device_id": "uuid-device-b",
  "offer_data": "encrypted-connection-params"
}
```

**Response (201):**

```json
{
  "success": true,
  "data": {
    "offer_id": "uuid-offer",
    "expires_at": "2026-02-13T22:01:00Z"
  }
}
```

#### 5.4.13 Get Signaling Offers

**GET** `/api/v1/signaling/offers?device_id={id}` (authenticated)

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "offer_id": "uuid-offer",
      "from_device_id": "uuid-device-a",
      "offer_data": "encrypted-connection-params",
      "created_at": "2026-02-13T22:00:30Z",
      "expires_at": "2026-02-13T22:01:00Z"
    }
  ]
}
```

#### 5.4.14 Respond to Signaling Offer

**POST** `/api/v1/signaling/answer` (authenticated)

**Body:**

```json
{
  "offer_id": "uuid-offer",
  "device_id": "uuid-device-b",
  "answer_data": "encrypted-connection-response"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Answer sent"
}
```

#### 5.4.15 Get Group Members

**GET** `/api/v1/groups/{group_id}/members` (authenticated)

**Response:**

```json
{
  "success": true,
  "data": {
    "group_id": "uuid-group",
    "group_type": "chain",
    "members": [
      {
        "device_id": "uuid-device-a",
        "position": 0,
        "is_online": true,
        "last_seen": "2026-02-13T22:35:00Z"
      },
      {
        "device_id": "uuid-device-b",
        "position": 1,
        "is_online": false,
        "last_seen": "2026-02-13T21:00:00Z"
      }
    ]
  }
}
```

#### 5.4.16 Leave Group

**POST** `/api/v1/groups/{group_id}/leave` (authenticated)

**Body:**

```json
{
  "device_id": "uuid-device-id"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Left group successfully"
}
```

#### 5.4.17 Health Check

**GET** `/api/health` (public)

**Response:**

```json
{
  "status": "healthy",
  "timestamp": "2026-02-13T22:37:00Z",
  "services": {
    "database": "connected",
    "redis": "connected",
    "websocket": "running",
    "queue": "processing"
  },
  "version": "1.0.0"
}
```

---

## 6. WebSocket Protocol

### 6.1 Connection

**URL:**

```text
ws(s)://{host}:6001/device/{device_id}?token={api_token}
```

**Authentication:** token query parameter.

### 6.2 Channels

- `device.{device_id}` – per-device notifications.  
- `group.{group_id}` – group-wide events.  
- `signaling.{device_id}` – signaling offers/answers.

### 6.3 Message Types

**sync_required**

```json
{
  "type": "sync_required",
  "group_id": "uuid-group",
  "source_device_id": "uuid-device-a",
  "state_version": "v123",
  "timestamp": "2026-02-13T22:00:00Z"
}
```

**device_joined**

```json
{
  "type": "device_joined",
  "group_id": "uuid-group",
  "device_id": "uuid-device-c",
  "position": 2,
  "timestamp": "2026-02-13T22:00:00Z"
}
```

**device_left**

```json
{
  "type": "device_left",
  "group_id": "uuid-group",
  "device_id": "uuid-device-b",
  "timestamp": "2026-02-13T22:00:00Z"
}
```

**signaling_offer**

```json
{
  "type": "signaling_offer",
  "offer_id": "uuid-offer",
  "from_device_id": "uuid-device-a",
  "offer_data": "encrypted-connection-params",
  "timestamp": "2026-02-13T22:00:00Z"
}
```

**signaling_answer**

```json
{
  "type": "signaling_answer",
  "offer_id": "uuid-offer",
  "from_device_id": "uuid-device-b",
  "answer_data": "encrypted-connection-response",
  "timestamp": "2026-02-13T22:00:01Z"
}
```

**sync_acknowledged**

```json
{
  "type": "sync_acknowledged",
  "group_id": "uuid-group",
  "device_id": "uuid-device-b",
  "state_version": "v123",
  "timestamp": "2026-02-13T22:00:05Z"
}
```

**pairing_accepted**

```json
{
  "type": "pairing_accepted",
  "group_id": "uuid-group",
  "device_id": "uuid-device-b",
  "group_type": "pair",
  "timestamp": "2026-02-13T22:00:00Z"
}
```

### 6.4 Heartbeat and Reconnect

Clients should:

- Send periodic pings (e.g., every 30s).  
- Implement exponential backoff on reconnect attempts (e.g., 1s, 2s, 4s, up to 30s).  

---

## 7. Push Notification System

### 7.1 Triggers

Push notifications are sent when:

- A sync state change occurs and the target device is offline.  
- A signaling offer is created for an offline device.  
- Optionally when a group membership change affects offline devices.

### 7.2 Payload Examples

**FCM:**

```json
{
  "to": "fcm-device-token",
  "priority": "high",
  "notification": {
    "title": "Sync Required",
    "body": "New changes available in your SyncOrc group",
    "sound": "default"
  },
  "data": {
    "type": "sync_required",
    "group_id": "uuid-group",
    "source_device_id": "uuid-device-a",
    "state_version": "v123"
  }
}
```

**APNs:**

```json
{
  "aps": {
    "alert": {
      "title": "Sync Required",
      "body": "New changes available in your SyncOrc group"
    },
    "sound": "default",
    "badge": 1,
    "content-available": 1
  },
  "type": "sync_required",
  "group_id": "uuid-group",
  "source_device_id": "uuid-device-a",
  "state_version": "v123"
}
```

**Web Push:**

```json
{
  "notification": {
    "title": "Sync Required",
    "body": "New changes available in your SyncOrc group",
    "icon": "/icon.png",
    "badge": "/badge.png",
    "data": {
      "type": "sync_required",
      "group_id": "uuid-group",
      "source_device_id": "uuid-device-a",
      "state_version": "v123"
    }
  }
}
```

### 7.3 Retry Strategy

- 3 attempts with backoff (e.g., 10s, 30s, 60s).  
- Failures logged and can be inspected.

---

## 8. Security Implementation

### 8.1 API Token Handling

- Generate 64-character random tokens per device.  
- Store only a SHA-256 hash of the token.  
- Token expiry default 7 days; refresh endpoint available.

### 8.2 Acknowledgment Token Hash

Computed on clients as:

```text
ack_token_hash = SHA256(encrypted_payload + state_version + nonce)
```

Only the hash is sent to SyncOrc.

### 8.3 Rate Limiting

Examples:

- Pairing initiation: 5/hour per device.  
- Sync state change: 1000/hour per device.  
- Global API: 60/minute per device/IP (configurable).  

Failures return `RATE_LIMIT_EXCEEDED`.

### 8.4 Input Validation

- Use strict validation rules for all endpoints (UUIDs, enum values, hex hashes).  
- Reject malformed JSON or unexpected properties where appropriate.

---

## 9. Core Business Logic

### 9.1 Topology Behavior

- **pair**: device A’s change notifies device B, and vice versa.  
- **chain**: only adjacent nodes are notified (position ±1).  
- **group**: every device not equal to the source is notified.

### 9.2 Vector Clock Logic

- Each device maintains a vector clock per group.  
- New local changes increment its own entry.  
- SyncOrc stores the clock with each `sync_state`.  
- If incoming vector clock is not strictly greater than the last known for that device, a loop is suspected and the update can be ignored or flagged.

### 9.3 Cache Service Rules

- Maximum payload size per entry (e.g., 10MB).  
- Optional per-device or per-group total quota.  
- TTL bounded (e.g., max 7 days).  
- Only E2E encrypted blobs are accepted.

---

## 10. Testing Requirements

### 10.1 Unit Tests

Cover:

- `DeviceService`  
- `PairingService`  
- `SyncCoordinatorService`  
- `VectorClockService`  
- `CacheService`  
- `NotificationService`

### 10.2 Feature Tests

Scenarios:

- Full pairing flow (initiate + accept).  
- Sync state change with online vs offline targets.  
- Loop prevention behavior.  
- Cache store/retrieve and expiry.  
- Signaling offer/answer exchange.

### 10.3 Non-Functional Tests

- Load test for concurrent connections and notifications.  
- Latency tests for API and WebSocket paths.  
- DB query performance on large tables.

---

## 11. Deployment Configuration

### 11.1 Environment Variables (Key Ones)

```text
APP_NAME=SyncOrc
APP_ENV=production
APP_URL=https://syncorc.example.com

DB_CONNECTION=pgsql
DB_HOST=...
DB_DATABASE=syncorc
DB_USERNAME=...
DB_PASSWORD=...

REDIS_HOST=...
QUEUE_CONNECTION=redis

# WebSocket/broadcasting settings
# Push credentials (FCM/APNs/Web Push)
# Rate limit and cache size configs
```

### 11.2 Containerization

A typical stack includes:

- `app` (PHP/Laravel, running SyncOrc)  
- `postgres` (PostgreSQL)  
- `redis` (Redis)  
- optional `queue-worker` and dedicated WebSocket container.

---

## 12. Performance Requirements

### 12.1 Targets

- API responses: p95 < 100ms under normal load.  
- WebSocket notification propagation: < 100ms to online devices.  
- Push queue throughput: ≥ 10,000 notifications/minute.

### 12.2 Scalability

- Stateless app layer allows horizontal scaling.  
- Redis used for centralized queues and online presence.  
- PostgreSQL scaling via read replicas and connection pooling.

---

## 13. Implementation Notes for Coding Agent

1. Implement migrations and models first.  
2. Implement services (Device, Pairing, SyncCoordinator, VectorClock, Cache, Notification).  
3. Implement controllers and routes, then authentication middleware.  
4. Integrate WebSockets for `device.*`, `group.*`, `signaling.*` channels.  
5. Implement push notification jobs for Android/iOS/Web.  
6. Add comprehensive tests for critical flows.  
7. Provide configuration templates (`.env.example`) and basic operational docs.  

This specification is intended to be directly actionable by an automated or human coding agent implementing SyncOrc end to end.
```
