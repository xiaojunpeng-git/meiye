<template>
	<view class="custom-service-page" :style="colorStyle">
		<view v-if="loading" class="notice">正在加载…</view>
		<view v-else-if="error" class="notice"><text>{{ error }}</text><button class="retry" @click="load">重试</button></view>
		<view v-else-if="!enabled" class="notice">门店暂未开启定制服务</view>
		<view v-else-if="!records.length" class="notice">暂无可选定制服务</view>
		<view v-else class="service-list">
			<view v-for="item in records" :key="item.id" class="service-row">
				<text class="service-name">{{ item.name }}</text>
				<view class="quantity-control">
					<button :disabled="item.quantity === 0" :aria-label="'减少' + item.name" @click="change(item, -1)">−</button>
					<text class="quantity">{{ item.quantity }}</text>
					<button :aria-label="'增加' + item.name" @click="change(item, 1)">+</button>
				</view>
			</view>
		</view>
		<view class="selection-footer"><text>已选 {{ total }} 份</text><button :disabled="loading || !!error" @click="confirm">确定</button></view>
	</view>
</template>
<script>
import colors from '@/mixins/color';
import { getReservationCustomServices } from '@/api/order.js';

export default {
	mixins: [colors],
	data() { return { storeId: 0, records: [], initial: [], enabled: false, loading: true, error: '', requestEpoch: 0 }; },
	computed: { total() { return this.records.reduce((sum, item) => sum + item.quantity, 0); } },
	onLoad(options) {
		this.storeId = Number(options.store_id || 0);
		// Event channel belongs to this navigation only; no persistent global
		// cart or shared storage can leak selections between stores/members.
		this.getOpenerEventChannel().on('initialSelection', items => {
			this.initial = Array.isArray(items) ? items : [];
			this.applyInitial();
		});
		this.load();
	},
	onUnload() { this.requestEpoch++; },
	methods: {
		applyInitial() {
			this.records = this.records.map(item => {
				const quantity = Number((this.initial.find(row => Number(row.id) === Number(item.id)) || {}).quantity || 0);
				return { ...item, quantity: Number.isSafeInteger(quantity) && quantity > 0 && quantity <= 2147483647 ? quantity : 0 };
			});
		},
		load() {
			const epoch = ++this.requestEpoch;
			this.loading = true; this.error = '';
			getReservationCustomServices(this.storeId).then(res => {
				if (epoch !== this.requestEpoch) return;
				this.enabled = res.data.enabled === true;
				this.records = (res.data.records || []).map(item => ({ ...item, quantity: 0 }));
				this.applyInitial();
			}).catch(() => { if (epoch === this.requestEpoch) this.error = '定制服务加载失败，请重试'; })
				.finally(() => { if (epoch === this.requestEpoch) this.loading = false; });
		},
		change(item, delta) { item.quantity = Math.min(2147483647, Math.max(0, item.quantity + delta)); },
		confirm() {
			if (this.loading || this.error) return;
			this.getOpenerEventChannel().emit('chosen', this.enabled ? this.records.filter(item => item.quantity > 0).map(item => ({ id: item.id, name: item.name, quantity: item.quantity })) : []);
			uni.navigateBack();
		}
	}
};
</script>
<style scoped>
.custom-service-page { min-height: 100vh; padding: 16px 16px 110px; box-sizing: border-box; background: #fff; color: #303133; }
.service-list { max-width: 640px; margin: 0 auto; }
.service-row { display: flex; align-items: center; gap: 16px; min-height: 76px; border-bottom: 1px solid #ebeef2; }
.service-name { flex: 1; min-width: 0; overflow-wrap: anywhere; font-size: 16px; }
.quantity-control { display: flex; align-items: center; flex: none; }
.quantity-control button { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; padding: 0; margin: 0; line-height: 1; border-radius: 6px; font-size: 22px; background: #f3f5f8; color: #303133; }
.quantity-control button[disabled] { color: #c7cbd1; }
.quantity { width: 94px; text-align: center; font-size: 14px; }
.notice { max-width: 640px; margin: 36px auto; color: #7d8188; text-align: center; }
.retry { width: 96px; margin: 16px auto; font-size: 14px; }
.selection-footer { position: fixed; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 20px calc(16px + env(safe-area-inset-bottom)); background: #fff; border-top: 1px solid #e4e7ed; }
.selection-footer button { width: 120px; height: 44px; line-height: 44px; margin: 0; border-radius: 6px; font-size: 16px; background: var(--view-theme, #8b3049); color: #fff; }
</style>
