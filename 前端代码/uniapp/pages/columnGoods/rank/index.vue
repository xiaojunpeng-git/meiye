<template>
	<view>
		<view class="w-full bg-top h-470 relative" :style="{backgroundImage:headerBg}">
			<view class="fixed-lt w-full z-20" :style="{'padding-top': sysHeight + 'px','background': pageScrollStatus ? '#e93323' : 'transparent'}">
				<view class="w-full px-20 pl-20 h-80 flex-between-center">
					<text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @click="goBack()"></text>
					<text class="fs-34 fw-500 text--w111-fff">{{pageScrollStatus ? '商品排行榜' : ''}}</text>
					<text></text>
				</view>
			</view>
			<view class="desc flex-between-center">
				<view class="h-40 fs-28 lh-40rpx text--w111-fff" 
				v-for="(item, index) in tabList" :key="index"
				:class="item.type == type ? 'active-tab' : ''"
				@tap="tabChange(item.type)">{{item.title}}</view>
			</view>
		</view>
		<view class="relative rd-t-40rpx bg--w111-f5f5f5 w-full content">
			<view class="">
				<view class="pt-32 pl-20 pr-20">
					<view class="card w-full bg--w111-fff rd-24rpx p-20 flex" v-for="(item,index) in productList" :key="index"
						@click="goDetail(item)">
						<view class="picture w-240 h-240 relative">
							<easy-loadimage
							:image-src="item.image"
							width="240rpx"
							height="240rpx"
							borderRadius="20rpx"></easy-loadimage>
							<image class="abs-lt w-72 h-72" 
								:src="`${imgHost}/statics/images/product/rank_icon${index + 1}.png`" v-if="index < 3"></image>
						</view>
						<view class="flex-1 pl-20 flex-col justify-between">
							<view class="w-full">
								<view class="w-full fs-28 lh-40rpx line2">{{item.store_name}}</view>
								<view class="flex items-end flex-wrap mt-12 w-full" v-if="item.store_label.length">
									<BaseTag
										:text="label.label_name" 
										:color="label.color" 
										:background="label.bg_color"
										:borderColor="label.border_color"
										:circle="label.border_color ? true : false" 
										:imgSrc="label.icon"
										v-for="(label, idx) in item.store_label" :key="idx"></BaseTag>
								</view>
								<view class="font-red lh-30rpx fs-22 fw-500 mt-4">{{item.sales}}人买过 | 评分{{item.star}}</view>
							</view>
							<view class="w-full flex-between-center">
								<baseMoney :money="item.price" symbolSize="24" integerSize="40" decimalSize="24" color="#e93323" weight></baseMoney>
								<view class="w-144 h-56 rd-30rpx flex-center fs-24 jianbian text--w111-fff">立即抢购</view>
							</view>
						</view>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	let sysHeight = uni.getSystemInfoSync().statusBarHeight;
	import { rankCategoryApi, rankListApi } from "@/api/store";
	import { goShopDetail } from '@/libs/order.js';
	import { HTTP_REQUEST_URL } from '@/config/app';
	import {mapGetters} from 'vuex';
	export default {
		data() {
			return {
				sysHeight:sysHeight,
				imgHost: HTTP_REQUEST_URL,
				pageScrollStatus:false,
				cateList:[],
				form:{
					page:1,
					limit:10,
					selectId:0
				},
				type:1,
				loading:false,
				productList:[],
				tabList:[
					{title:'销量榜',type:1},
					{title:'好评榜',type:2},
					{title:'收藏榜',type:3},
				],
			};
		},
		computed:{
			headerBg(){
				return 'url('+this.imgHost+'/statics/images/product/rank_header.png'+')'
			},
			...mapGetters(['isLogin', 'uid']),
		},
		onLoad(e) {
			this.type = e.type ? e.type : 1;
			this.getList();
		},
		onPageScroll(object) {
			if (object.scrollTop > 130) {
				this.pageScrollStatus = true;
			} else if (object.scrollTop < 130) {
				this.pageScrollStatus = false;
			}
			uni.$emit('scroll');
		},
		methods:{
			init(){
				rankCategoryApi().then(res=>{
					this.cateList = res.data;
					if(res.data.length){
						this.form.selectId = res.data[0].id;
						
					}
				})
			},
			getList(){
				if (this.loading) return;
				rankListApi(this.type,this.form).then(res=>{
					this.productList = this.productList.concat(res.data);
					this.loading = res.data.length < this.form.limit;
					this.form.page++;
				})
			},
			goBack(){
				this.$util.backPage();
			},
			goDetail(item){
				goShopDetail(item, this.uid).catch(res => {
					uni.navigateTo({
						url: `/pages/goods_details/index?id=${item.id}`
					});
				});
			},
			tabChange(type){
				this.type = type;
				this.loading = false;
				this.form.page = 1;
				this.productList  =[];
				this.getList();
			}
		},
		onReachBottom() {
			this.getList();
		}
	}
</script>

<style lang="scss">
.h-470{
	height: 470rpx;;
}
.w-624{
	width: 624rpx;
}
.desc{
	width: 402rpx;
	height: 52rpx;
	position: absolute;
	left: 50%;
	transform: translateX(-50%);
	bottom: 118rpx;
}
.active-tab{
	width: 132rpx;
	height: 52rpx;
	background: #FFF8E4;
	color: #F85517;
	border-radius: 30rpx;
	font-weight: 500;
	display: flex;
	justify-content: center;
	align-items: center;
}
.bg-top{
	background-size: 100% 100%;
	background-repeat: no-repeat;
}
.content{
	min-height: 400rpx;
	top: -80rpx;
}
._box{
	padding: 40rpx 20rpx 32rpx;
	background: #f5f5f5;
	position: sticky;
	z-index: 99;
}
.font-red{
	color: #e93323;
}
.bg-red{
	background-color: #e93323;
}
.jianbian{
	background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
}
.card ~ .card{
	margin-top: 20rpx;
}

</style>
