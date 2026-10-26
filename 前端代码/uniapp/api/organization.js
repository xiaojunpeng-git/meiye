import request from '@/utils/request.js';

/** 当前用户可管组织/门店树 */
export function organizationScopeTree(data) {
	return request.get('organization/scope/tree', data || {});
}

/**
 * 后端二次解析门店范围
 * @param {Object} data org_ids / store_ids / excluded_store_ids / snapshot
 */
export function organizationScopeResolve(data) {
	return request.post('organization/scope/resolve', data || {});
}
