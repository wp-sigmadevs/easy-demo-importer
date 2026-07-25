/**
 * Regression tests for the "Server Requirements Not Met" warning.
 *
 * The effect that raises the warning runs on [serverData, serverInfo]. Before
 * the fix it called setIsModalVisible(true) unconditionally whenever the server
 * report contained an error, with no memory of the user's dismissal — so any
 * later re-render that changed either dependency re-opened the warning, landing
 * it on top of an import wizard the user had already advanced into.
 *
 * These tests drive the real component against a real (test-local) Zustand
 * store, so the store update that used to re-raise the modal is reproduced
 * faithfully rather than simulated.
 */

import React from 'react';
import { create } from 'zustand';
import { render, cleanup, act, waitFor } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

/**
 * A server report with a failing field — this is what makes the warning fire.
 */
const failingServerData = () => ({
	success: true,
	data: {
		server: {
			fields: {
				memory_limit: {
					label: 'PHP Memory Limit',
					value: '128M',
					error: 'Recommended PHP Memory Limit is 256M',
				},
			},
		},
	},
});

/**
 * The demo list the grid renders from. Kept to a single demo: the cards are
 * stubbed out, so its only job is to make importList.success true (the warning
 * is gated on it).
 */
const importListPayload = () => ({
	success: true,
	message: 'ok',
	data: {
		demoData: {
			'home-1': { name: 'Home 1', previewImage: '', previewUrl: '' },
		},
	},
});

/**
 * Test-local store standing in for the shared Zustand store. Only the slices
 * AppDemoImporter selects are implemented.
 */
const buildStore = () =>
	create((set) => ({
		importList: importListPayload(),
		serverData: failingServerData(),
		loading: false,
		modalVisible: false,
		searchQuery: '',
		filteredDemoData: null,
		isSearchQueryEmpty: true,
		resumeRequest: null,
		fetchImportList: vi.fn().mockResolvedValue(undefined),
		fetchServerData: vi.fn().mockResolvedValue(undefined),
		resetStore: vi.fn(),
		setModalVisible: vi.fn(),
		handleModalCancel: vi.fn(),
		setSearchQuery: vi.fn(),
		setFilteredDemoData: vi.fn(),
		setIsSearchQueryEmpty: vi.fn(),

		// Re-fetching the server status replaces serverData with an equal but
		// newly-identified object — exactly the change that used to re-raise the
		// dismissed warning.
		refreshServerData: () => set({ serverData: failingServerData() }),
	}));

let store;

// Delegates to the real (test-local) Zustand store so subscriptions and
// re-renders behave exactly as they do in the app — the re-render is the whole
// point of the regression being tested.
vi.mock('../../src/js/backend/utils/sharedDataStore', () => {
	const hook = (...args) => store(...args);

	hook.getState = (...args) => store.getState(...args);
	hook.setState = (...args) => store.setState(...args);
	hook.subscribe = (...args) => store.subscribe(...args);

	return { default: hook };
});

// Children that fetch or render heavy trees are irrelevant here and would only
// add network + antd noise to the assertions.
vi.mock('../../src/js/backend/Layouts/Header', () => ({ default: () => null }));
vi.mock('../../src/js/backend/components/Support', () => ({
	default: () => null,
}));
vi.mock('../../src/js/backend/components/DemoCard', () => ({
	default: () => null,
}));
vi.mock('../../src/js/backend/components/GridSkeleton', () => ({
	default: () => null,
}));
vi.mock('../../src/js/backend/components/ErrorMessage', () => ({
	default: () => null,
}));
vi.mock('../../src/js/backend/components/RestorePointBanner', () => ({
	default: () => null,
}));
vi.mock('../../src/js/backend/components/Modal/ManualImportModal', () => ({
	default: () => null,
}));
vi.mock('../../src/js/backend/components/Modal/ModalComponent', () => ({
	default: () => null,
}));

// Stubbed so the assertions read the `isVisible` prop the app actually passes.
// The real antd Modal stays mounted when closed and only hides once its leave
// animation ends — which never happens in jsdom, since no transitionend fires.
// Asserting on our own prop keeps the test about our logic, not antd's motion.
vi.mock('../../src/js/backend/components/Modal/ModaRequirements', () => ({
	default: ({ isVisible, onClose }) => (
		<div
			data-testid="requirements-modal"
			data-visible={isVisible ? 'yes' : 'no'}
		>
			<button type="button" onClick={onClose}>
				Continue Anyway
			</button>
		</div>
	),
}));

// eslint-disable-next-line import/first
import AppDemoImporter from '../../src/js/backend/AppDemoImporter';

/**
 * The warning is only mounted at all when the report has errors, so an absent
 * node and an `isVisible={false}` node both mean "not shown to the user".
 */
const warningModal = () =>
	document.querySelector('[data-testid="requirements-modal"]');

const warningIsVisible = () => warningModal()?.dataset.visible === 'yes';

const dismissWarning = async () => {
	const button = warningModal().querySelector('button');

	await act(async () => {
		button.click();
	});
};

describe('AppDemoImporter — server requirements warning', () => {
	beforeEach(() => {
		store = buildStore();
	});

	// The Vitest config does not enable `globals`, so Testing Library cannot
	// register its own afterEach cleanup. Without this, antd's body portals
	// survive into the next test and every later assertion reads a stale modal.
	afterEach(() => {
		cleanup();
	});

	it('raises the warning when the server report contains an error', async () => {
		await act(async () => {
			render(<AppDemoImporter />);
		});

		expect(warningIsVisible()).toBe(true);
	});

	it('stays dismissed after the user acknowledges it', async () => {
		await act(async () => {
			render(<AppDemoImporter />);
		});

		await dismissWarning();

		await waitFor(() => expect(warningIsVisible()).toBe(false));
	});

	it('does not re-raise when serverData changes after acknowledgement', async () => {
		await act(async () => {
			render(<AppDemoImporter />);
		});

		await dismissWarning();
		await waitFor(() => expect(warningIsVisible()).toBe(false));

		// The regression: a fresh server report used to re-open the dismissed
		// warning, stacking it over whatever the user had moved on to.
		await act(async () => {
			store.getState().refreshServerData();
		});

		await waitFor(() => expect(warningIsVisible()).toBe(false));
	});

	it('leaves the warning closed when the server report has no errors', async () => {
		store.setState({
			serverData: {
				success: true,
				data: {
					server: {
						fields: {
							memory_limit: {
								label: 'PHP Memory Limit',
								value: '512M',
								error: '',
							},
						},
					},
				},
			},
		});

		await act(async () => {
			render(<AppDemoImporter />);
		});

		expect(warningIsVisible()).toBe(false);
	});
});
