<template>
	<view class="content">
		<jyf-parser :html="content" ref="article" :tag-style="tagStyle"></jyf-parser>
	</view>
</template>

<script>
	import parser from '@/components/jyf-parser/jyf-parser';
	import {
		getUserAgreement
	} from '@/api/user.js';

	export default {
		components: {
			'jyf-parser': parser
		},
		data() {
			return {
				tagStyle: {
					img: 'width:100%;display:block;',
					table: 'width:100%',
					video: 'width:100%'
				},
				content: ``,
				type: 'member'
			}
		},
		onLoad() {
			this.getUserAgreement();
		},
		methods: {
			getUserAgreement() {
				getUserAgreement(this.type).then(res => {
					this.content = res.data.content;
				}).catch(res => {
					this.$util.Tips({
						title: res.msg
					});
				})
			}
		},
	}
</script>

<style lang="scss" scoped>
	page {
		background-color: #fff;
	}

	.content {
		padding: 40rpx 30rpx;
	}
</style>