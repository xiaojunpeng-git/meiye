import request from '@/utils/request.js';
import store from '@/store';

/** 商家模式下附带当前门店，供后端按任职解析 staff（勿覆盖已显式传入的 store_id） */
function withMerchantContext(data) {
	const payload = data && typeof data === 'object' ? { ...data } : {};
	try {
		const m = store.state.merchant || {};
		if (m.mode === 'merchant') {
			const sid = Number(m.activeStoreId || 0);
			if (sid > 0 && payload.active_store_id === undefined) {
				payload.active_store_id = sid;
			}
		}
	} catch (e) {
		/* ignore */
	}
	return payload;
}

export function targetList(data) {
	return request.get('target/list', withMerchantContext(data));
}

export function targetDetail(id, data) {
	return request.get(`target/detail/${id}`, withMerchantContext(data));
}

export function targetSave(data) {
	return request.post('target/save', withMerchantContext(data));
}

export function targetCopy(id, data) {
	return request.post(`target/copy/${id}`, withMerchantContext(data || {}));
}

export function targetDelete(id, data) {
	return request.delete(`target/delete/${id}`, withMerchantContext(data || {}));
}

export function targetAnalysis(data) {
	return request.get('target/analysis', withMerchantContext(data));
}

export function targetMetricOptions() {
	return request.get('target/metric_options', withMerchantContext({}));
}

export function targetStoreOptions(data) {
	return request.get('target/store_options', withMerchantContext(data));
}

export function targetStoreOptionsTree(data) {
	return request.get('target/store_options_tree', withMerchantContext(data));
}

export function targetProductSearch(data) {
	return request.get('target/product_search', withMerchantContext(data));
}

export function targetProductSelect(data) {
	return request.get('target/product_select', withMerchantContext(data));
}

/** 商品选择-仅分类列表 */
export function targetProductSelectCategories(data) {
	return request.get('target/product_select', withMerchantContext({ ...data, scope: 'categories' }));
}

/** 商品选择-按分类分页商品 */
export function targetProductSelectProducts(data) {
	return request.get('target/product_select', withMerchantContext({ ...data, scope: 'products' }));
}

export function targetRanking(data) {
	return request.get('target/ranking', withMerchantContext(data));
}

export function targetAllocateInfo(data) {
	return request.get('target/allocate_info', withMerchantContext(data));
}

export function targetAllocateSave(data) {
	return request.post('target/allocate_save', withMerchantContext(data));
}
