<template>
	<view class="records-page" :style="colorStyle">
		<view class="records-head" :style="{'padding-top': sysHeight + 'px'}">
			<view class="records-nav">
				<text class="iconfont icon-ic_leftarrow records-back" @tap="backPage"></text>
				<text class="records-title">我的拼团</text>
				<text class="records-placeholder"></text>
			</view>
			<scroll-view class="records-tabs" scroll-x :show-scrollbar="false">
				<view class="records-tabs-inner">
					<view v-for="item in tabs" :key="item.key" class="records-tab"
						:class="{active: status === item.key}" @tap="changeStatus(item.key)">{{ item.name }}</view>
				</view>
			</scroll-view>
		</view>

		<view class="records-list" v-if="records.length">
			<view class="record-card" v-for="item in records" :key="item.record_id" @tap="openGroup(item)">
				<image class="record-image" :src="item.image" mode="aspectFill"></image>
				<view class="record-main">
					<view class="record-top">
						<text class="record-name line1">{{ item.title || '拼团活动' }}</text>
						<text class="record-status" :class="'is-' + item.status">{{ item.status_name }}</text>
					</view>
					<view class="record-meta">
						<text>{{ item.role }}</text>
						<text>团号 {{ item.group_id }}</text>
					</view>
					<view class="record-progress">已参团 {{ item.joined_people }}/{{ item.target_people }} 人</view>
					<view class="record-bottom">
						<text>{{ item.store_name || '购买门店未记录' }}</text>
						<text>{{ item.started_at }}</text>
					</view>
				</view>
				<text class="iconfont icon-ic_rightarrow record-arrow"></text>
			</view>
		</view>
		<view class="records-empty" v-else-if="!loading">
			<emptyPage title="暂无拼团记录～"></emptyPage>
		</view>
		<view class="records-loading" v-if="loading || records.length">
			<text>{{ loading ? '加载中…' : (loadEnd ? '没有更多了' : '上拉加载更多') }}</text>
		</view>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import { mapGetters } from 'vuex';
	import { toLogin } from '@/libs/login.js';
	import colors from '@/mixins/color.js';
	import emptyPage from '@/components/emptyPage';
	import home from '@/components/home';
	import { getMemberCombinationRecords } from '@/api/activity.js';

	export default {
		components: { emptyPage, home },
		mixins: [colors],
		data() {
			return {
				sysHeight,
				status: 'all',
				tabs: [
					{ key: 'all', name: '全部' },
					{ key: 'pending', name: '拼团中' },
					{ key: 'success', name: '已成团' },
					{ key: 'failed', name: '未成团' },
					{ key: 'refunded', name: '已退款' }
				],
				records: [],
				page: 1,
				limit: 20,
				loading: false,
				loadEnd: false,
				homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad() {
			if (!this.isLogin) {
				toLogin();
				return;
			}
			this.loadRecords();
		},
		onShow() {
			// 手机号授权完成返回本页时重新触发首屏查询，避免入口停留在空列表。
			if (this.isLogin && !this.records.length && !this.loading && !this.loadEnd) {
				this.loadRecords();
			}
		},
		onReachBottom() {
			this.loadRecords();
		},
		onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			/** 状态切换必须重置分页，防止把不同口径的记录混到同一列表。 */
			changeStatus(status) {
				if (this.status === status) return;
				this.status = status;
				this.page = 1;
				this.records = [];
				this.loadEnd = false;
				this.loadRecords();
			},
			/** 后端返回当前会员范围内的权威分页，前端只负责追加展示。 */
			loadRecords() {
				if (this.loading || this.loadEnd || !this.isLogin) return;
				this.loading = true;
				getMemberCombinationRecords({ status: this.status, page: this.page, limit: this.limit })
					.then(({ data }) => {
						const list = Array.isArray(data.list) ? data.list : [];
						this.records = this.page === 1 ? list : this.records.concat(list);
						this.loadEnd = this.records.length >= Number(data.count || 0) || list.length < this.limit;
						if (!this.loadEnd) this.page += 1;
					})
					.catch((error) => {
						uni.showToast({ title: error.msg || '拼团记录加载失败', icon: 'none' });
					})
					.finally(() => { this.loading = false; });
			},
			/** 团详情继续复用既有页面，避免记录入口产生第二套团状态口径。 */
			openGroup(item) {
				uni.navigateTo({ url: '/pages/activity/goods_combination_status/index?id=' + item.group_id });
			},
			backPage() {
				uni.navigateBack({ delta: 1 });
			}
		}
	};
</script>

<style lang="scss" scoped>
	.records-page { min-height: 100vh; background: #f5f5f5; }
	.records-head { position: sticky; top: 0; z-index: 20; background: #fff; }
	.records-nav { height: 88rpx; padding: 0 30rpx; display: flex; align-items: center; justify-content: space-between; }
	.records-back { width: 60rpx; font-size: 38rpx; color: #333; }
	.records-title { font-size: 34rpx; font-weight: 600; color: #222; }
	.records-placeholder { width: 60rpx; }
	.records-tabs { width: 100%; border-top: 1rpx solid #f2f2f2; }
	.records-tabs-inner { display: inline-flex; min-width: 100%; height: 88rpx; align-items: center; }
	.records-tab { position: relative; flex: 1 0 136rpx; text-align: center; font-size: 26rpx; color: #666; }
	.records-tab.active { color: var(--view-theme); font-weight: 600; }
	.records-tab.active::after { content: ''; position: absolute; left: 50%; bottom: -22rpx; width: 38rpx; height: 5rpx; border-radius: 4rpx; background: var(--view-theme); transform: translateX(-50%); }
	.records-list { padding: 20rpx; }
	.record-card { min-height: 188rpx; margin-bottom: 20rpx; padding: 24rpx; display: flex; align-items: center; background: #fff; border-radius: 20rpx; }
	.record-image { width: 150rpx; height: 150rpx; flex: none; border-radius: 14rpx; background: #f2f2f2; }
	.record-main { min-width: 0; flex: 1; padding-left: 20rpx; }
	.record-top, .record-bottom, .record-meta { display: flex; align-items: center; justify-content: space-between; }
	.record-name { max-width: 350rpx; font-size: 29rpx; font-weight: 600; color: #222; }
	.record-status { padding: 6rpx 12rpx; border-radius: 16rpx; font-size: 22rpx; color: #999; background: #f3f3f3; }
	.record-status.is-pending { color: #e88a12; background: #fff3df; }
	.record-status.is-success { color: #20a162; background: #e8f8ef; }
	.record-status.is-failed, .record-status.is-refunded { color: #999; background: #f2f2f2; }
	.record-meta { margin-top: 14rpx; justify-content: flex-start; gap: 22rpx; font-size: 23rpx; color: #999; }
	.record-progress { margin-top: 12rpx; font-size: 25rpx; color: #555; }
	.record-bottom { margin-top: 14rpx; font-size: 21rpx; color: #aaa; }
	.record-arrow { margin-left: 12rpx; font-size: 28rpx; color: #bbb; }
	.records-empty { padding-top: 170rpx; }
	.records-loading { padding: 12rpx 0 40rpx; text-align: center; font-size: 23rpx; color: #aaa; }
</style>
