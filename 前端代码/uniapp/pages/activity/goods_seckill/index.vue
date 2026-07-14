<template>
  <!-- 限时秒杀模块 -->
    <view :style="colorStyle">
        <!--  #ifdef  H5 -->
        <view class="w-full bg-top h-604 relative" :style="{backgroundImage:headerBg}">
        <!--  #endif -->
        <!--  #ifndef  H5 -->
        <view class="w-full bg-top relative" :style="{backgroundImage:headerBg,height: (sysHeight + 262) * 2 + 'rpx'}">
        <!--  #endif -->
            <view class="fixed-lt w-full z-20" 
                :class="pageScrollStatus ? 'sticky' : ''"
                :style="{'padding-top': sysHeight + 'px','background': pageScrollStatus ? '#e93323' : 'transparent'}">
                <view class="w-full px-20 pl-20 h-80 flex-between-center">
                    <text class="iconfont icon-ic_leftarrow fs-40 text--w111-fff" @click="goPage(3)"></text>
                    <text class="fs-34 fw-500 text--w111-fff">{{pageScrollStatus ? '限时秒杀' : ''}}</text>
                    <text></text>
                </view>
            </view>
        </view>
        <view class="relative w-full content">
            <view class="_box h-106 flex-y-center"
            :class="pageScrollStatus ? '' : 'rd-t-24rpx'"
            :style="{'top': 50 + sysHeight + 'px'}">
                <scroll-view scroll-x="true" scroll-with-animation class="white-nowrap vertical-middle w-full relative z-10" 
				:class="{'sel1':active == 0,'sel-last':active == timeList.length - 1}"
                show-scrollbar="false">
                    <view class="scroll-item" 
                    v-for="(item,index) in timeList" :key='index'
                    :class="active == index ? 'active-card' : ''"
                    :style="{'background-image': active == index ? navActiveBg : ''}"
                    @tap='settimeList(item,index)'>
                        <view class="flex-col flex-center z-10 relative t-22">
                            <text class="SemiBold fs-40 lh-40rpx"
                                :class="active == index ? 'text--w111-333':'text--w111-fff'">{{item.time}}</text>
                            <text class="inline-block h-36 lh-34rpx fs-24"
                                :class="active == index ? 'red-tag':'text--w111-fff'">{{item.state}}</text>
                        </view>
                    </view>
                </scroll-view>
                <view class="abs-lb w-full"
                :style="{'background': pageScrollStatus ? '#e93323' : 'rgba(245,245,245,0.2)','height': pageScrollStatus ? '130rpx' : '96rpx'}"></view>
            </view>
            <view class="bg--w111-f5f5f5 pt-32 pl-20 pr-20 relative" >
				<view class="card w-full bg--w111-fff rd-24rpx p-20 flex" v-for="(item,index) in seckillList" :key="index"
				    @tap='goDetails(item)'> 
					<view class="w-240 h-240">
						<easy-loadimage
						class="bg-w111-f9f9f9"
						mode="aspectFit" 
						:image-src="item.image"
						:border-src="item.activity_image"
						width="240rpx"
						height="240rpx"
						borderRadius="20rpx"></easy-loadimage>
					</view>
				    <view class="flex-1 h-240 pl-20 flex-col justify-between">
				        <view class="w-full">
				            <view class="w-410 fs-28 lh-40rpx line1">
								<text v-if="item.brand_name" class="brand-tag">{{ item.brand_name }}</text>
							{{item.title}}</view>
				            <view class="w-410 flex items-end flex-wrap mt-12" v-if="item.store_label.length">
				           	<BaseTag
				           		:text="label.label_name"
				           		:color="label.color"
				           		:background="label.bg_color"
				           		:borderColor="label.border_color"
				           		:circle="label.border_color ? true : false"
				           		:imgSrc="label.icon"
				           		v-for="(label, idx) in item.store_label" :key="idx"></BaseTag>
				           </view>
				        </view>
				        <view class="flex items-baseline">
				            <text class="fs-22 lh-30rpx text-primary pr-8">秒杀价:</text>
				            <baseMoney :money="item.price" symbolSize="24" integerSize="40" decimalSize="24" color="#E93323" weight></baseMoney>
				            <text class="fs-22 lh-30rpx text--w111-999 pl-16 text-line">¥{{item.product_price}}</text>
				        </view>
				        <view class="w-full progress-box flex-between-center" v-if="status == 1">
				            <view class="flex-y-center">
				                <view class="progress ml-16">
				                    <view class="active" :style="'width:'+item.percent+'%;'"></view>
				                </view>
				                <text class="fs-22 text-primary pl-8">已抢{{item.percent}}%</text>
				            </view>
				            <view class="qiang"></view>
				        </view>
				        <view class="w-full yuyue-box flex-between-center" v-else-if="status == 2">
				            <view class="flex-y-center fs-22 pl-16">活动即将开始</view>
				            <view class="yuyue"></view>
				        </view>
				        <view class="w-full over-box flex-between-center" v-else>
				            <view class="flex-y-center fs-22 pl-16">活动已结束</view>
							<view class="over"></view>
				        </view>
				    </view>
				</view>
				<view class="abs-lt cir" v-show="active > 0"></view>
            </view>
			<view class="bg--w111-f5f5f5 p-20" v-if="!seckillList.length && !pageloading">
				<emptyPage title="暂无秒杀商品，去看看其他商品吧～" src="/statics/images/noActivity.gif"></emptyPage>
			</view>
        </view>
        <home :isHide="homeHide"></home>
    </view>
</template>

<script>
    let sysHeight = uni.getSystemInfoSync().statusBarHeight;
    import { getSeckillIndexTime, getSeckillList } from '@/api/activity.js';
    import colors from '@/mixins/color.js'
    import {HTTP_REQUEST_URL} from '@/config/app';
	import emptyPage from '@/components/emptyPage.vue';
	import home from '@/components/home/index.vue';
	import {mapGetters} from 'vuex';
    export default {
        components: {
            emptyPage,
			home
        },
        mixins:[colors],
        data() {
            return {
                sysHeight:sysHeight,
                topImage: '',
                seckillList: [],
                timeList: [],
                active: 5,
                scrollLeft: 0,
                interval: 0,
                status: 1,
                countDownHour: "00",
                countDownMinute: "00",
                countDownSecond: "00",
                page: 1,
                limit: 8,
                loading: false,
                loadend: false,
                pageloading: false,
                intoindex:'',
                imgHost:HTTP_REQUEST_URL,
                pageScrollStatus: false,
                homeHide: false
            }
        },
        computed:{
            headerBg(){
                return 'url('+this.imgHost+'/statics/images/product/seckill_header.png'+')'
            },
            navActiveLeft(){
                if(this.active == 0){
                    return 0
                } else if(this.active == 1){
                    return '134rpx'
                } else {
                    return 154 * this.active + 'rpx'
                }
            },
            navActiveBg(){
                if(this.active == 0){
                    return 'url('+this.imgHost+'/statics/images/product/seckill_header_icon1.png'+')'
                }else{
                    return 'url('+this.imgHost+'/statics/images/product/seckill_header_icon2.png'+')'
                }
            },
			...mapGetters(['uid']),
        },
        onPageScroll(object) {
            if (object.scrollTop > 200) {
                this.pageScrollStatus = true;
            } else if (object.scrollTop < 200) {
                this.pageScrollStatus = false;
            }
            uni.$emit('scroll');
            this.homeHide = true;
        },
        onLoad() {
            this.getSeckillConfig();
			// #ifdef H5
			this.setOpenShare();
			// #endif
        },
        onShow(){
            uni.removeStorageSync('form_type_cart');
        },
        methods: {
            getSeckillConfig: function() {
                let that = this;
                getSeckillIndexTime().then(res => {
                    that.topImage = res.data.lovely;
                    that.timeList = res.data.seckillTime;
                    that.active = res.data.seckillTimeIndex;
                    that.$nextTick(()=>{
                        that.intoindex = 'sort'+res.data.seckillTimeIndex
                    })
                    if (that.timeList.length) {
                        that.scrollLeft = (that.active - 1.37) * 100
                        setTimeout(function() {
                            that.loading = true
                        }, 2000);
                        that.seckillList = [],
                            that.page = 1
                        that.status = that.timeList[that.active].status
                        that.getSeckillList();
                    }
                });
            },
            getSeckillList: function() {
                var that = this;
                var data = {
                    page: that.page,
                    limit: that.limit
                };
                if (that.loadend) return;
                if (that.pageloading) return;
                this.pageloading = true
                getSeckillList(that.timeList[that.active].id, data).then(res => {
                    var seckillList = res.data;
                    var loadend = seckillList.length < that.limit;
                    that.page++;
                    that.seckillList = that.seckillList.concat(seckillList),
                        that.page = that.page;
                    that.pageloading = false;
                    that.loadend = loadend;
                }).catch(err => {
                    that.pageloading = false
                });
            },
            settimeList: function(item, index) {
                var that = this;
                this.active = index
                if (that.interval) {
                    clearInterval(that.interval);
                    that.interval = null
                }
                that.interval = 0,
                    that.countDownHour = "00";
                that.countDownMinute = "00";
                that.countDownSecond = "00";
                that.status = that.timeList[that.active].status;
                that.loadend = false;
                that.page = 1;
                that.seckillList = [];
                that.getSeckillList();
            },
            goDetails(item){
                uni.navigateTo({
                    url: '/pages/activity/goods_details/index?id=' + item.id + '&type=1&time_id=' + this.timeList[this.active].id
                })
            },
            goPage(type, url){
                if(type == 1){
                    uni.navigateTo({
                        url
                    })
                }else if(type == 2){
                    uni.switchTab({
                        url
                    })
                }else if(type == 3){
					this.$util.backPage();
                }
            },
			// #ifdef H5
			// 微信分享；
			setOpenShare: function() {
				let that = this;
				let uid = this.uid ? this.uid : 0;
				if (that.$wechat.isWeixin()) {
					let data = res.data;
					let configAppMessage = {
						title: '限时秒杀',
						link: location.href + '?spid=' + uid
					};
					that.$wechat.wechatEvevt(['updateAppMessageShareData', 'updateTimelineShareData',
							'onMenuShareAppMessage', 'onMenuShareTimeline'
						],
						configAppMessage);
				}
			},
			// #endif
        },
        /**
         * 页面上拉触底事件的处理函数
         */
        onReachBottom: function() {
            this.getSeckillList();
        },
		/**
		 * 用户点击右上角分享
		 */
		//#ifdef MP
		onShareAppMessage() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '限时秒杀',
				path: '/pages/activity/goods_seckill/index?spid=' + uid
			};
		},
		//分享到朋友圈
		onShareTimeline: function() {
			let uid = this.uid ? this.uid : 0;
			return {
				title: '限时秒杀',
				path: '/pages/activity/goods_seckill/index?spid=' + uid
			};
		}
		//#endif
    }
</script>

<style lang="scss">
	.brand-tag {
		background: #E93323 !important;
	}
  .bg-top{
      background-size: 100% 100%;
      background-repeat: no-repeat;
  }
  .sticky{
      &:after{
          content:'';
          width:100%;
          height: 20px;
          background-color: #E93323;
          position: absolute;
          left: 0;
          bottom: -20rpx;
      }
  }
  .content{
      top: -114rpx;
  }
  ._box{  
      position: sticky;
      z-index: 99;
  }
  .card ~ .card{
      margin-top: 20rpx;
  }
  .w-21-p111-{
      width: 21%;
  }
  .max-w-96{
      max-width: 96rpx;
  }
  .fq{
      background-color: #E93323;
      color: #fff;
  }
  .text-primary{
      color: #E93323;;
  }
  .text-line{
      text-decoration: line-through;
  }
  .progress-box{
      height: 64rpx;
      background-color: rgba(233, 51, 35, 0.05);
      border-radius: 16rpx;
  }
  .progress{
     width:160rpx;
      height: 18rpx;
      border-radius: 10rpx;
      background-color: rgba(233, 51, 35, 0.2);
      .active{
          height: 18rpx;
          border-radius: 10rpx;
          background: linear-gradient(90deg, #FF7931 0%, #E93323 100%);
      }
  }
  .qiang{
      width:112rpx;
      height: 64rpx;
      background-image: url('../static/qiang.png');
      background-size: cover;
  }
  .yuyue-box{
      height: 64rpx;
      background-color: #FFF1E5;
      border-radius: 16rpx;
      color: #FF7D00;
  }
  .yuyue{
      width:140rpx;
      height: 64rpx;
      background-image: url('../static/yuyue.png');
      background-size: cover;
  }
  .over-box{
      height: 64rpx;
      background-color: rgba(233, 51, 35, 0.05);
      border-radius: 16rpx;
      color: rgba(233, 51, 35, 0.60);
  }
  .over{
      width:140rpx;
      height: 64rpx;
      background-image: url('../static/over.png');
      background-size: cover;
  }
	.sel1 .scroll-item{
		right: 30rpx;
	}
	.sel-last {
		/deep/.uni-scroll-view{
			
		margin-right: -10rpx;
		}
		.scroll-item{
			// left: 40rpx;
			// left: 32rpx;
		}
		.active-card{
			width: 180rpx;
			padding-left: 10px;
			.relative{
				width: 160rpx;
			}
		}
	} 
  .scroll-item{
      display: inline-block;
      height: 96rpx;
      width: 21%;
      position: relative;
      bottom: 0;
  }
  .active-card{
      width:234rpx;
      height: 106rpx;
      background-size: cover;
      z-index: 99;
  }
  .t-22{
      top: 22rpx;
  }
  .red-tag{
      display: inline-block;
      padding: 0 12rpx;
      height: 36rpx;
      border-radius: 18rpx;
      text-align: center;
      line-height: 36rpx;
      background-color: #E93323;
      font-size: 22rpx;
      color: #fff;
  }
  .cir{
	  width: 24rpx;
	  height: 24rpx;
	  background-color: #ED593E;
	  &:after{
		  content: '';
		  width: 24rpx;
		  height: 24rpx;
		  position: absolute;
		  left: 0;
		  top: 0;
		  border-top-left-radius: 100%; /* 左上角为半径大小的弧形 */
		  background-color: #f5f5f5;
	  }
  }
</style>
