# Security Policy (SOC 2–Aligned)

## 1. Overview

SyncOrc is a privacy-first, zero-knowledge synchronization orchestration service. This Security Policy describes the controls and practices used to protect the system and customer data and is designed to align with **SOC 2 Trust Services Criteria**, with a primary focus on:

- **Security** (Common Criteria, CC)
- **Availability** (A) 
- **Confidentiality** (C)

Because SyncOrc is intentionally application-agnostic and zero-knowledge, **Processing Integrity (PI)** and **Privacy (P)** are primarily the responsibility of client applications and data controllers.

This policy serves as a high-level reference for internal implementation and for external stakeholders evaluating our security posture.

---

## 2. Trust Services Criteria Mapping (High Level)

| Criteria | SyncOrc Controls |
|----------|------------------|
| **Security (CC)** | Logical access controls, network security, encryption in transit, change management, secure SDLC, vulnerability management, incident response |
| **Availability (A)** | Uptime targets, monitoring/alerting, capacity management, backup/recovery |
| **Confidentiality (C)** | Zero-knowledge architecture, encryption at rest/in transit, data retention/disposal |

---

## 3. Governance and Responsibility

### 3.1 Roles and Responsibilities

| Role | Responsibilities |
|------|------------------|
| **Security Owner** | Policy maintenance, SOC 2 control oversight, incident coordination |
| **Engineering Team** | Secure coding, infrastructure security, vulnerability remediation |
| **Operations/DevOps** | Monitoring, production access management, backup/recovery |

### 3.2 Policy Review

- Reviewed **annually** or after material system changes
- Changes versioned and communicated internally
- Aligned with SOC 2 2025 Trust Services Criteria updates [web:34][web:37]

---

## 4. Access Control and Identity Management (CC6.1-CC6.8)

### 4.1 Logical Access Controls

- **Production infrastructure access** restricted by **role and least privilege**
- **Administrative access** requires:
  - Unique user accounts (no shared credentials)
  - Strong authentication (SSO + MFA where available)
  - Session timeouts
- **Access reviews**: Quarterly review of production/critical system access
- **Offboarding**: Access revoked within 24 hours of role change/termination

### 4.2 Device and Endpoint Security

- Engineering workstations must:
  - Full-disk encryption
  - Up-to-date OS/security patches
  - Reputable endpoint protection
- Production access restricted to managed/trusted devices

---

## 5. Application-Level Security (CC6.1-CC6.8, CC7.1-CC7.5)

### 5.1 Zero-Knowledge Architecture (CC6.6)

**Core Principle**: SyncOrc **never receives plaintext synchronization payloads**.

**Data Stored by SyncOrc:**
```
✅ Device IDs (UUIDs)                    ✅ Public keys (for ECDH handshake)
✅ Group/membership IDs (UUIDs)          ✅ Push notification tokens
✅ State versions (opaque strings)       ✅ Vector clocks (per-device counters)
✅ Ack token hashes (SHA-256)            ✅ Connection metadata (timing only)
```

**Data NEVER Stored:**
```
❌ Plaintext sync payloads               ❌ Decryption keys
❌ User PII                              ❌ Private keys
❌ Shared secrets (derived client-side)  ❌ Application data
```

### 5.2 Authentication and Authorization

**Device Authentication** (CC6.2):
- Bearer tokens (64-char random, SHA-256 hashed storage)
- Token lifetime: 7 days default, refreshable
- Token validation on every authenticated request

**Authorization** (CC6.3):
```
Device can only access:
✅ Its own device record
✅ Groups it's a member of  
✅ Cache payloads addressed to it
❌ Other devices' data
❌ Groups it's not in
```

### 5.3 Input Validation and Output Encoding (CC6.7)

**All external inputs validated** for:
- Type (UUID, string, enum, JSON shape)
- Format/length (SHA-256 hex exactly 64 chars)
- Allowed values (platform: ios/android/web)

**Validation Examples:**
```php
// SHA-256 ack token hash
'ack_token_hash' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/',

// Vector clock entries
'vector_clock.*' => 'integer|min:0|max:2147483647',
```

### 5.4 Secure Development Lifecycle (CC7.1-CC7.5)

**Code Changes Require:**
1. Pull request with code review
2. Automated tests pass
3. Static analysis passes (PHPStan)
4. Code style compliance (Pint)

**Security-Sensitive Changes** (auth, crypto, access control):
- Additional peer review
- Security impact documented in PR
- Tests covering security scenarios

---

## 6. Network and Infrastructure Security (CC6.6, A1.2)

### 6.1 Network Security Controls

```
Public exposure: 443 (HTTPS), 6001 (WSS) only
Internal only: PostgreSQL, Redis, queue services
```

**Controls:**
- TLS-terminating reverse proxy/load balancer
- WAF recommended for production
- VPC/private networking for internal services
- Security groups / firewalls blocking unauthorized access

### 6.2 Cryptographic Controls

**In Transit (CC6.6):**
```
Protocol: TLS 1.2+ (1.3 preferred)
Ciphers: Modern, forward secrecy enabled
Certificate management: Automated ACME/Let's Encrypt
```

**At Rest (CC6.6):**
```
Database: Disk encryption or managed service encryption
Volumes: Encrypted EBS or equivalent
Backups: Encrypted storage
Keys: Cloud KMS or equivalent
```

### 6.3 API Security

```
✅ Bearer token authentication              ✅ Rate limiting
✅ Input validation                         ✅ CORS policy
✅ HTTPS-only                               ✅ CSRF protection (API)
✅ Content security headers                 ✅ Request/response size limits
```

---

## 7. Data Management, Confidentiality, and Privacy (C1.1-C1.5)

### 7.1 Data Classification

| Category | Examples | Confidentiality Level |
|----------|----------|----------------------|
| **System Metadata** | Device/group IDs, timestamps | Internal |
| **Notification Data** | Push tokens, sync triggers | Confidential |
| **Encrypted Cache** | Client-encrypted payloads | Confidential (opaque) |

**No PII stored by SyncOrc by design.**

### 7.2 Data Lifecycle Management

```
Pairing requests:    5 minutes TTL
Signaling offers:    30 seconds TTL  
Sync states:         Operational need (prunable)
Cached payloads:     Configurable TTL (max 7 days)
Backups:             30-90 days retention
```

**Secure Disposal**: Provider-native mechanisms (overwrite, secure delete).

### 7.3 Data Access Logging (CC7.3)

```
✅ Authentication failures (no plaintext tokens)
✅ Authorization failures
✅ Data access (cache retrieve)
✅ Configuration changes
❌ Never log: tokens, keys, plaintext payloads
```

---

## 8. Availability and Business Continuity (A1.1-A1.5)

### 8.1 Service Level Objectives

```
Uptime Target: 99.5% monthly (excluding planned maintenance)
RTO: 4 hours (critical services)
RPO: 1 hour (database)
```

### 8.2 Monitoring and Alerting

**Monitored Metrics:**
```
-  API error rates (p95 > 1%)
-  WebSocket connection drops
-  Queue depth (> 10k jobs)
-  Database connection pool exhaustion
-  Infrastructure CPU/memory > 80%
```

**Alerting:** PagerDuty/Slack/OPSGENIE for P1/P2 incidents.

### 8.3 Backup and Recovery

**Database Backups:**
```
-  Daily snapshots + WAL shipping
-  Point-in-time recovery (1 hour granularity)
-  Quarterly restore testing
-  Encrypted storage
```

---

## 9. Logging, Monitoring, and Incident Response (CC7.2-CC7.4)

### 9.1 Centralized Logging

**Log Aggregation:** ELK stack / Loki / CloudWatch Logs
**Retention:** 90 days searchable, 1 year archival

**Logged Events:**
```
-  Authentication failures (sanitized)
-  Rate limit triggers
-  API errors (422, 429, 5xx)
-  Infrastructure alerts
-  Security events (failed logins, anomalies)
```

**NEVER Logged:**
```
-  Bearer tokens (raw or hashed)
-  Encryption keys/shared secrets
-  Client sync payloads
```

### 9.2 Incident Response Process

```
1. Detection & Triage (MTTD < 15 min)
2. Containment (< 1 hour for P1)
3. Eradication & Recovery (RTO met)
4. Post-mortem & lessons learned (< 5 days)
```

**Notification:** Customers notified per contractual/legal obligations.

---

## 10. Third-Party Risk Management (CC9.2)

**Vendor Requirements:**
```
✅ SOC 2 Type II / ISO 27001 (or equivalent)
✅ Contractual security commitments
✅ Regular security reviews
✅ Right-to-audit clauses where applicable
```

**Current Dependencies:**
- Cloud provider (AWS/GCP/Azure)
- PostgreSQL managed service
- Redis managed service
- FCM/APNs (Google/Apple)

---

## 11. Vulnerability Management (CC7.1)

**Process:**
```
1. Weekly dependency scans (Dependabot/Snyk)
2. Critical/High CVEs: Patch within 7 days
3. Medium/Low: Patch within 30 days
4. Infrastructure scans monthly
```

**Tools:** GitHub Dependabot, Snyk, Trivy (containers)

---

## 12. Responsible Disclosure

**Report Security Issues To:** `syncorc-security@how-else.com`

**Include:**
- Clear description
- Steps to reproduce  
- Impact assessment
- Affected version/commit

**Our Commitment:**
- **24-hour acknowledgment**
- **7-day initial assessment**
- **90-day coordinated disclosure** (unless safety/legal requires faster)

**Please:**
```
✅ Test responsibly
✅ Avoid production data
✅ Don't disrupt service
✅ Give us time to fix
```

---

## 13. Compliance Evidence

**Available Upon Request (for Customers):**
```
✅ SOC 2 Type II bridge letter (post-audit)
✅ Security questionnaire responses
✅ Recent pen-test executive summary
✅ Architecture diagrams
✅ Control self-assessment
```

---

## 14. Policy Maintenance

- **Annual Review**: February each year
- **Trigger Reviews**: Material system changes, new regulations, SOC 2 criteria updates
- **Version Control**: Git-tagged releases of this policy
- **Communication**: Internal change log, customer security portal updates

---

## 15. License

SyncOrc is distributed under the **MIT License**. See `LICENSE` for full terms.

---

**Last Updated:** February 13, 2026  
**Version:** 1.0.0
```
