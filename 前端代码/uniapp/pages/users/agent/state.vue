<template>
	<view>
		<view class="px-20 mt-40">
			<view class="bg--w111-fff rd-24rpx card-bg">
				<view class="h-244 pt-72">
					<view class="px-122 flex-between-center">
						<text class="iconfont icon-a-ic_CompleteSelect font-red fs-28"></text>
						<view class="line bg-red mx-8"></view>
						<text class="iconfont icon-a-ic_CompleteSelect font-red fs-28"></text>
						<view class="line mx-8" :class="type > 0 ? 'bg-red' : 'bg-gray'"></view>
						<text class="iconfont fs-28" :class="type > 0 ? 'icon-a-ic_CompleteSelect font-red' : 'icon-ic_unselect text--w111-ccc'"></text>
					</view>
					<view class="flex-between-center px-74 fs-28 lh-40rpx mt-24">
						<text>提交成功</text>
						<text>正在审核</text>
						<text>审核结果</text>
					</view>
					<view class="flex-between-center px-40 mt-8 fs-22 text--w111-999">
						<text>{{ add_time }}</text>
						<text>{{ add_time }}</text>
						<text v-if="status_time">{{ status_time }}</text>
						<text v-else class="w-180"></text>
					</view>
				</view>
				<view class="flex-col flex-center content-box" v-if="!loading">
					<image :src="imgHost + '/statics/images/supplier/verify_fail_icon.png'" class="status-pic" v-if="type == 2"></image>
					<image :src="imgHost + '/statics/images/supplier/verify_ing_icon.png'" class="status-pic" v-else-if="type == 0"></image>
					<image :src="imgHost + '/statics/images/supplier/verify_ok_icon.png'" class="status-pic" v-else-if="type == 1"></image>
					<text class="fs-36 lh-50rpx pt-32">{{ type | typeFilter }}</text>
					<text class="fs-26 lh-36rpx text--w111-999 pt-12">{{ type | descFilter }}</text>
					<text class="fs-26 lh-36rpx text--w111-999 pt-12 pl-24 pr-24" v-if="refusal_reason">{{ refusal_reason }}</text>
					<view class="primary-btn flex-center fs-28 mt-40" @tap="edit">{{ type == 2 ? '重新填写' : '返回上一页' }}</view>
					<view class="border-btn flex-center fs-28 mt-24" v-if="type == 2" @tap="pageBack">返回上一页</view>
				</view>
			</view>
		</view>
	</view>
</template>
<script>
import { HTTP_REQUEST_URL } from '@/config/app';
import colors from '@/mixins/color.js';
import { userPromoterInfo } from '@/api/user.js';
export default {
	mixins: [colors],
	data() {
		return {
			imgHost: HTTP_REQUEST_URL,
			id: 0,
			type: 2,
			status_time: '',
			add_time: '',
			refusal_reason: '',
			loading: true
		};
	},
	filters: {
		typeFilter(val) {
			let obj = {
				0: '正在审核',
				1: '审核通过',
				2: '审核失败'
			};
			return obj[val];
		},
		descFilter(val) {
			let obj = {
				0: '正在审核当中，请耐心等待',
				1: '恭喜您，审核通过',
				2: '请按提示修改您所填写的信息'
			};
			return obj[val];
		}
	},
	onLoad(options) {
		this.id = options.id;
		this.supplierApply();
	},
	methods: {
		supplierApply() {
			this.loading = true;
			userPromoterInfo().then((res) => {
					let data = res.data.user;
					this.type = data.status;
					this.add_time = data.add_time;
					this.status_time = data.status_time;
					this.refusal_reason = data.refusal_reason;
					this.loading = false;
				})
				.catch((err) => {
					return this.$util.Tips({
						title: err
					});
				});
		},
		pageBack() {
			uni.navigateBack();
		},
		edit() {
			if (this.type == 0 || this.type == 1) {
				uni.navigateBack({
					delta: 1
				});
			} else {
				uni.reLaunch({
					url: '/pages/users/distributor/apply?id=' + this.id
				});
			}
		}
	}
};
</script>
<style scoped>
.card-bg {
	-webkit-mask: radial-gradient(circle at 16rpx 240rpx, transparent 16rpx, red 0) -16rpx;
}
.font-red {
	color: #e93323;
}
.h-244 {
	height: 244rpx;
	border-bottom: 1px dashed #ccc;
}
.px-74 {
	padding: 0 74rpx;
}
.px-122 {
	padding: 0 122rpx;
}
.pt-72 {
	padding-top: 72rpx;
}
.line {
	width: 186rpx;
	height: 1rpx;
}
.bg-red {
	background-color: #e93323;
}
.bg-gray {
	background-color: #dddddd;
}
.status-pic {
	width: 172rpx;
	height: 154rpx;
}
.content-box {
	padding: 48rpx 0 72rpx;
}
.primary-btn {
	width: 502rpx;
	height: 88rpx;
	background: linear-gradient(90deg, #ff7931 0%, #e93323 100%);
	color: #fff;
	border-radius: 50rpx;
}
.border-btn {
	width: 502rpx;
	height: 88rpx;
	background: #fff;
	border: 1px solid #e93323;
	color: #e93323;
	border-radius: 50rpx;
}
.copy-btn {
	width: 64rpx;
	height: 32rpx;
	background: #f5f5f5;
	border-radius: 20rpx;
}
</style>
