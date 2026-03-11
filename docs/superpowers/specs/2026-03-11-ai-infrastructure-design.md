# AI Infrastructure Design — Sub-project A

**Version:** 1.6.0-infra
**Status:** Approved
**Part of:** Phase 5 — AI Features (v1.6.0)

---

## Goal

Provide a zero-user-config AI layer for Easy Demo Importer. The Gemini API key lives on a Cloudflare Worker proxy; the plugin calls the proxy using HMAC-signed requests. All 5 planned AI features (Demo Finder, Conflict Advisor, NL Item Selection, Content Personalizer, Post-Import Diagnostics) consume this infrastructure.

---

## Sub-project Decomposition

The full AI phase (v1.6.0) is broken into 6 independent sub-projects, each with its own spec → plan → implementation cycle:

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
        │  signs with HMAC-SHA256(SHARED_SECRET, "action:timestamp:payload_json")
        │  wp_remote_post(), 10s timeout
        ▼
Cloudflare Worker  (cloudflare/worker.js)
        │  1. Validates timestamp window (±30s)
        │  2. Verifies HMAC signature (timing-safe comparison)
        │  3. Dispatches on action field
        ├── "generate"  →  Gemini Flash (gemini-2.0-flash) generateContent
        └── "embed"     →  text-embedding-004 embedContent
                              │
                              ▼
                        Google Gemini API
                    (key stored in CF env var GEMINI_API_KEY)
```

### Request format (plugin → Worker)

```json
{
  "action":    "generate",
  "timestamp": 1741694400,
  "payload":   { "prompt": "..." },
  "sig":       "ab12cd3e..."
}
```

**Signature string:** `HMAC-SHA256(SHARED_SECRET, "{action}:{timestamp}:{JSON.stringify(payload)}")`

The raw `SHARED_SECRET` never travels over the wire. Only the per-request HMAC digest is sent.

### Response format (Worker → plugin)

Success:
```json
{ "result": { /* raw Gemini response body */ } }
```

Error:
```json
{ "error": "human-readable message" }
```

---

## Cloudflare Worker

**Files:**
```
cloudflare/
  worker.js       — Worker script (named-action proxy + HMAC verification)
  wrangler.toml   — Cloudflare deployment config
  README.md       — deploy instructions
```

**`wrangler.toml`:**
```toml
name = "edi-ai-proxy"
main = "worker.js"
compatibility_date = "2025-01-01"
```

**Secrets** (set via `wrangler secret put`, never committed):
- `GEMINI_API_KEY` — Google Gemini API key
- `SHARED_SECRET`  — same 32+ char random string as `SD_EDI_AI_SHARED_SECRET` in plugin

**Worker logic (`worker.js`):**

```js
export default {
  async fetch(request, env) {
    if (request.method !== 'POST')
      return new Response('Method Not Allowed', { status: 405 });

    let body;
    try { body = await request.json(); }
    catch { return new Response('Bad Request', { status: 400 }); }

    const { action, timestamp, payload, sig } = body;

    // 1. Timestamp window (±30 seconds)
    if (!timestamp || Math.abs(Date.now() / 1000 - timestamp) > 30)
      return new Response('Request expired', { status: 401 });

    // 2. HMAC verification (timing-safe)
    const expected = await hmacSha256(
      env.SHARED_SECRET,
      `${action}:${timestamp}:${JSON.stringify(payload)}`
    );
    if (!timingSafeEqual(expected, sig))
      return new Response('Forbidden', { status: 403 });

    // 3. Dispatch to named action
    if (action === 'generate') return handleGenerate(payload, env);
    if (action === 'embed')    return handleEmbed(payload, env);

    return new Response('Unknown action', { status: 400 });
  }
};

async function handleGenerate({ prompt }, env) {
  const res = await fetch(
    `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=${env.GEMINI_API_KEY}`,
    { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ contents: [{ parts: [{ text: prompt }] }] }) }
  );
  const data = await res.json();
  if (!res.ok) return Response.json({ error: data.error?.message ?? 'Gemini error' }, { status: 502 });
  return Response.json({ result: data });
}

async function handleEmbed({ text }, env) {
  const res = await fetch(
    `https://generativelanguage.googleapis.com/v1beta/models/text-embedding-004:embedContent?key=${env.GEMINI_API_KEY}`,
    { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ model: 'models/text-embedding-004', content: { parts: [{ text }] } }) }
  );
  const data = await res.json();
  if (!res.ok) return Response.json({ error: data.error?.message ?? 'Gemini error' }, { status: 502 });
  return Response.json({ result: data });
}

// HMAC-SHA256 using Web Crypto API (available in CF Workers)
async function hmacSha256(secret, message) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw', enc.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', key, enc.encode(message));
  return Array.from(new Uint8Array(sig)).map(b => b.toString(16).padStart(2, '0')).join('');
}

// Timing-safe string comparison
function timingSafeEqual(a, b) {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}
```

---

## PHP AiClient

**File:** `inc/Common/Utils/AiClient.php`
**Namespace:** `SigmaDevs\EasyDemoImporter\Common\Utils`

### Public API

```php
/**
 * Call Gemini Flash for text generation.
 *
 * @param string $prompt   The prompt to send.
 * @param array  $options  Reserved for future options (e.g. temperature).
 * @return array{text: string}|WP_Error
 */
AiClient::generate(string $prompt, array $options = []): array|WP_Error

/**
 * Generate a text embedding vector via text-embedding-004.
 *
 * @param string $text  Text to embed.
 * @return array{embedding: float[]}|WP_Error
 */
AiClient::embed(string $text): array|WP_Error
```

### Internal flow (both methods)

1. Check `apply_filters('sd/edi/ai_enabled', true)` → return `WP_Error('ai_disabled')` if false
2. Build `$payload` (action-specific fields)
3. Compute `$sig = hash_hmac('sha256', "{action}:{timestamp}:{wp_json_encode($payload)}", SD_EDI_AI_SHARED_SECRET)`
4. `wp_remote_post(SD_EDI_AI_PROXY_URL, ['timeout' => 10, 'body' => wp_json_encode([...body + sig])])`
5. Map HTTP/JSON failures → `WP_Error`
6. Return structured result array

---

## PHP Constants

Defined in `easy-demo-importer.php` (plugin root). Both can be overridden in `wp-config.php` for dev/testing:

```php
// Cloudflare Worker endpoint
if ( ! defined( 'SD_EDI_AI_PROXY_URL' ) ) {
    define( 'SD_EDI_AI_PROXY_URL', 'https://edi-ai-proxy.YOUR_SUBDOMAIN.workers.dev' );
}

// Shared HMAC secret — must match SHARED_SECRET env var in the Worker
// Generate with: openssl rand -hex 32
if ( ! defined( 'SD_EDI_AI_SHARED_SECRET' ) ) {
    define( 'SD_EDI_AI_SHARED_SECRET', 'REPLACE_WITH_GENERATED_SECRET' );
}
```

> ⚠️ Before shipping, replace `REPLACE_WITH_GENERATED_SECRET` with the actual 32+ char random secret and set the matching `wrangler secret put SHARED_SECRET`.

---

## Feature Flag

```php
// Disable all AI features (e.g. in wp-config.php or a plugin):
add_filter( 'sd/edi/ai_enabled', '__return_false' );
```

When disabled, `AiClient` returns `WP_Error('ai_disabled')` immediately without making any HTTP request. All callers check `is_wp_error()` and silently hide AI UI.

---

## Error Handling

All failures return `WP_Error`. **No failure is surfaced to the admin UI** at the infrastructure level — individual feature implementations decide whether to show a degraded state or hide the widget.

| Scenario | `WP_Error` code |
|---|---|
| Feature flag off | `ai_disabled` |
| `wp_remote_post` timeout / unreachable | `ai_unavailable` |
| Worker 401 (expired timestamp) | `ai_unavailable` |
| Worker 403 (bad HMAC) | `ai_auth_failed` |
| Worker 5xx | `ai_unavailable` |
| Gemini API error (502 from Worker) | `ai_error` |
| JSON decode failure | `ai_invalid_response` |

---

## File Summary

| File | Action |
|---|---|
| `inc/Common/Utils/AiClient.php` | **Create** — PHP proxy client |
| `cloudflare/worker.js` | **Create** — Cloudflare Worker |
| `cloudflare/wrangler.toml` | **Create** — CF deployment config |
| `cloudflare/README.md` | **Create** — deploy instructions |
| `easy-demo-importer.php` | **Modify** — add `SD_EDI_AI_PROXY_URL` + `SD_EDI_AI_SHARED_SECRET` constants |

No DB changes. No new REST endpoints. No React changes.

---

## Deploy Checklist (one-time setup)

- [ ] Generate secret: `openssl rand -hex 32`
- [ ] Set Worker secrets: `wrangler secret put GEMINI_API_KEY` and `wrangler secret put SHARED_SECRET`
- [ ] Update `SD_EDI_AI_PROXY_URL` constant in plugin with actual Worker URL
- [ ] Update `SD_EDI_AI_SHARED_SECRET` constant with same secret value
- [ ] `wrangler deploy` from `cloudflare/` directory
- [ ] Smoke test: call `AiClient::generate('Hello')` from a WP admin page
