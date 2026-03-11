# AI Infrastructure (Sub-project A) Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the HMAC-signed Cloudflare Worker proxy and PHP `AiClient` that all future AI features (Sub-projects B–F) will consume.

**Architecture:** The plugin calls a Cloudflare Worker via `wp_remote_post` with HMAC-SHA256-signed requests; the Worker validates the signature and forwards to Gemini API. The PHP `AiClient` is a plain static class (no WP dependency at construction time) that handles signing, HTTP dispatch, and response extraction. PHP constants default to empty string — AI is silently disabled until the Worker is deployed and constants are configured.

**Tech Stack:** PHP 8.0+, WordPress `wp_remote_post`, Cloudflare Workers (ES module syntax), Web Crypto API (`crypto.subtle`), Google Gemini API (`gemini-2.0-flash` + `text-embedding-004`), wrangler v3+ CLI.

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `cloudflare/wrangler.toml` | Create | CF deployment config (name, main, compatibility_date) |
| `cloudflare/worker.js` | Create | Full Worker: method guard, Content-Type guard, body cap, HMAC verify, named action dispatch |
| `cloudflare/README.md` | Create | Deploy instructions: prerequisites, setup, deploy, constants, smoke test, rollback |
| `inc/Common/Utils/AiClient.php` | Create | PHP proxy client: feature flag, secret guard, canonical signing, `wp_remote_post`, response extraction, error mapping |
| `easy-demo-importer.php` | Modify | Add `SD_EDI_AI_PROXY_URL` + `SD_EDI_AI_SHARED_SECRET` constants; bump `Requires PHP` to `8.0` |
| `.gitignore` | Modify | Add `cloudflare/.wrangler/` to exclude wrangler build artifacts |

---

## Chunk 1: Cloudflare Worker

### Task 1: Scaffold `cloudflare/` directory

**Files:**
- Create: `cloudflare/wrangler.toml`
- Modify: `.gitignore`

- [ ] **Step 1: Create `cloudflare/wrangler.toml`**

Write the file with these exact contents:

```toml
name = "edi-ai-proxy"
main = "worker.js"
compatibility_date = "2026-01-01"
```

- [ ] **Step 2: Add `cloudflare/.wrangler/` to `.gitignore`**

Append to `.gitignore` at the end of the file:

```
# Cloudflare Wrangler
##########
cloudflare/.wrangler/
```

- [ ] **Step 3: Commit**

```bash
git add cloudflare/wrangler.toml .gitignore
git commit -m "feat: scaffold cloudflare/ directory with wrangler.toml"
```

---

### Task 2: Write `cloudflare/worker.js`

**Files:**
- Create: `cloudflare/worker.js`

This is the complete Worker implementation. Write it exactly as shown — do not abbreviate or restructure any helpers. The canonical JSON, timing-safe comparison, and HMAC functions are all required.

- [ ] **Step 1: Create `cloudflare/worker.js`**

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

    // 3. Body size cap (fast-path via content-length header; header may be absent on chunked
    //    transfers, so we also check the serialised body length after parsing).
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

// Timing-safe comparison using Uint8Array (not char codes — avoids length leak).
// Both inputs should be 64-char hex strings. Pad to same length to avoid early exit.
function timingSafeEqual(a, b) {
  const encA = new TextEncoder().encode(a);
  const encB = new TextEncoder().encode(b);
  const len = Math.max(encA.length, encB.length);
  const bufA = new Uint8Array(len);
  const bufB = new Uint8Array(len);
  bufA.set(encA);
  bufB.set(encB);
  let diff = 0;
  for (let i = 0; i < len; i++) diff |= bufA[i] ^ bufB[i];
  return diff === 0;
}

// Canonical JSON: keys sorted alphabetically at every level.
// Payloads in this system are always flat (no nested objects) — see AiClient spec invariant.
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

- [ ] **Step 2: Verify Worker syntax**

```bash
node --check cloudflare/worker.js
```

Expected: no output (no errors). Node.js parses the file without executing it — any syntax error will be printed immediately.

If you have wrangler v3 installed you can also run `cd cloudflare && wrangler deploy` (a full deploy) in Task 6 Step 1, which will additionally catch any runtime issues.

- [ ] **Step 3: Commit**

```bash
git add cloudflare/worker.js
git commit -m "feat: add Cloudflare Worker proxy for Gemini API (AI infrastructure)"
```

---

### Task 3: Write `cloudflare/README.md`

**Files:**
- Create: `cloudflare/README.md`

- [ ] **Step 1: Create `cloudflare/README.md`**

```markdown
# Easy Demo Importer — AI Proxy (Cloudflare Worker)

This Worker acts as a secure proxy between the WordPress plugin and the Google Gemini API.
The plugin never holds the Gemini API key — it lives in Cloudflare's encrypted secret store.

## Prerequisites

- Node.js 18+
- wrangler v3+: `npm i -g wrangler`
- Cloudflare account (free plan is sufficient)
- Google Gemini API key

## One-Time Setup

**1. Log in to Cloudflare:**

```bash
wrangler login
```

**2. Generate a shared secret (save this — you will need it for the plugin):**

```bash
openssl rand -hex 32
```

**3. Set Worker secrets (you will be prompted to paste the value for each):**

```bash
cd cloudflare
wrangler secret put GEMINI_API_KEY
wrangler secret put SHARED_SECRET
```

## Deploy

```bash
cd cloudflare
wrangler deploy
```

After a successful deploy, Wrangler prints the Worker URL, e.g.:
`https://edi-ai-proxy.<your-subdomain>.workers.dev`

## Update Plugin Constants

Open `easy-demo-importer.php` and update the two constants near the top of the file:

```php
define( 'SD_EDI_AI_PROXY_URL', 'https://edi-ai-proxy.<your-subdomain>.workers.dev' );
define( 'SD_EDI_AI_SHARED_SECRET', '<the-hex-string-from-openssl-above>' );
```

## Smoke Test

From WP Admin, run via WP CLI:

```bash
wp eval "var_dump(\SigmaDevs\EasyDemoImporter\Common\Utils\AiClient::generate('Say hello'));"
```

Expected: `array(1) { ["text"]=> string(...) "Hello! ..." }` (not a `WP_Error`).

If `$result` is a `WP_Error`, check:
- `SD_EDI_AI_PROXY_URL` is set to the correct Worker URL
- `SD_EDI_AI_SHARED_SECRET` matches the `SHARED_SECRET` you set in Cloudflare
- The Worker deployed successfully (`wrangler deploy` returned no errors)

## Rollback

To revert to the previous Worker deployment:

```bash
cd cloudflare
wrangler rollback
```

This does **not** affect the plugin constants — the plugin continues to use whichever URL
and secret you have configured.

## Rate Limiting

Enable Cloudflare's built-in rate limiting on the Worker route via the Cloudflare dashboard:
**Security → Rate Limiting → Create Rule**

Recommended starting value: 60 requests per minute per IP.
```

- [ ] **Step 2: Commit**

```bash
git add cloudflare/README.md
git commit -m "docs: add cloudflare/README.md with deploy and smoke-test instructions"
```

---

## Chunk 2: PHP AiClient + Plugin Constants

### Task 4: Create `inc/Common/Utils/AiClient.php`

**Files:**
- Create: `inc/Common/Utils/AiClient.php`

Follow the same conventions as `SnapshotManager.php` and `UrlReplacer.php` in this directory:
- `declare(strict_types=1)` at top
- Namespace: `SigmaDevs\EasyDemoImporter\Common\Utils`
- `ABSPATH` guard
- Plain static class — no `Singleton` trait, no `Base` extension
- DocBlock with `@package SigmaDevs\EasyDemoImporter` and `@since 1.6.0`

- [ ] **Step 1: Create `inc/Common/Utils/AiClient.php`**

```php
<?php
/**
 * Utility: AiClient
 *
 * Sends HMAC-signed requests to the Cloudflare Worker AI proxy and extracts
 * typed responses. All AI feature sub-projects (B–F) consume this class.
 *
 * Feature flag: return false from `sd/edi/ai_enabled` to disable all AI calls.
 * Constants (defined in easy-demo-importer.php, override in wp-config.php):
 *   SD_EDI_AI_PROXY_URL      — Cloudflare Worker endpoint URL (default '').
 *   SD_EDI_AI_SHARED_SECRET  — HMAC secret; empty = AI disabled (default '').
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.6.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class AiClient
 *
 * @since 1.6.0
 */
class AiClient {

	/**
	 * Call Gemini Flash for text generation.
	 *
	 * Returns ['text' => '...'] on success, or WP_Error on failure.
	 * $options is accepted but reserved for future use (silently ignored in v1.6.0).
	 *
	 * @param string $prompt  Prompt text to send.
	 * @param array  $options Reserved for future use.
	 * @return array|WP_Error
	 * @since 1.6.0
	 */
	public static function generate( string $prompt, array $options = [] ): array|WP_Error {
		$guard = self::guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$payload = [ 'prompt' => $prompt ];
		$result  = self::request( 'generate', $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

		if ( '' === $text ) {
			return new WP_Error( 'ai_error', __( 'Empty response from Gemini.', 'easy-demo-importer' ) );
		}

		return [ 'text' => $text ];
	}

	/**
	 * Generate an embedding vector via text-embedding-004.
	 *
	 * Returns ['embedding' => [0.1, 0.2, ...]] on success, or WP_Error on failure.
	 *
	 * @param string $text Text to embed.
	 * @return array|WP_Error
	 * @since 1.6.0
	 */
	public static function embed( string $text ): array|WP_Error {
		$guard = self::guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$payload = [ 'text' => $text ];
		$result  = self::request( 'embed', $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$values = $result['embedding']['values'] ?? null;

		if ( ! is_array( $values ) ) {
			return new WP_Error( 'ai_error', __( 'Empty embedding from Gemini.', 'easy-demo-importer' ) );
		}

		return [ 'embedding' => $values ];
	}

	/**
	 * Feature flag + secret guard.
	 *
	 * @return true|WP_Error
	 * @since 1.6.0
	 */
	private static function guard(): true|WP_Error {
		if ( ! apply_filters( 'sd/edi/ai_enabled', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return new WP_Error( 'ai_disabled', __( 'AI features are disabled.', 'easy-demo-importer' ) );
		}

		if ( SD_EDI_AI_SHARED_SECRET === '' ) {
			return new WP_Error( 'ai_misconfigured', __( 'AI proxy is not configured.', 'easy-demo-importer' ) );
		}

		return true;
	}

	/**
	 * Sign and send a request to the Worker proxy.
	 *
	 * Payload invariant: all payloads must be flat (no nested objects). ksort() is
	 * sufficient because the Worker's canonicalJson() only sorts top-level keys for
	 * flat payloads, and both sides must produce identical canonical JSON.
	 *
	 * @param string $action  Named action: 'generate' or 'embed'.
	 * @param array  $payload Flat key-value payload (no nested objects).
	 * @return array|WP_Error Decoded `result` value from Worker response, or WP_Error.
	 * @since 1.6.0
	 */
	private static function request( string $action, array $payload ): array|WP_Error {
		$timestamp = time();

		ksort( $payload );
		$canonical = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$message   = "{$action}:{$timestamp}:{$canonical}";
		$sig       = hash_hmac( 'sha256', $message, SD_EDI_AI_SHARED_SECRET );

		$body = wp_json_encode(
			[
				'action'    => $action,
				'timestamp' => $timestamp,
				'payload'   => $payload,
				'sig'       => $sig,
			]
		);

		$response = wp_remote_post(
			SD_EDI_AI_PROXY_URL,
			[
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_unavailable', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			return self::mapHttpError( $status, $raw );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ai_invalid_response', __( 'Could not decode AI proxy response.', 'easy-demo-importer' ) );
		}

		return $decoded['result'] ?? [];
	}

	/**
	 * Map a non-200 HTTP status code to a WP_Error.
	 *
	 * Error code reference:
	 *   ai_invalid_request  — 400 (bad payload), 413 (body too large), 415 (wrong Content-Type)
	 *   ai_unavailable      — 401 (expired timestamp), 5xx (Worker/Gemini unavailable)
	 *   ai_auth_failed      — 403 (bad HMAC)
	 *   ai_error            — 502 (Gemini API returned an error)
	 *
	 * @param int    $status HTTP status code.
	 * @param string $raw    Raw response body (may contain Worker error JSON).
	 * @return WP_Error
	 * @since 1.6.0
	 */
	private static function mapHttpError( int $status, string $raw ): WP_Error {
		$worker_msg = '';
		$decoded    = json_decode( $raw, true );

		if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
			$worker_msg = (string) $decoded['error'];
		}

		switch ( $status ) {
			case 400:
			case 413:
			case 415:
				return new WP_Error( 'ai_invalid_request', $worker_msg ?: __( 'Invalid AI request.', 'easy-demo-importer' ) );
			case 401:
				return new WP_Error( 'ai_unavailable', $worker_msg ?: __( 'AI request expired.', 'easy-demo-importer' ) );
			case 403:
				return new WP_Error( 'ai_auth_failed', $worker_msg ?: __( 'AI authentication failed.', 'easy-demo-importer' ) );
			case 502:
				return new WP_Error( 'ai_error', $worker_msg ?: __( 'Gemini API error.', 'easy-demo-importer' ) );
			default:
				return new WP_Error( 'ai_unavailable', $worker_msg ?: __( 'AI proxy unavailable.', 'easy-demo-importer' ) );
		}
	}
}
```

- [ ] **Step 2: Run PHPCS to verify coding standards**

```bash
./vendor/bin/phpcs inc/Common/Utils/AiClient.php --standard=WordPress
```

Expected: `Time: ... Memory: ... / No errors found!`

Fix any sniff violations before proceeding. Common fixes:
- Missing blank lines between methods → add them
- Tab indentation issues → use tabs, not spaces

- [ ] **Step 3: Run PHPStan**

```bash
./vendor/bin/phpstan analyse inc/Common/Utils/AiClient.php --level=5
```

Expected: `[OK] No errors`. If PHPStan flags `WP_Error` or WordPress functions as undefined, that is a stubs issue in the project's `phpstan.neon` — not a real bug. Check the existing baseline file at `phpstan-baseline.neon` (if it exists) or add to the baseline.

- [ ] **Step 4: Commit**

```bash
git add inc/Common/Utils/AiClient.php
git commit -m "feat: add AiClient PHP class for Gemini via signed Cloudflare Worker proxy"
```

---

### Task 5: Modify `easy-demo-importer.php`

**Files:**
- Modify: `easy-demo-importer.php`

Two changes to make:
1. **Line 14** — change `Requires PHP: 7.4` → `Requires PHP: 8.0` (required because `AiClient` uses `array|WP_Error` union return types, a PHP 8.0 feature)
2. **After line 37** (`define( 'SD_EDI_ROOT_FILE', __FILE__ );`) — add the two AI constants

- [ ] **Step 1: Bump PHP requirement in plugin header (line 14)**

Change:
```
 * Requires PHP: 7.4
```

To:
```
 * Requires PHP: 8.0
```

- [ ] **Step 2: Add AI proxy constants after `SD_EDI_ROOT_FILE`**

After the block:
```php
define( 'SD_EDI_ROOT_FILE', __FILE__ );
```

Add:

```php
/**
 * Cloudflare Worker endpoint URL for the AI proxy.
 * Override in wp-config.php: define( 'SD_EDI_AI_PROXY_URL', 'https://...' );
 *
 * @since 1.6.0
 */
if ( ! defined( 'SD_EDI_AI_PROXY_URL' ) ) {
	define( 'SD_EDI_AI_PROXY_URL', '' );
}

/**
 * Shared HMAC secret — must match SHARED_SECRET env var in the Cloudflare Worker.
 * Generate with: openssl rand -hex 32
 * Empty string = AI features disabled (AiClient returns WP_Error('ai_misconfigured')).
 * Override in wp-config.php: define( 'SD_EDI_AI_SHARED_SECRET', 'your-secret' );
 *
 * @since 1.6.0
 */
if ( ! defined( 'SD_EDI_AI_SHARED_SECRET' ) ) {
	define( 'SD_EDI_AI_SHARED_SECRET', '' );
}
```

- [ ] **Step 3: Run PHPCS on the modified file**

```bash
./vendor/bin/phpcs easy-demo-importer.php --standard=WordPress
```

Expected: no new errors.

- [ ] **Step 4: Commit**

```bash
git add easy-demo-importer.php
git commit -m "feat: add AI proxy constants; bump Requires PHP to 8.0 for union types"
```

---

### Task 6: End-to-End Smoke Test (Manual — requires a live WP install)

This task is performed after deploying the Worker. It confirms the full auth handshake works end-to-end before Sub-project B begins.

- [ ] **Step 1: Deploy the Worker to Cloudflare**

```bash
cd cloudflare
wrangler secret put GEMINI_API_KEY   # paste your Gemini API key when prompted
wrangler secret put SHARED_SECRET    # paste the output of: openssl rand -hex 32
wrangler deploy
```

Note the Worker URL printed by Wrangler (e.g. `https://edi-ai-proxy.yourname.workers.dev`).

- [ ] **Step 2: Update plugin constants**

In `easy-demo-importer.php`, change the two constants from `''` to real values:

```php
define( 'SD_EDI_AI_PROXY_URL', 'https://edi-ai-proxy.yourname.workers.dev' );
define( 'SD_EDI_AI_SHARED_SECRET', 'your-64-char-hex-secret' );
```

> ⚠️ Do not commit real secrets. These values should live in `wp-config.php` on the server, overriding the defaults in the plugin file.

- [ ] **Step 3: Smoke test `generate` via WP CLI**

```bash
wp eval "var_dump(\SigmaDevs\EasyDemoImporter\Common\Utils\AiClient::generate('Say hello'));"
```

Expected: `array(1) { ["text"]=> string(...) "Hello! ..." }` — not a `WP_Error`.

- [ ] **Step 4: Smoke test `embed` via WP CLI**

```bash
wp eval "var_dump(\SigmaDevs\EasyDemoImporter\Common\Utils\AiClient::embed('hello world'));"
```

Expected: `array(1) { ["embedding"]=> array(768) { [0]=> float(...) ... } }` — a 768-element float array.

- [ ] **Step 5: Smoke test feature flag**

```bash
wp eval "add_filter('sd/edi/ai_enabled','__return_false'); var_dump(\SigmaDevs\EasyDemoImporter\Common\Utils\AiClient::generate('test'));"
```

Expected: `object(WP_Error)#... { ["errors"]=> array(1) { ["ai_disabled"]=> ... } }`

- [ ] **Step 6: Revert constants to empty string after smoke test**

After confirming everything works, revert the plugin file constants back to `''` so the defaults are not committed with real values. Real credentials belong in `wp-config.php`.

```bash
git checkout easy-demo-importer.php  # or manually restore the '' defaults
```

- [ ] **Step 7: Commit if no changes needed**

All implementation tasks are already committed. If no additional code changes resulted from the smoke test:

```bash
# No commit needed — all implementation already committed.
# Optionally: git tag v1.6.0-infra-a to mark the sub-project as complete.
```

---

## Completion Criteria

All of the following must be true before marking Sub-project A complete:

- [ ] `cloudflare/worker.js` exists and deploys without errors
- [ ] `cloudflare/wrangler.toml` and `cloudflare/README.md` exist
- [ ] `cloudflare/.wrangler/` is in `.gitignore`
- [ ] `inc/Common/Utils/AiClient.php` exists, passes PHPCS + PHPStan
- [ ] `easy-demo-importer.php` has both AI constants (defaulting to `''`) and `Requires PHP: 8.0`
- [ ] `AiClient::generate('Say hello')` returns `['text' => '...']` on a live install (smoke test)
- [ ] `AiClient::embed('hello')` returns `['embedding' => [...]]` on a live install
- [ ] Feature flag (`sd/edi/ai_enabled` returning false) returns `WP_Error('ai_disabled')`
