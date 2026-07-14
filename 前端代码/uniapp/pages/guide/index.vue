<template>
	<view class="main">
		<guide v-if="guidePages" :advData="advData" @jumpPage='jumpPage'></guide>
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
		onShow() {
			// #ifdef H5
			if(this.$wechat.isWeixin()){
				this.$wechat.wechat();
			}
			// #endif
			this.loadExecution()
			if(this.jump){
				uni.switchTab({
					url: '/pages/index/index'
				});
			}
		},
		methods: {
			jumpPage(){
				this.jump = 1
			},
			loadExecution() {
				const tagDate = uni.getStorageSync('guideDate') || 0,
					nowDate = new Date().getTime();
				if ((nowDate - tagDate) <= uni.getStorageSync('intervalTime')) {
					uni.switchTab({
						url: '/pages/index/index'
					});
					return
				}
				getOpenAdv().then(res => {
					if (res.data.status == 0 || res.data.value.length == 0) {
						uni.switchTab({
							url: '/pages/index/index'
						});
					} else if (res.data.status && (res.data.value.length || res.data.video_link)) {
						this.advData = res.data
						let intervalTime = parseFloat(res.data.interval_time)*60*60*1000 || 0;
						uni.setStorageSync('intervalTime', intervalTime);
						uni.setStorageSync('guideDate', new Date().getTime());
						this.guidePages = true
					}
				}).catch(err => {
					uni.switchTab({
						url: '/pages/index/index'
					});
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
</style>
