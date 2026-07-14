<template>
	<view>
		<view class="content">
			<jyf-parser :html="content" ref="article" :tag-style="tagStyle"></jyf-parser>
		</view>
	</view>
</template>

<script>
import parser from '@/components/jyf-parser/jyf-parser';
import { getDistributionInfo } from '@/api/user.js';
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
			content: ``
		};
	},
	computed: {
	},
	onLoad(e) {
		this.distributionInfo();
	},
	mounted() {},
	methods: {
		distributionInfo(){
			getDistributionInfo().then(res=>{
				this.content = res.data.member_explain.content;
			}).catch((err) => {
				this.$util.Tips({
					title: err.msg
				});
			});
		}
	}
};
</script>

<style scoped lang="scss">
page {
	background-color: #fff;
}
.content {
	padding: 15px 30rpx 40rpx 30rpx;
}
.sys-head {
	position: fixed;
	width: 100%;
	background-color: #fff;
	top: 0;
	left: 0;
	z-index: 9;
	.sys-title {
		height: 43px;
		line-height: 43px;
		text-align: center;
		position: relative;
		.iconfont {
			position: absolute;
			left: 20rpx;
		}
	}
}
</style>