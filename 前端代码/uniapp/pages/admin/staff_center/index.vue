<template>
	<view class="teacher-page" :style="colorStyle">
		<view class="header-box" :style="{ paddingTop: sysHeight + 'px' }">
			<view class="nav-bar acea-row row-between-wrapper">
				<text class="iconfont icon-ic_leftarrow back-icon" @click="goBack"></text>
				<text class="nav-title">老师中心</text>
				<view class="nav-placeholder"></view>
			</view>
		</view>

		<view class="profile-card">
			<image class="avatar" :src="info.avatar || defaultAvatar" mode="aspectFill"></image>
			<view class="profile-body flex-1">
				<view class="name">{{ info.staff_name || '—' }}</view>
				<view class="sub">{{ info.store_name || '当前门店' }}</view>
				<view class="sub" v-if="info.phone">{{ info.phone }}</view>
			</view>
		</view>

		<view class="setting-card">
			<view class="setting-row acea-row row-between-wrapper">
				<text class="label">下班时间</text>
				<picker mode="time" :value="offWorkTime" @change="onOffWorkChange">
					<view class="value acea-row row-middle">
						<text>{{ offWorkTime || '未设置' }}</text>
						<text class="iconfont icon-ic_rightarrow ml-8"></text>
					</view>
				</picker>
			</view>
			<view class="divider"></view>
			<view class="setting-row acea-row row-between-wrapper">
				<text class="label">在岗状态</text>
				<switch :checked="onDuty" color="#07CD9A" @change="onDutyChange" />
			</view>
		</view>

		<view class="menu-grid acea-row">
			<view class="menu-item" @click="goPage('/pages/admin/staff_center/rest/index')">
				<image class="menu-icon" src="https://myqiniu.cc3798.com/mpstatic/images/work_xiu.png" mode="widthFix"></image>
				<text>调整休息</text>
			</view>
			<view class="menu-item" @click="goPage('/pages/admin/staff_center/introduction/index')">
				<image class="menu-icon" src="https://meiyue.meiyuekeji.cn/uploads/system/2c1bbd9fa1c769dde1f7dc9d12b690c2.png" mode="widthFix"></image>
				<text>修改简介</text>
			</view>
			<view class="menu-item" @click="goPage('/pages/admin/staff_center/order/index')">
				<view class="menu-badge" v-if="stats.pending_service">{{ stats.pending_service }}</view>
				<text class="iconfont icon-dingdanguanli menu-fa"></text>
				<text>老师订单</text>
			</view>
		</view>

		<view class="footer-space"></view>
		<staffCenterFooter />
	</view>
</template>

<script>
import staffCenterFooter from '../components/staffCenterFooter/index.vue';
import { staffTeacherCenter, staffTeacherUpdate } from '@/api/store.js';
import { toLogin } from '@/libs/login.js';
import { mapGetters } from 'vuex';
import colors from '@/mixins/color.js';
import { goWithYuyueSubscribe } from '@/utils/SubscribeMessage.js';

export default {
	components: { staffCenterFooter },
	mixins: [colors],
	data() {
		return {
			sysHeight: uni.getSystemInfoSync().statusBarHeight,
			defaultAvatar: '/static/images/f.png',
			info: {},
			stats: {},
			offWorkTime: '',
			onDuty: true
		};
	},
	computed: {
		...mapGetters(['isLogin'])
	},
	onShow() {
		if (!this.isLogin) return toLogin();
		this.loadCenter();
	},
	methods: {
		loadCenter() {
			staffTeacherCenter().then(res => {
				this.info = res.data || {};
				this.stats = this.info.order_stats || {};
				this.offWorkTime = this.info.off_work_time || '';
				this.onDuty = Number(this.info.is_reservable) === 1;
			}).catch(err => {
				this.$util.Tips({ title: err.msg || err || '加载失败' });
			});
		},
		saveProfile(data) {
			return staffTeacherUpdate(data).then(res => {
				this.$util.Tips({ title: res.msg || '保存成功' });
				this.loadCenter();
			}).catch(err => {
				this.$util.Tips({ title: err.msg || err || '保存失败' });
			});
		},
		onOffWorkChange(e) {
			this.offWorkTime = e.detail.value;
			this.saveProfile({ off_work_time: this.offWorkTime });
		},
		onDutyChange(e) {
			this.onDuty = !!e.detail.value;
			this.saveProfile({ is_reservable: this.onDuty ? 1 : 0 });
		},
		goPage(url) {
			if (url === '/pages/admin/staff_center/order/index') {
				goWithYuyueSubscribe(url);
				return;
			}
			uni.navigateTo({ url });
		},
		goBack() {
			const pages = getCurrentPages();
			if (pages.length > 1) uni.navigateBack();
			else uni.switchTab({ url: '/pages/user/index' });
		}
	}
};
</script>

<style scoped lang="scss">
.teacher-page {
	min-height: 100vh;
	background: #f5f5f5;
}
.header-box {
	background: linear-gradient(180deg, #07cd9a 0%, #06b888 100%);
	padding: 0 30rpx 40rpx;
}
.nav-bar {
	height: 88rpx;
	color: #fff;
}
.back-icon, .nav-placeholder {
	width: 60rpx;
	font-size: 40rpx;
}
.nav-title {
	font-size: 34rpx;
	font-weight: 600;
}
.profile-card {
	margin: -20rpx 30rpx 20rpx;
	background: #fff;
	border-radius: 16rpx;
	padding: 30rpx;
	display: flex;
	align-items: center;
	box-shadow: 0 8rpx 24rpx rgba(0, 0, 0, 0.04);
}
.avatar {
	width: 120rpx;
	height: 120rpx;
	border-radius: 50%;
	margin-right: 24rpx;
	background: #f5f5f5;
}
.name {
	font-size: 34rpx;
	font-weight: 600;
	color: #282828;
}
.sub {
	font-size: 26rpx;
	color: #999;
	margin-top: 8rpx;
}
.setting-card {
	margin: 0 30rpx 20rpx;
	background: #fff;
	border-radius: 16rpx;
	padding: 0 30rpx;
}
.setting-row {
	padding: 28rpx 0;
	font-size: 28rpx;
	color: #282828;
}
.divider {
	height: 1rpx;
	background: #f0f0f0;
}
.value {
	color: #666;
	font-size: 28rpx;
}
.menu-grid {
	margin: 0 30rpx;
	background: #fff;
	border-radius: 16rpx;
	padding: 20rpx 0 10rpx;
	flex-wrap: wrap;
}
.menu-item {
	width: 33.33%;
	text-align: center;
	padding: 24rpx 0 30rpx;
	font-size: 26rpx;
	color: #282828;
	position: relative;
}
.menu-icon {
	width: 56rpx;
	height: 56rpx;
	display: block;
	margin: 0 auto 12rpx;
}
.menu-fa {
	font-size: 56rpx;
	color: #07cd9a;
	display: block;
	margin-bottom: 12rpx;
}
.menu-badge {
	position: absolute;
	top: 10rpx;
	right: 36rpx;
	min-width: 32rpx;
	height: 32rpx;
	line-height: 32rpx;
	padding: 0 8rpx;
	border-radius: 16rpx;
	background: #e93323;
	color: #fff;
	font-size: 20rpx;
}
.footer-space {
	height: calc(120rpx + env(safe-area-inset-bottom));
}
</style>
