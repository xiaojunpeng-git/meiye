<template>
	<view class="user-list">
		<!-- #ifdef MP || APP-PLUS -->
		<view class="accountTitle">
			<view :style="{height:getHeight.barTop+'px'}"></view>
			<view class="sysTitle acea-row row-center-wrapper" :style="{height:getHeight.barHeight+'px'}">
				<view>用户管理</view>
				<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
			</view>
		</view>
		<view :style="{height:(getHeight.barTop+getHeight.barHeight)+'px'}"></view>
		<!-- #endif -->
		<view class="searchCon acea-row row-between-wrapper">
			<view class="search acea-row row-middle">
				<text class="iconfont icon-ic_search"></text>
				<input class="inputs" placeholder='请输入用户信息/门店全称' placeholder-class='placeholder' confirm-type='search' name="search"
					v-model="keyword" @confirm="searchSubmit"></input>
			</view>
		</view>
    <view class="searchOut">
         <button class="keepBnt" :class="filterData.birthday_type == 0?'chooseBtn':''" @click="lookBirthday(0)">全部</button>
         <button class="keepBnt" :class="filterData.birthday_type == 1?'chooseBtn':''" @click="lookBirthday(1)">今天生日</button>
         <button class="keepBnt" :class="filterData.birthday_type == 2?'chooseBtn':''" @click="lookBirthday(2)">明天生日</button>
         <button class="keepBnt" :class="filterData.birthday_type == 3?'chooseBtn':''" @click="lookBirthday(3)">本月生日</button>
    </view>
		<view class="list" v-if="userLists.length">
				<view class="acea-row row-middle" v-for="(item, index) in userLists" :key="index">
					<view class="item acea-row row-center-wrapper" @click="goDetails(item)">
						<view class="pictrue">
							<image :src="item.avatar"></image>
						</view>
						<view class="text">
							<view class="top acea-row row-middle">
								<view class="name line1">{{item.nickname}}</view>
							</view>
							<view class="phone" @click.stop="makePhone(item.phone)">
                    <view> {{item.phone}}</view>
                     <text class="iconfont icon-ic_phone"></text>
              </view>
							<view class="info acea-row row-middle">
								<view>积分：<text>{{item.integral}}</text></view>
								<view>余额：<text>{{item.now_money}}</text></view>
							</view>
              <view class="info acea-row row-middle">
								<view>上次服务时间：<text>{{ item.order_time }}</text></view>
							</view>
              <view class="info acea-row row-middle">
                <view>手艺人：<text>{{item.shouyi}}</text></view>
              </view>
              <view class="info acea-row row-middle">
                <view>归属门店：<text>{{item.belong_store}}</text></view>
              </view>
						</view>
<!--						<view v-if="!administer && storeNum && $util.auth('mall-admin-user-integral,mall-admin-user-moeny,mall-admin-user-level,mall-admin-user-svip,mall-admin-user-coupon')" class="iconfont icon-ic_more" @click.stop="openDrawer(item)"></view>-->
					</view>
				</view>
		</view>
		<view v-if="userLists.length == 0 && !loading">
			<emptyPage title="暂无用户信息～" src="/statics/images/empty-box.gif"></emptyPage>
		</view>
		<Loading :loaded="loadend" :loading="loading"></Loading>
		<view style="height: 110rpx;"></view>
		<coupon ref="coupon" :visible='visibleCoupon' :uid='uid' @closeDrawer='couponCloseDrawer'></coupon>
	</view>
</template>

<script>
	import Loading from '@/components/Loading/index'
	import emptyPage from '@/components/emptyPage.vue';
	import coupon from './components/coupon/index.vue';
	import {
		getUserList,
		getStoreUserList,
		getGroupList,
		getStoreGroupList,
		postUserUpdate,
		getLevelList,
		getStoreLevelList
	} from "@/api/admin";
	import {
		mapGetters
	} from "vuex";
	import legacyMerchantRedirect from '@/mixins/legacyMerchantRedirect.js';
	export default {
		mixins: [legacyMerchantRedirect],
		components: {
			coupon,
			emptyPage,
			Loading
		},
		computed: mapGetters(["storeNum"]),
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				editList:[
					{
						name:'修改积分',
						auth:'mall-admin-user-integral'
					},
					{
						name:'修改余额',
						auth:'mall-admin-user-moeny'
					},
					{
						name:'修改等级',
						auth:'mall-admin-user-level'
					},
					{
						name:'赠送会员',
						auth:'mall-admin-user-svip'
					},
					{
						name:'赠送优惠券',
						auth:'mall-admin-user-coupon'
					},
				],
				administer:0,
				isAllSelect: false,
				userLists:[],
				visible:false,
				visibleLable:false, //标签是否显示
				loadTitle: '加载更多',
				loading: false,
				loadend: false,
				limit: 20,
				page: 1,
				keyword:'', //搜索字段
				visibleBalance: false,
				type: 0,
				visibleMember:false,
				visibleCoupon:false,
				uid: 0,
				groupArray:[],
				userInfo:{},
				levelArray:[],
				visibleFilter:false,
				filterData:{
					labelIds:'',
          birthday_type:0,
					groupIds:0,
					levelIds:0
				}
			}
		},
		onShow() {
		},
		async onLoad() {
			// 有商家入口权限时，旧用户管理收藏 URL 过渡到商家客户页
			if (await this.redirectLegacyToMerchant(() => this.legacyMerchantCustomerUrl())) {
				return;
			}
			this.userList();
			// this.groupList();
		},
		methods: {
			goarrow(){
				uni.navigateBack()
			},
			filterChange(data){
				this.visibleFilter = false
				this.filterData = data
				this.init();
			},
			filterCloseDrawer(){
				this.visibleFilter = false
			},
			filterTap(){
				this.visibleFilter = true;
				this.$refs.filter.productLabel();
				this.levelList();
			},
			sendCoupon(){
				if(!this.getIds().length){
					this.$util.Tips({
						title: '请选择商品'
					});
					return
				}
				this.visibleCoupon = true;
				this.$refs.coupon.userCoupon(1,this.getIds());
			},
			levelList(){
				let funApi = '';
				if(this.storeNum){
					funApi = getLevelList;
				}else{
					funApi = getStoreLevelList;
				}
				funApi().then(res=>{
					this.levelArray = res.data.list;
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			groupList(){
				let funApi = '';
				if(this.storeNum){
					funApi = getGroupList;
				}else{
					funApi = getStoreGroupList;
				}
				funApi().then(res=>{
					this.groupArray = res.data;
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			userUpdate(data){
				postUserUpdate(data).then(res=>{
					this.$util.Tips({
						title: res.msg
					});
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			bindLevelChange(e){
				this.userUpdate({
					type:1,
					uid:this.uid,
					level:this.levelArray[e.detail.value].id
				})
				this.init();
			},
			bindPickerChange(e){
				if(!this.getIds().length){
					this.$util.Tips({
						title: '请选择商品'
					});
					return
				}
				this.userUpdate({
					type:4,
					uid:this.getIds(),
					group_id:this.groupArray[e.detail.value].id
				})
			},
			goDetails(item){
				uni.navigateTo({
					url: '/pages/admin/user/index?uid='+item.uid+'&types='+this.storeNum
				})
			},
			couponCloseDrawer(){
				this.visibleCoupon = false
			},
			memberCloseDrawer(){
				this.visibleMember = false
			},
			balanceCloseDrawer(){
				this.visibleBalance = false
			},
			successChange(){
				this.visibleMember = false
				this.visibleBalance = false
				this.init();
			},
			//批量编辑标签
			editLabels(){
				if(!this.getIds().length){
					this.$util.Tips({
						title: '请选择商品'
					});
					return
				}
				this.visibleLable = true
				this.visible = false
				this.$refs.lable.productLabel({},1,this.getIds());
			},
      /**
       * 拨打电话
       */
      makePhone: function(phone) {
        let that = this;
        // #ifdef APP-PLUS
        plus.device.dial(phone, true);
        // #endif
        // #ifdef MP || H5
        uni.makePhoneCall({
          phoneNumber: phone
        });
        // #endif
      },
			//批量获取id集合
			getIds(){
				let ids = []
				this.userLists.forEach(item=>{
					if(item.checked){
						ids.push(item.uid)
					}
				})
				ids.slice(0, 100)
				return ids
			},
			init(){
				this.userLists = [];
				this.page = 1;
				this.loadend = false;
				this.loading = false;
				this.userList();
			},
			searchSubmit(){
				this.init();
			},
			userList(){
				let that = this;
				if (this.loading) return;
				if (this.loadend) return;
				that.loading = true;
				that.loadTitle = "";
				let funApi = '';
				if(this.storeNum){
					funApi = getUserList
				}else{
					funApi = getStoreUserList
				}
				funApi({
					page: that.page,
					limit: that.limit,
					nickname: that.keyword,
					group_id: that.filterData.groupIds,
          birthday_type: that.filterData.birthday_type,
					level: that.filterData.levelIds,
					label_id: that.filterData.labelIds
				}).then(res=>{
					let userLists = res.data.list;
					userLists.forEach(item => {
						item.checked = false;
					})
					this.isAllSelect = false
					let loadend = userLists.length < that.limit;
					that.userLists = that.$util.SplitArray(userLists, that.userLists);
					that.$set(that, 'userLists', that.userLists);
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.page = that.page + 1;
					that.loading = false;
				}).catch(err=>{
					that.loading = false;
					that.loadTitle = "加载更多";
				})
			},
      lookBirthday(type){
         this.filterData.birthday_type=type;
         this.init();
      }
		},
		onReachBottom(){
			this.userList();
		}
	}
</script>

<style lang="scss" scoped>
.searchOut{
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 30rpx;
  padding: 0px 40rpx;
}
.keepBnt {
  height: 60rpx;
  border-radius: 40rpx;
  text-align: center;
  line-height: 60rpx;
  font-size: 24rpx;
  width: 20%;
}
.chooseBtn{
  background-color: $primary-admin;
  color: #fff;
}
	::v-deep checkbox .uni-checkbox-input.uni-checkbox-input-checked {
		border-color: #2A7EFB !important;
		background-color: #2A7EFB !important;
	}
	::v-deep checkbox:not([disabled]) .uni-checkbox-input:hover {
		border-color: #d1d1d1 !important;
	}
	::v-deep .empty-page{
		margin-top: 20rpx;
	}
	.accountTitle{
		background: #F5F5F5;
		position: fixed;
		left:0;
		top:0;
		width: 100%;
		z-index: 99;
		padding-bottom: 6rpx;
		.sysTitle{
			width: 100%;
			position: relative;
			font-weight: 500;
			color: #333333;
			font-size: 34rpx;
			.iconfont{
				position: absolute;
				font-size: 42rpx;
				left:20rpx;
				width: 60rpx;
				font-weight: 500;
			}
		}
	}
	.user-list{
		padding: 20rpx 20rpx 0 20rpx;
		.searchCon{
			.search{
				flex: 1;
				height: 72rpx;
				background: #FFFFFF;
				border-radius: 50rpx;
				padding: 0 34rpx;
				margin-right: 20rpx;

				.iconfont{
					color: #999;
					font-size: 32rpx;
					margin-right: 16rpx;
				}
				.inputs{
					font-size: 28rpx;
					width: 100%;
					height: 100%;
					flex: 1;
				}
				.placeholder{
					color: #ccc;
				}
			}
			.edit{
				width: 72rpx;
				height: 72rpx;
				background: #FFFFFF;
				border-radius: 50%;
				&.on{
					margin-right: 20rpx;
				}
				.iconfont {
					color: #666;
					font-size: 36rpx;
				}
			}
		}
		.list{
			padding-bottom: 20rpx;
			margin-top: 32rpx;
			::v-deep uni-checkbox .uni-checkbox-input{
				background-color: #f5f5f5;
				margin: 0 20rpx 20rpx 0;
			}
			::v-deep wx-checkbox .wx-checkbox-input{
				background-color: #f5f5f5;
				margin: 0 20rpx 20rpx 0;
			}
			.item {
				width: 100%;
				background-color: #fff;
				padding: 30rpx 24rpx;
				box-sizing: border-box;
				margin-bottom: 20rpx;
				border-radius: 24rpx;
				position: relative;
				flex: 1;
				.icon-ic_more{
					position: absolute;
					right: 20rpx;
					top: 30rpx;
					color: #999;
					font-size: 40rpx;
				}
				.pictrue{
					width: 96rpx;
					height: 96rpx;
					margin-right: 24rpx;
					image {
						width: 100%;
						height: 100%;
						border-radius: 50%;
					}
				}
				.text{
					flex: 1;
					overflow: hidden;
					.top{
						.name {
							max-width: 230rpx;
							font-size: 28rpx;
							font-weight: 600;
							color: #333333;
							font-family: PingFang SC, PingFang SC;
						}
						.svip {
							width: 56rpx;
							height: 26rpx;
							background: linear-gradient(270deg, #484643 0%, #1F1B17 100%);
							border-radius: 100rpx;
							font-size: 18rpx;
							font-family: PingFang SC, PingFang SC;
							font-weight: 600;
							color: #FDDAA4;
							margin-left: 10rpx;
						}
						.vip{
							min-width: 64rpx;
							padding: 0 10rpx;
							background: #FEF0D9;
							border: 1px solid #FACC7D;
							border-radius: 50rpx;
							font-size: 18rpx;
							font-family: PingFang SC, PingFang SC;
							font-weight: 500;
							color: #DFA541;
							margin-left: 10rpx;
							.iconfont {
								font-size: 20rpx;
								margin-right: 4rpx;
							}
						}
					}
					.phone{
            display: flex;
            gap: 20rpx;
            align-items: center;
						font-size: 24rpx;
						font-family: PingFang SC, PingFang SC;
						font-weight: 400;
						color: #999999;
						margin-top: 4rpx;
					}
					.info {
						font-size: 24rpx;
						font-weight: 400;
						color: #999999;
						margin-top: 4rpx;
						text {
							color: #333333;
							margin-right: 28rpx;
						}
					}
				}
				.bottom{
					margin-top: 26rpx;
					.bnt{
						width: 144rpx;
						height: 56rpx;
						border:1px solid #ccc;
						font-size: 24rpx;
						font-family: PingFang SC, PingFang SC;
						font-weight: 400;
						color: #333333;
						border-radius: 50rpx;
						&~.bnt{
							margin-left: 16rpx;
						}
						&.on{
							border-color: #2A7EFB;
							background-color: #2A7EFB;
							color: #fff;
						}
						&.up{
							border-color: #FF7E00;
							color: #FF7E00;
						}
					}
				}
			}
		}
		.footerH {
			height: calc(constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
			height: calc(env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		}
		.footer {
			box-sizing: border-box;
			padding: 0 32rpx;
			width: 100%;
			height: 96rpx;
			background-color: #fff;
			position: fixed;
			bottom: 0;
			z-index: 30;
			height: calc(96rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
			height: calc(96rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
			padding-bottom: constant(safe-area-inset-bottom); ///兼容 IOS<11.2/
			padding-bottom: env(safe-area-inset-bottom); ///兼容 IOS>11.2/
			width: 100%;
			left: 0;

			::v-deep uni-checkbox .uni-checkbox-input{
				margin-bottom: 6rpx;
			}

			.bnt {
				width: 160rpx;
				height: 64rpx;
				border-radius: 50rpx;
				border: 1rpx solid #2A7EFB;
				color: #2A7EFB;
				font-size: 24rpx;
				font-family: PingFang SC, PingFang SC;
				font-weight: 500;
				&~.bnt{
					margin-left: 16rpx;
				}
			}
		}
		.edit-list{
			background-color: #fff;
			padding: 45rpx 34rpx;
			.item{
				font-family: PingFang SC, PingFang SC;
				font-weight: 400;
				color: #333333;
				font-size: 32rpx;
				text-align: center;
				height: 106rpx;
				line-height: 106rpx;
			}
		}
	}
</style>
