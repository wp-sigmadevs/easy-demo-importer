# Easy Demo Importer — UI/UX & Feature-Set Assessment

**Date:** 2026-07-16
**Basis:** Hands-on assessment after driving the import wizard end-to-end ~15 times during E2E testing (bundled + remote media, reset/non-reset, rollback, mutex-wait, auto-resume). Observations are grounded in observed behaviour, not the marketing surface.

---

## UI/UX

### Genuinely strong

- **The 5-step wizard** (Start → Readiness → Configure → Imports → End) is well-sequenced and progressive — it doesn't dump every decision at once.
- **The Readiness step is the standout.** Surfacing PHP version / memory limit / execution time / ZipArchive / SimpleXML / image library / uploads-writable / disk space / reverse-proxy detection *before* the import is proactive and rare. Most importers let you fail first and guess why afterwards.
- **Restore point + one-click rollback** is a trust feature most competitors don't have. Verified working in testing (restored DB + 299 media files, no errors). This is the product's best differentiator.
- **Configure microcopy is good.** e.g. "Turn off for a faster import, or if the import fails repeatedly" explains the *why*, not just the *what*.
- **Progress cards with real progress bars** are clear and readable.

### Weak spots (real friction observed)

1. **The import is client-driven — the browser tab must stay open and focused.** This is the single biggest UX risk. Backgrounding/suspending the tab kills the in-flight AJAX (`ERR_NETWORK_IO_SUSPENDED`) and interrupts the import. A user who switches tabs during a multi-minute image import can lose it. The interrupted → resume flow softens the blow, but the architecture invites the failure.
2. **Resume / stale-modal state is confusing.** Reloading the page can auto-open a success or resume prompt that doesn't match what the user expects. (The worst race — the resume prompt marking a *live* session interrupted — was fixed on the `import-improvements` branch, but the overall pattern is still fragile.)
3. **Destructive-by-default.** The Configure step ships with **Reset Existing Database ON**. There is a confirm dialog, but a default that wipes the site is aggressive. Prefer defaulting it OFF and making a full reset a deliberate opt-in.
4. **Non-reset imports silently clear menus / widgets / theme-mods** (via the `cleanups()` path) and only report "Cleanup done!". Users won't know what was removed.
5. **Progress can desync from reality.** "Content 100%" was shown while media was still importing; there is no ETA / time-remaining anywhere.
6. **Playful copy** ("smooth sailing!", "sit tight!", "have a blast!") reads as charming to some and unprofessional to others for an admin tool. Subjective — worth toning down ~20%.

---

## Feature set

**Above average and mature.** Chunked resumable import + rollback + manual import (separate files & bundle zip) + activity log + preflight checks + dedicated image regeneration + bundled/remote media is a genuinely complete package — ahead of One Click Demo Import and most theme bundlers.

**Standout features:** rollback / restore-point, chunked-resumable import (gateway-timeout resilience), preflight/readiness checks, React activity log.

### Gaps / opportunities (rough impact order)

1. **Server-side / background import** (Action Scheduler or WP-Cron driven) to eliminate the "keep the tab open" fragility. Biggest robustness win available.
2. **Selective import** — choose content types ("products only", "skip posts", etc.). Frequently requested; the current flow is all-or-nothing.
3. **ETA / time-remaining** during the import.
4. **Frontend bundle weight** — all React apps (wizard, network, activity, regen) load from one entry on every EDI admin page; code-split per page. (Note: the 6.2 MB figure seen during testing was the *dev* build; measure the production build before acting.)
5. **Multisite** (already scoped on a separate branch).

---

## If I could change three things

1. **Move the import loop server-side** (or at least make it survive tab suspension). Architectural, but it's the root of most reliability issues.
2. **Default Reset DB → OFF** and make a full reset an explicit choice.
3. **Add selective content-type import** — the highest-value feature gap.

---

## Net

A solid, safety-conscious tool with a standout rollback story and unusually good pre-flight/readiness UX. The **client-driven import engine** is the main thing holding back its reliability ceiling — addressing that (or hardening it against tab suspension) would move the product from "good" to "hard to beat".
