# SyncOrc Developer Guide

**Building Client Applications with SyncOrc**

This guide helps developers implement client applications that leverage the SyncOrc synchronization orchestration service.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Getting Started](#getting-started)
4. [Authentication](#authentication)
5. [Device Registration](#device-registration)
6. [Pairing & Groups](#pairing--groups)
7. [Synchronization](#synchronization)
8. [Push Notifications](#push-notifications)
9. [WebRTC Signaling](#webrtc-signaling)
10. [Encrypted Cache](#encrypted-cache)
11. [Security Best Practices](#security-best-practices)
12. [Error Handling](#error-handling)
13. [SDK Reference](#sdk-reference)

---

## Overview

SyncOrc is a **privacy-first, zero-knowledge** orchestration service that enables devices to coordinate peer-to-peer (P2P) data synchronization. The service:

- Never sees your data (even encrypted)
- Manages device discovery and pairing
- Coordinates sync timing and loop prevention
- Delivers push notifications to offline peers
- Provides signaling for P2P connection establishment

### Your Responsibilities as a Client Developer

As a client developer, you are responsible for:

| Responsibility | Description |
|---------------|-------------|
| **E2E Encryption** | Encrypt all sync payloads before transmission |
| **Key Management** | Generate and securely store cryptographic keys |
| **Conflict Resolution** | Implement CRDTs, OT, or custom merge strategies |
| **P2P Connectivity** | Establish direct connections (WebRTC, sockets, etc.) |
| **Version Tracking** | Maintain local vector clocks |

---

## Architecture

```
┌─────────────────┐     REST API      ┌─────────────────┐
│   Your Client   │◄─────────────────►│    SyncOrc      │
│   Application   │                   │   (This Repo)   │
│                 │◄─────────────────►│                 │
│ ┌─────────────┐ │   WebSocket       │ ┌─────────────┐ │
│ │ Local Data  │ │   Signaling       │ │ Device      │ │
│ │ Store       │ │                   │ │ Registry    │ │
│ └─────────────┘ │                   │ └─────────────┘ │
│ ┌─────────────┐ │                   │ ┌─────────────┐ │
│ │ E2E Crypto  │ │◄─────────────────►│ │ Sync State  │ │
│ │ Engine      │ │    P2P Data       │ │ Coordinator │ │
│ └─────────────┘ │    (WebRTC/etc)   │ └─────────────┘ │
└─────────────────┘                   └─────────────────┘
         │                                      │
         ▼                                      ▼
┌─────────────────┐                   ┌─────────────────┐
│  Other Devices  │◄─────────────────►│  PostgreSQL +   │
│  (Peers)        │    P2P Sync       │  Redis Backend  │
└─────────────────┘                   └─────────────────┘
```

---

## Getting Started

### Base URL

```
Production: https://api.syncorc.example.com/api/v1
Development: http://localhost:8000/api/v1
```

### Required Headers

All authenticated requests must include:

```http
Authorization: Bearer {api_token}
Content-Type: application/json
Accept: application/json
```

### Response Format

All responses follow a consistent format:

```json
{
  "success": true|false,
  "data": { ... },
  "message": "Human-readable message (optional)"
}
```

---

## Authentication

SyncOrc uses **bearer tokens** for API authentication.

### Token Lifecycle

1. **Registration**: Device registers and receives an `api_token`
2. **Usage**: Include token in `Authorization: Bearer {token}` header
3. **Refresh**: Tokens expire periodically; refresh using `/devices/refresh-token`
4. **Revocation**: Tokens are invalidated when devices unregister

### Token Storage (Client-Side)

```javascript
// Secure storage recommendations by platform:

// Web: Use encrypted localStorage or sessionStorage with caution
const storeToken = (token) => {
  // Encrypt before storing in production
  localStorage.setItem('syncorc_token', encrypt(token));
};

// iOS: Keychain
// Android: EncryptedSharedPreferences
// Desktop: OS keychain (keytar, keyring)
```

---

## Device Registration

Before any sync operations, a device must register with SyncOrc.

### Registration Flow

```javascript
// Example: Device Registration
async function registerDevice() {
  // 1. Generate or load cryptographic key pair
  const keyPair = await generateKeyPair();
  const publicKey = await exportPublicKey(keyPair.publicKey);
  
  // 2. Get push notification token from FCM/APNs/Web Push
  const pushToken = await getPushNotificationToken();
  
  // 3. Register with SyncOrc
  const response = await fetch(`${API_BASE}/devices/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      public_key: publicKey,
      platform: 'ios', // or 'android', 'web'
      push_token: pushToken
    })
  });
  
  const result = await response.json();
  
  if (result.success) {
    // Store credentials securely
    await storeCredentials({
      deviceId: result.data.device_id,
      apiToken: result.data.api_token,
      expiresAt: result.data.expires_at
    });
    
    return result.data;
  }
}
```

### Platform Detection

```javascript
function getPlatform() {
  const userAgent = navigator.userAgent;
  
  if (/iPad|iPhone|iPod/.test(userAgent)) return 'ios';
  if (/Android/.test(userAgent)) return 'android';
  return 'web';
}
```

---

## Pairing & Groups

Devices must be paired before they can synchronize. SyncOrc supports three topologies:

### Topologies

| Type | Description | Use Case |
|------|-------------|----------|
| **pair** | Two devices sync bidirectionally | Phone ↔ Laptop |
| **chain** | Linear topology (A↔B↔C↔...↔N) | Workflow handoffs |
| **group** | Full mesh (all-to-all) | Team collaboration |

### QR Code Pairing

The most common pairing method uses QR codes:

```javascript
// Device A: Initiate Pairing
async function initiatePairing() {
  const response = await fetch(`${API_BASE}/pairing/initiate`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      device_id: deviceId,
      public_key: publicKey
    })
  });
  
  const result = await response.json();
  
  // Display QR code
  displayQRCode(result.data.qr_data);
  
  return result.data.pairing_code;
}

// Device B: Accept Pairing (scans QR code)
async function acceptPairing(pairingCode) {
  const response = await fetch(`${API_BASE}/pairing/accept`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      pairing_code: pairingCode,
      device_id: deviceId,
      public_key: publicKey,
      group_type: 'pair' // or 'chain', 'group'
    })
  });
  
  return response.json();
}
```

### QR Code Parsing

```javascript
// Decode QR code data
function parseQRData(base64Data) {
  const json = atob(base64Data);
  const data = JSON.parse(json);
  
  return {
    deviceId: data.d,
    publicKey: data.k,
    timestamp: data.t,
    code: data.c
  };
}
```

---

## Synchronization

### Vector Clocks

SyncOrc uses vector clocks for causality tracking and loop prevention:

```javascript
// Vector clock structure
const vectorClock = {
  'device-uuid-1': 5,
  'device-uuid-2': 3,
  'device-uuid-3': 1
};

// Increment local clock
function incrementClock(clock, deviceId) {
  return {
    ...clock,
    [deviceId]: (clock[deviceId] || 0) + 1
  };
}

// Merge clocks (take max of each entry)
function mergeClocks(clock1, clock2) {
  const merged = { ...clock1 };
  
  for (const [device, count] of Object.entries(clock2)) {
    merged[device] = Math.max(merged[device] || 0, count);
  }
  
  return merged;
}
```

### Reporting State Changes

```javascript
// Notify SyncOrc that local state has changed
async function reportStateChange(groupId, vectorClock, ackToken) {
  const response = await fetch(`${API_BASE}/sync/state-changed`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      device_id: deviceId,
      group_id: groupId,
      state_version: generateStateVersion(),
      ack_token_hash: hashAckToken(ackToken),
      vector_clock: vectorClock
    })
  });
  
  const result = await response.json();
  
  // SyncOrc returns list of peers to notify
  if (result.success) {
    return {
      peersToNotify: result.data.peers_to_notify,
      notificationSent: result.data.notification_sent
    };
  }
}
```

### Acknowledging Sync

```javascript
// Acknowledge successful sync completion
async function acknowledgeSync(syncStateId, ackToken) {
  const response = await fetch(`${API_BASE}/sync/acknowledge`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      sync_state_id: syncStateId,
      ack_token: ackToken,
      device_id: deviceId
    })
  });
  
  return response.json();
}
```

---

## Push Notifications

SyncOrc delivers push notifications to wake up offline devices.

### Supported Platforms

| Platform | Provider | Token Format |
|----------|----------|--------------|
| Android | FCM | String token |
| iOS | APNs | String token |
| Web | Web Push | JSON: `{endpoint, keys: {p256dh, auth}}` |

### Token Registration

```javascript
// Update push token (call when token changes)
async function updatePushToken(token) {
  const response = await fetch(`${API_BASE}/devices/push-token`, {
    method: 'PATCH',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      device_id: deviceId,
      push_token: token
    })
  });
  
  return response.json();
}
```

### Handling Notifications

```javascript
// iOS (Swift)
func userNotificationCenter(_ center: UNUserNotificationCenter, 
                            didReceive response: UNNotificationResponse) {
    let userInfo = response.notification.request.content.userInfo
    
    if let type = userInfo["type"] as? String {
        switch type {
        case "sync_required":
            // Initiate sync with peers
            syncCoordinator.syncWithPeers()
        case "device_joined":
            // Handle new device in group
            if let deviceId = userInfo["device_id"] as? String {
                handleDeviceJoined(deviceId)
            }
        default:
            break
        }
    }
}

// Android (Kotlin)
class SyncOrcMessagingService : FirebaseMessagingService() {
    override fun onMessageReceived(remoteMessage: RemoteMessage) {
        when (remoteMessage.data["type"]) {
            "sync_required" -> syncCoordinator.syncWithPeers()
            "device_joined" -> remoteMessage.data["device_id"]?.let { 
                handleDeviceJoined(it) 
            }
        }
    }
}

// Web (JavaScript)
self.addEventListener('push', event => {
  const data = event.data.json();
  
  switch (data.type) {
    case 'sync_required':
      event.waitUntil(syncWithPeers());
      break;
    case 'device_joined':
      event.waitUntil(handleDeviceJoined(data.device_id));
      break;
  }
});
```

### Notification Types

| Type | Triggered When | Client Action |
|------|----------------|---------------|
| `sync_required` | Peer reports state change | Initiate P2P sync |
| `device_joined` | New device joins group | Update peer list, sync keys |
| `device_left` | Device leaves group | Update peer list |
| `signaling_offer` | WebRTC offer received | Process SDP offer |
| `signaling_answer` | WebRTC answer received | Process SDP answer |
| `sync_acknowledged` | Sync completed | Mark sync as complete |

---

## WebRTC Signaling

SyncOrc provides signaling for P2P WebRTC connections.

### Creating an Offer

```javascript
async function createSignalingOffer(peerDeviceId, offerSdp) {
  const response = await fetch(`${API_BASE}/signaling/offer`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      to_device_id: peerDeviceId,
      offer_sdp: btoa(JSON.stringify(offerSdp))
    })
  });
  
  return response.json();
}
```

### Handling Offers (as callee)

```javascript
// Poll for pending offers
async function getPendingOffers() {
  const response = await fetch(`${API_BASE}/signaling/offers`, {
    headers: { 'Authorization': `Bearer ${apiToken}` }
  });
  
  const result = await response.json();
  
  if (result.success) {
    for (const offer of result.data.offers) {
      await handleSignalingOffer(offer);
    }
  }
}

async function handleSignalingOffer(offer) {
  // Create WebRTC answer
  const answerSdp = await createWebRTCAnswer(offer.offer_sdp);
  
  // Send answer back
  await fetch(`${API_BASE}/signaling/answer`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      offer_id: offer.offer_id,
      answer_sdp: btoa(JSON.stringify(answerSdp))
    })
  });
}
```

### Complete WebRTC Flow

```
Device A                          SyncOrc                          Device B
   │                                │                                │
   │ 1. Create offer (SDP)          │                                │
   ├───────────────────────────────►│                                │
   │ 2. POST /signaling/offer       │                                │
   ├───────────────────────────────►│                                │
   │                                │ 3. Push notification           │
   │                                ├───────────────────────────────►│
   │                                │                                │
   │                                │ 4. GET /signaling/offers       │
   │                                │◄───────────────────────────────┤
   │                                │ 5. Create answer (SDP)         │
   │                                │◄───────────────────────────────┤
   │                                │ 6. POST /signaling/answer      │
   │                                │◄───────────────────────────────┤
   │ 7. Push notification           │                                │
   │◄───────────────────────────────┤                                │
   │ 8. ICE exchange via STUN/TURN  │                                │
   │◄───────────────────────────────────────────────────────────────►│
   │ 9. Encrypted data channel open │                                │
   │◄───────────────────────────────────────────────────────────────►│
```

---

## Encrypted Cache

When peers are offline, you can store E2E encrypted payloads for later retrieval.

### Storing Encrypted Payload

```javascript
async function storeEncryptedPayload(groupId, encryptedData, targetDeviceId) {
  const response = await fetch(`${API_BASE}/cache/store`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${apiToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      group_id: groupId,
      encrypted_payload: encryptedData, // base64-encoded
      ttl: 604800, // 7 days (max)
      from_device_id: deviceId,
      to_device_id: targetDeviceId
    })
  });
  
  return response.json();
}
```

### Retrieving Payloads

```javascript
async function retrievePendingPayloads() {
  const response = await fetch(`${API_BASE}/cache/retrieve`, {
    headers: { 'Authorization': `Bearer ${apiToken}` }
  });
  
  const result = await response.json();
  
  if (result.success) {
    for (const item of result.data.payloads) {
      // Decrypt with your E2E encryption
      const decrypted = await decryptPayload(
        item.encrypted_payload,
        item.from_device_id
      );
      
      // Apply to local state
      await applySyncData(decrypted);
      
      // Delete from cache
      await deleteCachedPayload(item.cache_id);
    }
  }
}

async function deleteCachedPayload(cacheId) {
  await fetch(`${API_BASE}/cache/${cacheId}`, {
    method: 'DELETE',
    headers: { 'Authorization': `Bearer ${apiToken}` }
  });
}
```

### Cache Limits

| Limit | Value | Description |
|-------|-------|-------------|
| Max Payload Size | 10 MB | Per payload |
| Max TTL | 7 days | Time-to-live |
| Default TTL | 24 hours | If not specified |

---

## Security Best Practices

### End-to-End Encryption

SyncOrc is zero-knowledge; you must implement E2E encryption:

```javascript
// Example: E2E encryption using libsodium
async function encryptForDevice(plaintext, targetPublicKey) {
  // Generate ephemeral key pair
  const ephemeralKeyPair = await generateKeyPair();
  
  // Derive shared secret
  const sharedSecret = await deriveSharedSecret(
    ephemeralKeyPair.privateKey,
    targetPublicKey
  );
  
  // Encrypt with XChaCha20-Poly1305
  const nonce = generateNonce();
  const ciphertext = await encrypt(plaintext, sharedSecret, nonce);
  
  return {
    ephemeralPublicKey: await exportPublicKey(ephemeralKeyPair.publicKey),
    nonce: encodeBase64(nonce),
    ciphertext: encodeBase64(ciphertext)
  };
}
```

### Key Management

```javascript
// Secure key storage
class KeyManager {
  async generateIdentityKey() {
    const keyPair = await window.crypto.subtle.generateKey(
      { name: 'ECDH', namedCurve: 'P-256' },
      true, // extractable for backup
      ['deriveBits']
    );
    
    // Store in secure enclave/OS keychain
    await this.secureStore.storeKeyPair('identity', keyPair);
    
    return keyPair;
  }
  
  async getPublicKey() {
    const keyPair = await this.secureStore.getKeyPair('identity');
    return keyPair.publicKey;
  }
}
```

### Token Security

- Never log API tokens
- Rotate tokens periodically
- Use secure storage (Keychain, Keystore, etc.)
- Clear tokens on logout/uninstall

---

## Error Handling

### HTTP Status Codes

| Code | Meaning | Action |
|------|---------|--------|
| 200 | Success | Process response |
| 400 | Bad Request | Check request format |
| 401 | Unauthorized | Refresh token or re-authenticate |
| 403 | Forbidden | Check permissions |
| 404 | Not Found | Resource doesn't exist |
| 409 | Conflict | Handle conflict (e.g., pairing expired) |
| 422 | Validation Error | Check request data |
| 429 | Rate Limited | Back off and retry |
| 500 | Server Error | Retry with exponential backoff |

### Retry Strategy

```javascript
async function apiCallWithRetry(url, options, maxRetries = 3) {
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    try {
      const response = await fetch(url, options);
      
      if (response.ok) {
        return response;
      }
      
      // Don't retry on client errors
      if (response.status >= 400 && response.status < 500) {
        throw new Error(`Client error: ${response.status}`);
      }
      
      // Retry on server errors
      if (attempt < maxRetries) {
        const delay = Math.pow(2, attempt) * 1000; // Exponential backoff
        await sleep(delay);
      }
    } catch (error) {
      if (attempt === maxRetries) throw error;
    }
  }
}
```

---

## SDK Reference

While you can use the REST API directly, consider these SDK options:

### Official SDKs (Coming Soon)

- `@syncorc/sdk-js` - JavaScript/TypeScript
- `@syncorc/sdk-swift` - iOS/macOS
- `@syncorc/sdk-kotlin` - Android

### Community SDKs

- Contribute your own!

### TypeScript Types

```typescript
// Core types for TypeScript users
interface Device {
  device_id: string;
  public_key: string;
  platform: 'ios' | 'android' | 'web';
  last_seen_at: string | null;
  created_at: string;
}

interface SyncGroup {
  group_id: string;
  group_type: 'pair' | 'chain' | 'group';
  name: string | null;
  created_at: string;
}

interface VectorClock {
  [deviceId: string]: number;
}

interface SyncState {
  sync_state_id: string;
  device_id: string;
  group_id: string;
  state_version: string;
  ack_token_hash: string;
  vector_clock: VectorClock;
  acknowledged_at: string | null;
  created_at: string;
}
```

---

## Resources

- [API Reference](README.md#api-overview)
- [Specification](SyncOrc-Spec.md)
- [Security Policy](../SECURITY_POLICY.md)
- [Contributing](../CONTRIBUTING.md)

## Support

- GitHub Issues: [github.com/how-else-ai/syncorc/issues](https://github.com/how-else-ai/syncorc/issues)
- Discussions: [github.com/how-else-ai/syncorc/discussions](https://github.com/how-else-ai/syncorc/discussions)

---

**Last Updated:** 2025-02-14  
**Version:** 1.0.0
