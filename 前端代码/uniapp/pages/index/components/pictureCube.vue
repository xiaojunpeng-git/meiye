<template>
	<!-- 图片魔方 -->
	<view class="pictureCube" :style="[boxStyle]" v-if="picList.length && !dataConfig.isHide">
		<view class="advert-item-1 flex-1" :style="[grdiGap]" v-if="style==0">
			<view v-for="(item,index) in picList" :key="index" @click="goDetail(item)">
				<easy-loadimage mode="widthFix" width="100%" :image-src="item.image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="flex-1 grid-column-2" :style="[colGap]" v-else-if="style==1">
			<view class="item w-full" v-for="(item,index) in picList" :key="index" @click="goDetail(item)">
				<easy-loadimage :mode="filterMode(item.objectFit || 'cover')" width="100%"  :height="imageH + 'rpx'" :image-src="item.image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="flex-1 grid-column-3"  :style="[colGap]" v-else-if="style==2">
			<view class="item" v-for="(item,index) in picList" :key="index" @click="goDetail(item)">
				<easy-loadimage :mode="filterMode(item.objectFit || 'cover')" width="100%"  :height="imageH + 'rpx'" :image-src="item.image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="advert-item-4 flex-1" :style="[grdiGap]" v-else-if="style==3">
			<view class="pic1" @click="goDetail(picList[0])">
				<easy-loadimage mode="aspectFill" width="100%" :height="imageH + 'rpx'" :image-src="picList[0].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic2" @click="goDetail(picList[1])">
				<easy-loadimage :mode="filterMode(picList[1].objectFit || 'cover')" width="100%" :height="imageH + 'rpx'" :image-src="picList[1].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic3" @click="goDetail(picList[2])">
				<easy-loadimage mode="widthFix" width="100%" height="100%" :image-src="picList[2].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="advert-item-5 flex-1" :style="[grdiGap]" v-else-if="style==4">
			<view class="pic1" @click="goDetail(picList[0])">
				<easy-loadimage mode="widthFix" width="100%" height="100%" :image-src="picList[0].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic2" @click="goDetail(picList[1])">
				<easy-loadimage mode="aspectFill" width="100%" :height="imageH + 'rpx'" :image-src="picList[1].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic3" @click="goDetail(picList[2])">
				<easy-loadimage :mode="filterMode(picList[2].objectFit || 'cover')" width="100%" :height="imageH + 'rpx'" :image-src="picList[2].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="advert-item-6 flex-1" :style="[grdiGap]" v-else-if="style==5">
			<view class="pic1" @click="goDetail(picList[0])">
				<easy-loadimage width="100%" :height="imageH + 'rpx'" :image-src="picList[0].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic2 h-full" @click="goDetail(picList[1])">
				<easy-loadimage :mode="filterMode(picList[1].objectFit || 'cover')" width="100%" height="100%" :image-src="picList[1].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic3 h-full" @click="goDetail(picList[2])">
				<easy-loadimage :mode="filterMode(picList[2].objectFit || 'cover')" width="100%" height="100%" :image-src="picList[2].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="advert-item-7 flex-1" :style="[grdiGap]" v-else-if="style==6">
			<view class="pic1" @click="goDetail(picList[0])">
				<easy-loadimage mode="aspectFill" width="100%" :height="imageH + 'rpx'" :image-src="picList[0].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic2" @click="goDetail(picList[1])">
				<easy-loadimage :mode="filterMode(picList[1].objectFit || 'cover')" width="100%" :height="imageH + 'rpx'" :image-src="picList[1].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic3" @click="goDetail(picList[2])">
				<easy-loadimage :mode="filterMode(picList[2].objectFit || 'cover')" width="100%" height="100%" :image-src="picList[2].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="grid-column-2 flex-1" :style="[grdiGap]" v-else-if="style==7">
			<view class="w-full" v-for="(item,index) in picList" :key="index" @click="goDetail(item)">
				<easy-loadimage  :mode="filterMode(item.objectFit || 'cover')" width="100%" :height="imageH + 'rpx'" :image-src="item.image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="advert-item-9 flex-1" :style="[grdiGap]" v-else-if="style==8">
			<view class="pic1" @click="goDetail(picList[0])">
				<easy-loadimage width="100%" height="100%" :height="imageH + 'rpx'" :image-src="picList[0].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic2 " @click="goDetail(picList[1])">
				<easy-loadimage :mode="filterMode(picList[1].objectFit || 'cover')" width="100%" height="100%" :height="imageH + 'rpx'" :image-src="picList[1].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic3" @click="goDetail(picList[2])">
				<easy-loadimage width="100%" height="100%" :height="templateH + 'rpx'" :image-src="picList[2].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic4" @click="goDetail(picList[3])">
				<easy-loadimage :mode="filterMode(picList[3].objectFit || 'cover')" width="100%" height="100%" :height="templateH + 'rpx'" :image-src="picList[3].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic5" @click="goDetail(picList[4])">
				<easy-loadimage :mode="filterMode(picList[4].objectFit || 'cover')" width="100%" height="100%" :height="templateH + 'rpx'" :image-src="picList[4].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="advert-item-6 flex-1"  :style="[grdiGap]" v-else-if="style==9">
			<view class="pic1" @click="goDetail(picList[0])">
				<easy-loadimage width="100%" :height="imageH + 'rpx'" :image-src="picList[0].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic2" @click="goDetail(picList[1])">
				<easy-loadimage :mode="filterMode(picList[1].objectFit || 'cover')" width="100%" height="100%"  :image-src="picList[1].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
			<view class="pic3 h-full flex-between-center" >
				<view class="flex-1 h-full" @click="goDetail(picList[2])">
					<easy-loadimage :mode="filterMode(picList[2].objectFit || 'cover')" width="100%" height="100%" :image-src="picList[2].image" :borderRadius="imgBorderRadius"></easy-loadimage>
				</view>
				<view class="flex-1 h-full" :style="{'margin-left': dataConfig.imgConfig.val*2 + 'rpx'}" @click="goDetail(picList[3])">
					<easy-loadimage :mode="filterMode(picList[3].objectFit || 'cover')" width="100%" height="100%" :image-src="picList[3].image" :borderRadius="imgBorderRadius"></easy-loadimage>
				</view>
			</view>
		</view>
		<view class="flex-1" v-else-if="style==10">
			<view class="h-full" @click="goDetail(picList[0])">
				<easy-loadimage mode="widthFix" width="100%" :image-src="picList[0].image" :borderRadius="imgBorderRadius"></easy-loadimage>
			</view>
		</view>
		<view class="advert12" :style="[autoHeight]" v-else-if="style==11">
			<template v-if="dataConfig.picStyle.docPicList.length">
				<view v-for="(item,index) in dataConfig.picStyle.docPicList" :key="index" :style="{
					top: `${(item.doc.startY/375)*100}%`,
					left: `${(item.doc.startX/375)*100}%`,
					width: `${(item.doc.w/375)*100}%`,
					height: `${(item.doc.h/375)*100}%`,
					borderWidth: `${dataConfig.imgConfig.val*2}rpx`,
				}" class="item" @click="goDetail(item)">
					<easy-loadimage mode="aspectFill" width="100%" height="100%" :imageSrc="item.img" class="image" :borderRadius="imgBorderRadius"></easy-loadimage>
				</view>
			</template>
		</view>
	</view>
</template>

<script>
	export default {
		name: 'pictureCube',
		props: {
			dataConfig: {
				type: Object,
				default: () => {}
			},
		},
		data() {
			return {
				picList: this.dataConfig.picStyle.picList,
				style: this.dataConfig.styleConfig.tabVal,
				imageH: 0,
				templateH: 0
			};
		},
		computed: {
			boxStyle(){
				return {
					padding: `${this.dataConfig.topConfig.val*2}rpx ${this.dataConfig.prConfig.val*2}rpx ${this.dataConfig.bottomConfig.val*2}rpx ${this.dataConfig.prConfig.val*2}rpx`,
					marginTop: `${this.dataConfig.mbConfig.val*2}rpx`,
					background: this.dataConfig.bottomBgColor.color[0].item
				}
			},
			filletImg() {
				let borderRadius = `${this.dataConfig.filletImg.val * 2}rpx`;
				if (this.dataConfig.filletImg.type) {
					borderRadius =
						`${this.dataConfig.filletImg.valList[0].val * 2}rpx ${this.dataConfig.filletImg.valList[1].val * 2}rpx ${this.dataConfig.filletImg.valList[3].val * 2}rpx ${this.dataConfig.filletImg.valList[2].val * 2}rpx`;
				}
				return {
					'border-radius': borderRadius,
				}
			},
			colGap(){
				return {
					'grid-column-gap': `${this.dataConfig.imgConfig.val*2}rpx`
				}
			},
			grdiGap(){
				return {
					'grid-gap': `${this.dataConfig.imgConfig.val*2}rpx`
				}
			},
			imgBorderRadius() {
				let borderRadius = `${this.dataConfig.filletImg.val * 2}rpx`;
				if (this.dataConfig.filletImg.type) {
					borderRadius =
						`${this.dataConfig.filletImg.valList[0].val * 2}rpx ${this.dataConfig.filletImg.valList[1].val * 2}rpx ${this.dataConfig.filletImg.valList[3].val * 2}rpx ${this.dataConfig.filletImg.valList[2].val * 2}rpx`;
				}
				return borderRadius
			},
			autoHeight(){
				let windowWidth = uni.getWindowInfo().windowWidth;
				return {
					height: windowWidth + 'px'
				}
			},
		},
		mounted() {
			this.computedHeight();
		},
		methods: {
			//替换安全域名
			setDomain: function(url) {
				url = url ? url.toString() : '';
				//本地调试打开,生产请注销
				if (url.indexOf("https://") > -1) return url;
				else return url.replace('http://', 'https://');
			},
			goDetail(url) {
				let urls = url.link;
				this.$util.JumpPath(urls);
			},
			basePicHeight(index){
				let that = this;
				let windowWidth = uni.getWindowInfo().windowWidth;
				let prConfig = this.dataConfig.prConfig.val * 2;
				uni.getImageInfo({
					src: that.setDomain(that.picList[index].image),
					success: (res) => {
						if (res && res.height > 0){
							let height = 0;
							if(that.style == 2){
								height = res.height * ((250 - prConfig) / res.width);
							}else{
								height = res.height * ((windowWidth - prConfig) / res.width);
							}
							this.$set(that, 'imageH', height);	
						}
					}
				})
			},
			computedHeight(){
				if(this.picList.length){
					let that = this;
					this.$nextTick((e)=>{
						if(this.style == 4){
							//计算第二张图片的高度
							this.basePicHeight(1)
						} else if(this.style == 8){
							//计算两行各自第一张的高度
							this.basePicHeight(0);
							this.computeTemplateEight();
						} else {
							//计算第一张图片的高度
							this.basePicHeight(0)
						}
					})
				}
			},
			computeTemplateEight(){
				let that = this;
				let windowWidth = uni.getWindowInfo().windowWidth;
				let prConfig = this.dataConfig.prConfig.val * 2;
				uni.getImageInfo({
					src: that.setDomain(that.picList[2].image),
					success: (res) => {
						if (res && res.height > 0){
							let height = res.height * ((250 - prConfig) / res.width);
							this.$set(that, 'templateH', height);	
						}
					}
				})
			},
			filterMode(val){
				let obj = {
					cover: 'aspectFill',
					contain: 'aspectFit',
					fill: 'scaleToFill'
				};
				return obj[val]
			}
		}
	}
</script>

<style lang="scss">
	.pictureCube {
		display: flex;
		background-color: #fff;

		.advert-item-1 {
			display: grid;
			grid-template-columns: 1fr; /* 一列 */
			grid-template-rows: auto auto; /* 两行 */
		}

		.advert-item-4 {
			display: grid;
			grid-template-rows: auto auto; /* 上面两行，下面一行 */
			grid-template-columns: 1fr 1fr; /* 上面两列等分 */
			.pic1{
				grid-row: 1 / 2; 
				grid-column: 1 / 2;
			}
			.pic2{
				grid-row: 1 / 2; 
				grid-column: 2 / 3;
			}
			.pic3{
				grid-row: 2 / 3; 
				grid-column: 1 / 3;
			}
		}

		.advert-item-5 {
			display: grid;
			grid-template-rows: auto auto; /* 上面两行，下面一行 */
			grid-template-columns: 1fr 1fr; /* 上面两列等分 */
			.pic1{
				grid-row: 1 / 2; 
				grid-column: 1 / 3;
			}
			.pic2{
				grid-row: 2 / 3; 
				grid-column: 1 / 2;
			}
			.pic3{
				grid-row: 2 / 3; 
				grid-column: 2 / 3;
			}
		}

		.advert-item-6 {
			display: grid;
			grid-template-columns: 1fr 1fr; /* 左右两列 */
			grid-template-rows: auto; /* 自动行高 */
			align-items: stretch; /* 确保所有元素的高度一致 */
			.pic1{
				grid-column: 1 / 2; /* 第一列 */
				grid-row: 1 / 3; 
			}
			.pic2{
				grid-column: 2 / 3; /* 第二列 */
				grid-row: 1 / 2;
			}
			.pic3{
				grid-column: 2 / 3; /* 第二列 */
				grid-row: 2 / 3; 
			}
		}

		.advert-item-7 {
			display: grid;
			grid-template-columns: 1fr 1fr; /* 左右两列 */
			grid-template-rows: auto; /* 自动行高 */
			align-items: stretch; /* 确保所有元素的高度一致 */
			.pic1{
				grid-column: 1 / 2; /* 第一列 */
				grid-row: 1 / 2; 
			}
			.pic2{
				grid-column: 1 / 2; /* 第二列 */
				grid-row: 2 / 3;
			}
			.pic3{
				grid-column: 2 / 3; /* 第二列 */
				grid-row: 1 / 3; 
			}
		}

		.advert-item-9 {
			display: grid;
			grid-template-columns: repeat(6, 1fr); /* 6 列 */
			grid-template-rows: auto auto; /* 自动行高 */
			.pic1{
				grid-column: 1 / 4; /* 第一列 */
				grid-row: 1 / 2; 
			}
			.pic2{
				grid-column: 4 / 7; /* 第二列 */
				grid-row: 1 / 2;
			}
			.pic3{
				grid-column: 1 / 3; /* 第二列 */
				grid-row: 2 / 3; 
			}
			.pic4{
				grid-column: 3 / 5; /* 第二列 */
				grid-row: 2 / 3; 
			}
			.pic5{
				grid-column: 5 / 7; /* 第二列 */
				grid-row: 2 / 3; 
			}
		}

		.advert12 {
			flex: 1;
			position: relative;

			.item {
				position: absolute;
				border-style: solid;
				border-color: transparent;
			}

			.image {
				display: block;
				width: 100%;
				height: 100%;
			}
		}
	}
</style>