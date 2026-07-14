<script>
import baseDrawer from '@/components/tui-drawer/tui-drawer.vue';
import emptyPage from '@/components/emptyPage.vue';
import tuiModal from "@/components/tui-modal/index.vue"
import { mapGetters } from "vuex";
import { 
	communityReplySaveApi, 
	communityReplyListApi, 
	communityReplyLikeApi,
	communityReplyDeleteApi,
} from "@/api/community.js";
export default {
    props: {
        visible: {
            type: Boolean,
            default: false
        },
		community_id:{
			type: Number | String,
			default: ''
		},
		comment_num:{
			type: Number | String,
			default: 0
		},
        ensureInfo: {
            type: Object,
            default: () => {}
        },
		showFooter:{
			type: Boolean,
			default: false
		},
		addReply:{
			type: Number,
			default: 1
		}
    },
	data(){
		return {
			comment:'',
			comment_reply_id:'',
			replyIndex:'',
			replyList:[],
			placeholder:'快来说点儿什么吧...',
			showModal:false,
			reduceNum:0,
			focus:false,
		}
	},
	watch:{
		community_id(val){
			if(val){
				this.getReply()
			}
		}
	},
    components: {
        baseDrawer,
		emptyPage,
		tuiModal
    },
	computed:{
		...mapGetters(['isLogin','uid']),
	},
    methods: {
        closeDrawer() {
            this.$emit('closeDrawer');
        },
		getReply(){
			let params = {
				community_id:this.community_id,
				reply_id:'',
			};
			communityReplyListApi(params).then(res=>{
				if(res.data.list.length){
					res.data.list.map(item=>{
						this.$set(item,'show',false);
					})
					this.replyList = res.data.list;
				}else{
					this.replyList = [];
				}
			}).catch(err => {
				return this.$util.Tips({
					title: err
				});
			});
		},
		sendText(){
			if(!this.isLogin) return this.$util.Tips({
				title: '请登录'
			});
			if(this.comment == '') return this.$util.Tips({
				title: '请输入评论内容'
			}); 
			let data = {
				community_id:this.community_id,
				comment_reply_id:this.comment_reply_id,
				content:this.comment
			};
			communityReplySaveApi(data).then(res=>{
				this.comment_reply_id = '';
				this.comment = '';
				this.placeholder = '快来说点儿什么吧...';
				this.$emit('onCommentAdd',{type:1,num:1});
				this.getReply();
				this.hide();
				return this.$util.Tips({
					title: res.msg
				}); 
			}).catch(err => {
				return this.$util.Tips({
					title: err
				});
			});
		},
		showReply(id,author){
			this.comment_reply_id = id;
			this.placeholder = `回复：${author}`;
		},
		showMore(item,index,type){
			this.replyIndex = index;
			if(type){
				let params = {
					community_id:this.community_id,
					reply_id:item.id,
				};
				communityReplyListApi(params).then(res=>{
					this.$set(this.replyList[index],'children',res.data.list);
					this.$set(item,'show',true);
					his.$util.Tips({
						title: res.msg
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			}else{
				this.$set(item,'show',false);
			}
		},
		authTo(url){
			uni.navigateTo({
				url: url
			});
		},
		replyLike(item){
			if(!this.isLogin) return this.$util.Tips({
				title: '请登录'
			});
			let id = item.id;
			let status = item.isLike == 1 ? 0 : 1;
			let that = this;
			communityReplyLikeApi(id,{status}).then(res=>{
				item.isLike = status;
				if(status == 1){
					item.like_num++
				}else{
					item.like_num--
				}
				return that.$util.Tips({
					title: res.msg
				});
			}).catch(err=>{
				return that.$util.Tips({
					title: err
				});
			})
		},
		delReply(id, num){
			this.comment_reply_id = id;
			this.reduceNum = num;
			this.showModal = true;
		},
		handleClick(e){
			let index = e.index;
			let that = this;
			if(index == 1){
				communityReplyDeleteApi(this.comment_reply_id).then(res=>{
					this.showModal = false;
					this.replyList = [];
					this.comment_reply_id = '';
					this.$emit('commentAdd',{type:2,num:1 + this.reduceNum});
					this.getReply();
				}).catch(err => {
					this.showModal = false;
					return this.$util.Tips({
						title: err
					});
				});
			}else{
				this.showModal = false;
			}
		},
    }
};
</script>
<template> 
    <view>
        <base-drawer 
			mode="bottom" 
			:visible="visible" 
			zIndex="3000"
			background-color="transparent" 
			mask maskClosable 
			@close="closeDrawer">
            <view class="w-full bg--w111-fff relative rd-t-40rpx pt-32">
                <view class="text-center fs-32 text--w111-333 fw-500 mb-30">评论 {{comment_num}}</view>
				<view class="close-btn flex-center" @tap="closeDrawer()">
					<text class="iconfont icon-ic_close text--w111-666 fs-24"></text>
				</view>
                <scroll-view scroll-y="true" class="scroll-content" v-show="!focus">
                    <view class="px-30">
                    	<view class="reply-list" v-if="replyList.length">
                    		<view class="flex mt-22" v-for="(item,index) in replyList" :key="index"
								v-show="(item.uid == uid && item.is_show == 0) || item.is_show == 1">
                    			<image class="w-74 h-74 rd-50-p111-" :src="item.author_image"
									@tap="authTo(`/pages/discover/discoverUser/index?id=${item.uid}&is_store=${item.type}`)"></image>
                    			<view class="flex-1 ml-20">
                    				<view class="w-full">
                    					<view class="fs-26 text--w111-999">{{item.author}}</view>
                    					<view class="fs-28 lh-42rpx text--w111-333" @tap="showReply(item.id,item.author,item.is_show)">{{item.content}}
											<text class="pl-8 text--w111-999" v-if="!item.is_show && item.uid == uid">(该评论已被屏蔽)</text> 
											<text class="bg-color inline-block h-30 rd-4rpx ml-8 fs-0 overflow" v-else-if="item.is_show && item.uid == uid && !item.is_verify">
												<text class="inline-block lh-30rpx h-30 px-6 fs-18 text-w111-theme bg--w111-fff-s111-80">审核中</text> 
											</text>
										</view>
                    					<view class="lh-36rpx flex-between-center pt-4">
                    						<view class="flex-y-center fs-24">
                    							<text class="text--w111-ccc">{{item.time_text}}</text>
												<text class="pl-24 text--w111-999" @tap="showReply(item.id,item.author,item.is_show)"
												v-show="isLogin">回复</text>
                    							<text class="pl-24 text--w111-999" @tap="delReply(item.id,item.comment_num)" 
                    							v-show="isLogin && uid == item.uid">删除</text>
                    						</view>
                    						<view class="flex-y-center text--w111-999" @tap="replyLike(item)">
                    							<text class="iconfont fs-30" :class="item.isLike ? 'icon-icon_Like_2' : 'icon-ic_Like'"></text>
                    							<text class="fs-24 lh-36rpx pl-10">{{item.like_num}}</text>
                    						</view>
                    					</view>
                    				</view>
                    				<view class="mt-12 w-full" v-if="item.show">
                    					<view class="child-reply flex justify-between" v-for="(reply,i) in item.children" :key="i"
											v-show="(reply.uid == uid && reply.is_show == 0) || reply.is_show == 1">
                    						<view class="flex w-full" >
                    							<image :src="reply.author_image" class="w-36 h-36 rd-50-p111-"
													@tap="authTo(`/pages/discover/discoverUser/index?id=${reply.uid}&is_store=${reply.type}`)"></image>
                    							<view class="ml-20 flex-1">
                    								<view class="text--w111-999 fs-26 lh-40rpx">{{reply.author}}</view>
                    								<view class="w-full fs-26 lh-42 flex-y-center flex-wrap text--w111-333" @tap="showReply(reply.id, reply.author)">
                    									<view v-if="reply.comment_author">
                    										回复<text class="comment-author"
                    										@tap="authTo(`/pages/discover/discoverUser/index?id=${reply.comment_reply_uid}&is_store=${reply.type}`)">@{{reply.comment_author}}</text>
                    									</view>
                    									{{reply.content}}<text class="pl-8 text--w111-999" v-if="!reply.is_show && reply.uid == uid">(该评论已被屏蔽)</text> 
														<text class="bg-color inline-block h-30 rd-4rpx ml-8 fs-0 overflow" v-else-if="reply.is_show && reply.uid == uid && !reply.is_verify">
															<text class="inline-block lh-30rpx h-30 px-6 fs-18 text-w111-theme bg--w111-fff-s111-80">审核中</text> 
														</text>
                    								</view>
                    								<view class="flex-between-center pt-4">
                    									<view class="fs-24 text--w111-ccc lh-36rpx flex-y-center">
                    										<text>{{reply.time_text}}</text>
															<text class="pl-24 text--w111-999" @tap="showReply(reply.id, reply.author)"
																v-show="isLogin">回复</text>
                    										<text class="pl-24 text--w111-999" @tap="delReply(reply.id,reply.comment_num)" 
                    											v-show="isLogin && uid == reply.uid">删除</text>
                    									</view>
                    									<view class="flex-y-center text--w111-999" @tap.stop="replyLike(reply)">
                    										<text class="iconfont fs-30" :class="reply.isLike ? 'icon-icon_Like_2' : 'icon-ic_Like'"></text>
                    										<text class="fs-24 lh-36rpx pl-10">{{reply.like_num}}</text>
                    									</view>
                    								</view>
                    							</view>
                    						</view>
                    					</view>
                    				</view>
                    				<view class="mt-14 flex-y-center text--w111-666 fs-26" 
                    					v-if="item.comment_num > 0 && !item.show" @tap="showMore(item,index,1)">
                    					<text class="more-line"></text>
                    					<text class="pl-16">展开{{item.comment_num}}条回复</text>
                    					<text class="iconfont icon-ic_downarrow fs-28"></text>
                    				</view>
                    				<view class="mt-14 flex-y-center text--w111-666 fs-26"
                    					v-if="item.comment_num > 0 && item.show" @tap="showMore(item,index,0)">
                    					<text class="more-line"></text>
                    					<text class="pl-16">收起回复</text>
                    					<text class="iconfont icon-ic_uparrow fs-28"></text>
                    				</view>
                    			</view>
                    		</view>
                    	</view>
						<view v-else>
							<emptyPage title="暂无评论，快去抢沙发吧~" src="/statics/images/noActivity.gif"></emptyPage>
						</view>
                    </view>
                </scroll-view>
				<view class="h-100" v-if="addReply"></view>
                <view class="fixed-lb w-full pb-safe" :class="{'hide-bar': showFooter}" v-if="addReply">
                   <view class="h-100 px-30 flex-between-center bg--w111-fff">
					   <input type="text" v-model="comment" :placeholder="placeholder" 
					   :focus="focus"
					   placeholder-class="text--w111-999"
						class="flex-1 h-64 rd-32rpx bg--w111-f5f5f5 pl-30 fs-26 text--w111-333" />
					   <view class="w-112 h-64 rd-32rpx bg-gradient flex-center fs-24 text--w111-fff ml-20" @tap="sendText">发送</view>
				   </view>
                </view>
            </view>
        </base-drawer>
		<!-- 确认框 -->
		<tuiModal
			:show="showModal"
			title="确认删除该评论"
			:maskClosable="false"
			@click="handleClick"></tuiModal>
    </view>
</template>
<style>
.scroll-content{
	height: 900rpx;
}
.hide-bar{
	bottom: 100rpx;
}
.mb-54{
	margin-bottom: 54rpx;
}
.child-reply ~ .child-reply{
	margin-top: 22rpx;
}
.more-line{
	width: 40rpx;
	height: 2rpx;
	background: #D8D8D8;
}
.comment-author{
	color: #4A8AC9;
	padding: 0 4rpx;
}
.icon-icon_Like_2{
	color: #e93323;
}
.close-btn{
	position: absolute;
	right: 28rpx;
	top: 28rpx;
	width: 36rpx;
	height: 36rpx;
	border-radius: 50%;
	background-color: #eee;
	
}
</style>
