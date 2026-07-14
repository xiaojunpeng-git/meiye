<template>
	<view :style="colorStyle">
		<view class="gradient-box">
			<view class="flex-between-center">
				<view class="search-box flex-y-center pl-24 pr-20">
					<text class="iconfont icon-ic_search fs-32 text--w111-999"></text>
					<input type="text" v-model="params.keyword" placeholder="请输入关键词" class="fs-26 ml-16 flex-1" />
					<text class="iconfont icon-ic_close2 fs-28 text--w111-999 pl-20" v-if="params.keyword" @tap="clearWord"></text>
				</view>
				<text class="fs-26 pl-24" @tap="getSearch">搜索</text>
			</view>
			<!-- <view class="flex-y-center">
				<scroll-view scroll-x="true"
				scroll-with-animation
				:scroll-into-view="intoindex"
				class="white-nowrap vertical-middle w-676 pl-30"
				show-scrollbar="false">
					<view class="inline-block mr-52">
						<view class="flex-y-center h-100 fs-28"
							:class="{'cate-active': cateActive == ''}"
							@tap="changeCate(-1,'')">全部</view>
					</view>
					<view class="inline-block mr-52" v-for="(item,index) in topicList" :key="index"
						:id='"sort"+index'>
						<view class="flex-y-center h-100 fs-28"
							:class="{'cate-active': cateActive == item.id}"
							@tap="changeCate(index,item.id)">{{item.name}}</view>
					</view>
				</scroll-view>
			</view> -->
		</view>
		<view class="px-20">
			<waterfallsFlow :wfList="contentList" @onFlowLike="flowLike"></waterfallsFlow>
		</view>
		<view class="px-20" v-if="contentList.length == 0 && !loading">
			<emptyPage
				:title="params.keyword ? '无搜索结果,换个词试试吧' : '暂无内容，去看点别的吧～'"
				:src="params.keyword ? '/statics/images/noSearch.gif' : '/statics/images/empty-box.gif'"
			></emptyPage>
		</view>
		<!-- <home></home> -->
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import { communityListApi, getTopicApi } from "@/api/community.js";
	import waterfallsFlow from "../components/discoverWaterfall/WaterfallsFlow.vue";
	import emptyPage from '@/components/emptyPage.vue';
	export default {
		data() {
			return {
				keyword:'',
				cateActive:'',
				topicList:[],
				contentList:[],
				loading:false,
				intoindex:'',
				params:{
					page:1,
					limit:20,
					topic_id:'', //话题
					keyword:'',
					is_interest:0, //是否关注 
				},
			};
		},
		components:{
			waterfallsFlow, 
			emptyPage, 
		},
		mixins: [colors],
		provide() {
			return {
				flowLike: this.flowLike
			}
		},
		onPageScroll(object) {
			uni.$emit('scroll');
		},
		onLoad() {
			this.getTopic();
			this.getList();
		},
		methods:{
			clearWord(){
				this.params.keyword = ''
			},
			changeCate(index,id){
				this.cateActive = id;
				this.$nextTick(()=>{
					this.intoindex = 'sort' + index;
				})
				this.params.page = 1;
				this.params.topic_id = id;
				this.contentList = [];
				this.loading = false;
				this.getList();
			},
			getSearch(){
				this.params.page = 1;
				this.contentList = [];
				this.loading = false;
				this.getList();
			},
			getTopic(){
				getTopicApi().then(res=>{
					this.topicList = res.data;
				})
			},
			getList(){
				if (this.loading) return;
				this.loading = true;
				communityListApi(this.params).then(res=>{
					let list = res.data;
					let loading = list.length < this.params.limit;
					this.contentList = this.contentList.concat(list);
					this.params.page++;
					this.loading = false;
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
	width: 750rpx;
	// height: 182rpx;
	background: linear-gradient( 180deg, #FFFFFF 0%, #F5F5F5 100%);
	padding: 20rpx 30rpx;
	.search-box{
		width: 614rpx;
		height: 64rpx;
		background: #F3F3F3;
		border-radius: 32rpx;
	}
}
.cate-active{
	font-weight: 500;
	font-size: 34rpx;
	color: var(--view-theme);
	position: relative;
	&:after{
		content: '';
		position: absolute;
		left: 50%;
		transform: translateX(-50%);
		bottom: 14rpx;
		width: 64rpx;
		height: 5rpx;
		background: var(--view-theme);
		border-radius: 4rpx;
	}
}
</style>
