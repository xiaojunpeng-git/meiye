/**
 * Imported mall media lives on the customer's server, not in the mini-program
 * package. Resolve only that namespace; CDN, data and bundled asset URLs retain
 * their original meaning. An invalid customer origin must never select a host.
 */
export function resolveServerAssetUrl(value, origin) {
	if (typeof value !== 'string' || !value.startsWith('/static/mall/')) return value;
	const base = typeof origin === 'string' ? origin.replace(/\/$/, '') : '';
	return /^https?:\/\/[a-z0-9.-]+(?::\d+)?$/i.test(base) ? base + value : value;
}
