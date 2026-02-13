# Contributing to SyncOrc

Thank you for your interest in contributing to **SyncOrc**! Contributions of all kinds are welcome: bug reports, feature requests, documentation improvements, testing, and code changes.

SyncOrc is designed to be **privacy-first** and **SOC 2–friendly**, so all contributions must respect the zero-knowledge architecture and security requirements.

---

## Code of Conduct

By participating, you agree to:

- Be **respectful, inclusive, and constructive**
- Focus on **technical issues**, not individuals
- **Assume good intent** and seek clarification when needed

If you experience unacceptable behavior, contact `maintainers-syncorc@how-else.com`.

---

## Ways to Contribute

1. **🐛 Report bugs** – Help us identify and fix issues
2. **🚀 Suggest features** – Propose new capabilities or improvements  
3. **📚 Improve documentation** – Clarify setup, APIs, or client integration
4. **🧪 Add tests** – Unit tests for services, feature tests for flows
5. **⚡ Optimize performance** – Database queries, caching, scaling
6. **🔒 Security improvements** – Within the zero-knowledge constraints

---

## Getting Started

### 1. Fork and Clone

```bash
# Fork on GitHub, then clone your fork
git clone https://github.com/YOUR_USERNAME/syncorc.git
cd syncorc

# Add upstream for future updates
git remote add upstream https://github.com/how-else-ai/syncorc.git
```

### 2. Development Environment

```bash
# Install PHP dependencies
composer install

# Copy and configure environment
cp .env.example .env
php artisan key:generate

# Configure database/Redis in .env
# Run migrations
php artisan migrate

# Optional: seed test data
php artisan db:seed --class=DatabaseSeeder
```

**Start services:**
```bash
php artisan serve           # API server
php artisan queue:work      # Jobs
php artisan reverb:start    # WebSocket
```

---

## Development Workflow

### Branching Strategy

```bash
# Always branch from main
git checkout main
git pull upstream main

# Create focused feature branches
git checkout -b feature/add-cache-size-limit
# or
git checkout -b fix/vector-clock-loop-detection
# or  
git checkout -b docs/update-pairing-guide
```

### Commit Messages

Keep them **clear and concise**:

```
✅ Add device registration rate limiting
✅ Fix vector clock comparison for empty clocks  
✅ Update README with Docker instructions
✅ Add tests for cache TTL expiration

❌ "fix bug"
❌ "update stuff"
❌ "."
```

**Format:** `[type]: [short description]`

- `feat` – New feature
- `fix` – Bug fix
- `docs` – Documentation
- `test` – Adding tests
- `refactor` – Code changes without behavior change
- `chore` – Build process, tooling

---

## Before Submitting a Pull Request

### 1. Run Tests

```bash
# Run all tests
php artisan test

# Run specific suite  
php artisan test tests/Feature/DeviceTest.php

# Run with coverage
php artisan test --coverage
```

### 2. Code Style

```bash
# Auto-format
./vendor/bin/pint

# Check only  
./vendor/bin/pint --test
```

### 3. Static Analysis

```bash
# If configured
./vendor/bin/phpstan analyse
```

### 4. Verify No Secrets

```
❌ Never commit:
  - .env files
  - API keys, tokens, passwords
  - Private keys or certificates
```

---

## Pull Request Requirements

### Checklist

- [ ] **Tests pass** (`php artisan test`)
- [ ] **Code style clean** (`./vendor/bin/pint`)
- [ ] **No secrets committed**
- [ ] **Documentation updated** (README, SPECIFICATION, inline docs)
- [ ] **Security considerations addressed** (see below)

### PR Description Template

```markdown
## What

[Brief description of what this PR does]

## Why

[Why this change is needed - problem it solves, use case, etc.]

## How to Test

1. [Step 1]
2. [Step 2]
3. [Expected result]

## Security Considerations

- [ ] No new logging of sensitive data
- [ ] Input validation covers edge cases  
- [ ] Access controls verified
- [ ] Zero-knowledge architecture preserved

Closes #123
```

---

## Security Requirements

**SyncOrc's zero-knowledge design is non-negotiable.** Contributions must **never**:

```
❌ Log bearer tokens (raw or hashed)
❌ Store plaintext sync payloads  
❌ Log encryption keys/shared secrets
❌ Add server-side decryption logic
❌ Bypass client-side E2E encryption
❌ Expose device relationships unnecessarily
```

**Always validate:**

```
✅ All external inputs (API, WebSocket, push)
✅ Device owns the group/resource it's accessing  
✅ Rate limits applied
✅ Zero-knowledge principles preserved
```

**Security-sensitive changes** (auth, crypto, access control):
- **Explicitly document** security impact in PR
- **Add security tests**
- **Tag with `security` label**

**Found a vulnerability?** Do **not** open a public issue. Email `syncorc-security@how-else.com`.

---

## Testing Guidelines

### Unit Tests (Services)

```php
// Example: VectorClockServiceTest.php
public function test_loop_detection_prevents_infinite_sync()
{
    $service = new VectorClockService();
```
