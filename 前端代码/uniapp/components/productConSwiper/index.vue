<template>
  <!-- 商品详情轮播图 -->
  <view class="product-bg" :style="[swiperHeight]">
    <swiper
	    :current="currents"
        :indicator-dots="indicatorDots"
        indicator-active-color="var(--view-theme)"
        :autoplay="showSku || videoline || pageHide ? false : true"
        :circular="circular"
        :interval="interval"
        :duration="duration"
        @change="change"
        v-if="isPlay"
    >
      <!-- #ifndef APP-PLUS -->
      <swiper-item v-if="videoline">
        <view class="item">
          <view v-show="!controls" class="item-box">
            <video
                id="myVideo"
                :src="videoline"
                objectFit="cover"
                controls
                style="width: 100%; height: 100%"
                show-center-play-btn
                show-mute-btn="true"
                auto-pause-if-navigate
                :custom-cache="false"
                :enable-progress-gesture="false"
                :poster="imgUrls[0]"
                @pause="videoPause"
            ></video>
          </view>
          <view class="poster" v-show="controls">
            <image class="image" :src="imgUrls[0]" @click='getpreviewImage(0)'></image>
          </view>
          <view class="stop" v-show="controls" @tap="bindPause">
            <image class="image" src="../../static/images/stop.png"></image>
          </view>
        </view>
      </swiper-item>
      <!-- #endif -->
      <!-- #ifdef APP-PLUS -->
      <swiper-item v-if="videoline">
        <view class="item">
          <view class="poster" v-show="controls">
            <image class="image" :src="imgUrls[0]" @click='getpreviewImage(0)'></image>
          </view>
          <view class="stop" v-show="controls" @tap="bindPause">
            <image class="image" src="../../static/images/stop.png"></image>
          </view>
        </view>
      </swiper-item>
      <!-- #endif -->
      <block v-for="(item, index) in imgUrls" :key="index">
        <swiper-item v-if="videoline ? index >= 1 : index >= 0">
          <image :src="item" class="slide-image" :mode="!autoHeight ? 'aspectFit' : ''" @click='getpreviewImage(index)' />
        </swiper-item>
      </block>
    </swiper>
    <!-- #ifdef APP-PLUS -->
    <view v-if="!isPlay" style="width: 100%; height: 750rpx">
      <video
          id="myVideo"
          :src="videoline"
          objectFit="cover"
          controls
          style="width: 100%; height: 100%"
          show-center-play-btn
          show-mute-btn="true"
          autoplay="true"
          auto-pause-if-navigate
          :custom-cache="false"
          :enable-progress-gesture="false"
          :poster="imgUrls[0]"
          @pause="videoPause"
      ></video>
    </view>
    <!-- #endif -->
	<view class="w-full flex-center absolute left-0 bottom-86rpx z-20" v-if="showSku">
		<view class="sku-desc flex-center px-20 fs-20 text--w111-fff line1">{{sku}}</view>
	</view>
    <view class="w-full flex-center absolute left-0 bottom-54rpx z-4" v-if="imgUrls.length > 1 && showDot && controls && !showSku">
      <block v-for="(_, index) in imgUrls" :key="index">
        <view class="dot_item h-4 rd-2rpx" :style="{ 'background-color': currents == index ? '#fff' : 'rgba(255,255,255,0.4)', width: dotWidth + 'rpx' }"></view>
      </block>
    </view>
    <view class="w-full flex-center abs-lb h-200 white-jianbian" v-show="controls"></view>
  </view>
</template>

<script>
export default {
  props: {
	currents: {
		type: Number,
		default: 0
	},
    imgUrls: {
      type: Array,
      default: () => []
    },
    videoline: {
      type: String,
      default: ''
    },
    showDot: {
      type: Number,
      default: 1
    },
    autoHeight: {
      type: Number,
      default: 0
    },
	sku:{
		type: String,
		default: ''
	},
	showSku:{
		type: Boolean,
		default: false
	},
	pageHide:{
		type: Boolean,
		default: false
	}
  },
  data() {
    return {
      indicatorDots: false,
      circular: true,
      autoplay: true,
      interval: 3000,
      duration: 500,
      // currents: 0,
      controls: true,
      isPlay: true,
      videoContext: '',
      imgHeight:375
    };
  },
  watch:{
  	currents(val){
  		if(val != 0){
  			this.controls = true;
  		}
  	},
  	// imgUrls(nval,oldval){
  	// 	this.currents = 0;
  	// }
  },
  mounted() {
    if (this.videoline) {
      this.imgUrls.shift();
    }
    // #ifndef APP-PLUS
    this.videoContext = uni.createVideoContext('myVideo', this);
    // #endif
  },
  computed: {
    dotWidth() {
      let windowWidth = uni.getSystemInfoSync().windowWidth;
      return (windowWidth * 2 - (40 + (this.imgUrls.length - 1) * 12)) / this.imgUrls.length;
    },
    swiperHeight() {
      let windowWidth = uni.getSystemInfoSync().windowWidth;
      let that = this;
      uni.getImageInfo({
        src: this.imgUrls[0],
        success: (image) => {
          that.imgHeight = image.height * windowWidth / image.width;
        }
      });

      if (this.autoHeight) {
        return {
          height: `${this.imgHeight}px`
        };
      } else {
        return {
          height: '750rpx'
        };
      }
    }
  },
  methods: {
	getpreviewImage: function(index) {
		if(this.showSku){
			this.$emit('previewImageOpen')
		}else{
			uni.previewImage({
				urls: this.imgUrls,
				current: this.imgUrls[index]
			});
		}
	},
    videoPause(e) {
      // #ifdef APP-PLUS
      this.isPlay = true;
      this.autoplay = true;
      // #endif
    },
    bindPause: function () {
      // #ifndef APP-PLUS
      this.videoContext.play();
      this.$set(this, 'controls', false);
      this.autoplay = false;
      // #endif
      // #ifdef APP-PLUS
      this.isPlay = false;
      this.videoContext = uni.createVideoContext('myVideo', this);
      this.videoContext.play();
      // #endif
    },
    change: function (e) {
	  this.$emit('attrPicIndex',e.detail.current)
	  if(this.showSku){
		  this.$emit('changeAttrPic',e.detail.current)
	  }
    }
  }
};
</script>

<style scoped lang="scss">
.item-box {
  width: 100%;
  height: 100%;
}

.product-bg {
  width: 100%;
  position: relative;
}

.product-bg swiper {
  width: 100%;
  height: 100%;
  position: relative;
}

.product-bg .slide-image {
  width: 100%;
  height: 100%;
}

.product-bg .pages {
  position: absolute;
  background-color: #fff;
  height: 34rpx;
  padding: 0 10rpx;
  border-radius: 3rpx;
  right: 30rpx;
  bottom: 30rpx;
  line-height: 34rpx;
  font-size: 24rpx;
  color: #050505;
}

#myVideo {
  width: 100%;
  height: 100%;
}

.product-bg .item {
  position: relative;
  width: 100%;
  height: 100%;
}

.product-bg .item .poster {
  position: absolute;
  top: 0;
  left: 0;
  height: 750rpx;
  width: 100%;
  z-index: 9;
}

.product-bg .item .poster .image {
  width: 100%;
  height: 100%;
}

.product-bg .item .stop {
  position: absolute;
  top: 50%;
  left: 50%;
  width: 136rpx;
  height: 136rpx;
  margin-top: -68rpx;
  margin-left: -68rpx;
  z-index: 9;
}

.product-bg .item .stop .image {
  width: 100%;
  height: 100%;
}

.dot_item ~ .dot_item {
  margin-left: 12rpx;
}
.white-jianbian {
  background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, rgba(0, 0, 0, 0.2) 100%);
}
.bottom-86rpx{
	bottom: 86rpx;
}
.sku-desc{
	height: 40rpx;
	background: rgba(0,0,0,0.3);
	border-radius: 20rpx;
}
</style>
