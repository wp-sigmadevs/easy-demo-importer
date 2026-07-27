/**
 * Tests for the ErrorMessage component.
 *
 * The component is fed the REST `message` field, which is EITHER a plain string
 * (simple errors, e.g. pluginList's "configuration not found") OR an object
 * `{ text, btnUrl, btnText }` (errors carrying a call-to-action, e.g. buildList).
 * A regression once rendered the string case blank because only `message.text`
 * was read. These lock in that both shapes render, and that the object case is
 * unchanged.
 */

import React from 'react';
import { render, cleanup } from '@testing-library/react';
import { describe, it, expect, afterEach } from 'vitest';
import ErrorMessage from '../../src/js/backend/components/ErrorMessage';

afterEach(cleanup);

describe('ErrorMessage', () => {
	it('renders a plain string message as text (not blank)', () => {
		const { container } = render(
			<ErrorMessage message="Demo data configuration not found." />
		);

		expect(container.querySelector('.error-message')).not.toBeNull();
		expect(container.textContent).toContain(
			'Demo data configuration not found.'
		);
		// No CTA button for a bare string.
		expect(container.querySelector('button')).toBeNull();
	});

	it('renders an object message with a call-to-action button', () => {
		const { container, getByRole } = render(
			<ErrorMessage
				message={{
					text: 'Your server is not ready.',
					btnUrl: 'http://example.test/status',
					btnText: 'View status',
				}}
			/>
		);

		expect(container.textContent).toContain('Your server is not ready.');
		expect(getByRole('button').textContent).toContain('View status');
	});

	it('renders an object message without a button when the CTA fields are absent', () => {
		const { container } = render(
			<ErrorMessage message={{ text: 'Just the message.' }} />
		);

		expect(container.textContent).toContain('Just the message.');
		expect(container.querySelector('button')).toBeNull();
	});

	it('renders nothing for a null message', () => {
		const { container } = render(<ErrorMessage message={null} />);

		expect(container.querySelector('.error-message')).toBeNull();
	});
});
