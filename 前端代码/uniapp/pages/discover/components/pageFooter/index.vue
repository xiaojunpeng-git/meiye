<template>
	<view class="">
		<view v-if="tab != 2" style="height: 296rpx"></view>
		<view v-if="tab != 2" class="pb-safe"></view>
		<view :class="{
			'bg--w111-fff': tab != 2,
			'bg--w111-000 dark': tab == 2,
		}" class="community-footer fixed-lb z-1000 w-full">
			<view class="flex h-100 fs-28 text--w111-666">
				<template v-for="(item, index) in pageList">
					<view v-if="item.name" :class="{
						active: item.tab.includes(tab)
					}" class="flex-1 flex-center" @tap="onPageChange(index)">{{item.name}}</view>
					<view v-else class="flex-1 flex-center" @tap="onPageChange(index)">
						<view class="flex-center w-80 h-60 rd-10rpx bg-color">
							<text class="iconfont icon-ic_increase2 fs-40 text--w111-fff"></text>
						</view>
					</view>
				</template>
			</view>
			<view class="pb-safe"></view>
		</view>
		<mainPageFooter></mainPageFooter>
	</view>
</template>

<script>
	import {
		mapGetters
	} from 'vuex';
	import {
		toLogin
	} from '@/libs/login.js';
	import mainPageFooter from '@/components/pageFooter/index.vue';

	export default {
		props: {
			tab: {
				type: Number,
				default: 1,
			},
		},
		components: { mainPageFooter },
		data() {
			return {
				pageList: [],
				route: '',
			}
		},
		computed: mapGetters(['isLogin', 'uid']),
		created() {
			const pages = getCurrentPages();
			const page = pages[pages.length - 1];
			const route = page.route;
			const pageList = [{
					name: '首页',
					url: '/pages/discover/discoverIndex/index?type=tab',
					tab: [0, 1],
				},
				{
					name: '精选',
					url: '/pages/discover/discoverIndex/index?type=tab&tab=2',
					tab: [2],
				},
				{
					name: '',
					url: '/pages/discover/discoverCreate/index?type=tab',
					tab: [3],
				},
				{
					name: '消息',
					url: '/pages/discover/discoverMessage/index?type=tab',
					tab: [4],
				},
				{
					name: '我的',
					url: `/pages/discover/discoverUser/index?type=tab&is_store=2&id=${this.uid}`,
					tab: [5],
				},
			];
			this.route = route;
			this.pageList = pageList;
		},
		methods: {
			onPageChange(index) {
				const page = this.pageList[index];
				const url = page.url;
				if (!this.isLogin && page.tab.some(item => [3, 4, 5].includes(item))) {
					toLogin();
					return;
				}
				if (page.tab.includes(3)) {
					uni.navigateTo({
						url
					});
				} else {
					uni.redirectTo({
						url
					});
				}
			}
		},
	}
</script>

<style lang="scss" scoped>
	.community-footer {
		bottom: calc(184rpx + env(safe-area-inset-bottom));
		border-top: 1rpx solid rgba(123, 41, 65, .08);
		box-shadow: 0 -8rpx 22rpx rgba(83,45,54,.06);
		color: #856b72;
	}

	.community-footer .bg--w111-fff { background: rgba(255,253,251,.96); }
	.community-footer .bg-color { background: linear-gradient(135deg, #8e4056, #6d2138); }

	.active {
		font-weight: 500;
		color: #7b2941;
	}

	.dark .text--w111-666 {
		color: rgba(255, 255, 255, 0.6);
	}

	.dark .active {
		color: #FFFFFF;
	}
</style>
