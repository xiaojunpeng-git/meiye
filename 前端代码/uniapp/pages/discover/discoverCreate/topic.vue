<template>
	<view :style="colorStyle">
		<view class="px-30">
			<view class="topic-box pt-24 pb-10 bb-e w-full flex-y-center flex-wrap">
				<view class="h-52 px-16 font-num bg-light rd-30rpx flex-center fs-26 mr-22 mb-20"
					v-for="(item, index) in topicSelectedList" :key="index"
					@tap="delTopic(index)">
					<text class="pr-8">#{{item.name}} </text>
					<text class="iconfont icon-ic_close fs-24"></text>
				</view>
				<input type="text" placeholder="新建或搜索话题" class="fs-28 lh-40rpx flex-1 mb-20 min-w-150" 
					:maxlength="10" :focus="focus" v-model.trim="topicKeyword" 
					@input="handleInput" @confirm="confirmCreate" />
			</view>
			<view class="flex-y-center flex-wrap pt-32">
				<view class="h-52 px-16 bg--w111-f5f5f5 rd-30rpx flex-center fs-26 mr-22 mb-20"
					v-for="(item, index) in recommendTopic" :key="index"
					@tap="changeTopic(item)">#{{item.name}}</view>
			</view>
			<view class="w-full fixed-lt bg--w111-fff z-20" :style="[filterStyle]" v-show="showFilter">
				 <view class="relative mt-20 px-30 h-full overflow-scroll">
				 	<view class="h-88 filter-cell flex-between-center"
				 		v-for="(cell,i) in filterTopicList" :key="i"
				 		@tap="changeTopic(cell)">
				 		<text class="fs-28 fw-500 lh-40rpx">
				 			<text>#</text>{{cell.name}}</text>
				 		<text class="text--w111-999 fs-22" v-show="cell.community_count > 0">{{cell.community_count}}篇内容</text>
				 	</view>
					<view class="h-80"></view>
				 </view>
			</view>
			<view class="fixed-lb w-full pb-safe">
				<view class="h-128 flex-center px-30">
					<view class="w-full h-88 rd-44rpx bg-color text--w111-fff flex-center fs-28"
						@tap="saveTopic">完成</view>
				</view>
			</view>
		</view>
	</view>
</template>
<script>
	
let sysHeight = uni.getWindowInfo().statusBarHeight;
import { getTopicApi } from "@/api/community.js"
import colors from '@/mixins/color';
import { Debounce } from '@/utils/validate.js'
export default {
	name: "createTopic",
	data(){
		return {
			sysHeight,
			id: 0,
			topicSelectedList:[],
			topicKeyword:"",
			showFilter: false,
			filterTopicList:[],
			focus: true,
			recommendParams:{
				page:1,
				limit:10,
				is_recommend: 1,
			},
			showMore: false,
			recommendTopic: [],
			boxHeight: 0
		}
	},
	mixins: [colors],
	computed:{
		filterStyle(){
			let windowHeight = uni.getWindowInfo().windowHeight;
			return {
				height: windowHeight - this.boxHeight + 'px',
				top: this.boxHeight + 'px'
			}
		}
	},
	onLoad(options) {
		if(options.data){
			this.topicSelectedList = JSON.parse(options.data)
		}
		this.getTopic();
	},
	methods:{
		getHeight() {
			let that = this;
		    uni.createSelectorQuery().select('.topic-box').boundingClientRect(function(rect) {
		      that.boxHeight = rect.height;
		    }).exec();
		},
		handleInput: Debounce(function(e){
			if(this.topicKeyword){
				getTopicApi({is_community: 1,name: this.topicKeyword}).then(res => {
					this.getHeight();
					this.filterTopicList = res.data;
					this.filterTopicList.unshift({name: this.topicKeyword})
					this.showFilter = true;
				});
			}else{
				this.showFilter = false;
			}
		}),
		getTopic() {
		    getTopicApi(this.recommendParams).then((res) => {
				this.showMore = res.data.length >= this.recommendParams.limit;
				this.recommendTopic = this.recommendTopic.concat(res.data);
				this.recommendParams.page++;
		        // 如果是从话题页面点进来，把url上携带的话题名称塞入已选择话题，并从推荐列表删除该话题
		        // if (this.topicName) {
		        //     this.topicSelectedList.push({name: '#' + this.topicName});
		        //     this.recommendTopic.splice(
		        //         this.recommendTopic.findIndex((itemn) => itemn.name == this.topicName),
		        //         1
		        //     );
		        // }
		        // 如果是编辑内容，添加的话题存在推荐话题中，从推荐话题中删除已添加的
		        let indicesToRemove = [];
		        this.recommendTopic.forEach((item, index) => {
		            this.topicSelectedList.forEach((itemn) => {
		                if (itemn.name.includes(item.name)) {
		                    indicesToRemove.push(index);
		                }
		            });
		        });
		        // 去重并按降序排序，确保删除时不会影响后续索引
		        indicesToRemove = [...new Set(indicesToRemove)].sort((a, b) => b - a);
		        // 删除指定索引的元素
		        indicesToRemove.forEach((index) => {
		            this.recommendTopic.splice(index, 1);
		        });
		    });
		},
		changeTopic(item) {
			this.showFilter = false;
			this.topicKeyword = '';
		    if (this.topicSelectedList.length > 4) return this.$util.Tips({
				title: '最多可选择5个话题'
			});
			this.focus = false;
			let arr = this.topicSelectedList.map(item => item.name);
			if(!arr.includes(item.name)){
				this.topicSelectedList.push(item);
			}
		    this.recommendTopic.splice(this.recommendTopic.findIndex((itemn) => itemn.id == item.id),1);
			this.$nextTick(() => {
			  this.focus = true;
			});
		},
		confirmCreate(){
			if(!this.topicKeyword) return 
			this.changeTopic({name: this.topicKeyword});
		},
		saveTopic(){
			let that = this;
			uni.$emit("topicSave", that.topicSelectedList)
			uni.navigateBack()
		},
		delTopic(index){
			this.topicSelectedList.splice(index,1)
		}
	}
}
</script>
<style>
	page{
		background-color: #ffffff;
	}
</style>
<style lang="scss">
	.bg-light{
		background-color: var(--view-minorColorT);
	}
	.bb-e {
	    border-bottom: 1rpx solid #eee;
	}
	.min-w-150{
		min-width: 150rpx;
	}
	.filter-cell ~ .filter-cell{
		border-top: 1px solid #f5f5f5;
	}
	.overflow-scroll{
		overflow-y: scroll;
	}
</style>