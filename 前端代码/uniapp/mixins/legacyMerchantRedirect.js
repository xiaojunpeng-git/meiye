/**
 * 旧商家管理页过渡重定向：
 * 有 merchant.enter 时，收藏/分享的旧 admin 工作台、用户列表等路由跳到统一商家端。
 * 无商家权限时不拦截，保留极少数旧链路兜底。
 */
export default {
	methods: {
		async redirectLegacyToMerchant(targetUrlOrFn) {
			if (!this.$store || !this.$store.dispatch) {
				return false;
			}
			try {
				await this.$store.dispatch('merchant/fetchAccess', true);
			} catch (e) {
				return false;
			}
			if (!this.$store.state.merchant.canEnter) {
				return false;
			}
			this.$store.dispatch('merchant/enterMerchant');
			let url = '/pages/merchant/data/index';
			if (typeof targetUrlOrFn === 'function') {
				url = targetUrlOrFn() || url;
			} else if (targetUrlOrFn) {
				url = targetUrlOrFn;
			}
			uni.reLaunch({ url });
			return true;
		},
		legacyMerchantCustomerUrl() {
			const list = (this.$store && this.$store.state.merchant.permissions) || [];
			if (Array.isArray(list) && list.indexOf('merchant.customer.view') !== -1) {
				return '/pages/merchant/customer/index';
			}
			return '/pages/merchant/data/index';
		},
	},
};
