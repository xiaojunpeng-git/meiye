<template>
	<view>
		<view class="px-20">
			<view v-if="expList.length" class="list">
				<view class="item" v-for="(item,index) in expList" :key="index">
					<view class="pt-32 pb-24 fs-24 lh-34rpx text--w111-999">{{ item.time }}</view>
					<view class="px-24 rd-24rpx bg--w111-fff">
						<view class="flex py-32" v-for="cell in item.list" :key="cell.id">
							<view class="flex-1">
								<view class="fs-28 lh-40rpx">{{cell.title}}</view>
								<view class="mt-12 text--w111-999 fs-24 lh-34rpx">{{cell.add_time}}</view>
							</view>
							<view class="Regular fs-36 lh-40rpx" v-if="cell.pm">+{{cell.number}}</view>
							<view class="Regular fs-36 lh-40rpx" v-else>-{{cell.number}}</view>
						</view>
					</view>
				</view>
			</view>
			<view v-if="!expList.length && !loading" class="mt-20">
				<emptyPage title="暂无经验记录~" src="/statics/images/noOrder.gif"></emptyPage>
			</view>
		</view>
		<view class='loadingicon flex-center' v-if="expList.length">
			<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>

<script>
	import {
		getUserInfo,
		getlevelInfo,
		getlevelExpList
	} from '@/api/user.js';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	export default {
		data() {
			return {
				loading: false,
				loadend: false,
				loadTitle: '加载更多', //提示语
				page: 1,
				limit: 20,
				expList: [],
				list: [],
				imgHost: HTTP_REQUEST_URL,
        homeHide: false
			}
		},
		components: {
			emptyPage,
			home
		},
		created() {
			this.getlevelList();
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			getlevelList: function() {
				if (this.loadend) return false;
				if (this.loading) return false;
				getlevelExpList({
					page: this.page,
					limit: this.limit
				}).then(res => {
					let list = res.data;
					this.list = [...this.list, ...list];
					let expData = [...this.list];
					let expList = [];
					for (let i = 0; i < expData.length; i++) {
						expList.push({
							time: expData[i].time,
							list: [expData[i]],
						});
						for (let j = i + 1; j < expData.length; j++) {
							if (expData[i].time == expData[j].time) {
								expList[expList.length - 1].list.push(expData[j]);
								expData.splice(j, 1);
								j--;
							}
						}
					}
					this.expList = expList;
					this.loadend = list.length < this.limit;;
					this.loadTitle = this.loadend ? '没有更多了～' : '加载更多';
					this.page = this.page + 1;
					this.loading = false;
				}).catch(err => {
					this.loading = false;
					this.loadTitle = '加载更多';
				});
			},
      onhomehide() {
				this.homeHide = false;
			}
		},
		onReachBottom: function() {
			this.getlevelList();
		}
	}
</script>