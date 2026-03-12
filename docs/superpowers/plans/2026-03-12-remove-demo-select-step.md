# Remove Demo Select Step Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the "Select Demo" wizard step and pre-populate `selectedDemo` from the demo clicked on the main listing page.

**Architecture:** `WizardContext` hydrates `selectedDemo` from `sessionStorage` on mount (the listing page already writes there). The `demos` route and step component are deleted. Three step files with hardcoded `/wizard/demos` navigations are updated to their correct targets.

**Tech Stack:** React 18, React Router, WordPress plugin (no JS test suite — verification via build + manual smoke test)

**Spec:** `docs/superpowers/specs/2026-03-12-remove-demo-select-step-design.md`

---

## Chunk 1: All Changes

### Task 1: Hydrate `selectedDemo` from sessionStorage in WizardContext

**Files:**
- Modify: `src/js/backend/wizard/WizardContext.jsx:21`

- [ ] **Step 1: Replace the null initialiser with a lazy sessionStorage read**

In `WizardContext.jsx`, line 21, change:

```js
const [ selectedDemo,  setSelectedDemo  ] = useState( null );
```

To:

```js
const [ selectedDemo, setSelectedDemo ] = useState( () => {
    try {
        const stored = sessionStorage.getItem( 'sd_edi_selected_demo' );
        return stored ? JSON.parse( stored ) : null;
    } catch {
        return null;
    }
} );
```

The `try/catch` guards against malformed JSON in sessionStorage (e.g. from a prior failed write).

---

### Task 2: Remove `demos` from the STEPS array

**Files:**
- Modify: `src/js/backend/wizard/WizardLayout.jsx:13`

- [ ] **Step 2: Delete the demos entry from STEPS**

Remove line 13:

```js
{ key: 'demos',        title: 'Select Demo'  },
```

The STEPS array becomes 9 entries: `welcome → requirements → plugins → options → select-items → confirm → importing → regen → complete`.

---

### Task 3: Remove `DemoSelectStep` import and route from App.jsx

**Files:**
- Modify: `src/js/backend/App.jsx:16` (import) and `App.jsx:133` (route child)

- [ ] **Step 3: Remove the import statement (line 16)**

Delete:

```js
import DemoSelectStep from './wizard/steps/DemoSelectStep';
```

- [ ] **Step 4: Remove the route entry (line 133)**

Delete:

```js
{ path: 'demos',        element: <DemoSelectStep /> },
```

---

### Task 4: Fix hardcoded `/wizard/demos` navigations in three step files

**Files:**
- Modify: `src/js/backend/wizard/steps/PluginInstallerStep.jsx:53`
- Modify: `src/js/backend/wizard/steps/ImportOptionsStep.jsx:98`
- Modify: `src/js/backend/wizard/steps/SelectItemsStep.jsx:37`

- [ ] **Step 5: PluginInstallerStep — Next button**

Line 53, change:

```js
onClick={ () => navigate( '/wizard/demos' ) }
```

To:

```js
onClick={ () => navigate( '/wizard/options' ) }
```

- [ ] **Step 6: ImportOptionsStep — Back button**

Line 98, change:

```js
<Button onClick={ () => navigate( '/wizard/demos' ) }>Back</Button>,
```

To (note: trailing comma is part of the portal call — preserve it):

```js
<Button onClick={ () => navigate( '/wizard/plugins' ) }>Back</Button>,
```

- [ ] **Step 7: SelectItemsStep — null-guard redirect**

Line 37, change:

```js
navigate( '/wizard/demos' ); return;
```

To:

```js
navigate( '/wizard/welcome' ); return;
```

---

### Task 5: Delete DemoSelectStep.jsx

**Files:**
- Delete: `src/js/backend/wizard/steps/DemoSelectStep.jsx`

- [ ] **Step 8: Delete the file**

```bash
rm src/js/backend/wizard/steps/DemoSelectStep.jsx
```

---

### Task 6: Build and verify

- [ ] **Step 9: Run the build**

```bash
npm run build
```

Expected: zero errors. If a build error references `DemoSelectStep`, confirm the import was removed from `App.jsx`.

- [ ] **Step 10: Manual smoke test**

1. Load the WordPress admin demo importer page — all demos appear.
2. Click "Import" on any demo — wizard opens at Welcome step.
3. Advance through Requirements → Plugins. Confirm the wizard goes directly to Options (no Select Demo step appears).
4. Confirm the step counter shows 9 steps total.
5. On the Options step, click Back — confirm it returns to Plugins (not a dead route).
6. Continue through to Confirm step — verify the demo name shown matches the one clicked.
7. Refresh the page mid-wizard (e.g. on Options step) — confirm the demo is still set and the wizard does not lose state.
8. Navigate directly to `#/wizard/select-items` in the browser address bar without first clicking Import (clear sessionStorage first via DevTools → Application → Session Storage → delete `sd_edi_selected_demo`). Confirm the wizard redirects to `/wizard/welcome`.

- [ ] **Step 11: Commit**

```bash
git add src/js/backend/wizard/WizardContext.jsx \
        src/js/backend/wizard/WizardLayout.jsx \
        src/js/backend/App.jsx \
        src/js/backend/wizard/steps/PluginInstallerStep.jsx \
        src/js/backend/wizard/steps/ImportOptionsStep.jsx \
        src/js/backend/wizard/steps/SelectItemsStep.jsx
git rm src/js/backend/wizard/steps/DemoSelectStep.jsx
git commit -m "feat: remove Select Demo wizard step; hydrate selectedDemo from sessionStorage"
```
