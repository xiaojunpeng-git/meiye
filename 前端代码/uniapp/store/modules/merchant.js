import { merchantAccess, merchantContextSwitch } from '@/api/merchant.js';
import Cache from '@/utils/cache';
import { MERCHANT_STAFF_SESSION, MERCHANT_STAFF_TOKEN } from '@/config/cache';

const MERCHANT_CONTEXT_KEY = 'MERCHANT_CONTEXT';

function readCachedContext() {
	try {
		return uni.getStorageSync(MERCHANT_CONTEXT_KEY) || null;
	} catch (e) {
		return null;
	}
}

function writeCachedContext(payload) {
	try {
		uni.setStorageSync(MERCHANT_CONTEXT_KEY, payload || {});
	} catch (e) {}
}

function clearCachedContext() {
	try {
		uni.removeStorageSync(MERCHANT_CONTEXT_KEY);
		uni.removeStorageSync('MERCHANT_MODE');
	} catch (e) {}
}

const cached = readCachedContext() || {};
const employeeSession = Cache.get(MERCHANT_STAFF_SESSION, true) || {};

const state = {
	accessLoaded: false,
	canEnter: false,
	// 冷启动始终买家端；仅会话内切换到 merchant（不持久化 mode，避免重启卡在商家端）
	mode: 'buyer',
	roles: cached.roles || [],
	activeRole: cached.active_role || '',
	activeStoreId: cached.active_store_id || 0,
	activeStore: cached.active_store || null,
	stores: cached.stores || [],
	dataScopeType: cached.data_scope_type || 'NONE',
	storeSwitchAllowed: !!cached.store_switch_allowed,
	taskVisibility: cached.task_visibility || 'NONE',
	permissions: cached.permissions || [],
	identity: cached.identity || {},
	mallUniqueAuth: cached.mall_unique_auth || [],
	loading: false,
	employeeAuthenticated: !!Cache.get(MERCHANT_STAFF_TOKEN),
	employeeSession,
};

const mutations = {
	SET_ACCESS(state, payload) {
		const data = payload || {};
		state.accessLoaded = true;
		state.canEnter = !!data.can_enter_merchant;
		state.roles = data.roles || [];
		state.activeRole = data.active_role || '';
		state.activeStoreId = Number(data.active_store_id || 0);
		state.activeStore = data.active_store || null;
		state.stores = data.stores || [];
		state.dataScopeType = data.data_scope_type || 'NONE';
		state.storeSwitchAllowed = !!data.store_switch_allowed;
		state.taskVisibility = data.task_visibility || 'NONE';
		state.permissions = data.permissions || [];
		state.identity = data.identity || {};
		state.mallUniqueAuth = data.mall_unique_auth || [];
		writeCachedContext({
			roles: state.roles,
			active_role: state.activeRole,
			active_store_id: state.activeStoreId,
			active_store: state.activeStore,
			stores: state.stores,
			data_scope_type: state.dataScopeType,
			store_switch_allowed: state.storeSwitchAllowed,
			task_visibility: state.taskVisibility,
			permissions: state.permissions,
			identity: state.identity,
			mall_unique_auth: state.mallUniqueAuth,
			can_enter_merchant: state.canEnter,
		});
	},
	SET_MODE(state, mode) {
		state.mode = mode === 'merchant' ? 'merchant' : 'buyer';
	},
	SET_LOADING(state, val) {
		state.loading = !!val;
	},
	SET_EMPLOYEE_SESSION(state, payload) {
		const data = payload || {};
		const token = String(data.token || '');
		if (!token) return;
		const expiresAt = Number(data.expires_time || 0);
		const ttl = expiresAt > 0 ? Math.max(1, expiresAt - Cache.time()) : 0;
		Cache.set(MERCHANT_STAFF_TOKEN, token, ttl);
		const session = {
			staff: data.user_info || {},
			store_id: Number(data.store_id || 0),
			store_name: data.store_name || '',
			expires_time: expiresAt,
		};
		Cache.set(MERCHANT_STAFF_SESSION, session, ttl);
		state.employeeAuthenticated = true;
		state.employeeSession = session;
	},
	CLEAR_EMPLOYEE_SESSION(state) {
		Cache.clear(MERCHANT_STAFF_TOKEN);
		Cache.clear(MERCHANT_STAFF_SESSION);
		state.employeeAuthenticated = false;
		state.employeeSession = {};
	},
	RESET_MERCHANT(state) {
		state.accessLoaded = false;
		state.canEnter = false;
		state.mode = 'buyer';
		state.roles = [];
		state.activeRole = '';
		state.activeStoreId = 0;
		state.activeStore = null;
		state.stores = [];
		state.dataScopeType = 'NONE';
		state.storeSwitchAllowed = false;
		state.taskVisibility = 'NONE';
		state.permissions = [];
		state.identity = {};
		state.mallUniqueAuth = [];
		state.loading = false;
		clearCachedContext();
	},
};

const actions = {
	async fetchAccess({ commit, state, rootGetters }, force = false) {
		if (!rootGetters.isLogin && !state.employeeAuthenticated) {
			commit('RESET_MERCHANT');
			return { can_enter_merchant: false };
		}
		if (state.loading) {
			return null;
		}
		if (state.accessLoaded && !force) {
			return {
				can_enter_merchant: state.canEnter,
				roles: state.roles,
				active_role: state.activeRole,
				active_store_id: state.activeStoreId,
				active_store: state.activeStore,
				stores: state.stores,
				data_scope_type: state.dataScopeType,
				store_switch_allowed: state.storeSwitchAllowed,
				task_visibility: state.taskVisibility,
				permissions: state.permissions,
				identity: state.identity,
				mall_unique_auth: state.mallUniqueAuth,
			};
		}
		commit('SET_LOADING', true);
		try {
			const res = await merchantAccess({
				active_store_id: state.activeStoreId || 0,
				active_role: state.activeRole || '',
			});
			const data = (res && res.data) || res || {};
			commit('SET_ACCESS', data);
			return data;
		} catch (e) {
			commit('SET_ACCESS', { can_enter_merchant: false });
			throw e;
		} finally {
			commit('SET_LOADING', false);
		}
	},
	async switchContext({ commit, state }, payload = {}) {
		const res = await merchantContextSwitch({
			active_store_id: payload.active_store_id != null ? payload.active_store_id : state.activeStoreId,
			active_role: payload.active_role != null ? payload.active_role : state.activeRole,
		});
		const data = (res && res.data) || res || {};
		commit('SET_ACCESS', data);
		return data;
	},
	signInWithEmployeeSession({ commit }, payload) {
		commit('RESET_MERCHANT');
		commit('SET_EMPLOYEE_SESSION', payload);
	},
	clearEmployeeSession({ commit }) {
		commit('CLEAR_EMPLOYEE_SESSION');
		commit('RESET_MERCHANT');
	},
	enterMerchant({ commit }) {
		commit('SET_MODE', 'merchant');
	},
	exitMerchant({ commit }) {
		commit('SET_MODE', 'buyer');
	},
	resetMerchant({ commit }) {
		commit('RESET_MERCHANT');
	},
};

export default {
	namespaced: true,
	state,
	mutations,
	actions,
};
