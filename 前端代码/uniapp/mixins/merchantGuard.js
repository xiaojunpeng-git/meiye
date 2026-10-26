import { mapGetters } from 'vuex';

export default {
	computed: {
		...mapGetters(['isLogin']),
		merchantCanEnter() {
			return this.$store.state.merchant.canEnter;
		},
		merchantMode() {
			return this.$store.state.merchant.mode;
		},
		merchantPermissions() {
			return this.$store.state.merchant.permissions || [];
		},
	},
	methods: {
		hasMerchantPermission(code) {
			if (!code) return true;
			const list = this.merchantPermissions;
			return Array.isArray(list) && list.indexOf(code) !== -1;
		},
		async ensureMerchantAccess(options = {}) {
			const {
				redirect = true,
				permission = 'merchant.enter',
				fallbackMerchantHome = false,
			} = options;
			const employeeAuthenticated = !!this.$store.state.merchant.employeeAuthenticated;
			if (!this.isLogin && !employeeAuthenticated) {
				if (redirect) {
					uni.reLaunch({ url: '/pages/users/login/index' });
				}
				return false;
			}
			try {
				await this.$store.dispatch('merchant/fetchAccess', true);
			} catch (e) {
				if (redirect) {
					uni.showToast({ title: '商家权限校验失败', icon: 'none' });
					uni.reLaunch({ url: '/pages/index/index' });
				}
				return false;
			}
			const canEnter = this.$store.state.merchant.canEnter;
			if (!canEnter) {
				if (redirect) {
					uni.showToast({ title: '当前账号暂无商家权限', icon: 'none' });
					this.$store.dispatch('merchant/exitMerchant');
					uni.reLaunch({ url: '/pages/index/index' });
				}
				return false;
			}
			this.$store.dispatch('merchant/enterMerchant');
			if (permission && permission !== 'merchant.enter' && !this.hasMerchantPermission(permission)) {
				if (fallbackMerchantHome) {
					uni.showToast({ title: '暂无该功能权限', icon: 'none' });
					uni.redirectTo({ url: '/pages/merchant/data/index' });
				}
				return false;
			}
			return true;
		},
		merchantNavigate(url) {
			if (!url) return;
			uni.redirectTo({ url });
		},
	},
};
