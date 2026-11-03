// One cold-start recovery per process. Protected requests wait; public requests,
// the recovery probe and WeChat code exchange must not wait on themselves.
let recovery = null;

export function beginMemberSessionRecovery(restore) {
	if (recovery) return recovery;
	const pending = Promise.resolve().then(restore).finally(() => {
		if (recovery === pending) recovery = null;
	});
	recovery = pending;
	return pending;
}

export function getMemberSessionRecovery() {
	return recovery;
}

// Network/timeout/server errors are not evidence that a member was logged out.
// The backend remains authoritative for expiration, revocation and bans.
export function isMemberSessionExpired(error) {
	return [410000, 410001, 410002].indexOf(Number(error && error.status)) !== -1;
}
