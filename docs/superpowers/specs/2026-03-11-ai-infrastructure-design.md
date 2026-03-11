# AI Infrastructure Design — Sub-project A

**Version:** 1.6.0-infra
**Status:** Approved
**Part of:** Phase 5 — AI Features (v1.6.0)

---

## Goal

Provide a zero-user-config AI layer for Easy Demo Importer. The Gemini API key lives on a Cloudflare Worker proxy; the plugin calls the proxy using HMAC-signed requests. All 5 planned AI features (Demo Finder, Conflict Advisor, NL Item Selection, Content Personalizer, Post-Import Diagnostics) consume this infrastructure.

---

## Sub-project Decomposition

| Sub-project | Deliverable | Depends on |
|---|---|---|
| **A — AI Infrastructure** ← this spec | Cloudflare Worker proxy, PHP `AiClient`, feature flag | — |
| **B — Demo Finder** | Embedding-based demo search, bundled embeddings JSON | A |
| **C — Conflict Advisor** | Server-aware AI warnings in RequirementsStep | A |
| **D — NL Item Selection** | Natural language filter for SelectItemsStep | A |
| **E — Content Personalizer** | Adapts demo content to user's niche | A |
| **F — Post-Import Diagnostics** | Scans installed content for issues after import | A |

---

## Architecture

```
WordPress plugin (PHP)
  └── AiClient::generate($prompt)
  └── AiClient::embed($text)
        │  signs with HMAC-SHA256 using canonical message
        │  wp_remote_post() with Content-Type: application/json, 10s timeout
        ▼
Cloudflare Worker  (cloudflare/worker.js)
        │  1. Enforces Content-Type: application/json (server-to-server guard)
        │  2. Enforces 32 KB body size cap
        │  3. Validates timestamp window (±30s)
        │  4. Verifies HMAC (Uint8Array timing-safe comparison)
        │  5. Validates action-specific payload fields
        │  6. Dispatches on action field
        ├── "generate"  →  Gemini Flash (gemini-2.0-flash) generateContent
        └── "embed"     →  text-embedding-004 embedContent
                              │
                              ▼
                        Google Gemini API
                    (key stored in CF env var GEMINI_API_KEY)
```

---

## HMAC Signing Contract

**This contract must be followed identically on both PHP and Worker sides.**

### Canonical message format

```
{action}:{timestamp}:{canonical_payload_json}
```

Where `canonical_payload_json` is the JSON-encoded payload with **keys sorted alphabetically** (recursively). Both PHP and the Worker must produce identical JSON from identical input.

**PHP side:**
```php
// Sort payload keys recursively before encoding
ksort($payload);
$canonical = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$message   = "{$action}:{$timestamp}:{$canonical}";
$sig       = hash_hmac('sha256', $message, SD_EDI_AI_SHARED_SECRET);
// $sig is a 64-char lowercase hex string
```

**Worker side:**
```js
// Sort payload keys alphabetically before stringifying
function canonicalJson(obj) {
  if (typeof obj !== 'object' || obj === null || Array.isArray(obj)) return JSON.stringify(obj);
  return '{' + Object.keys(obj).sort()
    .map(k => JSON.stringify(k) + ':' + canonicalJson(obj[k]))
    .join(',') + '}';
}
const message  = `${action}:${timestamp}:${canonicalJson(payload)}`;
const expected = await hmacSha256Hex(env.SHARED_SECRET, message);
```

Both produce a 64-character lowercase hex string. Comparison is always on 64-char hex strings.

---

## Cloudflare Worker

### Files

```
cloudflare/
  worker.js       — Worker script
  wrangler.toml   — Cloudflare deployment config
  README.md       — deploy instructions (see content spec below)
```

### `wrangler.toml`

```toml
name = "edi-ai-proxy"
main = "worker.js"
compatibility_date = "2026-01-01"
```

Requires `wrangler` CLI v3+.

### Secrets (never committed — set via `wrangler secret put`)

| Variable | Description |
|---|---|
| `GEMINI_API_KEY` | Google Gemini API key |
| `SHARED_SECRET` | 32+ char random hex string; must match `SD_EDI_AI_SHARED_SECRET` in plugin |

### `worker.js` — complete implementation spec

```js
const MAX_BODY_BYTES = 32 * 1024; // 32 KB hard cap

export default {
  async fetch(request, env) {
    // 1. Method guard
    if (request.method !== 'POST')
      return jsonError('Method not allowed', 405);

    // 2. Content-Type guard (server-to-server only; browsers cannot set this cross-origin)
    if (!request.headers.get('content-type')?.includes('application/json'))
      return jsonError('Content-Type must be application/json', 415);

    // 3. Body size cap (fast-path via content-length header; header may be absent on chunked transfers,
    //    so we also check the serialised body length after parsing — Cloudflare's hard platform cap
    //    is 100 MB, but we enforce 32 KB as an application-level guard).
    const contentLength = parseInt(request.headers.get('content-length') ?? '0', 10);
    if (contentLength > MAX_BODY_BYTES)
      return jsonError('Request too large', 413);

    // 4. Parse body
    let body;
    try { body = await request.json(); }
    catch { return jsonError('Invalid JSON', 400); }

    // Post-parse size check (catches chunked requests that bypass content-length above)
    if (JSON.stringify(body).length > MAX_BODY_BYTES)
      return jsonError('Request too large', 413);

    const { action, timestamp, payload, sig } = body;

    // 5. Required fields
    if (!action || !timestamp || !payload || !sig)
      return jsonError('Missing required fields', 400);

    // 6. Timestamp window (±30 seconds)
    if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 30)
      return jsonError('Request expired', 401);

    // 7. HMAC verification (timing-safe Uint8Array comparison)
    const message  = `${action}:${timestamp}:${canonicalJson(payload)}`;
    const expected = await hmacSha256Hex(env.SHARED_SECRET, message);
    if (!timingSafeEqual(expected, String(sig)))
      return jsonError('Forbidden', 403);

    // 8. Dispatch
    if (action === 'generate') return handleGenerate(payload, env);
    if (action === 'embed')    return handleEmbed(payload, env);
    return jsonError('Unknown action', 400);
  }
};

// --- Action handlers ---

async function handleGenerate(payload, env) {
  const prompt = payload?.prompt;
  if (typeof prompt !== 'string' || !prompt.trim())
    return jsonError('payload.prompt must be a non-empty string', 400);

  const res = await fetch(
    `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${env.GEMINI_API_KEY}`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ contents: [{ parts: [{ text: prompt }] }] }),
    }
  );
  const data = await res.json();
  if (!res.ok) return jsonError(data.error?.message ?? 'Gemini error', 502);
  return Response.json({ result: data });
}

async function handleEmbed(payload, env) {
  const text = payload?.text;
  if (typeof text !== 'string' || !text.trim())
    return jsonError('payload.text must be a non-empty string', 400);

  const res = await fetch(
    `https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key=${env.GEMINI_API_KEY}`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ model: 'models/text-embedding-004', content: { parts: [{ text }] } }),
    }
  );
  const data = await res.json();
  if (!res.ok) return jsonError(data.error?.message ?? 'Gemini error', 502);
  return Response.json({ result: data });
}

// --- Crypto helpers ---

async function hmacSha256Hex(secret, message) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw', enc.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false, ['sign']
  );
  const sigBuf = await crypto.subtle.sign('HMAC', key, enc.encode(message));
  return Array.from(new Uint8Array(sigBuf))
    .map(b => b.toString(16).padStart(2, '0')).join('');
}

// Timing-safe comparison using Uint8Array (not char codes — avoids length leak)
function timingSafeEqual(a, b) {
  const encA = new TextEncoder().encode(a);
  const encB = new TextEncoder().encode(b);
  // Both should be 64-char hex strings; pad to same length to avoid early exit
  const len = Math.max(encA.length, encB.length);
  const bufA = new Uint8Array(len);
  const bufB = new Uint8Array(len);
  bufA.set(encA);
  bufB.set(encB);
  let diff = 0;
  for (let i = 0; i < len; i++) diff |= bufA[i] ^ bufB[i];
  return diff === 0;
}

// Canonical JSON: keys sorted alphabetically at every level
function canonicalJson(obj) {
  if (typeof obj !== 'object' || obj === null || Array.isArray(obj))
    return JSON.stringify(obj);
  return '{' + Object.keys(obj).sort()
    .map(k => JSON.stringify(k) + ':' + canonicalJson(obj[k]))
    .join(',') + '}';
}

function jsonError(message, status) {
  return Response.json({ error: message }, { status });
}
```

### Rate limiting

Enable Cloudflare's built-in rate limiting on the Worker route (Cloudflare dashboard → Security → Rate Limiting). Recommended starting value: **60 requests per minute per IP**. This is operational configuration, not code.

---

## PHP AiClient

**File:** `inc/Common/Utils/AiClient.php`
**Namespace:** `SigmaDevs\EasyDemoImporter\Common\Utils`
**Pattern:** Plain static class — no Singleton trait, no Base extension (consistent with `SnapshotManager`, `UrlReplacer`)

### Public API

```php
/**
 * Call Gemini Flash for text generation.
 * Returns ['text' => '...'] or WP_Error.
 * $options is accepted but silently ignored in v1.6.0 (reserved for future use).
 */
public static function generate(string $prompt, array $options = []): array|WP_Error

/**
 * Generate an embedding vector via text-embedding-004.
 * Returns ['embedding' => [0.1, 0.2, ...]] or WP_Error.
 */
public static function embed(string $text): array|WP_Error
```

### Internal flow (both methods)

1. **Feature flag check:** `if ( ! apply_filters( 'sd/edi/ai_enabled', true ) ) return new WP_Error( 'ai_disabled', ... );`
2. **Secret guard:** `if ( SD_EDI_AI_SHARED_SECRET === '' ) return new WP_Error( 'ai_misconfigured', ... );`
3. Build `$payload` (action-specific):
   - `generate`: `['prompt' => $prompt]`
   - `embed`: `['text' => $text]`

   **Invariant:** all payloads are flat objects (no nested keys). This is a hard contract — Sub-projects B–F must never add nested payload keys without updating the signing logic on both sides. `ksort()` is therefore sufficient (no recursive sort needed) and must stay in sync with the Worker's `canonicalJson()` depth.

4. Sort `$payload` keys with `ksort()`, then encode: `$canonical = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )`
5. Compute signature: `$sig = hash_hmac( 'sha256', "{$action}:{$timestamp}:{$canonical}", SD_EDI_AI_SHARED_SECRET )`
6. HTTP call:
   ```php
   wp_remote_post( SD_EDI_AI_PROXY_URL, [
       'timeout' => 10,
       'headers' => [ 'Content-Type' => 'application/json' ],
       'body'    => wp_json_encode( [
           'action'    => $action,
           'timestamp' => $timestamp,
           'payload'   => $payload,
           'sig'       => $sig,
       ] ),
   ] )
   ```
7. On `is_wp_error($response)` or HTTP status ≠ 200 → map to `WP_Error` per error table below
8. JSON-decode body. On failure → `WP_Error( 'ai_invalid_response' )`
9. **Response extraction:**
   - `generate`: extract `$body['result']['candidates'][0]['content']['parts'][0]['text']`. If path is missing/empty → `WP_Error( 'ai_error', 'Empty response from Gemini' )`. Return `['text' => $text]`.
   - `embed`: extract `$body['result']['embedding']['values']`. If missing/not array → `WP_Error( 'ai_error', 'Empty embedding from Gemini' )`. Return `['embedding' => $values]`.

---

## PHP Constants

Defined in `easy-demo-importer.php`. Can be overridden in `wp-config.php`.

```php
// Cloudflare Worker endpoint URL
if ( ! defined( 'SD_EDI_AI_PROXY_URL' ) ) {
    define( 'SD_EDI_AI_PROXY_URL', '' ); // set to your Workers URL before shipping
}

// Shared HMAC secret — must match SHARED_SECRET env var in the Worker
// Generate with: openssl rand -hex 32
// Empty string = AI features disabled (AiClient returns WP_Error('ai_misconfigured'))
if ( ! defined( 'SD_EDI_AI_SHARED_SECRET' ) ) {
    define( 'SD_EDI_AI_SHARED_SECRET', '' );
}
```

> ⚠️ Both constants default to empty string intentionally. `AiClient` checks for empty secret and returns `WP_Error('ai_misconfigured')` rather than signing with an empty key. AI features are silently hidden on sites where the constants are not configured.

The `easy-demo-importer.php` plugin header must also be updated: `Requires PHP: 8.0` (was `7.4`). `AiClient` uses union return types (`array|WP_Error`) which require PHP 8.0.

---

## Feature Flag

```php
// Disable all AI features (e.g. in a child plugin or wp-config.php):
add_filter( 'sd/edi/ai_enabled', '__return_false' );
```

`AiClient` returns `WP_Error('ai_disabled')` immediately without making any HTTP request. All callers check `is_wp_error()` and silently hide AI UI.

---

## Error Handling

All failures return `WP_Error`. No failure is surfaced to the admin UI at the infrastructure level.

| Scenario | `WP_Error` code |
|---|---|
| `SD_EDI_AI_SHARED_SECRET` is empty | `ai_misconfigured` |
| Feature flag filter returns false | `ai_disabled` |
| `wp_remote_post` timeout / unreachable | `ai_unavailable` |
| Worker 400 (bad payload / unknown action) | `ai_invalid_request` |
| Worker 401 (expired timestamp) | `ai_unavailable` |
| Worker 403 (bad HMAC) | `ai_auth_failed` |
| Worker 413 (body too large) | `ai_invalid_request` |
| Worker 415 (wrong Content-Type) | `ai_invalid_request` |
| Worker 5xx | `ai_unavailable` |
| Gemini API error (502 from Worker) | `ai_error` |
| JSON decode failure | `ai_invalid_response` |
| Empty/missing response path | `ai_error` |

Individual AI feature implementations decide whether to show a degraded UI message or simply omit the widget on any `WP_Error` from `AiClient`.

---

## File Summary

| File | Action | Notes |
|---|---|---|
| `inc/Common/Utils/AiClient.php` | **Create** | PHP proxy client |
| `cloudflare/worker.js` | **Create** | Cloudflare Worker |
| `cloudflare/wrangler.toml` | **Create** | CF deployment config |
| `cloudflare/README.md` | **Create** | Deploy instructions (see below) |
| `easy-demo-importer.php` | **Modify** | Add constants; bump `Requires PHP` to `8.0` |
| `.gitignore` | **Modify** | Add `cloudflare/.wrangler/` |

No DB changes. No new REST endpoints. No React changes.

---

## `cloudflare/README.md` — required sections

The README must cover:

1. **Prerequisites** — Node.js 18+, wrangler v3+ (`npm i -g wrangler`), Cloudflare account
2. **One-time setup** — `wrangler login`, generate secret (`openssl rand -hex 32`), set Worker secrets:
   ```bash
   wrangler secret put GEMINI_API_KEY
   wrangler secret put SHARED_SECRET
   ```
3. **Deploy** — `cd cloudflare && wrangler deploy`
4. **Update plugin constants** — paste the Worker URL into `SD_EDI_AI_PROXY_URL` and the generated secret into `SD_EDI_AI_SHARED_SECRET` in `easy-demo-importer.php`
5. **Smoke test** — call `AiClient::generate('Say hello')` from WP admin. Expected result: `['text' => '...some string...']` (not `WP_Error`)
6. **Rollback** — `wrangler rollback` reverts to previous deployment; does not affect plugin constants

---

## Deploy Checklist

- [ ] `openssl rand -hex 32` → copy the output as your shared secret
- [ ] `wrangler secret put GEMINI_API_KEY` (paste Gemini API key)
- [ ] `wrangler secret put SHARED_SECRET` (paste shared secret from above)
- [ ] Update `SD_EDI_AI_PROXY_URL` in `easy-demo-importer.php` with Worker URL
- [ ] Update `SD_EDI_AI_SHARED_SECRET` in `easy-demo-importer.php` with shared secret
- [ ] Add `cloudflare/.wrangler/` to `.gitignore`
- [ ] `wrangler deploy` from `cloudflare/` directory
- [ ] Smoke test: `AiClient::generate('Say hello')` returns `['text' => '...']`
- [ ] Enable Cloudflare rate limiting on the Worker route (60 req/min/IP recommended)
