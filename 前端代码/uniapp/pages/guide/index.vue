<template>
		<view class="main">
			<guide v-if="guidePages" :advData="advData" @jumpPage='jumpPage'></guide>
			<view v-else class="loading-tip">加载中...</view>
		</view>
</template>

<script>
	import guide from '@/components/guide/index.vue'
	import Cache from '@/utils/cache';
	import {
		getOpenAdv
	} from '@/api/api.js'
	export default {
		components: {
			guide
		},
		data() {
			return {
				guidePages: false,
				advData: [],
				jump: 0
			}
		},
		onLoad() {
			this._guideFallbackTimer = setTimeout(() => {
				this.goHome();
			}, 5000);
		},
		onUnload() {
			clearTimeout(this._guideFallbackTimer);
		},
		onShow() {
			// #ifdef H5
			if(this.$wechat.isWeixin()){
				this.$wechat.wechat();
			}
			// #endif
			this.loadExecution()
			if(this.jump){
				this.goHome();
			}
		},
		methods: {
			goHome() {
				clearTimeout(this._guideFallbackTimer);
				uni.switchTab({
					url: '/pages/index/index'
				});
			},
			jumpPage(){
				this.jump = 1
			},
			loadExecution() {
				const tagDate = uni.getStorageSync('guideDate') || 0,
					nowDate = new Date().getTime();
				if ((nowDate - tagDate) <= uni.getStorageSync('intervalTime')) {
					this.goHome();
					return
				}
				getOpenAdv().then(res => {
					if (res.data.status == 0 || res.data.value.length == 0) {
						this.goHome();
					} else if (res.data.status && (res.data.value.length || res.data.video_link)) {
						clearTimeout(this._guideFallbackTimer);
						this.advData = res.data
						let intervalTime = parseFloat(res.data.interval_time)*60*60*1000 || 0;
						uni.setStorageSync('intervalTime', intervalTime);
						uni.setStorageSync('guideDate', new Date().getTime());
						this.guidePages = true
					}
				}).catch(err => {
					this.goHome();
				})
			}
		},
		onHide() {
			this.guidePages = false
		}
	}
</script>

<style>
	page,
	.main {
		width: 100%;
		height: 100%;
	}
	.loading-tip {
		display: flex;
		align-items: center;
		justify-content: center;
		width: 100%;
		height: 100%;
		color: #999;
		font-size: 28rpx;
	}
</style>
