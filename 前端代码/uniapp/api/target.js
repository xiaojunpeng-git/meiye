import request from '@/utils/request.js';

export function targetList(data) {
	return request.get('target/list', data);
}

export function targetDetail(id, data) {
	return request.get(`target/detail/${id}`, data);
}

export function targetSave(data) {
	return request.post('target/save', data);
}

export function targetCopy(id, data) {
	return request.post(`target/copy/${id}`, data || {});
}

export function targetDelete(id, data) {
	return request.delete(`target/delete/${id}`, data || {});
}

export function targetAnalysis(data) {
	return request.get('target/analysis', data);
}

export function targetMetricOptions() {
	return request.get('target/metric_options');
}

export function targetStoreOptions(data) {
	return request.get('target/store_options', data);
}

export function targetStoreOptionsTree(data) {
	return request.get('target/store_options_tree', data);
}

export function targetProductSearch(data) {
	return request.get('target/product_search', data);
}

export function targetProductSelect(data) {
	return request.get('target/product_select', data);
}

/** 商品选择-仅分类列表 */
export function targetProductSelectCategories(data) {
	return request.get('target/product_select', { ...data, scope: 'categories' });
}

/** 商品选择-按分类分页商品 */
export function targetProductSelectProducts(data) {
	return request.get('target/product_select', { ...data, scope: 'products' });
}

export function targetRanking(data) {
	return request.get('target/ranking', data);
}

export function targetAllocateInfo(data) {
	return request.get('target/allocate_info', data);
}

export function targetAllocateSave(data) {
	return request.post('target/allocate_save', data);
}
