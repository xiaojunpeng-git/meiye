<template>
	<view :style="colorStyle">
		<view class="bg--w111-fff">
			<view :style="{'padding-top': sysHeight + 'px'}">
				<view class="flex-between-center h-80 px-20" >
					<text class="iconfont icon-ic_leftarrow fs-40" @tap="backPage"></text>
					<view class="h-80 flex-y-center fs-32 text--w111-999">
						<view :class="{'tab-active': tabActive == 0}" @tap="changeTab(0)">好友</view>
						<view class="ml-48" :class="{'tab-active': tabActive == 1}" @tap="changeTab(1)">关注</view>
						<view class="ml-48" :class="{'tab-active': tabActive == 2}" @tap="changeTab(2)">粉丝</view>
					</view>
					<text class="w-40"></text>
				</view>
			</view>
			<view class="h-180 p-30 flex-between-center" v-for="(item,index) in userList" :key="index">
				<view class="flex-y-center" @tap="authTo(`/pages/discover/discoverUser/index?id=${item.relation_id}&is_store=${item.type}`)">
					<image :src="item.author_image" mode="aspectFill" class="w-120 h-120 rd-50-p111-"></image>
					<view class="pl-20">
						<view class="fs-30">{{item.author}}</view>
						<view class="flex-y-center mt-16 fs-24 text--w111-999">
							<text>内容·{{item.community_num}}</text>
							<text class="pl-30">粉丝·{{item.fans_num}}</text>
						</view>
					</view>
				</view>
				<view class="w-124 h-52 rd-30rpx flex-center bg-color text--w111-fff fs-24" v-show="item.is_follow == 0 && item.is_fans == 0"
					@tap="followChange(item)">关注</view>
				<view class="w-124 h-52 rd-30rpx flex-center bg--w111-fff text--w111-999 b-d fs-24" v-show="item.is_follow == 1 && item.is_fans == 0" 
					@tap="openModal(item,index)">已关注</view>
				<view class="w-124 h-52 rd-30rpx flex-center bg-color text--w111-fff fs-24" v-show="item.is_follow == 0 && item.is_fans == 1"
					@tap="followChange(item)">回关</view>
				<view class="w-124 h-52 rd-30rpx flex-center bg--w111-fff text--w111-999 b-d fs-24" v-show="item.is_follow == 1 && item.is_fans == 1" 
					@tap="openModal(item,index)">互相关注</view>
			</view>
			<view v-show="!userList.length">
				<emptyPage :title="emptyText()" src="/statics/images/video/no_friend.png"></emptyPage>
			</view>
		</view>
		<!-- 确认框 -->
		<tuiModal
			:show="showModal"
			title="确认取消关注"
			:maskClosable="false"
			@click="handleClick"></tuiModal>
		<home></home>
	</view>
</template>

<script>
	let sysHeight = uni.getWindowInfo().statusBarHeight;
	import { communityFlowListApi, communitySetInsterestApi, communityFriendListApi } from "@/api/community.js" ;
	import colors from "@/mixins/color";
	import emptyPage from '@/components/emptyPage.vue';
	import tuiModal from "@/components/tui-modal/index.vue"
	export default {
		data() {
			return {
				sysHeight,
				tabActive:0,
				params:{
					page:1,
					limit:20,
				},
				type:'follow',
				loading:false,
				userList:[],
				showModal:false,
				uid:'',
				index:0
			};
		},
		mixins: [colors],
		components:{ emptyPage, tuiModal },
		computed:{
			menuBoxStyle(){
				let res = wx.getMenuButtonBoundingClientRect();
				return {
					width: res.left + 'px' 
				}
			}
		},
		onLoad(options) {
			if(options.type && options.type == 0){
				this.tabActive = 1;
				this.type = 'follow';
				this.getList()
			}else if(options.type && options.type == 1){
				this.tabActive = 0;
				this.getFriend();
			}else if(options.type && options.type == 2){
				this.tabActive = 2;
				this.type = 'fans';
				this.getList()
			}else{
				this.tabActive = 0;
				this.getFriend();
			}
		},
		methods:{
			changeTab(val){
				if(this.tabActive == val) return
				this.tabActive = val;
				this.loading = false;
				this.userList = [];
				this.params.page = 1;
				if(val == 1){
					this.type = 'follow';
					this.getList()
				}else if(val == 2){
					this.type = 'fans';
					this.getList()
				}else if(val == 0){
					this.getFriend();
				}
			},
			authTo(url){
				uni.navigateTo({
					url
				})
			},
			backPage(){
				uni.navigateBack()
			},
			getList(){
				if (this.loading) return;
				this.loading = true;
				communityFlowListApi(this.type,this.params).then(res=>{
					let list = res.data;
					let loading = list.length < this.params.limit;
					this.userList = this.userList.concat(list);
					this.params.page++;
					this.loading = loading;
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			getFriend(){
				if (this.loading) return;
				this.loading = true;
				communityFriendListApi(this.params).then(res=>{
					let list = res.data;
					let loading = list.length < this.params.limit;
					this.userList = this.userList.concat(list);
					this.params.page++;
					this.loading = loading;
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			openModal(item, index){
				this.uid = item.relation_id;
				this.index = index;
				this.showModal = true;
			},
			handleClick(e){
				let index = e.index;
				let that = this;
				if(index == 1){
					communitySetInsterestApi(this.uid,{status:0,is_store:this.userList[this.index].type}).then(res=>{
						this.userList[this.index].is_follow = 0;
						this.showModal = false;
					}).catch(err => {
						this.showModal = false;
						uni.showToast({
							title:err,
							icon:'none',
							duration:2000
						})
					});
				}else{
					this.showModal = false;
				}
			},
			followChange(item){
				communitySetInsterestApi(item.relation_id,{status:1,is_store:item.type}).then(res=>{
					item.is_follow = 1;
					return this.$util.Tips({
						title: res.msg
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			emptyText(){
				let obj = {
					0:'您还没有好友哦~',
					1:'您还没有关注任何人哦~',
					2:'您还没有粉丝哦~'
				};
				return obj[this.tabActive]
			}
		},
		onReachBottom() {
			this.getList()
		},
		onPageScroll() {
			uni.$emit('scroll');
		}
	}
</script>

<style lang="scss">
.tab-active{
	font-weight: 500;
	color: #333;
	position: relative;
	&:after{
		content: '';
		position: absolute;
		left: 50%;
		transform: translateX(-50%);
		bottom: -14rpx;
		width: 64rpx;
		height: 5rpx;
		background: var(--view-theme);
		border-radius: 4rpx;
	}
}
.b-d{
	border: 1px solid #ddd
}
</style>
