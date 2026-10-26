/**
 * Weixin cannot use this application's package-local @font-face URL. Load the
 * unchanged font globally from this customer's HTTPS origin. Do not block app
 * launch or choose another customer's CDN when loading fails.
 */
export function loadMemberIconFont(loadFontFace, origin) {
	const base = typeof origin === 'string' ? origin.replace(/\/$/, '') : '';
	if (typeof loadFontFace !== 'function' || !/^https:\/\/[a-z0-9.-]+(?::\d+)?$/i.test(base)) {
		return Promise.resolve(false);
	}
	return new Promise(resolve => {
		try {
			loadFontFace({
				global: true,
				family: 'iconfont',
				source: 'url("' + base + '/static/iconfont/iconfont.woff2")',
				success: () => resolve(true),
				fail: () => resolve(false)
			});
		} catch (error) {
			resolve(false);
		}
	});
}
