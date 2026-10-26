<template>
	<view class="merchant-page">
		<!-- 入口页：有权限则默认进目标看板 -->
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';

export default {
	mixins: [merchantGuard],
	async onShow() {
		const ok = await this.ensureMerchantAccess({
			permission: 'merchant.target.view',
			fallbackMerchantHome: true,
		});
		if (!ok) return;
		const canManage = this.hasMerchantPermission('merchant.target.manage');
		// 普通员工也可看看板；设置页由管理权限控制（管理页内部再裁剪）
		uni.redirectTo({
			url: '/pages/admin/target/analysis/index?from=merchant',
		});
		if (!canManage) {
			// keep analysis only; management tab still visible but backend/UI already restricts writes
		}
	},
};
</script>

<style scoped>
.merchant-page {
	min-height: 100vh;
	background: #f5f6f8;
}
</style>
