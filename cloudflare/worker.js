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
