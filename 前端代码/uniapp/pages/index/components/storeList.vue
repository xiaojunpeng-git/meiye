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
							<text v-if="showStoreName" :style="[storeNameStyle]" class="name line1">{{item.name}}</text>
							<!-- 门店展示只传达门店资料，不再展示历史履约类型标签。 -->
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
					<text v-if="showStoreName" :style="[storeNameStyle]" class="name2 line1">{{item.name}}</text>
					<!-- 不再用配送配置描述门店。 -->
				</view>
				<view class="fs-22 mt-4 text-w111-909399">
					<text class="iconfont icon-ic_location51 fs-24 mr-4" v-if="checkboxInfo.includes(2) || checkboxInfo.includes(3)"></text>
					<text v-if="checkboxInfo.includes(2)">距您{{item.distance/1000 || 0}}km</text>
					<text class="ml-8 mr-8" v-if="checkboxInfo.includes(2) && checkboxInfo.includes(3)">|</text>
					<text v-if="checkboxInfo.includes(3)">{{item.address}}{{item.detailed_address}}</text>
				</view>
			</view>
		</view>
		<view class="bg--w111-fff pt-20" :style="[boxContentStyle]" v-else-if="storeStyleConfig!=3">
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
						<text v-if="showStoreName" :style="[storeNameStyle]">{{item.name}}</text>
						<!-- 购买门店与服务门店由业务流程选择，无须履约标签。 -->
					</view>
					<view class="fs-20 text--w111-666 mt-4" v-if="checkboxInfo.includes(1)">营业时间：{{item.day_time}}</view>
					<view class="fs-22 text--w111-666 mt-4" v-if="checkboxInfo.includes(3)">{{item.address}}{{item.detailed_address}}</view>
					<view class="fs-22 text-w111-909399 mt-6" v-if="checkboxInfo.includes(2)"><text class="iconfont icon-ic_location51 fs-24 mr-4 text-w111-F7BA1E"></text>距离当前{{item.distance/1000}}km</view>
				</view>
			</view>
		</view>
        <!-- A horizontal rail changes presentation only; API scope, ordering
             and existing navigation/isolated-store rules remain authoritative. -->
        <view v-else class="store-horizontal" :style="[boxContentStyle]">
            <scroll-view scroll-x="true" :show-scrollbar="false" class="store-horizontal__scroll">
                <view class="store-horizontal__track">
                    <view class="store-horizontal__card" :style="[horizontalSpacing]" v-for="item in storeData" :key="item.id" @click="goStore(item)">
                        <view class="store-horizontal__image">
                            <easy-loadimage :image-src="item.image" width="100%" height="100%" :borderRadius="bgRadiusStore"></easy-loadimage>
                        </view>
                        <text v-if="showStoreName" class="store-horizontal__name" :style="[storeNameStyle]">{{item.name}}</text>
						<!-- 横向门店卡片同样不展示历史履约类型。 -->
                        <view v-if="checkboxInfo.includes(1)" class="store-horizontal__meta">营业时间：{{item.day_time}}</view>
                        <view v-if="checkboxInfo.includes(3)" class="store-horizontal__address">{{item.address}}{{item.detailed_address}}</view>
                        <view v-if="checkboxInfo.includes(2) && storeDistance(item)!==null" class="store-horizontal__meta">距您{{storeDistance(item)}}km</view>
                    </view>
                </view>
            </scroll-view>
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
				checkboxGoodsInfo: this.dataConfig.checkboxGoodsInfo.type,
				storeData:[],
				numberGoodsConfig: this.dataConfig.numberGoodsConfig.val,
			};
		},
		computed:{
            // Read display choices reactively; old decorations without the new
            // name option retain the previous always-visible behaviour.
            storeStyleConfig() {
                return this.dataConfig.storeStyleConfig.tabVal;
            },
            checkboxInfo() {
                return this.dataConfig.checkboxInfo.type;
            },
            showStoreName() {
                const info = this.dataConfig.checkboxInfo;
                return !(info.list || []).some(item => Number(item.id) === 4) || this.checkboxInfo.includes(4);
            },
            // The existing store spacing becomes column spacing in style four.
            horizontalSpacing() {
                return { marginRight: Math.max(0, Number(this.dataConfig.storeNumber.val) || 0) * 2 + 'rpx' };
            },
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
            // A missing coordinate-derived distance is unknown, not zero/NaN.
            // Keep the API's metre unit and do not synthesize a location.
            storeDistance(item) {
                if (item.distance === '' || item.distance === null || item.distance === undefined) return null;
                const metres = Number(item.distance);
                return Number.isFinite(metres) && metres >= 0 ? Math.round(metres / 100) / 10 : null;
            },
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
    .store-horizontal { max-width: 100%; overflow: hidden; }
    .store-horizontal__scroll { width: 100%; }
    .store-horizontal__track { display: inline-flex; min-width: 100%; box-sizing: border-box; align-items: stretch; padding: 20rpx 24rpx; }
    .store-horizontal__card { display: flex; flex-direction: column; flex: 0 0 300rpx; width: 300rpx; min-width: 0; white-space: normal; }
    .store-horizontal__card:last-child { margin-right: 0 !important; }
    .store-horizontal__image { width: 100%; height: 220rpx; flex-shrink: 0; overflow: hidden; }
    .store-horizontal__name { display: block; min-height: 42rpx; margin-top: 12rpx; font-size: 30rpx; line-height: 42rpx; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .store-horizontal__meta { margin-top: 6rpx; font-size: 22rpx; line-height: 32rpx; overflow-wrap: anywhere; }
    .store-horizontal__address { display: -webkit-box; min-height: 64rpx; margin-top: 6rpx; font-size: 22rpx; line-height: 32rpx; overflow: hidden; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow-wrap: anywhere; }
    .store-horizontal__badges { display: flex; flex-wrap: wrap; gap: 8rpx; margin-top: 6rpx; }
    .store-horizontal__badges text { padding: 2rpx 6rpx; border-radius: 4rpx; font-size: 18rpx; line-height: 26rpx; }
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

