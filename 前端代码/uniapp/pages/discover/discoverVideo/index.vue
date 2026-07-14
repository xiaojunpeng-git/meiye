<template>
	<view :style="colorStyle">
		<view class="full-page">
			<swiperVideo 
			:statusBarHeight="statusBarHeight" 
			:swiperData="contentList" 
			:showFooter="false" 
			:replyStatus="configData.community_comment_status"
			:addReply="configData.community_comment_add"
			fullVideo 
			@onLike="likeFun"
			@followChange="followUser"
			@onSwiper="beforeRequest"
			@onShare="resShare"></swiperVideo>
		</view>
		
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import swiperVideo from '../components/discoverVideo/index.vue';
	import { communityListApi, getCommunityConfig } from "@/api/community.js";
	import { getUserInfo } from "@/api/user.js" 
	import {LOGIN_STATUS} from '@/config/cache';
	import { mapGetters } from 'vuex';
	export default {
		name:'discoverVideo',
		data() {
			return {
				contentList:[],
				loading:false,
				params:{
					page:1,
					limit:10,
					topic_id:'', //话题
					keyword:'',
					is_interest:0, //是否关注 
					content_type:0,
					start_id:'',
					relation_id: ''
				},
				infoData:{},
				configData:{
					community_status:1,
					community_comment_add:1,
					community_comment_status:1
				},
				current: 0,
				statusBarHeight: '',
			};
		},
		mixins: [colors],
		components:{ swiperVideo },
		provide() {
			return {
				flowLike: this.flowLike
			}
		},
		onPageScroll(object) {
			uni.$emit('scroll');
		},
		computed:{
			...mapGetters(['isLogin','uid']),
		},
		onLoad(options) {
			this.params.start_id = options.id;
			if(options.relation_id){
				this.$set(this.params,'relation_id',options.relation_id);
			}
			// #ifdef H5
			if (options.statusBarHeight) {
				this.statusBarHeight = options.statusBarHeight;
			}
			if(options.content_type == 0){
				this.$set(this.params,'content_type','');
			}
			if(options.token && !this.isLogin){ 
				this.$store.commit("LOGIN", {
					'token': options.token
				});
				getUserInfo().then(res => {
					this.$store.commit("SETUID", res.data.uid);
					this.$store.commit("UPDATE_USERINFO", res.data);
					location.reload()
				}).catch(error => {
					return this.$util.Tips({
						title:err
					})
				})
			}
			// #endif
			
			this.getConfig();
			this.getList();
		},
		methods:{
			getConfig(){
				getCommunityConfig().then(res=>{
					this.configData = res.data;
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
					this.loading = loading;
				})
			},
			followUser(data){
				this.contentList[data.index].is_follow = 1;
				return this.$util.Tips({
					title: '关注成功'
				});
			},
			beforeRequest(current){
				this.current = current;
				if(this.contentList.length - 1 == current){
					this.getList();
				}
			},
			likeFun(data){
				this.contentList[data.index].is_like = data.status;
				if(data.status == 1){
					this.contentList[data.index].like_num++;
				}else{
					this.contentList[data.index].like_num--;
				}
			},
			resShare(data){
				this.infoData = data;
			},
		},
		onReachBottom() {
			this.getList()
		},
		/**
		 * 用户点击右上角分享
		 */
		// #ifdef MP
		onShareAppMessage() {
			let that = this;
			return {
				title: that.contentList[that.current].title || that.contentList[that.current].content,
				imageUrl: that.contentList[that.current].image,
				path: `/pages/discover/${that.contentList[that.current].content_type == 1 ? 'discoverDetails' : 'discoverVideo'}/index?id=${that.contentList[that.current].id}&spid=${that.uid}&relation_id=${that.params.relation_id}`
			};
		},
		onShareTimeline() {
			let that = this;
			return {
				title: that.contentList[that.current].title || that.contentList[that.current].content,
				imageUrl: that.contentList[that.current].image,
				path: `/pages/discover/${that.contentList[that.current].content_type == 1 ? 'discoverDetails' : 'discoverVideo'}/index?id=${that.contentList[that.current].id}&spid=${that.uid}&relation_id=${that.params.relation_id}`
			};
		},
		// #endif
		
	}
</script>

<style lang="scss">
.full-page{
	height:100vh;
	background-color: #000000;
}
</style>
