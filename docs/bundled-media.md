# Bundled media (theme authors)

Ship a demo's images **inside the demo package** so the importer installs them
from local disk instead of downloading each one over HTTP. This makes image
import **Cloudflare-proof** (no remote fetch, no bot challenge) and dramatically
faster. Missing files gracefully fall back to a remote fetch, so a partial
bundle still works.

## How to enable

Add an `uploads/` folder to your demo zip, mirroring the standard WordPress
uploads layout:

```
demo.zip
├── content.xml
├── customizer.dat
└── uploads/                 ← add this
    ├── 2024/01/hero.jpg
    └── 2024/02/gallery.png
```

That's it. When the extracted package contains an `uploads/` folder, bundled
mode turns on **automatically** — no config change required. Bundle **originals
only**; the importer regenerates the resized thumbnail sizes in its own phase.

## How a URL is matched to a bundled file

For each attachment URL in the WXR, the importer looks for the uploads-relative
path under your bundled folder:

1. Everything after `…/uploads/` (e.g. `2024/01/hero.jpg`) — host-agnostic.
2. A `YYYY/MM/…` tail, for CDN URLs that dropped the `uploads/` segment.

## Effect on existing sites

**None.** Bundled mode only activates for a demo whose package contains an
`uploads/` folder. Themes already live and configured are unaffected — demos
without a bundle fetch remotely exactly as before.

## Filters

| Filter | Purpose |
|---|---|
| `sd/edi/importer/bundled_media_enabled` (`bool`, `$slug`, `$config`) | Force bundled mode on/off per demo. |
| `sd/edi/importer/bundled_media_path` (`?string $path`, `$url`, `$dir`) | Override resolution for a specific URL (e.g. a basename fallback). |
| `sd/edi/importer/attachment_timeout` (`int`, default `40`) | Remote-fetch timeout for any file **not** bundled. |
