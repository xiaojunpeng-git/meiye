/** 全屏选择页回写结果 */
export const MERCHANT_STORE_PICKER_RESULT_KEY = 'merchant_store_picker_result';
/** 打开选择页时的预填参数 */
export const MERCHANT_STORE_PICKER_PRESET_KEY = 'merchant_store_picker_preset';
/** 最近使用门店 */
export const MERCHANT_STORE_PICKER_RECENT_KEY = 'merchant_store_picker_recent';
export const MERCHANT_STORE_PICKER_RECENT_MAX = 8;

export function isStoreNode(node) {
	if (!node) return false;
	return node.node_type === 'store' || Number(node.object_type) === 1;
}

export function isOrgNode(node) {
	if (!node) return false;
	return node.node_type === 'org' || Number(node.object_type) === 2;
}

export function nodeKey(node) {
	if (!node) return '';
	const type = isStoreNode(node) ? 'store' : 'org';
	return `${type}-${node.id}`;
}

export function collectStoreIdsFromNode(node) {
	if (!node) return [];
	if (isStoreNode(node)) {
		const id = Number(node.id);
		return id > 0 ? [id] : [];
	}
	const ids = [];
	(node.children || []).forEach((child) => {
		ids.push(...collectStoreIdsFromNode(child));
	});
	return Array.from(new Set(ids.filter((id) => Number(id) > 0)));
}

export function buildStoreNameMap(tree) {
	const map = {};
	const walk = (nodes) => {
		(nodes || []).forEach((node) => {
			if (isStoreNode(node)) {
				map[Number(node.id)] = node.name || `门店${node.id}`;
			}
			if (node.children && node.children.length) walk(node.children);
		});
	};
	walk(tree);
	return map;
}

export function filterTreeByKeyword(tree, keyword) {
	const kw = String(keyword || '').trim().toLowerCase();
	if (!kw) return tree || [];
	const filterNode = (node) => {
		if (!node) return null;
		const nameHit = String(node.name || '').toLowerCase().includes(kw);
		const descHit = String(node.desc || '').toLowerCase().includes(kw);
		const phoneHit = String(node.phone || '').toLowerCase().includes(kw);
		const children = (node.children || []).map(filterNode).filter(Boolean);
		if (isStoreNode(node)) {
			return nameHit || descHit || phoneHit ? { ...node, children: [] } : null;
		}
		if (nameHit || descHit || children.length) {
			return { ...node, children };
		}
		return null;
	};
	return (tree || []).map(filterNode).filter(Boolean);
}

/**
 * 从已选门店集合反推 org_ids / store_ids / excluded_store_ids
 * - 组织下全选 → 记入 org_ids
 * - 组织下半选 → 已选门店记入 store_ids（不写 org）
 * - 若未来扩展「选组织后排除」：org 全选后取消部分店 → org_ids + excluded
 */
export function deriveSelectionPayload(tree, selectedStoreIdMap) {
	const selectedSet = {};
	Object.keys(selectedStoreIdMap || {}).forEach((id) => {
		if (selectedStoreIdMap[id]) selectedSet[Number(id)] = true;
	});
	const orgIds = [];
	const storeIds = [];
	const excludedStoreIds = [];

	const walk = (nodes, parentOrgSelected) => {
		(nodes || []).forEach((node) => {
			if (isStoreNode(node)) {
				const sid = Number(node.id);
				if (!sid) return;
				if (selectedSet[sid]) {
					if (!parentOrgSelected) storeIds.push(sid);
				} else if (parentOrgSelected) {
					excludedStoreIds.push(sid);
				}
				return;
			}
			const ids = collectStoreIdsFromNode(node);
			if (!ids.length) {
				walk(node.children || [], false);
				return;
			}
			const selectedCount = ids.filter((id) => selectedSet[id]).length;
			const allSelected = selectedCount === ids.length;
			const noneSelected = selectedCount === 0;
			if (allSelected && Number(node.id) > 0) {
				orgIds.push(Number(node.id));
				// 子树已由 org 覆盖，不再向下拆 store_ids
				return;
			}
			if (noneSelected) {
				return;
			}
			// 半选：不下沉为 org，继续拆叶子 / 子组织
			walk(node.children || [], false);
		});
	};
	walk(tree, false);

	const resolved = Object.keys(selectedSet)
		.map((id) => Number(id))
		.filter((id) => id > 0)
		.sort((a, b) => a - b);

	return {
		org_ids: Array.from(new Set(orgIds)),
		store_ids: Array.from(new Set(storeIds)),
		excluded_store_ids: Array.from(new Set(excludedStoreIds)),
		resolved_store_ids: resolved,
	};
}

export function loadRecentStores() {
	try {
		const list = uni.getStorageSync(MERCHANT_STORE_PICKER_RECENT_KEY);
		return Array.isArray(list) ? list : [];
	} catch (e) {
		return [];
	}
}

export function pushRecentStores(stores) {
	const incoming = (stores || [])
		.map((item) => ({
			id: Number(item.id),
			name: item.name || `门店${item.id}`,
		}))
		.filter((item) => item.id > 0);
	if (!incoming.length) return;
	const prev = loadRecentStores().filter(
		(item) => !incoming.some((n) => Number(n.id) === Number(item.id))
	);
	const next = [...incoming, ...prev].slice(0, MERCHANT_STORE_PICKER_RECENT_MAX);
	try {
		uni.setStorageSync(MERCHANT_STORE_PICKER_RECENT_KEY, next);
	} catch (e) {
		/* ignore */
	}
}

export function formatSelectionSummary(payload, storeNameMap) {
	if (!payload) return '请选择门店';
	if (payload.mode === 'single') {
		const id = Number(payload.store_id || (payload.resolved_store_ids || [])[0] || 0);
		if (!id) return '请选择门店';
		return (storeNameMap && storeNameMap[id]) || payload.summary || `门店${id}`;
	}
	const ids = payload.resolved_store_ids || [];
	if (!ids.length) return '请选择门店';
	if (ids.length === 1) {
		return (storeNameMap && storeNameMap[ids[0]]) || `门店${ids[0]}`;
	}
	return `已选${ids.length}家门店`;
}

export function openStoreSelectPage(options = {}) {
	const mode = options.mode === 'single' ? 'single' : 'multiple';
	const snapshot = options.snapshot ? 1 : 0;
	const realtime = options.realtime === false || snapshot ? 0 : 1;
	const preset = {
		mode,
		snapshot: !!snapshot,
		realtime: !!realtime,
		org_ids: options.org_ids || [],
		store_ids: options.store_ids || [],
		excluded_store_ids: options.excluded_store_ids || [],
		resolved_store_ids: options.resolved_store_ids || [],
		store_id: options.store_id || 0,
	};
	try {
		uni.setStorageSync(MERCHANT_STORE_PICKER_PRESET_KEY, preset);
	} catch (e) {
		/* ignore */
	}
	const query = `mode=${mode}&snapshot=${snapshot}&realtime=${realtime}`;
	uni.navigateTo({
		url: `/pages/admin/organization/store-select?${query}`,
		fail: () => {
			uni.showToast({ title: '打开门店选择失败', icon: 'none' });
		},
	});
}

export function consumePickerResult() {
	try {
		const result = uni.getStorageSync(MERCHANT_STORE_PICKER_RESULT_KEY);
		uni.removeStorageSync(MERCHANT_STORE_PICKER_RESULT_KEY);
		return result || null;
	} catch (e) {
		return null;
	}
}
