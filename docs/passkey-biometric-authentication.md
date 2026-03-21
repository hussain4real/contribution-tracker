# Passkey / Biometric Authentication

## Overview

This feature adds WebAuthn-based passkey support, enabling users to authenticate using biometrics (fingerprint, Face ID, Windows Hello) or hardware security keys. It provides two authentication modes:

1. **Passwordless Login** — Sign in with a passkey instead of email/password.
2. **Biometric 2FA** — Use a passkey as an alternative to TOTP codes during two-factor authentication.

Both modes use the [Web Authentication API (WebAuthn)](https://www.w3.org/TR/webauthn-2/), supported by ~97% of modern browsers.

---

## Prerequisites

### Browser Support

WebAuthn requires a browser that supports the `navigator.credentials` API:

| Browser          | Minimum Version |
|------------------|-----------------|
| Chrome / Edge    | 67+             |
| Firefox          | 60+             |
| Safari           | 13+             |
| iOS Safari       | 14+             |
| Chrome Android   | 70+             |

The UI automatically hides passkey options when the browser does not support WebAuthn.

### Server Requirements

- **HTTPS** — WebAuthn only works over HTTPS (or `localhost` for development).
- **Stable domain** — The Relying Party ID is derived from `APP_URL` in your `.env`. Changing the domain will invalidate existing passkeys.

### Dependencies

**Backend (Composer):**

| Package                  | Version | Purpose                              |
|--------------------------|---------|--------------------------------------|
| `spomky-labs/cbor-php`   | ^3.2    | CBOR decoding for attestation data   |
| `web-auth/cose-lib`      | ^4.5    | COSE key parsing and signature verification (ES256, RS256) |

**Frontend (npm):**

| Package                    | Version | Purpose                              |
|----------------------------|---------|--------------------------------------|
| `@simplewebauthn/browser`  | ^13.3   | Browser WebAuthn API wrapper (startRegistration, startAuthentication) |

### Database

Run the migration to create the `passkeys` table:

```bash
php artisan migrate
```

**Schema:**

| Column           | Type          | Description                              |
|------------------|---------------|------------------------------------------|
| `id`             | bigint (PK)   | Auto-increment primary key               |
| `user_id`        | bigint (FK)   | References `users.id`, cascading delete  |
| `name`           | varchar       | User-given name (e.g., "MacBook Touch ID") |
| `credential_id`  | varchar(512)  | Base64-encoded credential identifier (unique) |
| `public_key`     | text          | Base64-encoded COSE public key           |
| `aaguid`         | varchar       | Authenticator attestation GUID (nullable)|
| `sign_count`     | bigint        | Signature counter for clone detection    |
| `attachment_type`| varchar       | `platform` or `cross-platform` (nullable)|
| `transports`     | json          | Transport hints: `usb`, `ble`, `nfc`, `internal` (nullable) |
| `last_used_at`   | timestamp     | Last authentication timestamp (nullable) |
| `created_at`     | timestamp     | Registration timestamp                   |
| `updated_at`     | timestamp     | Last update timestamp                    |

---

## Architecture

### Backend

| File | Purpose |
|------|---------|
| `app/Models/Passkey.php` | Eloquent model with `user()` relationship |
| `app/Services/WebAuthnService.php` | Core WebAuthn ceremony logic — challenge generation, attestation verification, assertion verification, CBOR/COSE cryptography |
| `app/Http/Controllers/Settings/PasskeyController.php` | CRUD for managing passkeys (settings page) |
| `app/Http/Controllers/Auth/PasskeyLoginController.php` | Passwordless login flow |
| `app/Http/Controllers/Auth/PasskeyTwoFactorController.php` | Biometric 2FA alternative |
| `app/Http/Requests/StorePasskeyRequest.php` | Validation for passkey registration |
| `database/factories/PasskeyFactory.php` | Test factory |

### Frontend

| File | Purpose |
|------|---------|
| `resources/js/composables/useWebAuthn.ts` | Shared composable — wraps WebAuthn API calls, error handling, and HTTP requests |
| `resources/js/pages/settings/Passkeys.vue` | Settings page for registering/deleting passkeys |
| `resources/js/pages/auth/Login.vue` | Login page with "Sign in with passkey" button |
| `resources/js/pages/auth/TwoFactorChallenge.vue` | 2FA page with "Verify with biometrics" option |

---

## API Routes

### Guest Routes (no authentication required)

| Method | URI | Name | Description |
|--------|-----|------|-------------|
| `POST` | `/passkey/login/options` | `passkey.login.options` | Generate challenge for passwordless login |
| `POST` | `/passkey/login` | `passkey.login` | Verify passkey assertion and log in |
| `POST` | `/passkey/two-factor/has-passkeys` | `passkey.two-factor.has-passkeys` | Check if user in login flow has passkeys |

### Authenticated Routes (auth middleware)

| Method | URI | Name | Description |
|--------|-----|------|-------------|
| `POST` | `/passkey/two-factor/options` | `passkey.two-factor.options` | Generate 2FA challenge options |
| `POST` | `/passkey/two-factor/verify` | `passkey.two-factor.verify` | Verify 2FA passkey assertion |

### Settings Routes (auth middleware, password confirmation on `show`)

| Method | URI | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/settings/passkeys` | `passkeys.show` | Passkey management page |
| `POST` | `/settings/passkeys/options` | `passkeys.create-options` | Generate registration options |
| `POST` | `/settings/passkeys` | `passkeys.store` | Store a newly registered passkey |
| `DELETE` | `/settings/passkeys/{passkey}` | `passkeys.destroy` | Delete a passkey |

---

## User Flows

### 1. Registering a Passkey

1. Navigate to **Settings → Passkeys** (requires password confirmation).
2. Click **Register new passkey**.
3. Enter a name for the passkey (e.g., "Work Laptop", "iPhone").
4. The browser prompts for biometric verification (fingerprint, Face ID, etc.).
5. On success, the passkey appears in the list.

**Technical flow:**

```
Frontend                        Backend                         Authenticator
   │                               │                                │
   │──POST /passkeys/options──────>│                                │
   │<─────── challenge + options───│                                │
   │                               │                                │
   │──navigator.credentials.create()────────────────────────────────>│
   │<──────────────── attestation response──────────────────────────│
   │                               │                                │
   │──POST /passkeys { credential, name }──>│                      │
   │<─────── 201 Created──────────│                                │
```

### 2. Passwordless Login

1. On the **Login page**, click **Sign in with passkey**.
2. The browser prompts for biometric verification.
3. On success, the user is logged in and redirected to the dashboard.
4. If the user has 2FA enabled, it is automatically bypassed (passkey is a stronger factor).

**Technical flow:**

```
Frontend                        Backend                         Authenticator
   │                               │                                │
   │──POST /passkey/login/options─>│                                │
   │<─────── challenge─────────────│                                │
   │                               │                                │
   │──navigator.credentials.get()───────────────────────────────────>│
   │<──────────────── assertion response────────────────────────────│
   │                               │                                │
   │──POST /passkey/login { assertion }──>│                        │
   │<─────── { redirect }──────────│                                │
```

### 3. Biometric 2FA

1. Log in with email/password as usual.
2. On the **Two-Factor Challenge page**, if passkeys are registered, a **Verify with biometrics** button appears.
3. Click the button — the browser prompts for biometric verification.
4. On success, the 2FA step is completed.

This works alongside the existing TOTP code and recovery code options. Users can choose whichever method they prefer.

---

## Security Considerations

- **Relying Party ID** — Derived from `APP_URL`. Only passkeys registered on the same domain will work.
- **Challenge expiry** — Challenges are stored in the server-side session and consumed on verification (single use).
- **Signature counter** — Tracked per credential to detect cloned authenticators. The counter is updated on each successful authentication.
- **User verification** — Set to `preferred`, meaning the authenticator will perform biometric/PIN verification when possible.
- **Attestation** — Set to `none` (most privacy-friendly). No attestation certificate verification is performed.
- **Supported algorithms** — ES256 (ECDSA P-256) and RS256 (RSA PKCS#1 v1.5 with SHA-256).
- **2FA bypass on passkey login** — When a user logs in via passkey, the session flag `two_factor_confirmed_via_passkey` is set, skipping the TOTP challenge. This is secure because passkeys are inherently multi-factor (possession + biometric/PIN).
- **Credential storage** — Credential IDs and public keys are stored as base64-encoded strings (not raw binary) for MySQL compatibility.

---

## Testing

23 feature tests cover all passkey flows:

```bash
# Run all passkey tests
php artisan test tests/Feature/Settings/PasskeyTest.php --compact
php artisan test tests/Feature/Auth/PasskeyLoginTest.php --compact
php artisan test tests/Feature/Auth/PasskeyTwoFactorTest.php --compact
```

| Test File | Tests | Coverage |
|-----------|-------|----------|
| `tests/Feature/Settings/PasskeyTest.php` | 9 | Page rendering, password confirmation, list passkeys, registration options (structure, user details, exclude existing), delete, authorization, auth guard |
| `tests/Feature/Auth/PasskeyLoginTest.php` | 6 | Challenge generation, discoverable credentials, invalid assertion, required fields, validation, archived user rejection |
| `tests/Feature/Auth/PasskeyTwoFactorTest.php` | 8 | Challenge with/without passkeys, credential count, invalid assertion, required fields, has-passkeys endpoint, route guards |

---

## Local Development

### Setup

```bash
# Install dependencies
composer install
npm install

# Run migration
php artisan migrate

# Build frontend
npm run dev
```

### Testing Passkeys Locally

WebAuthn works on `localhost` without HTTPS. To test:

1. Use Chrome, Edge, or Safari on a device with biometric hardware (Touch ID, fingerprint reader, Windows Hello).
2. If no biometric hardware is available, Chrome DevTools can simulate WebAuthn authenticators:
   - Open DevTools → **More tools → WebAuthn**
   - Enable **Virtual authenticator environment**
   - Add a virtual authenticator with **supports user verification** enabled.

### Wayfinder Routes

After any route changes, regenerate TypeScript route functions:

```bash
php artisan wayfinder:generate
```

Generated files are located at:
- `resources/js/actions/App/Http/Controllers/Settings/PasskeyController.ts`
- `resources/js/actions/App/Http/Controllers/Auth/PasskeyLoginController.ts`
- `resources/js/actions/App/Http/Controllers/Auth/PasskeyTwoFactorController.ts`
- `resources/js/routes/passkeys.ts`
- `resources/js/routes/passkey/`
