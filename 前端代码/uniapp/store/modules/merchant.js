import { merchantAccess, merchantContextSwitch } from '@/api/merchant.js';

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

const state = {
	accessLoaded: false,
	canEnter: false,
	// 冷启动始终买家端；仅会话内切换到 merchant（不持久化 mode，避免重启卡在商家端）
	mode: 'buyer',
	roles: cached.roles || [],
	activeRole: cached.active_role || '',
	activeStoreId: cached.active_store_id || 0,
	stores: cached.stores || [],
	permissions: cached.permissions || [],
	identity: cached.identity || {},
	mallUniqueAuth: cached.mall_unique_auth || [],
	loading: false,
};

const mutations = {
	SET_ACCESS(state, payload) {
		const data = payload || {};
		state.accessLoaded = true;
		state.canEnter = !!data.can_enter_merchant;
		state.roles = data.roles || [];
		state.activeRole = data.active_role || '';
		state.activeStoreId = Number(data.active_store_id || 0);
		state.stores = data.stores || [];
		state.permissions = data.permissions || [];
		state.identity = data.identity || {};
		state.mallUniqueAuth = data.mall_unique_auth || [];
		writeCachedContext({
			roles: state.roles,
			active_role: state.activeRole,
			active_store_id: state.activeStoreId,
			stores: state.stores,
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
	RESET_MERCHANT(state) {
		state.accessLoaded = false;
		state.canEnter = false;
		state.mode = 'buyer';
		state.roles = [];
		state.activeRole = '';
		state.activeStoreId = 0;
		state.stores = [];
		state.permissions = [];
		state.identity = {};
		state.mallUniqueAuth = [];
		state.loading = false;
		clearCachedContext();
	},
};

const actions = {
	async fetchAccess({ commit, state, rootGetters }, force = false) {
		if (!rootGetters.isLogin) {
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
				stores: state.stores,
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
