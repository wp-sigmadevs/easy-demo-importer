/**
 * Decodes the small, fixed set of HTML entities that WordPress's esc_html*()
 * family emits (&amp; &lt; &gt; &quot; &#039;).
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
		.replace(/&#0*39;/g, "'")
		.replace(/&#x27;/gi, "'")
		.replace(/&amp;/g, '&');
};

export default decodeEntities;
