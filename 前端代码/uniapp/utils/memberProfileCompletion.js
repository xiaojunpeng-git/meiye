/** Existing nonempty profile values count as completed; no per-device flag can replace saved member data. */
export function needsMemberProfileCompletion(enabled, user = {}) {
	return Boolean(Number(enabled)) && (!String(user.avatar || '').trim() || !String(user.nickname || '').trim());
}
