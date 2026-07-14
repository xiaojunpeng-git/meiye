<template>
	<view>
		<groupGoodsList :goodsList="goodsList"></groupGoodsList>
		<home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import groupGoodsList from '../components/groupGoodsList/index.vue';
	import home from '@/components/home/index.vue';
	import {
		getCollagePartake
	} from '@/api/store.js';

	export default {
		components: {
			groupGoodsList,
			home
		},
		data() {
			return {
				goodsList: [],
        homeHide: false
			}
		},
		onLoad(option) {
			this.collage_id = option.collage_id;
			this.getCollagePartake();
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods: {
			getCollagePartake() {
				getCollagePartake({
					collage_id: this.collage_id
				}).then(res => {
					this.goodsList = res.data;
				});
			}
		},
	};
</script>

<style lang="scss" scoped>
	/deep/.goods-list {
		margin: 20rpx 30rpx;
	}
</style>
