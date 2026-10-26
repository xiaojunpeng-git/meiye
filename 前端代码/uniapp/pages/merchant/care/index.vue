<template>
	<view class="care-page">
		<view class="care-page__body">
			<view class="care-head">
				<view class="care-head__title">客情管理</view>
			</view>

			<view class="bucket-bar">
				<view v-for="item in buckets" :key="item.key" class="bucket" :class="{ active: bucket === item.key }" @click="changeBucket(item.key)">
					<text>{{ item.name }}</text><text class="bucket__count">{{ bucketCount(item.key) }}</text>
				</view>
			</view>

			<view v-if="loading" class="state">加载中...</view>
			<view v-else-if="error" class="state state--error" @click="load">{{ error }}</view>
			<view v-else-if="!tasks.length" class="state">暂无客情任务</view>
			<view v-else class="task-list">
				<view v-for="task in tasks" :key="task.taskId || task.id" class="task-card">
					<view class="task-card__head"><text class="task-card__name">{{ task.memberName || task.member_name || '客户' }}</text><text class="task-card__status">{{ task.statusLabel || task.status_label || task.status || '' }}</text></view>
					<view class="task-card__title">{{ task.title || task.taskTypeLabel || task.task_type_label || '客户跟进' }}</view>
					<view class="task-card__meta">计划时间 {{ task.plannedAt || task.planned_at || '-' }}</view>
					<view v-if="task.latestCare || task.latest_care" class="task-card__meta">最近客情 {{ task.latestCare || task.latest_care }}</view>
				</view>
			</view>
		</view>
		<merchant-tab-bar current="workbench" />
	</view>
</template>

<script>
import merchantGuard from '@/mixins/merchantGuard.js';
import merchantTabBar from '@/components/merchantTabBar/index.vue';
import { merchantCustomerCareWorkbench } from '@/api/merchant.js';

export default {
	mixins: [merchantGuard],
	components: { merchantTabBar },
	data() {
		return {
			bucket: 'today',
			buckets: [
				{ key: 'today', name: '今日' }, { key: 'overdue', name: '逾期' },
				{ key: 'future', name: '未来' }, { key: 'completed', name: '已完成' },
			],
			counts: {}, tasks: [], loading: false, error: '',
		};
	},
	async onShow() {
		const ok = await this.ensureMerchantAccess({ permission: 'merchant.customer.view' });
		if (ok) this.load();
	},
	methods: {
		bucketCount(key) { return Number((this.counts || {})[key] || 0); },
		changeBucket(key) { if (key !== this.bucket) { this.bucket = key; this.load(); } },
		async load() {
			if (this.loading) return;
			this.loading = true; this.error = '';
			try {
				const res = await merchantCustomerCareWorkbench({ bucket: this.bucket });
				const data = (res && res.data) || {};
				const projection = (((data.projection || {}).customerCare) || data.customerCare || {});
				const taskView = projection.taskView || {};
				this.tasks = Array.isArray(taskView.records) ? taskView.records : [];
				this.counts = taskView.bucketCounts || {};
				if ((data.result || {}).status === 'failed') this.error = data.result.message || '客情任务加载失败，点击重试';
			} catch (e) {
				this.error = (e && (e.msg || e.message)) || '客情任务加载失败，点击重试';
			} finally { this.loading = false; }
		},
	},
};
</script>

<style scoped>
.care-page { min-height: 100vh; background: #f5f6f8; padding-bottom: 150rpx; box-sizing: border-box; }
.care-page__body { padding: 32rpx 24rpx; }
.care-head { padding: 8rpx 8rpx 28rpx; }
.care-head__title { color: #1d1d1f; font-size: 42rpx; font-weight: 600; }
.bucket-bar { display: flex; gap: 12rpx; overflow: hidden; margin-bottom: 22rpx; }
.bucket { flex: 1; min-width: 0; background: #fff; color: #777; border-radius: 12rpx; padding: 16rpx 6rpx; text-align: center; font-size: 23rpx; }
.bucket.active { color: #e93323; background: #fff0ee; }
.bucket__count { display: block; font-size: 28rpx; font-weight: 600; margin-top: 5rpx; }
.state { padding: 72rpx 24rpx; text-align: center; color: #999; font-size: 27rpx; }
.state--error { color: #e93323; }
.task-list { display: flex; flex-direction: column; gap: 18rpx; }
.task-card { background: #fff; padding: 26rpx 24rpx; border-radius: 16rpx; }
.task-card__head { display: flex; justify-content: space-between; align-items: center; gap: 20rpx; }
.task-card__name { color: #1d1d1f; font-size: 30rpx; font-weight: 600; }
.task-card__status { color: #e93323; font-size: 23rpx; }
.task-card__title { color: #454545; font-size: 27rpx; margin-top: 15rpx; }
.task-card__meta { color: #999; font-size: 23rpx; margin-top: 10rpx; }
</style>
