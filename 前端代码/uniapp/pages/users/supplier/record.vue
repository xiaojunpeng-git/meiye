<template>
	<view>
		<view class="px-20">
			<view class="w-full bg--w111-fff rd-24rpx flex-between-center mt-24 p-32"
				v-for="(item,index) in list" :key="index">
				<view>
					<view class="flex-y-center">
						<text class="fs-30 lh-42rpx max-300 line1">{{item.system_name}}</text>
						<text class="tag tag0 fs-22 ml-16" :class="'tag' + item.status">{{item.status | typeFilter}}</text>
					</view>
					<view class="fs-24 text--w111-999 mt-20">提交时间：{{item.add_time}}</view>
					<view class="fs-24 text--w111-999 mt-20" v-if="item.status==2">原因：{{item.fail_msg}}</view>
				</view>
				<text class="btn info-btn fs-24" @tap="lookUp(item)" v-if="item.status==1">查看</text>
				<text class="btn danger-btn fs-24" @tap="resubmit(item)" v-else-if="item.status==2">重新提交</text>
				<text class="btn info-btn fs-24" @tap="edit(item)" v-else>编辑</text>
			</view>
			<view class="mt-20" v-if="list.length == 0">
				<emptyPage title="暂无申请记录~" src="/statics/images/noOrder.gif"></emptyPage>
			</view>
		</view>
    <home :isHide="homeHide"></home>
	</view>
</template>

<script>
	import { recordList } from '@/api/user.js';
	import colors from '@/mixins/color.js';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home';
	export default{
		mixins: [colors],
		components: {
		   emptyPage,
		   home
		},
		data(){
			return{
				list:[],
        homeHide: false
			}
		},
		filters:{
			typeFilter(val){
				let obj = {
					0: '待审核',
					1: '审核通过',
					2: '审核未通过'
				};
				return obj[val]
			},
		},
		onLoad(){
			this.getList();
		},
    onPageScroll() {
			this.homeHide = true;
		},
		methods:{
			getList(){
				recordList().then(res=>{
					this.list = res.data;
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			edit(item){
				uni.navigateTo({
					url: '/pages/users/supplier/index?id='+ item.id
				})
			},
			resubmit(item){
				uni.navigateTo({
					url: '/pages/users/supplier/state?id='+ item.id+'&type=2'
				})
			},
			lookUp(item){
				uni.navigateTo({
					url: '/pages/users/supplier/state?id='+ item.id+'&type=1'
				})
			}
		}
	}
</script>

<style>
	.max-300{
		max-width: 300rpx;
	}
.tag{
	height: 38rpx;
	padding: 0 8rpx;
	display: inline-flex;
	justify-content: center;
	align-items: center;
	border-radius: 8rpx;
}
.tag0{
	color: rgba(0, 122, 255, 1);
	background-color: rgba(0, 122, 255, 0.1);
}
.tag1{
	color: rgba(0, 180, 42, 1);
	background-color: rgba(0, 180, 42, 0.1);
}
.tag2{
	color: rgba(245, 63, 63, 1);
	background-color: rgba(245, 63, 63, 0.1);
}
.btn{
	height: 56rpx;
	padding: 0 24rpx;
	display: inline-flex;
	justify-content: center;
	align-items: center;
	border-radius: 30rpx;
}
.info-btn{
	border: 1px solid #ccc;
	color: #333;
}
.danger-btn{
	border: 1px solid #e93323;
	color: #e93323;
}
</style>
