<template>
	<view class="user-details">
		<!-- #ifdef MP || APP-PLUS -->
		<view class="accountTitle">
			<view :style="{height:getHeight.barTop+'px'}"></view>
			<view class="sysTitle acea-row row-center-wrapper" :style="{height:getHeight.barHeight+'px'}">
				<view>用户详情</view>
				<text class="iconfont icon-ic_leftarrow" @click="goarrow"></text>
			</view>
		</view>
		<view :style="{height:(getHeight.barTop+getHeight.barHeight)+'px'}"></view>
		<!-- #endif -->
		<view class="header">
			<view class="picTxt acea-row row-middle">
				<view class="pictrue">
					<image :src="infoData.avatar"></image>
				</view>
				<view class="text">
					<view class="name acea-row row-middle">
						<view class="nameCon line1">{{infoData.nickname}}</view>
						<view class="svip acea-row row-center-wrapper" v-if="infoData.isMember == 1">SVIP</view>
						<view class="vip acea-row row-center-wrapper" v-if="infoData.level_status == 1 && infoData.level > 0">
							<text class="iconfont icon-huiyuandengji"></text>
							V{{infoData.level_grade}}
						</view>
					</view>
					<view v-if="infoData.phone">{{infoData.phone}}（ID：{{uid}}）</view>
					<view v-else>ID：{{uid}}</view>
				</view>
			</view>
		</view>
		<view class="property">
			<view class="info acea-row">
				<view class="item">
					<view>积分</view>
					<view class="bottom acea-row row-between-wrapper">
						<view class="num">{{infoData.integral}}</view>
					</view>
				</view>
				<view class="item" @click="toMemberBill">
					<view>余额</view>
					<view class="bottom acea-row row-between-wrapper">
						<view class="num">{{infoData.now_money}}</view>
					</view>
				</view>
			</view>
			<view class="info acea-row">
				<view class="item">
					<view class="acea-row row-between-wrapper">
						<view>优惠券</view>
						<view class="iconfont icon-ic_rightarrow" @click="couponSeeTap"></view>
					</view>
					<view class="bottom acea-row row-between-wrapper">
						<view class="num">{{infoData.coupon_num}}张</view>
					</view>
				</view>
				<view class="item" @click="toMember">
					<view>会员卡</view>
					<view class="bottom acea-row row-between-wrapper">
						<view class="num">{{ infoData.card_sum}}</view>
					</view>
				</view>
			</view>
		</view>
    <view class="w-710 rd-16rpx bg--w111-fff m-auto mt-20 pl-24 pr-24">
      <view class="kuai_out">
        <view class="kuai" :class="show_type == 1?'kuai_choose':''" @click="changeType(1)">订单信息</view>
        <view class="kuai" :class="show_type == 2?'kuai_choose':''" @click="changeType(2)">服务记录</view>
        <view class="kuai" :class="show_type == 3?'kuai_choose':''" @click="changeType(3)">会员档案</view>
      </view>
      <view v-if="show_type < 3">
        <view  v-for="(item, index) in ranking" :key="index" class="fs-22 text--w111-333  border-b border-b-s b-w111-F1F1F1 acea-row row-middle kuai_kuai">
          <view>
            <view class="orderId" @click="copyOrder(item.order_id)">{{ item.order_id }}</view>
            <view style="margin-bottom:10rpx;width: 300rpx" v-if="item.order_type == 0">
              <view class="tabBox" v-for="(val, i) in item._info" :key="i">
                <view class="tabBox_tit line1">
	               <view class="font-color-red" v-if="val.cart_info.is_gift">赠品</view>
	                  {{ val.cart_info.productInfo.store_name + ' | ' }}
	                    {{val.cart_info.productInfo.attrInfo?val.cart_info.productInfo.attrInfo.suk: ''}}
                    {{' | '+'￥' + val.cart_info.sum_price +' x ' + val.cart_info.cart_num }}
                 </view>
              </view>
            </view>
            <view style="margin-bottom:10rpx" v-if="item.order_type == 1">充值订单</view>
            <view style="margin-bottom:10rpx;width: 300rpx" v-if="item.order_type == 2">{{ item.link_name }}</view>
            <view style="width: 300rpx">{{ item.yeji_staff }}</view>
          </view>
          <view>
            <view class="text--w111-999 names">{{ item.add_time }}</view>
            <view class="text--w111-999 names">{{ item.store_name }}</view>
          </view>
        </view>
        <view class="bg--w111-f5f5f5 p-20" v-if="!ranking.length && !pageloading">
          <emptyPage title="暂无记录" src="/statics/images/noActivity.gif"></emptyPage>
        </view>
      </view>
      <view class="info_out" v-else>
        <view class="item acea-row row-between-wrapper">
          <view class="input_label">姓名</view>
          <input class="input" type='text' placeholder='请输入姓名'  v-model="userFrom.real_name" placeholder-class='placeholder'></input>
        </view>
        <view class="item acea-row row-between-wrapper">
          <view>生日</view>
          <view class="acea-row row-middle" @click="dataPickerTap">
            <view>
              <text v-if="userFrom.birthday">{{ userFrom.birthday }}</text>
              <text v-else>选择生日</text>
            </view>
            <text class="iconfont icon-ic_rightarrow sexChoose" style="padding-top: 8rpx"></text>
          </view>
        </view>
        <view class="item acea-row row-between-wrapper">
          <view>性别</view>
          <picker mode="selector" :range="array" @change="chooseSex">
            <view class="acea-row row-middle">
              <view>
                   <text>{{ array[index] }}</text>
              </view>
              <text class="iconfont icon-ic_rightarrow sexChoose" style="padding-top: 8rpx"></text>
            </view>
          </picker>
        </view>
        <view class="acea-row row-between">
          <view>地址</view>
          <view class="money acea-row row-right">
            <textarea class="reason" placeholder="请输入地址" placeholder-class='placeholder' v-model="userFrom.addres" fixed :cursor-spacing="100"></textarea>
          </view>
        </view>
        <view class="acea-row row-between" style="margin-top: 20rpx">
          <view>备注</view>
          <view class="money acea-row row-right">
            <textarea class="reason" placeholder="请输入备注" placeholder-class='placeholder' v-model="userFrom.mark" fixed :cursor-spacing="100"></textarea>
          </view>
        </view>
        <button class='keepBnt' @click="doSave">立即保存</button>
      </view>
    </view>
		<coupon ref="coupon" :visible='visibleCoupon' :uid='parseInt(uid)' @closeDrawer='couponCloseDrawer'></coupon>
    <uni-datetime-picker ref="dataPicker" type="date" @change='changeData'>
      <view></view>
    </uni-datetime-picker>
	</view>
</template>

<script>
	import coupon from './components/coupon/index.vue';
  import emptyPage from '@/components/emptyPage.vue';
	import {
		getStoreUserInfo,
		getUserInfo,
    getOrderList,
		getGroupList,
		getLevelList,
    saveUser,
		postUserUpdate
	} from "@/api/admin";
	export default {
		components: {
			coupon,
      emptyPage
		},
		data() {
			return {
        array: ['其他','男', '女'],
        index: 0,
        userFrom:{},
        loading: false,
        loadend: false,
        pageloading: false,
        show_type:1,
        ranking:[],
				getHeight: this.$util.getWXStatusHeight(),
				uid: 0,
        page: 1,
        limit: 20,
				infoData: {},
				groupArray:[],
				levelArray:[],
				groupIndex:-1,
				levelIndex:-1,
				visibleLable:false,
				visibleBalance:false,
				type: 0,
				visibleMember:false,
				visibleCoupon:false,
				isShow:false,
				types:1 //判断是门店页面还是平台页面
			}
		},
		onLoad(options) {
			this.uid = options.uid
			this.userInfo(this.types);
      this.agentStore();
		},
    onReachBottom: function() {
      this.agentStore();
    },
		methods: {
      doSave(){
        saveUser(this.userFrom).then(res=>{
          this.$util.Tips({
            title: '保存成功'
          });
        }).catch(err=>{
          this.$util.Tips({
            title: err
          })
        })
      },
      dataPickerTap(){
        this.$refs.dataPicker.show();
      },
      changeData(e){
         this.userFrom.birthday=e;
      },
      chooseSex: function(e) {
        this.index = e.detail.value
        this.userFrom.sex=e.detail.value;
      },
      copyOrder(key){
        uni.setClipboardData({
          data: key, // 要复制的文本
          success: () => {
            // 复制成功的提示反馈
            this.$util.Tips({
              title: '复制成功'
            });
          },
          fail: (error) => {
            // 复制失败的提示与调试信息
            this.$util.Tips({
              title: '复制失败'
            });
          }
        });
      },
      changeType(type){
        this.page=1;
        this.ranking=[];
        this.loadend=false;
        this.show_type=type;
        if(type < 3) {
          this.agentStore();
        }
      },
      agentStore(){
        let data = {
          uid:this.uid,
          show_type:this.show_type,
          page:this.page,
          limit:this.limit
        }
        let that=this;
        if(this.loadend){
          return false;
        }
        getOrderList(data).then(res=>{
          var seckillList = res.data;
          var loadend = seckillList.length < that.limit;
             that.page++;
             that.ranking = that.ranking.concat(seckillList),
              that.pageloading = false;
             that.loadend = loadend;
        }).catch(err=>{
          this.$util.Tips({
            title: err
          })
        })
      },
			openTap(){
				this.isShow = !this.isShow
			},
			couponTap(){
				this.$refs.coupon.userCoupon(0);
				this.visibleCoupon = true;
			},
			couponSeeTap(){
				this.$refs.coupon.userCoupon(2);
				this.visibleCoupon = true;
			},
      toMember(){
        uni.navigateTo({
          url: '/pages/users/user_card_list/index?uid='+this.infoData.uid
        })
      },
      toMemberBill(){
        uni.navigateTo({
          url: '/pages/users/user_bill/index?uid='+this.infoData.uid+"&type=1"
        })
      },
			couponCloseDrawer(e){
				this.visibleCoupon = false;
				if(e){
					this.userInfo();
				}
			},
			memberTap(){
				this.visibleMember = true;
			},
			memberCloseDrawer(){
				this.visibleMember = false;
			},
			balanceTap(){
				this.type = 1;
				this.visibleBalance = true;
			},
			integralTap(){
				this.type = 0;
				this.visibleBalance = true;
			},
			successChange(){
				this.visibleBalance = false
				this.visibleMember = false;
				this.userInfo();
			},
			balanceCloseDrawer(){
				this.visibleBalance = false
			},
			lableCloseDrawer(e){
				this.visibleLable = false
				if(e){
					this.userInfo();
				}
			},
			editLabels(){
				this.visibleLable = true
				this.$refs.lable.productLabel(JSON.parse(JSON.stringify(this.infoData)),0,[]);
			},
			bindPickerChange(e){
				this.groupIndex = e.detail.value
				this.userUpdate(4)
			},
			bindLevelChange(e){
				this.levelIndex = e.detail.value
				this.userUpdate(1)
			},
			userUpdate(num){
				let data = {}
				if(num == 4){
					data = {
						type:4,
						uid:this.uid,
						group_id:this.groupArray[this.groupIndex].id
					}
				}else{
					data = {
						type:1,
						uid:this.uid,
						level:this.levelArray[this.levelIndex].id
					}
				}
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
			levelList(){
				getLevelList().then(res=>{
					let id = this.infoData.level
					res.data.list.forEach((item,index)=>{
						if(item.id == id){
							this.levelIndex = index
						}
					})
					this.levelArray = res.data.list;
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			groupList(){
				getGroupList().then(res=>{
					let id = this.infoData.group_id
					res.data.forEach((item,index)=>{
						if(item.id == id){
							this.groupIndex = index
						}
					})
					this.groupArray = res.data;
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			goarrow(){
				uni.navigateBack()
			},
			userInfo(num){
				let funApi = '';
				if(this.types){
					funApi = getUserInfo;
				}else{
					funApi = getStoreUserInfo;
				}
				funApi(this.uid).then(res=>{
					this.infoData = res.data;
          this.userFrom={
            uid:res.data.uid,
            real_name:res.data.real_name,
            birthday:res.data.birthday,
            sex:res.data.sex,
            addres:res.data.addres,
            mark:res.data.mark
          };
          this.index=res.data.sex;
					// if(num){
					// 	this.groupList();
					// 	this.levelList();
					// }
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			}
		}
	}
</script>

<style lang="scss" scoped>
.reason {
  width: 462rpx !important;
  height: 120rpx !important;
  padding:10rpx 10rpx;
  border: 2rpx solid #eee;
  border-radius: 10rpx;
}
.sexChoose{
    font-size: 14px;
}
.input_label{
  width: 300rpx;
}
.input{
   border: 2rpx solid #eee;
   height: 60rpx;
   line-height: 60rpx;
   padding-left: 20rpx;
  border-radius: 10rpx;
}
.placeholder {
  color: #ccc;
}
input {
  flex: 1;
  font-size: 30rpx;
}
.keepBnt {
  position: fixed;
  right: 20rpx;
  bottom: 40rpx;
  left: 20rpx;
  height: 80rpx;
  border-radius: 40rpx;
  text-align: center;
  line-height: 80rpx;
  font-size: 28rpx;
  color: #fff;
  background-color: $primary-admin;
}
.info_out{
  padding-bottom: 30rpx;
}
.info_out .item{
   height: 80rpx;
  line-height: 80rpx;
}
.dataRange{
  color: #fff;
  font-size: 20rpx;
  margin-left: 10rpx;
}
.kuai_kuai{
  justify-content: space-between;
  padding-bottom: 20rpx;
  margin-bottom: 20rpx;
}
.names{
  font-size: 22rpx;
  margin-bottom: 10rpx;
}
.orderId{
  font-size: 22rpx;
  margin-bottom: 10rpx;
  color: #2A7EFB;
}
.title_out{
  display: flex;
  justify-content: space-between;
}
.kuai_out{
  padding-top: 20rpx;
  display: flex;
  gap:20px;
  margin-bottom: 20px;
  margin-top: 20px;
  font-size: 24rpx;
  overflow-x: scroll;
  width: 100%;
  overflow-y: hidden;
}
.kuai{
  padding-bottom: 10px;
  flex-shrink: 0;
}
.kuai_choose{
  color: #2A7EFB;
  border-bottom: 2px solid #2A7EFB;
}
	.accountTitle{
		background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);
		position: fixed;
		left:0;
		top:0;
		width: 100%;
		z-index: 99;
		.sysTitle{
			width: 100%;
			position: relative;
			font-weight: 500;
			color: #fff;
			font-size: 30rpx;
			.iconfont{
				position: absolute;
				font-size: 39rpx;
				left:11rpx;
				width: 60rpx;
				font-weight: 600;
			}
		}
	}
	.user-details{
		padding-bottom: 1rpx;
		padding-bottom: calc(1rpx + constant(safe-area-inset-bottom)); ///兼容 IOS<11.2/
		padding-bottom: calc(1rpx + env(safe-area-inset-bottom)); ///兼容 IOS>11.2/
		.header{
			background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);
			height: 346rpx;
			padding: 20rpx 30rpx 0 30rpx;
			position: relative;
			.picTxt{
				.pictrue{
					width: 112rpx;
					height: 112rpx;
					image {
						width: 100%;
						height: 100%;
						border-radius: 50%;
					}
				}
				.text{
					margin-left: 32rpx;
					font-size: 24rpx;
					font-family: PingFang SC, PingFang SC;
					font-weight: 400;
					color: rgba(255, 255, 255, 0.5);
					.name{
						margin-bottom: 12rpx;
						.nameCon{
							font-size: 32rpx;
							color: #fff;
							max-width: 300rpx;
						}
						.svip{
							width: 56rpx;
							height: 26rpx;
							background: linear-gradient(270deg, #484643 0%, #1F1B17 100%);
							border-radius: 14rpx;
							font-size: 18rpx;
							font-weight: 600;
							color: #FDDAA4;
							margin-left: 12rpx;
						}
						.vip{
							width: 68rpx;
							height: 26rpx;
							background: #FEF0D9;
							margin-left: 12rpx;
							border-radius: 50rpx;
							font-size: 18rpx;
							font-weight: 500;
							color: #DFA541;
							.iconfont{
								font-size: 20rpx;
								margin-right: 4rpx;
							}
						}
					}
				}
			}
			.bottom{
				font-size: 26rpx;
				font-family: PingFang SC, PingFang SC;
				font-weight: 400;
				color: rgba(255, 255, 255, 0.8);
				margin-top: 40rpx;
				.item{
					margin-right: 44rpx;
					.num{
						margin-left: 8rpx;
						color: #FFFFFF;
					}
				}
			}
			&::after{
				position: absolute;
				content: '';
				width: 50%;
				height: 88rpx;
				background: linear-gradient(180deg, #2A7EFB 0%, #F5F5F5 100%);
				left:0;
				bottom: 0;
			}
			&::before{
				position: absolute;
				content: '';
				width: 50%;
				height: 88rpx;
				background: linear-gradient(180deg, #01ABF8 0%, #F5F5F5 100%);
				right:0;
				bottom: 0;
			}
		}
		.list{
			width: 710rpx;
			background-color: #fff;
			border-radius: 24rpx;
			margin: -96rpx auto 0 auto;
			position: relative;
			font-family: PingFang SC, PingFang SC;
			color: #333333;
			padding: 32rpx 24rpx 40rpx 24rpx;
			.title{
				margin-bottom: 40rpx;
				.name{
					font-size: 30rpx;
					font-weight: 600;
				}
				.tip{
					font-size: 26rpx;
					font-weight: 400;
					color: #999999;
					.iconfont {
						font-size: 22rpx;
						margin-left: 4rpx;
					}
				}
			}
			.listn{
				margin-top: 52rpx;
			}
			.item {
				font-weight: 400;
				&~.item{
					margin-top: 52rpx;
				}
				.not{
					color: #999;
				}
				.iconfont {
					font-size: 26rpx;
					color: #999;
					margin-left: 6rpx;
				}
				.info{
					color: #999999;
					width: 465rpx;
					text-align: right;
				}
				.add{
					font-size: 24rpx;
					color: #2A7EFB;
					.iconfont {
						margin-right: 8rpx;
						color: #2A7EFB;
					}
				}
				.labelList{
					margin-top: 12rpx;
					.label{
						border: 1px solid #2A7EFB;
						border-radius: 20rpx;
						font-size: 20rpx;
						color: #2A7EFB;
						padding: 4rpx 16rpx;
						margin-right: 16rpx;
						margin-top: 16rpx;
					}
				}
			}
		}
		.property{
			width: 710rpx;
			background-color: #fff;
			border-radius: 24rpx;
      margin: -150rpx auto 0 auto;
      position: relative;
			font-family: PingFang SC, PingFang SC;
			.title{
				font-size: 30rpx;
				font-family: PingFang SC, PingFang SC;
				font-weight: 600;
				color: #333333;
				padding: 32rpx 24rpx 2rpx 24rpx;
				margin-bottom: 32rpx;
			}
			.info{
				margin: 0 26rpx;
				.item{
					width: 50%;
					font-size: 28rpx;
					font-weight: 400;
					color: #999999;
					padding: 10rpx 40rpx 40rpx 18rpx;
					&~.item{
						border-left: 1px solid #EEEEEE;
						padding-left: 40rpx;
						padding-right: 14rpx;
					}
					.bottom{
						margin-top: 20rpx;
						.num{
							font-size: 30rpx;
							font-weight: 600;
							color: #333333;
						}
						.give{
							font-size: 24rpx;
							font-weight: 400;
							color: #2A7EFB;
							padding-left: 40rpx;
						}
					}
					.icon-ic_rightarrow{
						font-size: 26rpx;
						padding: 6rpx 0 6rpx 40rpx;
					}

				}
				&~.info{
					border-top: 1px solid #EEEEEE;
					.item{
						padding-top: 40rpx;

					}
				}
			}
		}
	}
</style>
