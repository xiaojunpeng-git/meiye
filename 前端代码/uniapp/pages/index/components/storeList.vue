<template>
	<view :style="[boxStyle]" v-if="storeData.length">
		<!-- 门店头部 -->
		<view class="w-full h-96 px-24 flex-between-center bg-cover" :style="[headerStyle]">
			<view class="flex-y-center">
				<text class="fs-32 lh-44rpx fw-500" :style="[titleStyle]" v-if="titleConfig">{{titleTxtConfig}}</text>
				<image :src="titleImg" class="w-140 h-32" v-else></image>
			</view>
			<view class="flex-y-center fs-24 text--w111-999" :style="[headerBntColor]"
				@tap="goPage('/pages/store_list/index')">
				<text>{{rightBntTxt}}</text>
				<text class="iconfont icon-ic_rightarrow" :style="[headerBntColor]"></text>
			</view>
		</view>
		<view class="bg--w111-fff pt-20" :style="[boxContentStyle]" v-if="storeStyleConfig==0">
			<view class="store overflow" :style="[storeSpacing]" v-for="(item, index) in storeData" :key="index" @click="goStore(item)">
				<view class="acea-row ml-24 mr-24">
					<view class="w-104 h-104 mr-20">
						<easy-loadimage
						:image-src="item.image"
						width="100%"
						height="100%"
						:borderRadius="bgRadiusStore"></easy-loadimage>
					</view>
					<view class="picBox">
						<view class="fs-30 text--w111-333 acea-row row-middle">
							<text :style="[storeNameStyle]" class="name line1">{{item.name}}</text>
							<view class="acea-row row-middle" v-if="checkboxInfo.includes(0)">
								<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-12" :style="[deliveryStyle]" v-if="item.delivery_type.includes('1')">快递</view>
								<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-8" :style="[cityDeliveryStyle]" v-if="item.delivery_type.includes('3')">闪送</view>
								<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-8" :style="[storeStyle]" v-if="item.delivery_type.includes('2')">到店</view>
							</view>
						</view>
						<view class="fs-22 text-w111-909399 mt-4" v-if="checkboxInfo.includes(1)">营业时间：{{item.day_time}}</view>
						<view class="fs-22 text-w111-909399 mt-4 line1">
							<text class="iconfont icon-ic_location51 fs-24 mr-4 text-w111-F7BA1E" v-if="checkboxInfo.includes(2) || checkboxInfo.includes(3)"></text>
							<text v-if="checkboxInfo.includes(2)">距您{{item.distance/1000 || 0}}km</text>
							<text class="ml-8 mr-8" v-if="checkboxInfo.includes(2) && checkboxInfo.includes(3)">|</text>
							<text v-if="checkboxInfo.includes(3)">{{item.address}}{{item.detailed_address}}</text>
						</view>
					</view>
				</view>
				<scroll-view scroll-x="true" show-scrollbar="false" class="mt-18 ml-24 white-nowrap vertical-middle w-full overflow">
					<view class="mr-12 w-144 inline-block" v-for="(j, jIndex) in item.product" :key="jIndex" @click.stop="goDetails(j)">
						<view class="w-full h-144">
							<easy-loadimage
							:image-src="j.image"
							width="100%"
							height="100%"
							:borderRadius="bgRadiusImg"></easy-loadimage>
						</view>
						<view class="fs-22 mt-4 text--w111-333 line1" :style="[goodsNameStyle]" v-if="checkboxGoodsInfo.includes(0)">{{j.store_name}}</view>
						<view class="fs-28 mt-8 SemiBold" :style="[goodsPrice]" v-if="checkboxGoodsInfo.includes(1)"><text class="fs-22 PingFang mr-1">¥</text>{{j.price}}</view>
					</view>
				</scroll-view>
			</view>
		</view>
		<view class="bg--w111-fff pt-24" :style="[boxContentStyle]" v-else-if="storeStyleConfig==1">
			<view class="store pl-24 pr-24" :style="[storeSpacing]" v-for="(item, index) in storeData" :key="index" @click="goStore(item)">
				<view class="w-full h-278">
					<easy-loadimage
					:image-src="item.background_image"
					width="100%"
					height="100%"
					:borderRadius="bgRadiusStore"></easy-loadimage>
				</view>
				<view class="fs-30 text--w111-333 mt-16 acea-row row-middle">
					<text :style="[storeNameStyle]" class="name2 line1">{{item.name}}</text>
					<view class="acea-row row-middle" v-if="checkboxInfo.includes(0)">
						<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-12" :style="[deliveryStyle]" v-if="item.delivery_type.includes('1')">配送</view>
						<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-8" :style="[cityDeliveryStyle]" v-if="item.delivery_type.includes('3')">闪送</view>
						<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-8" :style="[storeStyle]" v-if="item.delivery_type.includes('2')">到店</view>
					</view>
				</view>
				<view class="fs-22 mt-4 text-w111-909399">
					<text class="iconfont icon-ic_location51 fs-24 mr-4" v-if="checkboxInfo.includes(2) || checkboxInfo.includes(3)"></text>
					<text v-if="checkboxInfo.includes(2)">距您{{item.distance/1000 || 0}}km</text>
					<text class="ml-8 mr-8" v-if="checkboxInfo.includes(2) && checkboxInfo.includes(3)">|</text>
					<text v-if="checkboxInfo.includes(3)">{{item.address}}{{item.detailed_address}}</text>
				</view>
			</view>
		</view>
		<view class="bg--w111-fff pt-20" :style="[boxContentStyle]" v-else>
			<view class="store acea-row row-middle ml-20 mr-20" :style="[storeSpacing]" v-for="(item, index) in storeData" :key="index" @click="goStore(item)">
				<view class="w-136 h-136 mr-16">
					<easy-loadimage
					:image-src="item.image"
					width="100%"
					height="100%"
					:borderRadius="bgRadiusStore"></easy-loadimage>
				</view>
				<view class="flex-1">
					<view class="fs-30 text--w111-333 fw-500 acea-row row-middle">
						<text :style="[storeNameStyle]">{{item.name}}</text>
						<view class="acea-row row-middle" v-if="checkboxInfo.includes(0)">
							<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-12" :style="[deliveryStyle]" v-if="item.delivery_type.includes('1')">配送</view>
							<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-8" :style="[cityDeliveryStyle]" v-if="item.delivery_type.includes('3')">闪送</view>
							<view class="w-52 h-26 rd-4rpx acea-row row-center-wrapper fs-18 ml-8" :style="[storeStyle]" v-if="item.delivery_type.includes('2')">到店</view>
						</view>
					</view>
					<view class="fs-20 text--w111-666 mt-4" v-if="checkboxInfo.includes(1)">营业时间：{{item.day_time}}</view>
					<view class="fs-22 text--w111-666 mt-4" v-if="checkboxInfo.includes(3)">{{item.address}}{{item.detailed_address}}</view>
					<view class="fs-22 text-w111-909399 mt-6" v-if="checkboxInfo.includes(2)"><text class="iconfont icon-ic_location51 fs-24 mr-4 text-w111-F7BA1E"></text>距离当前{{item.distance/1000}}km</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import { homeStoreList } from '@/api/store.js';
	export default {
		name: 'storeList',
		props: {
			dataConfig: {
				type: Object,
				default: () => {}
			},
			isSortType:{
				type: String | Number,
				default:0
			}
		},
		data() {
			return {
				storeStyleConfig: this.dataConfig.storeStyleConfig.tabVal,
				checkboxInfo: this.dataConfig.checkboxInfo.type,
				checkboxGoodsInfo: this.dataConfig.checkboxGoodsInfo.type,
				storeData:[],
				numberGoodsConfig: this.dataConfig.numberGoodsConfig.val,
			};
		},
		computed:{
			goodsPrice(){
				return {
					color: this.dataConfig.toneConfig.tabVal ? this.dataConfig.goodsPriceColor.color[0].item : 'var(--view-theme)'
				}
			},
			goodsNameStyle(){
				return{
					color:this.dataConfig.goodsNameColor.color[0].item,
					fontWeight:this.dataConfig.goodsName.tabVal?'normal':'bold'
				}
			},
			bgRadiusImg(){
				let filletImg = this.dataConfig.filletImg;
				let borderRadius = `${filletImg.val*2}rpx`;
				if (filletImg.type) {
				  borderRadius =
				      `${filletImg.valList[0].val*2}rpx ${filletImg.valList[1].val*2}rpx ${filletImg.valList[3].val*2}rpx ${filletImg.valList[2].val*2}rpx`;
				}
				return borderRadius
			},
			deliveryStyle(){
				return{
					background:this.dataConfig.deliveryBg.color[0].item,
					color:this.dataConfig.deliveryText.color[0].item
				}
			},
			cityDeliveryStyle(){
				return{
					background:this.dataConfig.cityDeliveryBg.color[0].item,
					color:this.dataConfig.cityDeliveryText.color[0].item
				}
			},
			storeStyle(){
				return{
					background:this.dataConfig.storeBg.color[0].item,
					color:this.dataConfig.storeText.color[0].item
				}
			},
			storeNameStyle(){
				let storeNameColor = this.dataConfig.storeNameColor.color[0].item;
				return{
					color:storeNameColor,
					fontWeight:this.dataConfig.storeName.tabVal?'normal':'bold'
				}
			},
			bgRadiusStore(){
				let filletStoreImg = this.dataConfig.filletStoreImg;
				let borderRadius = `${filletStoreImg.val*2}rpx`;
				if (filletStoreImg.type) {
				  borderRadius =
				      `${filletStoreImg.valList[0].val*2}rpx ${filletStoreImg.valList[1].val*2}rpx ${filletStoreImg.valList[3].val*2}rpx ${filletStoreImg.valList[2].val*2}rpx`;
				}
				return borderRadius
			},
			boxContentStyle(){
				let fillet = this.dataConfig.fillet;
				let borderRadius = `0 0 ${fillet.val*2}rpx ${fillet.val*2}rpx`;
				let moduleColor = this.dataConfig.moduleColor.color;
				if (fillet.type) {
				  borderRadius =
				      `0 0 ${fillet.valList[3].val*2}rpx ${fillet.valList[2].val*2}rpx`;
				}
				return{
					borderRadius:borderRadius,
					background: `linear-gradient(90deg, ${moduleColor[0].item} 0%, ${moduleColor[1].item} 100%)`,
				}
			},
			storeSpacing(){
				return{
					paddingBottom:this.dataConfig.storeNumber.val*2+'rpx'
				}
			},
			boxStyle(){
				return {
					padding: `${this.dataConfig.topConfig.val * 2}rpx ${this.dataConfig.prConfig.val * 2}rpx ${this.dataConfig.bottomConfig.val * 2}rpx`,
					marginTop: `${this.dataConfig.mbConfig.val * 2}rpx`,
					background: this.dataConfig.bottomBgColor.color[0].item,
				}
			},
			headerStyle(){
				let br = `${this.dataConfig.fillet.val * 2}rpx`,
					borderRadius= '',
					imgBgUrl = this.dataConfig.imgBgConfig.url;
				if (this.dataConfig.fillet.type){
					borderRadius = `${this.dataConfig.fillet.valList[0].val * 2}rpx ${this.dataConfig.fillet.valList[1].val * 2}rpx 0 0`
				}else{
					borderRadius = `${br} ${br} 0 0`
				}
				return {
					backgroundImage: this.styleConfig ? 'url(' + imgBgUrl + ')' : `linear-gradient(90deg,${this.dataConfig.headerBgColor.color[0].item} 0%,${this.dataConfig.headerBgColor.color[1].item} 100%)`,
					borderRadius,
			
				}
			},
			styleConfig(){
				return this.dataConfig.styleConfig.tabVal
			},
			titleStyle(){
				let titleText = this.dataConfig.titleText
				return {
					fontStyle: !titleText.tabVal?'normal':titleText.tabList[titleText.tabVal].style,
					fontWeight: !titleText.tabVal?'bold':'normal',
					color: this.dataConfig.titleColor.color[0].item,
					fontSize: this.dataConfig.titleNumber.val*2+'rpx'
				}
			},
			/*标题是文本还是图片*/
			titleConfig(){
				return this.dataConfig.titleConfig.tabVal
			},
			/*标题文本*/
			titleTxtConfig(){
				return this.dataConfig.titleTxtConfig.value
			},
			/*标题图片*/
			titleImg(){
				return this.styleConfig ? this.titleUrl : this.titleColorUrl
			},
			titleColorUrl(){
				return this.dataConfig.imgColorConfig.url
			},
			titleUrl(){
				return this.dataConfig.imgConfig.url
			},
			/*头部按钮文本*/
			rightBntTxt(){
				return this.dataConfig.rightBntConfig.value
			},
			/*头部按钮样式*/
			headerBntColor(){
				return {
					color: this.styleConfig ? this.dataConfig.headerBntColor.color[0].item : this.dataConfig.headerBntColor2.color[0].item,
					fontSize: `${this.dataConfig.bntNumber.val * 2}rpx`
				}
			},
			/*门店数量*/
			numberConfig(){
			  return this.dataConfig.numberConfig.val
			}
		},
		mounted(){
			this.storeList();
		},
		methods: {
			goPage(url){
				uni.reLaunch({
					url: url
				})
			},
			//跳转门店详情
			goStore(item){
				uni.navigateTo({
					url: `/pages/store/home/index?&id=${item.id}`
				})
			},
			//跳转商品详情
			goDetails(j){
				uni.navigateTo({
					url: `/pages/goods_details/index?id=${j.id}`
				})
			},
			storeList(){
				let data = {
					page:1,
					limit:this.numberConfig,
					product_num:this.numberGoodsConfig,
					latitude: uni.getStorageSync('user_latitude'),
					longitude: uni.getStorageSync('user_longitude')
				}
				homeStoreList(data).then(res=>{
					this.storeData = res.data;
				}).catch(err=>{
					return this.$util.Tips({
						title: err
					});
				})
			}
		}
	}
</script>
<style lang="scss" scoped>
	.store:last-child{
		padding-bottom: 20rpx !important;
	}
	.picBox{
		width: calc(100% - 124rpx);
		.name{
			max-width: calc(100% - 150rpx);
		}
	}
	.name2{
		max-width: calc(100% - 128rpx);
	}
</style>

