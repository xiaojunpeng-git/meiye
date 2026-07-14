<template>
	<view>
		<view class="px-20 mt-20">
			<view class="bg--w111-fff rd-24rpx card-bg">
				<view class="h-244 pt-72">
					<view class="px-122 flex-between-center">
						<text class="iconfont icon-a-ic_CompleteSelect font-red fs-30"></text>
						<view class="line bg-red mx-8"></view>
						<text class="iconfont icon-a-ic_CompleteSelect font-red fs-30"></text>
						<view class="line mx-8" :class="type > 0 ? 'bg-red' : 'bg-gray'"></view>
						<text class="iconfont fs-30" :class="type > 0 ? 'icon-a-ic_CompleteSelect font-red' : 'icon-ic_unselect text--w111-ccc'"></text>
					</view>
					<view class="flex-between-center px-74 fs-28 lh-40rpx mt-24">
						<text>提交成功</text>
						<text>正在审核</text>
						<text>审核结果</text>
					</view>
					<view class="flex-between-center px-40 mt-8 fs-22 text--w111-999">
						<text>{{add_time}}</text>
						<text>{{add_time}}</text>
						<text v-if="status_time">{{status_time}}</text>
						<text v-else class="w-180"></text>
					</view>
				</view>
				<view class="flex-col flex-center content-box">
					<image :src="imgHost + '/statics/images/supplier/verify_fail_icon.png'" class="status-pic" v-if="type == 2"></image>
					<image :src="imgHost + '/statics/images/supplier/verify_ing_icon.png'" class="status-pic" v-else-if="type == 0"></image>
					<image :src="imgHost + '/statics/images/supplier/verify_ok_icon.png'" class="status-pic" v-else-if="type == 1"></image>
					<text class="fs-36 lh-50rpx pt-32">{{type | typeFilter}}</text>
					<text class="fs-26 lh-36rpx text--w111-999 pt-12">{{type | descFilter}}</text>
					<view class="primary-btn flex-center fs-28 mt-40"  v-if="type != 2" @tap="pageBack">返回上一页</view>
					<view class="primary-btn flex-center fs-28 mt-40" v-if="type == 2" @tap="edit">重新填写</view>
					<view class="border-btn flex-center fs-28 mt-24" v-if="type == 2" @tap="pageBack">返回上一页</view>
				</view>
			</view>
			<view class="bg--w111-fff rd-24rpx mt-20 pt-40 pr-24 pb-40 pl-24 fs-28 lh-40rpx" v-if="type == 2">
				<view class="fs-28 text--w111-333">拒绝原因</view>
				<view class="fs-26 text--w111-666 mt-12">{{fail_msg}}</view>
			</view>
			<view class="bg--w111-fff rd-24rpx mt-20 pt-40 pr-24 pb-40 pl-24 fs-28 lh-40rpx" v-if="type == 1">
				<view class="flex-between-center">
					<text>后台网址</text>
					<text>{{url}}</text>
				</view>
				<view class="flex-between-center mt-40">
					<text>后台账号</text>
					<text>{{account}}</text>
				</view>
				<view class="flex-between-center mt-40">
					<text>登录密码</text>
					<view class="flex-y-center">
						<text class="pr-12">{{pwd}}</text>
						<view class="copy-btn flex-center fs-20" @tap="copyWb">复制</view>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>
<script>
	import { HTTP_REQUEST_URL } from '@/config/app';
	import colors from '@/mixins/color.js';
	import { storeApply } from '@/api/user.js';
	export default{
		mixins: [colors],
		data(){
			return{
			   imgHost:HTTP_REQUEST_URL,
			   id:0,
			   type:2,
			   url:'',
			   account:'',
			   pwd:'',
			   status_time:'',
			   add_time:'',
			   fail_msg:''
			}
		},
		filters:{
			typeFilter(val){
				let obj = {
					0: '正在审核',
					1: '审核通过',
					2: '审核失败'
				};
				return obj[val]
			},
			descFilter(val){
				let obj = {
					0: '正在审核当中，请耐心等待',
					1: '恭喜您，审核通过',
					2: '请按提示修改您所填写的信息'
				};
				return obj[val]
			}
		},
		onLoad(options){
			this.id = options.id;
			this.type = options.type || 0;
			if(options.id){
				this.supplierApply();
			}
		},
		methods:{
			copyWb: function() {
				let that = this;
				uni.setClipboardData({
					data: '网址:'+this.url+'\n账号:'+this.account+'\n密码:'+this.pwd
				});
			},
			supplierApply(){
				storeApply(this.id).then(res=>{
					let data = res.data;
					this.url = data.url;
					this.add_time = data.add_time;
					this.status_time = data.status_time;
					this.account = data.account;
					this.type = data.status;
					this.pwd = data.pwd;
					this.fail_msg = data.fail_msg;
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			edit(){
				uni.reLaunch({
					url: '/pages/users/franchisees/index?id=' + this.id
				})
			},
			pageBack(){
				if(this.type == 1 || this.type == 2){
					uni.navigateBack({
						delta:1
					})
				}else{
					uni.reLaunch({
						url: '/pages/users/franchisees/index'
					})
				}
			},
		}
	}
</script>
<style scoped>
	.card-bg{
		-webkit-mask: radial-gradient(circle at 16rpx 240rpx, transparent 16rpx, red 0) -16rpx;
	}
	.font-red{
		background: linear-gradient( 270deg, #FF7931 0%, #E93323 100%);
		-webkit-background-clip: text;
		-webkit-text-fill-color: transparent;
	}
	.h-244{
		height:244rpx;
		border-bottom: 1px dashed #ccc;
	}
	.px-74{
		padding: 0 74rpx;
	}
	.px-122{
		padding: 0 122rpx;
	}
	.pt-72{
		padding-top: 72rpx;
	}
	.line{
		width: 186rpx;
		height: 1rpx;

	}
	.bg-red{
		background-color: #e93323;
	}
	.bg-gray{
		background-color: #DDDDDD;
	}
	.status-pic{
		width: 172rpx;
		height: 154rpx;
	}
	.content-box{
		padding: 48rpx 0 72rpx;
	}
	.primary-btn{
		width: 502rpx;
		height: 88rpx;
		background: linear-gradient(270deg, #FF7931 0%, #E93323 100%);
		color: #fff;
		border-radius: 50rpx;
	}
	.border-btn{
		width: 502rpx;
		height: 88rpx;
		background: #fff;
		border: 1px solid #E93323;
		color: #E93323;
		border-radius: 50rpx;
	}
	.copy-btn{
		width: 64rpx;
		height: 32rpx;
		background: #F5F5F5;
		border-radius: 20rpx;
	}
</style>