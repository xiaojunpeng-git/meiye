<template>
	<view class="records-page" :style="colorStyle">
		<view class="records-head" :style="{'padding-top': sysHeight + 'px'}">
			<view class="records-nav">
				<text class="iconfont icon-ic_leftarrow records-back" @tap="backPage"></text>
				<text class="records-title">{{ detailId ? '拼团详情' : '我的拼团' }}</text>
				<text class="records-placeholder"></text>
			</view>
			<scroll-view v-if="!detailId" class="records-tabs" scroll-x :show-scrollbar="false">
				<view class="records-tabs-inner">
					<view v-for="item in tabs" :key="item.key" class="records-tab"
						:class="{active: status === item.key}" @tap="changeStatus(item.key)">{{ item.name }}</view>
				</view>
			</scroll-view>
		</view>

        <!-- 历史记录已下架也能查看，页面只展示权威参与事实。 -->
        <view v-if="detail" class="records-list">
            <view class="record-card"><image class="record-image" :src="detail.image" mode="aspectFill" />
                <view class="record-main"><view class="record-name">{{ detail.title || '拼团活动' }}</view>
                    <view class="record-progress">{{ detail.status_name }} · {{ detail.role }}</view>
                    <view class="record-progress">已参团 {{ detail.joined_people }}/{{ detail.target_people }} 人</view></view>
            </view>
            <!-- 详情事实逐行排列，长订单号只在值区域换行，不挤压字段标题。 -->
            <view class="detail-facts">
                <view class="detail-fact"><text class="detail-label">团号</text><text class="detail-value">{{ detail.group_id }}</text></view>
                <view class="detail-fact"><text class="detail-label">购买门店</text><text class="detail-value">{{ detail.store_name || '历史订单未记录' }}</text></view>
                <view class="detail-fact"><text class="detail-label">订单号</text><text class="detail-value">{{ detail.order_no || '历史订单未记录' }}</text></view>
                <view class="detail-fact"><text class="detail-label">参团时间</text><text class="detail-value">{{ detail.started_at || '--' }}</text></view>
                <view class="detail-fact"><text class="detail-label">截止时间</text><text class="detail-value">{{ detail.expires_at || '--' }}</text></view>
            </view>
            <button v-if="canViewGroup" class="detail-group-button" @tap="viewGroup">查看拼团</button>
        </view>
        <view v-if="detailError" class="records-loading" role="status">{{ detailError }}</view>
		<view class="records-list" v-if="!detailId && records.length">
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
		<view class="records-empty" v-else-if="!detailId && !loading">
			<emptyPage :title="emptyTitle"></emptyPage>
            <view v-if="status === 'pending'" class="records-empty-hint">已超时的未成团记录，请到“未成团”查看</view>
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
	import { getMemberCombinationRecords, getMemberCombinationRecordDetail } from '@/api/activity.js';

	export default {
		components: { emptyPage, home },
		mixins: [colors],
		data() {
			return {
				sysHeight, detailId: 0, detail: null, detailError: '',
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
				requestId: 0,
                homeHide: false
			};
		},
		computed: {
            ...mapGetters(['isLogin']),
            /** 入口跟随会员参与记录的权威状态；历史终态不重新进入正在招募的团。 */
            canViewGroup() {
                return !!this.detail && this.detail.status === 'pending'
                    && Number.isInteger(Number(this.detail.group_id)) && Number(this.detail.group_id) > 0;
            },
            // 空状态明确当前筛选范围，不能把历史已超时记录伪装成正在拼团。
            emptyTitle() {
                const names = { pending: '暂无拼团中的记录', success: '暂无已成团记录', failed: '暂无未成团记录', refunded: '暂无已退款记录' };
                return names[this.status] || '暂无拼团记录～';
            }
        },
		onLoad(options) {
            this.detailId = Number(options.record_id) || 0;
			if (!this.isLogin) {
				toLogin();
				return;
			}
			if (this.detailId) this.loadDetail(); else this.loadRecords();
		},
		onShow() {
			// 手机号授权完成返回本页时重新触发首屏查询，避免入口停留在空列表。
			if (!this.detailId && this.isLogin && !this.records.length && !this.loading && !this.loadEnd) {
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
            /** 与订单详情 goJoinPink 使用同一团根 ID 和目标页，不新建参团或支付操作。 */
            viewGroup() {
                if (!this.canViewGroup) return;
                uni.navigateTo({ url: '/pages/activity/goods_combination_status/index?id=' + Number(this.detail.group_id) });
            },
            /** 独立只读详情；失败显示在页内，保留返回操作。 */
            async loadDetail() {
                this.loading = true; this.detailError = '';
                try { const res = await getMemberCombinationRecordDetail(this.detailId); this.detail = res.data; }
                catch (error) { this.detailError = error.msg || '拼团详情加载失败'; }
                finally { this.loading = false; }
            },
			/** 状态切换必须重置分页，防止把不同口径的记录混到同一列表。 */
			changeStatus(status) {
				if (this.status === status) return;
				this.status = status;
				this.page = 1;
				this.records = [];
                this.loadEnd = false;
                // 新筛选可立即发起查询，旧请求只能结束自身，不能阻塞或覆盖新页签。
                this.loading = false;
                this.loadRecords();
			},
			/** 后端返回当前会员范围内的权威分页，前端只负责追加展示。 */
			loadRecords() {
				if (this.detailId || this.loading || this.loadEnd || !this.isLogin) return;
                const requestId = ++this.requestId;
                const status = this.status, page = this.page;
                this.loading = true;
                getMemberCombinationRecords({ status, page, limit: this.limit })
					.then(({ data }) => {
                        if (requestId !== this.requestId || status !== this.status) return;
                        const list = Array.isArray(data.list) ? data.list : [];
						this.records = page === 1 ? list : this.records.concat(list);
						this.loadEnd = this.records.length >= Number(data.count || 0) || list.length < this.limit;
						if (!this.loadEnd) this.page += 1;
					})
					.catch((error) => {
                        if (requestId !== this.requestId || status !== this.status) return;
                        uni.showToast({ title: error.msg || '拼团记录加载失败', icon: 'none' });
					})
					.finally(() => { if (requestId === this.requestId) this.loading = false; });
			},
			/** 详情使用同一只读参与事实接口，不调用参团或退款命令。 */
			openGroup(item) {
				uni.navigateTo({ url: '/pages/activity/user_combination_records/index?record_id=' + item.record_id });
			},
			backPage() {
				uni.navigateBack({ delta: 1 });
			}
		}
	};
</script>

<style lang="scss" scoped>
    .detail-group-button { display: flex; align-items: center; justify-content: center; width: 100%; height: 88rpx; padding: 0 24rpx; margin: 24rpx 0; border-radius: 44rpx; border: 0; background: var(--view-theme); color: #fff; font-size: 28rpx; font-weight: 600; line-height: normal; }
    .detail-group-button::after { border: 0; }
    /* 与列表横排卡片分离，避免后声明的 .record-card flex 布局覆盖详情。 */
    .detail-facts { padding: 8rpx 24rpx; margin-bottom: 20rpx; background: #fff; border-radius: 20rpx; font-size: 26rpx; color: #555; }
    .detail-fact { display: flex; align-items: flex-start; padding: 20rpx 0; line-height: 1.6; border-bottom: 1rpx solid #f2f2f2; }
    .detail-fact:last-child { border-bottom: 0; }
    .detail-label { flex: none; width: 128rpx; margin-right: 20rpx; color: #999; }
    .detail-value { flex: 1; min-width: 0; word-break: break-all; overflow-wrap: anywhere; }
    .records-empty-hint { padding: 0 36rpx; text-align: center; line-height: 1.6; font-size: 24rpx; color: #999; }
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
