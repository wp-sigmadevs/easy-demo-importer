/**
 * Decodes the HTML entities that WordPress's esc_html*() family emits
 * (&amp; &lt; &gt; &quot;) plus any numeric entity — decimal (&#8220;) or hex
 * (&#x201C;) — such as the curly quotes the importer wraps around item titles.
 *
 * Progress and activity-log messages are escaped server-side for an HTML
 * context, then sent as JSON and rendered by React as text nodes — which
 * escape again — so the raw entities would otherwise show through, e.g.
 * "let&#039;s get started" instead of "let's get started". Decoding here and
 * still rendering the result as a text node is safe: no markup is ever
 * interpreted, so there is no XSS surface. `&amp;` is decoded last so an
 * already-encoded entity isn't double-decoded.
 *
 * @param {string} value - Possibly entity-encoded string.
 * @return {string} Decoded string (returned unchanged if not a string or has no entities).
 */
export const decodeEntities = (value) => {
	if (typeof value !== 'string' || value.indexOf('&') === -1) {
		return value;
	}

	return value
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&quot;/g, '"')
		.replace(/&#x([0-9a-f]+);/gi, (_match, hex) =>
			String.fromCodePoint(parseInt(hex, 16))
		)
		.replace(/&#(\d+);/g, (_match, dec) =>
			String.fromCodePoint(parseInt(dec, 10))
		)
		.replace(/&amp;/g, '&');
};

export default decodeEntities;
