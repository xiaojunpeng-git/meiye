<template>
	<view :style="colorStyle">
		<view>
			<view v-show="[0,-1,-2].includes(infoData.is_verify)">
				<view class="error pt-26 pb-24 pl-32" v-if="infoData.is_verify == -2">
					<view class="flex-y-center fs-28 fw-500">
						<text class="iconfont icon-a-ic_tanhao1 fs-30"></text>
						<text class="pl-16">平台强制下架，内容仅自己可见</text>
					</view>
					<view class="fs-22 text--w111-666 pt-16 pl-46">{{infoData.refusal}}</view>
				</view>
				<view class="error pt-26 pb-24 pl-32" v-if="infoData.is_verify == -1">
					<view class="flex-y-center fs-28 fw-500">
						<text class="iconfont icon-a-ic_tanhao1 fs-30"></text>
						<text class="pl-16">审核未通过，内容仅自己可见</text>
					</view>
					<view class="fs-22 text--w111-666 pt-16 pl-46">{{infoData.refusal}}</view>
				</view>
				<view class="warning pt-26 pb-24 pl-32" v-if="infoData.is_verify == 0">
					<view class="flex-y-center fs-28 fw-500">
						<text class="iconfont icon-icon_clock1 fs-30"></text>
						<text class="pl-16">正在审核，内容仅自己可见</text>
					</view>
					<view class="fs-22 text--w111-666 pt-16 pl-46">发布的内容审核通过后，将在首页展示！</view>
				</view>
			</view>
			<view class="load-box w-full" v-show="!imageH"></view>
			<swiper
				class="swiper"
				circular
				:indicator-dots="infoData.slider_image.length > 1"
				indicator-color="rgba(255, 255, 255, 0.4)"
				indicator-active-color="#f5f5f5"
				autoplay
				:interval="3000"
				:duration="500"
				:style="{height:imageH + 'px'}"
				v-show="imageH">
				<swiper-item v-for="(item,index) in infoData.slider_image" :key="index">
					<view class="swiper-item flex-center">
						<image class="w-full"
							show-menu-by-longpress
							:src="item" 
							:mode="isAuto ?  'aspectFit' : 'heightFix'"
							:style="{height:imageH + 'px'}"
							@tap="proview(item)"></image>
					</view>
				</swiper-item>
			</swiper>
			<view class="px-20">
				<view class="fs-32 lh-42rpx fw-500 pt-24">{{infoData.title}}</view>
				<view class="pt-12 fs-28 lh-42rpx">
					<text>{{infoData.content}}</text>
				</view>
				<navigator v-if="infoData.type == 1 && infoData.relation_id" :url="`/pages/store/home/index?id=${infoData.relation_id}`" hover-class="none" class="flex-y-center h-112 px-20 rd-16rpx mt-16 bg--w111-FAFAFA">
					<image :src="infoData.author_image" mode="" class="w-72 h-72 rd-8rpx"></image>
					<view class="flex-1 px-16 fs-28">{{infoData.author}}</view>
					<text class="iconfont icon-ic_rightarrow fs-36 text--w111-999"></text>
				</navigator>
				<view class="text-w111-133667 fs-28 lh-42rpx pt-10">
					<text class="pr-10" v-for="(topic,i) in infoData.topic" :key="i"
					@tap="authTo('/pages/discover/discoverTopic/index?id=' + topic.id + '&name=' + topic.name)">#{{topic.name}}</text>
				</view>
				<view class="pt-36 pb-26rpx fs-22 text--w111-999">{{infoData.add_time}}</view>
				<view class="w-full pb-40 bb-f5" v-if="infoData.product && infoData.product.length > 1">
					<scroll-view scroll-x="true" class="white-nowrap vertical-middle w-full" show-scrollbar="false">
						<view class="inline-block mr-30" v-for="(item,index) in infoData.product" :key="index">
							<view class="w-536 rd-24rpx p-20 bg-w111-f9f9f9  flex-between-center" @tap="authTo('/pages/goods_details/index?id=' + item.id)">
								<image :src="item.image" class="w-160 h-160 rd-16rpx"></image>
								<view class="flex-1 h-160 flex-col justify-between pl-20">
									<view class="w-308 h-80 lh-40rpx line2 break_word fs-28 text--w111-333">{{item.store_name}}</view>
									<baseMoney :money="item.price" symbolSize="32" integerSize="40" decimalSize="32" color="#333" weight></baseMoney>
								</view>
							</view>
						</view>
					</scroll-view>
				</view>
				<view class="w-full pb-40" v-else-if="infoData.product && infoData.product.length == 1">
					<view class="w-full rd-16rpx p-20 bg-w111-f9f9f9 flex-between-center" @tap="authTo('/pages/goods_details/index?id=' + infoData.product[0].id)">
						<image :src="infoData.product[0].image" class="w-160 h-160 rd-16rpx"></image>
						<view class="flex-1 h-160 flex-col justify-between pl-20">
							<view class="w-486 h-80 lh-40rpx line2 break_word fs-26">{{infoData.product[0].store_name}}</view>
							<view class="flex-between-center">
								<baseMoney :money="infoData.product[0].price" symbolSize="32" integerSize="40" decimalSize="32" color="#333" weight></baseMoney>
								<view class="w-92 h-40 rd-20rpx flex-center fs-22 font-num theme-border">购买</view>
							</view>
						</view>
					</view>
				</view>
			</view>
			<view class="px-30 bt-f5" v-if="configData.community_comment_status">
				<view class="pt-40 fs-26">评论 {{infoData.comment_num}}</view>
				<view class="reply-list" v-if="replyList.length">
					<view class="flex mt-22" v-for="(item,index) in replyList" :key="index"
						v-show="(item.uid == uid && item.is_show == 0) || item.is_show == 1">
						<image class="w-74 h-74 rd-50-p111-" :src="item.author_image"
							@tap="authTo(`/pages/discover/discoverUser/index?id=${item.uid}&is_store=${item.type}`)"></image>
						<view class="flex-1 ml-20">
							<view class="w-full">
								<view class="flex-y-center">
									<view class="fs-26 text--w111-999">{{item.author}}</view>
									<view class="flex-y-center h-26 px-6 rd-4rpx ml-12 bg-color fs-18 text--w111-fff" v-if="item.uid == infoData.relation_id">作者</view>
								</view>
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
								<view class="child-reply w-full flex justify-between" 
									v-for="(reply,i) in item.children" :key="i"
									v-show="(reply.uid == uid && reply.is_show == 0) || reply.is_show == 1">
									<view class="flex w-full" >
										<image :src="reply.author_image" class="w-36 h-36 rd-50-p111-"
											@tap="authTo(`/pages/discover/discoverUser/index?id=${reply.reply_uid}&is_store=${reply.type}`)"></image>
										<view class="ml-20 flex-1">
											<view class="flex-y-center">
												<view class="text--w111-999 fs-26 lh-40rpx">{{reply.author}}</view>
												<view class="flex-y-center h-26 px-6 rd-4rpx ml-12 bg-color fs-18 text--w111-fff" v-if="reply.uid == infoData.relation_id">作者</view>
											</view>
											<view class="w-full fs-26 lh-42 flex-y-center flex-wrap" @tap="showReply(reply.id, reply.author,reply.is_show)">
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
													<text class="pl-24 text--w111-999" @tap="showReply(reply.id, reply.author,reply.is_show)"
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
					<emptyPage title="暂无评论" src="/statics/images/noActivity.gif"></emptyPage>
				</view>
			</view>
			<view class="h-200"></view>
			<view class="fixed-lb w-full bg--w111-fff pb-safe z-999">
				<view class="h-90 pl-20 pr-24 flex-between-center" v-show="!showInput">
					<view class="flex-y-center">
						<image :src="infoData.author_image || '/static/images/f.png'" class="w-60 h-60 rd-50-p111-"
							@tap="authTo(`/pages/discover/discoverUser/index?id=${infoData.relation_id}&is_store=${infoData.type}`)"></image>
						<view class="max-w-200 line1 ml-20 fs-28 fw-500"
							@tap="authTo(`/pages/discover/discoverUser/index?id=${infoData.relation_id}&is_store=${infoData.type}`)">{{infoData.author || '用户已注销'}}</view>
						<view class="flex-y-center pl-12">
							<view class="relative" v-if="infoData.is_self && isLogin">
								<view class="w-124 h-52 rd-30rpx b-e flex-center fs-24" @tap="()=>{showBubble = !showBubble}">管理</view>
								<view class="bubble_box">
									<view class="bubble flex-col justify-between" v-if="showBubble">
										<view class="flex-y-center pl-16" @tap="authTo('/pages/discover/discoverCreate/index?id=' + community_id)">
											<text class="iconfont icon-ic_edit"></text>
											<text class="fs-26 pl-18">编辑</text>
										</view>
										<view class="x-line"></view>
										<view class="flex-y-center pl-16" @tap="delContent">
											<text class="iconfont icon-ic_delete"></text>
											<text class="fs-26 pl-18">删除</text>
										</view>
									</view>
								</view>
							</view>
							<view v-else>
								<view class="flex-y-center" v-show="!infoData.is_follow" @tap="followChange">
									<view class="h-44 px-24 rd-30rpx theme-border font-num flex-center fs-20">
										<text class="iconfont icon-ic_increase fs-20" ></text>关注
									</view>
								</view>
								<view class="flex-y-center" v-show="infoData.is_follow" @tap="followChange">
									<view class="h-44 px-24 rd-30rpx b-e flex-center fs-20 text--w111-999">已关注</view>
								</view>
							</view>
						</view>
					</view>
					<view class="flex-y-center">
						<view class="text-primary-con flex-y-center" @tap="contentLike">
							<text class="iconfont fs-40" :class="infoData.is_like ? 'icon-ic_love_2' : 'icon-ic_love'"></text>
							<text class="fs-26 pl-10">{{infoData.like_num}}</text>
						</view>
						<view class="pl-32 flex-y-center" @tap="openInput">
							<text class="iconfont icon-ic_daipingjia1 fs-40"></text>
							<text class="fs-26 pl-10">{{infoData.comment_num}}</text>
						</view>
						<!-- #ifdef H5 || APP-PLUS -->
						<text class="iconfont icon-ic_share fs-40 pl-32" @tap="openShare"></text>
						<!-- #endif -->
						<!-- #ifdef MP-WEIXIN -->
						<button class="iconfont icon-ic_share fs-40 pl-32" open-type="share" hover-class="none"></button>
						<!-- #endif -->
					</view>
					<!-- v-if="configData.community_comment_status && configData.community_comment_add" -->
				</view>
				<view class="h-90 pl-20 pr-24 flex-between-center" v-show="showInput">
					<input type="text"
					v-model="comment" 
					:placeholder="placeholder"
					:focus="focus"
					placeholder-class="text--w111-999"
					class="flex-1 h-64 rd-32rpx bg--w111-f5f5f5 pl-30 fs-26 text--w111-333" />
					<view class="w-112 h-64 rd-32rpx flex-center bg-gradient text--w111-fff fs-24 ml-24"
						@tap="sendText">发送</view>
				</view>
			</view>
		</view>
		<view class="bubble-mask" v-if="showBubble"  @tap="()=>{showBubble = false}"></view>
		<view class="mask" v-if="showInput" @tap="()=>{showInput = false}"></view>
		<!-- 确认框 -->
		<tuiModal
			:show="showModal"
			:title="modalTitle"
			:maskClosable="false"
			@click="handleClick"></tuiModal>
		<!-- <home></home> -->
	</view>
</template>

<script>
	import { HTTP_REQUEST_URL } from '@/config/app';
	import colors from "@/mixins/color";
	import {
		getCommunityConfig,
		communityInfoApi,
		communityReplySaveApi,
		communityReplyListApi,
		communityReplyLikeApi,
		communitySetInsterestApi,
		communityLikeApi,
		communityDeleteApi,
		communityShareApi,
		communityReplyDeleteApi,
		communityBrowseApi
	} from "@/api/community.js";
	import { mapState, mapGetters } from 'vuex';
	import { toLogin } from '@/libs/login.js';
	import emptyPage from '@/components/emptyPage.vue';
	import tuiModal from "@/components/tui-modal/index.vue"
	let app = getApp();
	export default {
		data() {
			return {
				sysHeight: app.globalData.sysHeight,
				infoData:{
					userInfo:{
						avatar:'',
						nickname:''
					},
					author_image:'',
					author:'',
					title:'',
					content:'',
					content_type:1,
					slider_image:[],
					productList:[],
					topicList:[],
					video_url:'',
					comment_num:0
				},
				imageH:0,
				videoHeight:0,
				community_id:'',
				comment_reply_id:'',
				replyIndex:'',
				showBubble:false,
				showInput:false,
				comment:'',
				replyList:[],
				modalTitle:'',
				showModal:false,
				modalType:0,
				placeholder:'说说你的看法吧～',
				commentNum:0,
				configData:{},
				focus:false,
				configData:{
					community_status:1,
					community_comment_add:1,
					community_comment_status:1
				},
				isAuto:true
			};
		},
		components: {
			emptyPage,
			tuiModal,
		},
		mixins: [colors],
		computed: {
			...mapGetters(['isLogin','uid']),
		},
		onLoad(options) {
			if(options.id){
				this.community_id = options.id;
				this.getConfig();
				this.getInfo();
				this.getReply();
				if(this.isLogin){
					communityBrowseApi(options.id).catch(err=>{
						console.error(err);
					})
				}
			}else{
				return this.$util.Tips({
					title: '缺少参数无法查看内容'
				}, {
					tab: 3
				});
			}
		},
		onShow() {
			this.showBubble = false;
		},
		onPageScroll() {
			this.showBubble = false;
			uni.$emit('scroll');
		},
		methods:{
			getConfig(){
				getCommunityConfig().then(res=>{
					this.configData = res.data;
				})
			},
			proview(url){
				uni.previewImage({
					urls: this.infoData.slider_image,
					current: url,
				});
			},
			computedHeight(){
				let that = this;
				if(this.infoData.slider_image.length){
					let windowWidth = uni.getWindowInfo().windowWidth;
					uni.getImageInfo({
						src: that.setDomain(this.infoData.slider_image[0]),
						success: (image) => {
							let imageH = parseInt(image.height * windowWidth / image.width);
							if(imageH > 500){
								this.isAuto = false;
								this.imageH = 500;
							}else{
								this.imageH = imageH;
							}
						}
					})
				}
			},
			openShare(){
				let that = this;
				if (!this.isLogin) {
					toLogin();
					return;
				}
				// #ifdef H5
				uni.setClipboardData({
					data: `${HTTP_REQUEST_URL}/pages/discover/discoverDetails/index?id=${this.community_id}&spid=${this.uid}`,
					success: () =>{
						communityShareApi(that.community_id).catch(err=>{
							console.error(err);
						})
						uni.showToast({
							title: '链接已复制'
						})
					}
				})
				// #endif
				// #ifdef APP-PLUS
				uni.share({
					provider: "weixin",
					scene: 'WXSceneSession',
					type: 0,
					href: `${HTTP_REQUEST_URL}/pages/discover/discoverDetails/index?id=${this.community_id}&spid=${this.uid}`,
					title: that.infoData.title,
					summary: that.infoData.content,
					imageUrl: this.infoData.image,
					fail: function(err) {
						uni.showToast({
							title: '分享失败',
							icon: 'none',
							duration: 2000
						})
					}
				});
				// #endif
			},
			delContent(){
				this.modalTitle = '确认删除该内容';
				this.modalType = 1;
				this.showModal = true;
				this.showBubble = false;
			},
			delReply(id,num){
				this.modalTitle = '确认删除该评论';
				this.commentNum = num;
				this.modalType = 2;
				this.comment_reply_id = id;
				this.showModal = true;
			},
			authTo(url){
				if (!this.isLogin && url.includes('discoverUser')) {
					toLogin();
					return;
				}
				uni.navigateTo({
					url: url
				});
			},
			getInfo(){
				communityInfoApi(this.community_id).then(res=>{
					this.infoData = res.data;
					// #ifdef H5
					this.ShareInfo();
					// #endif
					if(res.data.slider_image.length){
						this.computedHeight();
					}
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			setDomain(url) {
				url = url ? url.toString() : '';
				//本地调试打开,生产请注销
				if (url.indexOf("https://") > -1) return url;
				else return url.replace('http://', 'https://');
			},
			onVideoLoad(event) {
				this.videoHeight = `${710 * (event.detail.height / event.detail.width)}rpx`
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
					}
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			sendText(){
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
					this.infoData.comment_num++;
					this.showInput = false;
					this.focus = false;
					this.comment = '';
					this.placeholder = '说说你的看法吧～'
					this.getReply();
					return this.$util.Tips({
						title: res.msg
					});
				}).catch(err => {
					return this.$util.Tips({
						title: err
					});
				});
			},
			showReply(id,author,is_show){
				if(is_show == 0) return this.$util.Tips({
					title: '该评论已被屏蔽，禁止回复'
				});
				this.comment_reply_id = id;
				this.placeholder = `回复：${author}`
				this.showInput = true;
			},
			replyLike(item){
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
					}).catch(err => {
						return this.$util.Tips({
							title: err
						});
					});
				}else{
					this.$set(item,'show',false);
				}
			},
			followChange(){
				if (!this.isLogin) {
					toLogin();
					return;
				}
				if(this.infoData.is_follow == 0){
					communitySetInsterestApi(this.infoData.relation_id,{status:1,is_store:this.infoData.type}).then(res=>{
						this.infoData.is_follow = 1;
						return this.$util.Tips({
							title: res.msg
						});
					}).catch(err => {
						return this.$util.Tips({
							title: err
						});
					});
				}else{
					this.modalTitle = '确认取消关注';
					this.modalType = 0;
					this.showModal = true;
				}

			},
			handleClick(e){
				let index = e.index;
				let that = this;
				if(index == 1){
					if(this.modalType == 0){
						communitySetInsterestApi(this.infoData.relation_id,{status:0,is_store:this.infoData.type}).then(res=>{
							this.infoData.is_follow = 0;
							this.showModal = false;
							return this.$util.Tips({
								title: res.msg
							});

						}).catch(err => {
							this.showModal = false;
							return this.$util.Tips({
								title: err
							});
						});
					}else if(this.modalType == 1){
						communityDeleteApi(this.community_id).then(res=>{
							this.showModal = false;
							return this.$util.Tips({
								title: res.msg
							},`/pages/discover/discoverUser/index?id=${this.infoData.relation_id}&is_store=${this.infoData.type}`);
						}).catch(err => {
							this.showModal = false;
							return this.$util.Tips({
								title: err
							});
						});
					}else if(this.modalType == 2){
						communityReplyDeleteApi(this.comment_reply_id).then(res=>{
							this.showModal = false;
							this.replyList = [];
							this.reply_id = '';
							this.comment_reply_id = '';
							this.infoData.comment_num = this.infoData.comment_num - (1 + this.commentNum);
							this.getReply();
						}).catch(err => {
							this.showModal = false;
							return this.$util.Tips({
								title: err
							});
						});
					}
				}else{
					this.showModal = false;
				}
			},
			contentLike(){
				let status = this.infoData.is_like == 1 ? 0 : 1;
				let that = this;
				if (!this.isLogin) {
					toLogin();
					return;
				}
				communityLikeApi(this.infoData.id,{status,is_store:this.infoData.type}).then(res=>{
					this.infoData.is_like = status;
					if(status == 1){
						this.infoData.like_num++
					}else{
						this.infoData.like_num--
					}
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			},
			openInput(){
				if (!this.isLogin) {
					toLogin();
					return;
				}
				this.showInput = true;
				this.focus = true;
			},
			ShareInfo() {
				if (this.$wechat.isWeixin()) {
					let configAppMessage = {
						desc: this.infoData.content,
						title: this.infoData.title,
						link: `${HTTP_REQUEST_URL}/pages/discover/discoverDetails/index?id=${this.community_id}&spid=${this.uid}`,
						imgUrl: this.infoData.image
					};
					this.$wechat
						.wechatEvevt([
							'updateAppMessageShareData', 
							'updateTimelineShareData',
							'onMenuShareAppMessage',
							'onMenuShareTimeline'
						], configAppMessage)
						.then(res => {})
						.catch(err => {});
				}
			},
		},
		/**
		 * 用户点击右上角分享
		 */
		// #ifdef MP
		onShareAppMessage() {
			let that = this;
			communityShareApi(that.community_id).catch(err=>{
				console.error(err);
			})
			return {
				title: that.infoData.title || that.infoData.content,
				imageUrl: that.infoData.slider_image[0],
				path: '/pages/discover/discoverDetails/index?id=' + that.community_id + '&spid=' + that.uid
			};
		},
		onShareTimeline() {
			let that = this;
			communityShareApi(that.community_id).catch(err=>{
				console.error(err);
			})
			return {
				title: that.infoData.title || that.infoData.content,
				imageUrl: that.infoData.slider_image[0],
				path: '/pages/discover/discoverDetails/index?id=' + that.community_id + '&spid=' + that.uid
			};
		},
		// #endif
	}
</script>
<style>
page{
	background-color: #ffffff;
}
</style>

<style lang="scss">
.b-e{
	border: 1rpx solid #eee;
}
.bt-f5{
	border-top: 1px solid #f5f5f5;
}
.swiper, .swiper-item{
	width: 100%;
	height: 100%;
}
.error{
	background-color: #FDF3F2;
	color: #E93323;
}
.theme-border{
	border: 1px solid var(--view-theme);
}
.warning{
	background-color: #FFF4EB;
	color: #FC8327;
}
.theme-border{
	border: 1rpx solid var(--view-theme);
}
.bubble_box{
	position: absolute;
	bottom: 74rpx;
	left: -24rpx;
	width: 184rpx;
	z-index: 999;
	.bubble{
		width: 184rpx;
		height: 198rpx;
		background: #FFFFFF;
		box-shadow: 0rpx 2rpx 15rpx 0rpx rgba(0,0,0,0.102);
		border-radius: 16rpx;
		padding: 36rpx 16rpx 26rpx 16rpx;
		position: relative;
		&:before{
			content: '';
			position: absolute;
			left: 70rpx;
			bottom: -20rpx;
			width: 0;
			height: 0;
			border-style: solid;
			border-width: 10px 10px 0 10px;
			border-color: #fff  transparent transparent transparent;
		}
	}
	.x-line{
		border-bottom: 1rpx solid rgba(238, 238, 238, 0.4);
	}
}
.drawer-text{
	padding-bottom: calc(32rpx + env(safe-area-inset-bottom));
}
.child-reply ~ .child-reply{
	margin-top: 22rpx;
}
.more-line{
	width: 40rpx;
	height: 2rpx;
	background: #D8D8D8;
}
.max-w-200{
	max-width: 200rpx;
}
.comment-author{
	color: #4A8AC9;
	padding: 0 4rpx;
}
.icon-ic_love_2,.icon-icon_Like_2{
	color: #e93323;
}
.load-box{
	height: 750rpx;
	animation: looming-gray 1s infinite linear;
	background-color: #e3e3e3;
}
.bubble-mask{
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 5;
}
@keyframes looming-gray {
	0% {
		background-color: #e3e3e3aa;
	}

	50% {
		background-color: #e3e3e3;
	}

	100% {
		background-color: #e3e3e3aa;
	}
}
// #ifdef APP-PLUS || H5
/deep/uni-swiper .uni-swiper-dots-horizontal .uni-swiper-dot{
	width: 12rpx;
	height: 12rpx;
	border-radius: 50%;
}
// #endif
// #ifdef MP
 swiper /deep/.wx-swiper-dot{
	width: 12rpx;
	height: 12rpx;
	border-radius: 50%;
}
 /* #endif */
</style>
