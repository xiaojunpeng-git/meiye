<template>
	<view :style="colorStyle">
		<view class="w-full abs-lt gradient-box"></view>
		<view class="topic-box relative z-10">
			<view class="flex-between-center">
				<view>
					<view class="fs-34 fw-500">
						<text class="iconfont icon-ic_talk_2 fs-36"></text> 
						<text class="pl-8">{{topicName}}</text>
					</view>
					<view class='fs-24 lh-36rpx pt-20 text--w111-666'>{{count}}篇内容</view>
				</view>
				<view class="w-162 h-60 rd-30rpx flex-center fs-24 text--w111-3d b-e" @tap="createTo()">
					<text class="iconfont icon-ic_edit fs-24"></text>
					<text class="pl-8">去发布</text>
				</view>
			</view>
			<view class="pb-26rpx bb-e"></view>
		</view>
		<view class="nav-box w-full h-100 pl-30 fs-30 text--w111-999 flex-y-center" :style="[navStyle]">
			<text :class="{'tab-active': tabActive == 0}" @tap="changeTab(0)">最热</text>
			<text class="ml-52" :class="{'tab-active': tabActive == 1}" @tap="changeTab(1)">最新</text>
		</view>
		<view class="px-20">
			<waterfallsFlow :wfList="contentList" @onFlowLike="flowLike"></waterfallsFlow>
		</view>
		<home></home>
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import { communityListApi, getTopicApi, communityTopicCountApi } from "@/api/community.js";
	import emptyPage from '@/components/emptyPage.vue';
	import waterfallsFlow from "../components/discoverWaterfall/WaterfallsFlow.vue";
	export default {
		data() {
			return {
				tabActive:0,
				scrollStatus:false,
				contentList:[],
				loading:false,
				params:{
					page:1,
					limit:20,
					topic_id:'', //话题
					keyword:'',
					is_interest:0, //是否关注
					order:2
				},
				topicName:'',
				count:1
			};
		},
		components:{ emptyPage, waterfallsFlow },
		mixins: [colors],
		computed:{
			navStyle(){
				return {
					backgroundColor: this.scrollStatus ? '#fff' : 'transparent'
				}
			}
		},
		provide() {
			return {
				flowLike: this.flowLike
			}
		},
		onPageScroll(object) {
			if (object.scrollTop > 50) {
				this.scrollStatus = true;
			} else if (object.scrollTop < 50) {
				this.scrollStatus = false;
			}
			uni.$emit('scroll');
		},
		onLoad(options) {
			this.params.topic_id = options.id || '';
			this.topicName = options.name || '';
			this.getList();
			this.getCount();
		},
		methods:{
			createTo(){
				uni.navigateTo({
					url: '/pages/discover/discoverCreate/index?topic_name=' + this.topicName
				})
			},
			changeTab(val){
				this.tabActive = val;
				if(val == 0){
					this.params.order = 2;
				}else{
					this.params.order = 1;
				}
				this.loading = false;
				this.params.page = 1;
				this.contentList = [];
				this.getList();
			},
			getList(){
				if (this.loading) return;
				this.loading = true;
				communityListApi(this.params).then(res=>{
					let list = res.data;
					let loading = list.length < this.params.limit;
					this.contentList = this.contentList.concat(list);
					this.params.page++;
					this.loading = loading;
				})
			},
			getCount(){
				communityTopicCountApi(this.params.topic_id).then(res=>{
					this.count = res.data.count;
				})
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
		},
		onReachBottom() {
			this.getList()
		}
	}
</script>

<style lang="scss">
.gradient-box{
	height: 260rpx;
	background: linear-gradient( 180deg, #FFFFFF 0%, #F5F5F5 100%);
	z-index: 2;
}
.topic-box{
	padding: 42rpx 32rpx 0;
}
.nav-box{
	position: sticky;
	left: 0;
	top: 0;
	z-index: 10;
}
.b-e{
	border: 1rpx solid #eee;
}
.bb-e{
	border-bottom: 1rpx solid #eee;
}
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
		width: 40rpx;
		height: 5rpx;
		background: var(--view-theme);
		border-radius: 4rpx;
	}
}
</style>
