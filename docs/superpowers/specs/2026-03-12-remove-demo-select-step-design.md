# Design: Remove Demo Select Step from Wizard

**Date:** 2026-03-12
**Status:** Draft

## Problem

The main importer page already displays all demos with individual Import buttons. Clicking an Import button already knows which demo was selected. The wizard's "Select Demo" step therefore duplicates work the user has already done, adding unnecessary friction.

## Goal

Remove the Select Demo step from the wizard. Pre-populate `selectedDemo` in WizardContext from the demo the user clicked on the listing page. The wizard flow becomes:

```
Welcome → Requirements → Plugins → Options → Select Items → Confirm → Importing → Images → Done
```

## Approach: sessionStorage Hydration

`AppDemoImporter.showModal()` already writes the clicked demo object to `sessionStorage('sd_edi_selected_demo')` before navigating to `/wizard/welcome`. No change needed there.

`WizardContext` changes its initial state to read from sessionStorage synchronously on mount, making the demo available from step 1:

```js
const [ selectedDemo, setSelectedDemo ] = useState(
  () => {
    const stored = sessionStorage.getItem( 'sd_edi_selected_demo' );
    return stored ? JSON.parse( stored ) : null;
  }
);
```

All downstream steps (`SelectItemsStep`, `ConfirmationStep`, `ImportingStep`) use `selectedDemo` from context and require no changes — except the null-guard redirect in `SelectItemsStep` (see Files Changed).

## Files Changed

| File | Change |
|------|--------|
| `src/js/backend/wizard/WizardContext.jsx` | Hydrate `selectedDemo` initial state from sessionStorage |
| `src/js/backend/wizard/WizardLayout.jsx` | Remove `{ key: 'demos', title: 'Select Demo' }` from STEPS array |
| `src/js/backend/App.jsx` | Remove `/wizard/demos` route **and** remove `DemoSelectStep` import (build will fail if left) |
| `src/js/backend/wizard/steps/PluginInstallerStep.jsx` | Change Next navigation: `/wizard/demos` → `/wizard/options` |
| `src/js/backend/wizard/steps/ImportOptionsStep.jsx` | Change Back navigation: `/wizard/demos` → `/wizard/plugins` |
| `src/js/backend/wizard/steps/SelectItemsStep.jsx` | Change null-guard redirect: `/wizard/demos` → `/wizard/welcome` |
| `src/js/backend/wizard/steps/DemoSelectStep.jsx` | Delete — no longer routed to |

## Files Unchanged

- `AppDemoImporter.jsx` — `showModal()` already writes to sessionStorage and navigates to `/wizard/welcome`
- `ConfirmationStep.jsx`, `ImportingStep.jsx`, `ImageRegenStep.jsx`, `CompleteStep.jsx` — read `selectedDemo` from context, no navigation to demos step

## Edge Cases

- **Direct URL access to `/wizard/demos`**: Route removed; falls through to router's catch-all. Acceptable — no external entry points reference this URL.
- **No demo in sessionStorage**: If `selectedDemo` is null (e.g. direct URL navigation to wizard), `SelectItemsStep`'s null-guard redirects to `/wizard/welcome`. Other steps that require `selectedDemo` guard on it being set as before.
- **Page refresh mid-wizard**: sessionStorage persists across soft reloads, so `selectedDemo` survives a refresh.
- **Wizard reset**: `resetWizard()` in `WizardContext` resets `selectedDemo` to `null`. sessionStorage still holds the previous demo's data. Low risk — the listing page always overwrites sessionStorage on the next Import click. No change needed.
