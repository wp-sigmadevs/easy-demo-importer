# Easy Demo Importer — AI Proxy Worker

This Cloudflare Worker proxies requests from the WordPress plugin to the Google Gemini API.
It holds the Gemini API key server-side so the plugin never exposes it.

The Worker name (from `wrangler.toml`) is **`edi-ai-proxy`**.

---

## 1. Prerequisites

- **Node.js 18+** (required by Wrangler)
- **Wrangler v3+** — `npm install -g wrangler`
- **Cloudflare account** with Workers enabled (free tier is sufficient)
- **Google Gemini API key** — obtain from [Google AI Studio](https://aistudio.google.com/app/apikey)

---

## 2. One-Time Setup

### 2a. Authenticate with Cloudflare

```bash
wrangler login
```

This opens a browser window. Approve the OAuth prompt.

### 2b. Generate a shared secret

```bash
openssl rand -hex 32
```

Copy the 64-character hex string — this is your `SHARED_SECRET`. The plugin and the Worker both
use it to sign and verify every request via HMAC-SHA-256.

### 2c. Set secrets in Cloudflare

From the `cloudflare/` directory, run each command and paste the value when prompted:

```bash
cd cloudflare
wrangler secret put GEMINI_API_KEY
wrangler secret put SHARED_SECRET
```

> ⚠️ **Warning:** If `SHARED_SECRET` is not set via `wrangler secret put`, the Worker has no key
> to import — `crypto.subtle.importKey` will throw on every request and the Worker will return 500
> errors for all calls. Always verify both secrets are set before deploying (`wrangler secret list`).

To confirm the secrets exist:

```bash
wrangler secret list
```

You should see both `GEMINI_API_KEY` and `SHARED_SECRET` listed.

---

## 3. Deploy

```bash
cd cloudflare && wrangler deploy
```

Wrangler prints the Worker URL when the deploy succeeds, for example:

```
Deployed edi-ai-proxy to https://edi-ai-proxy.<your-subdomain>.workers.dev
```

Copy that URL — you need it in the next step.

---

## 4. Update Plugin Constants

Open `easy-demo-importer.php` in the plugin root and set the two AI constants:

```php
define( 'SD_EDI_AI_PROXY_URL',    'https://edi-ai-proxy.<your-subdomain>.workers.dev' );
define( 'SD_EDI_AI_SHARED_SECRET', '<the-64-char-hex-secret-from-step-2b>' );
```

- `SD_EDI_AI_PROXY_URL` — the Worker URL printed by `wrangler deploy`
- `SD_EDI_AI_SHARED_SECRET` — the same secret you set with `wrangler secret put SHARED_SECRET`

Both values must match exactly. The HMAC signature will fail if they differ.

> ⚠️ **Never commit real credentials to git.** The constants in `easy-demo-importer.php` default
> to empty string — that is intentional. On your server, override them in `wp-config.php` instead:
>
> ```php
> define( 'SD_EDI_AI_PROXY_URL', 'https://edi-ai-proxy.yourname.workers.dev' );
> define( 'SD_EDI_AI_SHARED_SECRET', 'your-64-char-hex-secret' );
> ```
>
> Place these lines in `wp-config.php` before the `/* That's all, stop editing! */` line. The
> plugin's constants use `if ( ! defined(...) )` guards, so `wp-config.php` definitions take
> precedence.

---

## 5. Smoke Test

With the plugin active, run this from the WordPress root via WP-CLI:

```bash
wp eval 'var_export( \SigmaDevs\EasyDemoImporter\Common\Utils\AiClient::generate("Say hello") );'
```

**Expected output:**

```php
array (
  'text' => '...some response from Gemini...',
)
```

**Troubleshooting:**

| Symptom | Likely cause |
|---------|--------------|
| `403 Forbidden` | `SHARED_SECRET` in plugin does not match the Worker secret |
| `401 Request expired` | Server clock skew > 30 s — sync time on the WordPress host |
| `502` from Worker | `GEMINI_API_KEY` is invalid or quota exhausted |
| `cURL error 6` / no response | `SD_EDI_AI_PROXY_URL` is wrong or Worker was not deployed |
| `AiClient class not found` | Plugin not active or autoloader not loaded |

Check the Worker's live log for server-side errors:

```bash
wrangler tail
```

---

## 6. Rollback

To revert to the previous Worker deployment:

```bash
wrangler rollback
```

> **Note:** `wrangler rollback` only reverts the Worker code. It does **not** change the plugin
> constants `SD_EDI_AI_PROXY_URL` or `SD_EDI_AI_SHARED_SECRET`. If the previous Worker version
> used different secrets, update the plugin constants accordingly.

---

## Rate Limiting

To protect against abuse, enable rate limiting in the Cloudflare dashboard:

1. Log in to [dash.cloudflare.com](https://dash.cloudflare.com)
2. Select your account → **Workers & Pages** → `edi-ai-proxy`
3. Go to **Security** → **Rate Limiting**
4. Create a rule: **60 requests / minute / IP**

This prevents a single IP from exhausting your Gemini quota or incurring unexpected costs.
