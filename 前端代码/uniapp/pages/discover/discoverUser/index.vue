<template>
	<view :style="colorStyle">
		<NavBar
			titleText="个人主页"
			textSize="34rpx"
			iconWeight="400"
			:placeholder="false"
			:isScrolling="pageScrollStatus"
			:iconColor="pageScrollStatus ? '#333333' : '#ffffff'"
			:textColor="pageScrollStatus ? '#333333' : '#ffffff'"
			:showBack="pageType != 'tab'"
			:showHome="pageType == 'tab'"
			></NavBar>
		<view class="info-card w-full relative" >
			<view class="blur-bg w-full h-full abs-lt" :style="[headerStyle]"></view>
			<view class="w-full h-full content" :style="[cardStyle]">
				<view class="info">
					<view class="flex-between-center">
					 	<view class="flex-y-center">
							<view class="w-140 h-140 rd-50-p111- relative" :class="userInfo.vip_status ? 'svip-border' : 'white-border'">
								<image class="w-full h-full rd-50-p111- block" :src="userInfo.author_image" mode="aspectFill"></image>
								<image class="vip-badge" src="../static/svip_badge.png" v-if="userInfo.vip_status"></image>
							</view>
					 		<view class="pl-28 text--w111-fff">
					 			<view class="w-260 flex-y-center flex-wrap">
									<text class="fs-32 fw-500">{{userInfo.author}}</text>
									<view class="vip flex-center" v-show="userInfo.level_name">
										<text class="iconfont icon-huiyuandengji"></text>
										V{{userInfo.level_name}}
									</view>
								</view>
					 			<view class="fs-24 pt-22">ID:{{userInfo.relation_id}}</view>
					 		</view>
					 	</view>
						<view class="flex-y-center">
							<view class="w-154 h-58 rd-30rpx flex-center fs-24 text--w111-fff bg-white-01"
								v-if="userInfo.is_self" @tap="authTo('/pages/discover/discoverCreate/index')">
								<text class="iconfont icon-ic_edit fs-26"></text>
								<text class="pl-8">去发布</text>
								
							</view>
							<view v-else class="h-58 pr-22 pl-22 rd-30rpx flex-center fs-24 text--w111-fff border-FFFFFF" 
								@tap="followChange">
								<text v-show="!userInfo.is_follow" class="iconfont icon-ic_increase fs-24 mr-8"></text>
								<text>{{userInfo.is_follow ? '已关注' : '关注'}}</text>
							</view>
							<!-- <view class="w-58 h-58 rd-50-p111- ml-12 text--w111-fff bg-white-01 flex-center" 
								:class="{'msg-icon': is_view == 0}" v-if="userInfo.is_self" @tap="authTo('/pages/discover/discoverMessage/index')">
								<text class="iconfont icon-ic_message2 w-32"></text>
							</view> -->
						</view>
					 </view>
					 <view class="fs-26 text--w111-fff lh-40rpx space-line user-desc mt-20">
						<text v-show="userInfo.desc">{{userInfo.desc}} </text>
						<text v-show="!userInfo.desc">此人很神秘，什么也没有留下～</text>
						<text class="iconfont icon-ic_edit fs-26" v-if="userInfo.is_self" @tap="toggleModal"></text>
					 </view>
					 <view class="mt-48 px-28 flex-between-center text--w111-fff">
						<view class="text-center" v-for="(item,index) in infoList" :key="index"
							@tap="userTo('/pages/discover/discoverFollow/index?type=' + index)">
							<view class="fs-30">{{item.count}}</view>
							<view class="pt-10 fs-24">{{item.name}}</view>
						 </view>
					 </view>
					 <view class="h-56"></view>
				</view>
			</view>
		</view>
		<view class="list-box">
			<view class="list-tab flex-between-center relative" :class="showSearch ? 'pt-28' : 'pt-42'" v-if="userInfo.is_self">
				<text v-show="!showSearch"></text>
				<view class="flex-y-center fs-34 text--w111-999 center-tabs" v-show="!showSearch">
					<view :class="{'active-tab': active == 0}" @tap="changeTab(0)">作品</view>
					<view class="ml-66" :class="{'active-tab': active == 1}" @tap="changeTab(1)">赞过</view>
				</view>
				<view class="w-608 h-58 rd-30rpx bg--w111-f1f1f1 flex-y-center ml-12 pl-20" v-show="showSearch">
					<text class="iconfont icon-ic_search fs-30 text--w111-999"></text>
					<input type="text" v-model="params.keyword" placeholder="搜索笔记/用户/商品" @confirm="searchSubmit" 
						class="flex-1 pl-14 fs-24" />
				</view>
				<text v-show="!showSearch && !params.keyword" class="iconfont icon-ic_search fs-40 pr-10" @tap="openSearch"></text>
				<text v-show="showSearch && params.keyword" class="fs-26 text--w111-999 pr-10" @tap="searchSubmit">搜索</text>
				<text v-show="showSearch && !params.keyword" class="fs-26 text--w111-999 pr-10" @tap="reset">取消</text>
			</view>
			<view class="list-tab" v-else></view>
			<view class="pt-20 pl-20 pr-20 bg--w111-f5f5f5" v-show="contentList.length">
				<waterfallsFlow :wfList="contentList" isSelf @onFlowLike="flowLike"></waterfallsFlow>
			</view>
			<view class="px-20 bg--w111-f5f5f5" v-show="!contentList.length">
				<emptyPage title="暂无内容～"></emptyPage>
			</view>
		</view>
		<pageFooter v-if="pageType == 'tab'" :tab="5"></pageFooter>
		<!-- 确认框 -->
		<tui-modal :show="showModal" maskClosable custom @cancel="toggleModal">
			<view class="tui-modal-custom">
				<view class="fs-32 fw-500 lh-44rpx text-center">编辑简介</view>
				<view class="mt-24 bg--w111-f5f5f5 rd-16rpx p-30rpx h-342" @tap="openFocus">
					<textarea class="w-full fs-26" 
					ref="myTextarea"
					v-model="userInfo.desc" 
					auto-height
					:focus="focus"
					wrap-style="wrap"
					:always-embed="true"
					:adjust-position="true"
					cursor-spacing="85rpx"
					:maxlength="50"
					name="desc" />
				</view>
				<view class="flex-between-center mt-40">
					<view class="w-244 h-72 rd-36rpx flex-center fs-26 font-num close-btn" @tap="toggleModal">取消</view>
					<view class="w-244 h-72 rd-36rpx flex-center bg-color text--w111-fff fs-26" @tap="saveDesc">保存</view>
				</view>
			</view>
		</tui-modal>
		<!-- 确认框 -->
		<tuiModal
			:show="showFollow"
			title="确认取消关注"
			:maskClosable="false"
			@click="handleClick"></tuiModal>
		<!-- <home></home> -->
	</view>
</template>

<script>
	let sysHeight = uni.getWindowInfo().statusBarHeight;
	import colors from "@/mixins/color";
	import emptyPage from '@/components/emptyPage.vue';
	import NavBar from "@/components/NavBar.vue";
	import waterfallsFlow from "../components/discoverWaterfall/WaterfallsFlow.vue";
	import pageFooter from "../components/pageFooter/index.vue";
	import tuiModal from "@/components/tui-modal/index.vue";
	import { 
		communityListApi, 
		communityUserInfoApi, 
		communityUpdateDescApi,
		communitySetInsterestApi,
		communityLikeListApi
	} from "@/api/community.js"
	import {serviceRecord} from '@/api/user.js';
	import { mapGetters } from 'vuex';
	import { toLogin } from '@/libs/login.js';
	export default {
		data() {
			return {
				is_store: 0,
				sysHeight,
				pageScrollStatus:false,
				id:0,
				params:{
					page:1,
					limit:20,
					topic_id:'', //话题
					keyword:'',
					is_interest:0, //是否关注 
					relation_id:'',
				},
				infoList:[
					{name:'关注',count:'0'},
					{name:'好友',count:'0'},
					{name:'粉丝',count:'0'},
					{name:'获赞',count:'0'},
				],
				active:0,
				contentList:[],
				userInfo:{},
				showSearch:false,
				showModal:false,
				showFollow:false,
				focus:false,
				is_view: 1,
				pageType: '',
			};
		},
		mixins: [colors],
		components:{ emptyPage, NavBar, waterfallsFlow, tuiModal, pageFooter },
		computed:{
			...mapGetters(['isLogin']),
			headerStyle(){
				if (!this.userInfo.author_image) {
					return {};
				}
				return {
					'background-image':`url(${this.userInfo.author_image})`
				}
			},
			cardStyle(){
				return {
					'padding-top':40 + this.sysHeight + 'px',
				}
			}
		},
		provide() {
			return {
				flowLike: this.flowLike
			}
		},
		onPageScroll(object) {
			if (object.scrollTop > 130) {
				this.pageScrollStatus = true;
			} else if (object.scrollTop < 130) {
				this.pageScrollStatus = false;
			}
			uni.$emit('scroll');
		},
		onReachBottom() {
			this.getList();
		},
		onLoad(options) {
			this.pageType = options.type || this.pageType;
			this.id = options.id;
			this.is_store = options.is_store || 0;
		},
		onShow() {
			this.getUserInfo();
		},
		methods:{
			openFocus(){
				this.focus = true;
			},
			changeTab(val){
				if(this.active == val) return
				this.active = val;
				this.loading = false;
				this.contentList = [];
				this.params.page = 1;
				val == 1 ? this.getLike() : this.getList()
			},
			getList(){
				if (this.loading) return;
				this.loading = true;
				this.$set(this.params,'relation_id',this.id);
				this.$set(this.params,'type',this.userInfo.type);
				communityListApi(this.params).then(res=>{
					let list = res.data;
					let loading = list.length < this.params.limit;
					this.contentList = this.contentList.concat(list);
					this.params.page++;
					this.loading = loading;
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			getLike(){
				if (this.loading) return;
				this.loading = true;
				communityLikeListApi().then(res=>{
					let list = res.data.list;
					let loading = list.length < this.params.limit;
					this.contentList = this.contentList.concat(list);
					this.params.page++;
					this.loading = loading;
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			getUserInfo(){
				communityUserInfoApi(this.id,{is_store:this.is_store}).then(res=>{
					this.userInfo = res.data;
					this.infoList[0].count = res.data.follow_num;
					this.infoList[1].count = res.data.friend_count;
					this.infoList[2].count = res.data.fans_num;
					this.infoList[3].count = res.data.like_num;
					serviceRecord({page: 1,limit: 1}).then(res => {
						this.is_view = res.data.community.is_viewed;	
					})
					this.getList();
				}).catch(err => {
					return this.$util.Tips({
						title: err
					},{
						tab: 3
					});
				});
			},
			userTo(url){
				if(this.userInfo.is_self){
					if(url == '/pages/discover/discoverFollow/index?type=3') return
					this.authTo(url);
				}
			},
			authTo(url){
				uni.navigateTo({
					url
				})
			},
			openSearch(){
				this.showSearch = !this.showSearch;
				if(!this.showSearch && this.params.keyword){
					this.params.keyword = '';
					this.params.page = 1;
					this.contentList = [];
					this.loading = false;
					this.getList();
				}
			},
			searchSubmit(){
				this.params.page = 1;
				this.contentList = [];
				this.loading = false;
				this.getList();
			},
			reset(){
				this.params.keyword = '';
				this.showSearch = false;
				this.searchSubmit();
			},
			toggleModal(){
				// this.showModal = !this.showModal;
				uni.navigateTo({
					url: `/pages/discover/discoverDesc/index?id=${this.id}&is_store=${this.is_store}`
				});
			},
			saveDesc(){
				if(this.userInfo.desc == '') return this.$util.Tips({
					title: '请输入介绍'
				}); 
				communityUpdateDescApi({desc:this.userInfo.desc}).then(res=>{
					this.showModal = false;
					this.getUserInfo();
					uni.showToast({
						title:res.msg,
						icon:'none'
					})
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			followChange(){
				if(this.userInfo.is_follow == 0){
					communitySetInsterestApi(this.id,{status:1,is_store:this.userInfo.type}).then(res=>{
						this.userInfo.is_follow = 1;
						return this.$util.Tips({
							title: res.msg
						});
					}).catch(err => {
						return this.$util.Tips({
							title: err
						});
					});
				}else{
					this.showFollow = true;
				}
			},
			handleClick(e){
				let index = e.index;
				let that = this;
				if(index == 1){
					communitySetInsterestApi(this.id,{status:0,is_store:this.userInfo.type}).then(res=>{
						this.userInfo.is_follow = 0;
						this.showFollow = false;
						return this.$util.Tips({
							title: res.msg
						});
						
					}).catch(err => {
						this.showFollow = false;
						return this.$util.Tips({
							title: err
						});
					});
				}else{
					this.showFollow = false;
				}
			},
			flowLike(data){
				let index = this.contentList.findIndex(item=> data.id == item.id);
				this.contentList[index].is_like = data.status;
				if(data.status == 1){
					this.contentList[index].like_num++;
				}else{
					this.contentList[index].like_num--;
				}
			},
		}
	}
</script>

<style lang="scss">
.info-card{
	background-size: cover;
}
.blur-bg{
	filter: blur(50rpx);
	background-size: cover;
}
.content{
	position: relative; /* 设置为相对定位 */
	z-index: 2; /* 设置更高的层级以覆盖背景 */
	filter: blur(0);
	background: linear-gradient(180deg, rgba(0, 0, 0, 0.5) 0%, rgba(0, 0, 0, 0.1) 39%, rgba(0, 0, 0, 0.7) 100%);
}
.info{
	padding: 38rpx 34rpx 0 30rpx;
}
.bg-white-01{
background-color:rgba(255, 255, 255, 0.1);
}
.bg--w111-f1f1f1{
	background-color: #f1f1f1;
}
.border-white{
	border: 1rpx solid #fff;
}
.list-box{
	position: relative;
	left: 0;
	top: -24rpx;
	border-radius: 24rpx 24rpx 0 0;
	z-index: 90;
}
.list-tab{
	background: linear-gradient( 180deg, #FFFFFF 0%, #F5F5F5 100%);
	padding-left: 20rpx;
	padding-right: 20rpx;
	padding-bottom: 24rpx;
	border-radius: 24rpx 24rpx 0 0;
}
.center-tabs{
	position: absolute;
	top: 38rpx;
	left: 50%;
	transform: translateX(-50%);
}
.active-tab{
	font-weight: 500;
	color: #333;
	position: relative;
	&:after{
		content: '';
		position: absolute;
		left: 0;
		bottom: -14rpx;
		width: 64rpx;
		height: 5rpx;
		background: var(--view-theme);
		border-radius: 4rpx;
	}
}
.vip{
	width: 64rpx;
	height: 26rpx;
	background: #FEF0D9;
	border: 1px solid #FACC7D;
	border-radius: 50rpx;
	font-size: 18rpx;
	font-weight: 500;
	color: #DFA541;
	margin-left: 10rpx;
	.iconfont {
		font-size: 16rpx;
		margin-right: 4rpx;
	}
}
.vip-badge{
	position: absolute;
	top: -20rpx;
	right: 0;
	width: 48rpx;
	height: 46rpx;
}
.svip-border{
	border: 2rpx solid #F1BB0D;
}
.tui-modal-custom{
	height: 530rpx;
	.h-342{
		height: 342rpx;
	}
}
.white-border{
	border: 2rpx solid #FFFFFF;
}
.modal-btn{
	width: 244rpx;
	height: 72rpx;
	border-radius: 36rpx;
} 
.close-btn{
	border: 1rpx solid var(--view-theme);
}
.confirm-btn{
	background-color: var(--view-theme);
}
.msg-icon{
	position: relative;
	&:after{
		content: '';
		position: absolute;
		right: 8rpx;
		top: 10rpx;
		width: 12rpx;
		height: 12rpx;
		border-radius: 6rpx;
		background-color: #e93323;
	}
}
.user-desc{
	max-height: 200rpx;
	overflow-y: scroll;
}
</style>
