<template>
	<view>
		<view class="content">
			<jyf-parser :html="content" ref="article" :tag-style="tagStyle"></jyf-parser>
		</view>
	</view>
</template>

<script>
let sysHeight = uni.getSystemInfoSync().statusBarHeight;
import parser from '@/components/jyf-parser/jyf-parser';
import { getUserAgreement } from '@/api/user.js';
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
			sysHeight: sysHeight || 0,
			type: ''
		};
	},
	computed: {
		pageName() {
			let list = {
				privacy: '隐私协议',
				cancel: '注销协议',
				user: '用户协议',
				supplier: '供应商入驻协议'
			};
			uni.setNavigationBarTitle({
				title: list[this.type]
			});
			return list[this.type];
		}
	},
	onLoad(e) {
		this.type = e.type;
		if (e) {
			getUserAgreement(e.type)
				.then((res) => {
					this.content = res.data.content;
				})
				.catch((err) => {
					that.$util.Tips({
						title: err.msg
					});
				});
		} else {
			getUserAgreement('privacy')
				.then((res) => {
					this.content = res.data.content;
				})
				.catch((err) => {
					that.$util.Tips({
						title: err.msg
					});
				});
		}
	},
	mounted() {},
	methods: {
		goBack() {
			uni.navigateBack({
				delta: 1
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
	padding: 0 30rpx 40rpx 30rpx;
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
