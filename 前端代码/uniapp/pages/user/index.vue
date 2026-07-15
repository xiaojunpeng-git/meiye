<template>
	<!-- 个人中心模块 -->
	<view class="user-page" :style="colorStyle">
		<template v-if="isObjectData(diyData)">
			<user-member :userInfo="userInfo" :memberData="diyData.member" :orderAdminData="orderAdminData" :balanceStatus="balanceStatus" :isScrolling="isScrolling"></user-member>
			<user-order :orderMenu="orderMenu" :orderAdminData="orderAdminData" :userInfo="userInfo" :memberData="diyData.member" :orderData="diyData.order"></user-order>
			<user-order-static
				v-if="isObjectData(orderAdminData) && orderAdminData.order.user_order && userInfo"
				:orderAdminData="orderAdminData.order"
				:orderStaticData="diyData.orderStatic"
			></user-order-static>
			<user-poster :posterData="diyData.poster"></user-poster>
			<user-menu :menuData="diyData.menu" :routineContact='routineContact'></user-menu>
			<!-- 旧商家入口已由统一商家端替代
			<user-mer-menu v-if="diyData.merMenu.list.length" :merMenuData="diyData.merMenu"></user-mer-menu>
			<user-agent-menu v-if="diyData.agentMenu.list.length" :agentMenuData="diyData.agentMenu"></user-agent-menu>
			<user-store-menu v-if="diyData.storeMenu.list.length" :storeMenuData="diyData.storeMenu"></user-store-menu>
			-->
			<view class="m0ee4 pb-20">
				<template v-if="m854f8">
					<image :src="m6d38d" mode="aspectFill" class="m6d38d"></image>
					<view class="m854f8">
						{{ m854f8 }}
					</view>
				</template>
			</view>
		</template>
		<!-- 店员推广码 extension-->
		<view class="fixed-ct w-620 h-690 bg--w111-fff rd-24rpx ml-f310 mt-f400 z-1000" v-show="isextension" @touchmove.stop.prevent>
		  <view class="fs-32 text-#333 fw-500 mt-48 mb-48 text-center">店员推广码</view>
		  <view class="acea-row row-center-wrapper">
			<!-- #ifdef MP -->
			<image :src="extension.code" class="w-380 h-380" @click="downloadCode"></image>
			<!-- #endif -->
			<!-- #ifndef MP -->
		    <view class="acea-row row-center-wrapper" @click="downloadCode">
		      <w-qrcode id="hah" :options="extension" @generate="hello"></w-qrcode>
		    </view>
			<!-- #endif -->
		    <view class="fs-26 text--w111-999 mt-40 w-312 text-center">如遇到扫码失败，</view>
			<view class="fs-26 text--w111-999 mt-10 w-312 text-center">请将屏幕调至最亮重新扫码</view>
		  </view>
		  <view class="iconfont icon-ic_close1 text--w111-fff absolute fs-50 bottom-f100rpx left-50 ml-f25" @click="closeCode"></view>
		</view>
		<view class="fixed-lt bg-w111-000-s111-80 w-full h-full z-999" v-show="isextension" @touchmove.stop.prevent></view>
		<pageFooter :style="colorStyle"></pageFooter>
		<merchant-switch side="buyer" />
		<ewcomerPop v-if="isComerGift" :comerGift="comerGift" @comerPop="comerPop"></ewcomerPop>
		<!-- #ifdef MP -->
		<editUserModal :isShow="editModal" @closeEdit="closeEdit" @editSuccess="editSuccess"></editUserModal>
		<!-- #endif -->
	</view>
</template>
<script>
import { storeCardApi } from '@/api/store.js';
import { newcomerGift } from '@/api/activity.js';
import { copyRight } from '@/api/api.js';
import {getCartCounts} from '@/api/order.js';
import { getMenuList, getUserInfo, setVisit, updateUserInfo, getRandCode, updateWechatInfo, getMenuData } from '@/api/user.js';
import { wechatAuthV2, silenceAuth, routineBindingPhoneNumber } from '@/api/public.js';
import { toLogin } from '@/libs/login.js';
import { mapState, mapGetters } from 'vuex';
// #ifdef H5
import Auth from '@/libs/wechat';
// #endif
import { HTTP_REQUEST_URL } from '@/config/app';
const app = getApp();
import ewcomerPop from '@/components/ewcomerPop/index.vue';
import pageFooter from '@/components/pageFooter/index.vue';
import dayjs from '@/plugin/dayjs/dayjs.min.js';
import Routine from '@/libs/routine';
import colors from '@/mixins/color';
// #ifdef MP
import editUserModal from '@/components/eidtUserModal/index.vue';
// #endif
import userMember from './components/member/index.vue';
import userOrder from './components/order/index.vue';
import userOrderStatic from './components/order_static/index.vue';
import userMenu from './components/menus/index.vue';
// 旧商家入口组件保留目录，停止渲染
// import userMerMenu from './components/merMenus/index.vue';
// import userAgentMenu from './components/agentMenus/index.vue';
// import userStoreMenu from './components/storeMenus/index.vue';
import userPoster from './components/poster/index.vue';
import merchantSwitch from '@/components/merchantSwitch/index.vue';

export default {
	components: {
		userMember,
		userOrder,
		userOrderStatic,
		userMenu,
		// userMerMenu,
		// userAgentMenu,
		// userStoreMenu,
		userPoster,
		pageFooter,
		merchantSwitch,
		ewcomerPop,
		// #ifdef MP
		editUserModal
		// #endif
	},
	computed: {
		...mapGetters({
			cartNum: 'cartNum',
			isLogin: 'isLogin'
		})
	},
	filters: {},
	mixins: [colors],
	provide() {
		return {
			tapQrCode: this.tapQrCode,
			bindPhone: this.bindPhone,
			intoPage: this.intoPage,
			goMenuPage: this.goMenuPage,
			getMenuData: this.getMenuData,
			goEdit: this.goEdit,
			getPhoneNumber: this.getPhoneNumber,
		};
	},
	data() {
		return {
			diyData: {},
			orderAdminData: {},
			// #ifdef MP
			getHeight: this.$util.getWXStatusHeight(),
			// #endif
			vipStatus: 0,
			stu: false,
			orderMenu: [
				{
					icon: '',
					title: '待付款',
					url: '/pages/goods/order_list/index?status=0'
				},
				{
					icon: 'icon-ic_money',
					title: '欠款',
					url: '/pages/goods/order_list/index?status=debt',
					debt_amount_text: ''
				},
				{
					icon: '',
					title: '待发货',
					url: '/pages/goods/order_list/index?status=1'
				},
				{
					icon: '',
					title: '待收货',
					url: '/pages/goods/order_list/index?status=10'
				},
				{
					icon: '',
					title: '待评价',
					url: '/pages/goods/order_list/index?status=3'
				},
				{
					icon: '',
					title: '售后/退款',
					url: '/pages/users/user_return_list/index'
				}
			],
			imgUrls: [],
			orderStatusNum: {},
			userInfo: {},
			showStatus: 1,
			routineContact: 0,
			// #ifdef H5 || MP
			pageHeight: '100%',
			// #endif
			// #ifdef APP-PLUS
			pageHeight: app.globalData.windowHeight,
			// #endif
			// #ifdef H5
			isWeixin: Auth.isWeixin(),
			//#endif
			footerSee: false,
			member_style: 1,
			my_banner_status: 1,
			menu_status: 1,
			service_status: 1,
			newcomer_status: 1,
			isextension: false,
			extension: {
				code: '',
				size: 380, // 二维码大小
				level: 3, //等级 0～4
				bgColor: '#FFFFFF', //二维码背景色 默认白色
				color: ['#333', '#333'] //边框颜色支持渐变色
			},
			imgHost: HTTP_REQUEST_URL,
			m854f8: '',
			m6d38d: '',
			giftPic: '',
			vip_type: 1,
			newcomer_style: 1,
			newList: [],
			newBg: '',
			comerGift: {},
			isComerGift: false,
			memberStatus: 0,
			balanceStatus: 0, // 余额是否展示
			editModal: false, // 编辑头像信息
			isScrolling: false,
			orderStyle: {
				1: ['icon-ic_daifukuan12', 'icon-ic_daifahuo11', 'icon-ic_daishouhuo1', 'icon-ic_daipingjia1', 'icon-ic_daituikuan1'],
				2: ['icon-ic_daifukuan2', 'icon-ic_daifahuo2', 'icon-ic_daishouhuo2', 'icon-ic_daipingji2', 'icon-ic_daituikuan2'],
				3: ['icon-ic_daifukuan', 'icon-ic_daifahuo', 'icon-ic_daishouhuo', 'icon-ic_daipingjia', 'icon-ic_daituikuan']
			},
			codeImg:''
		};
	},
	created() {
		let that = this;
		// #ifdef MP
		// 小程序静默授权
		if (!this.$store.getters.isLogin) {
			// Routine.getCode()
			//   .then(code => {
			//     Routine.silenceAuth(code).then(res => {
			//       this.onLoadFun();
			//     })
			//   })
			//   .catch(res => {
			//     uni.hideLoading();
			//   });
		}
		// #endif
		// #ifdef APP-PLUS
		that.$set(that, 'pageHeight', app.globalData.windowHeight);
		// #endif
	},
	async onLoad(option) {
		if (this.isLogin) {
			// this.getNewcomerGift();
		}
		// #ifdef H5
		if (this.isLogin && Auth.isWeixin() && option.code) {
			try {
				let res = await updateWechatInfo({
					code: option.code
				});
				this.userInfo.nickname = res.data.nickname;
				this.userInfo.avatar = res.data.avatar;
				this.userInfo.is_complete = 1;
			} catch (e) {}
		}
		// #endif
		this.colorData();
	},
	onReady() {},
	onShow() {
		if (!this.isLogin) {
			this.userInfo = {};
			this.balanceStatus = 1;
		}
		this.getCartNum();
		this.m854f8 = uni.getStorageSync('copyNameInfo');
		this.m6d38d = uni.getStorageSync('copyImageInfo');
		uni.removeStorageSync('form_type_cart');
		if (!this.m854f8) {
			this.getCopyRight();
		}
		let that = this;
		// #ifdef APP-PLUS
		uni.getSystemInfo({
			success: function (res) {
				that.pageHeight = res.windowHeight + 'px';
			}
		});
		// #endif
		this.getMyMenus();
		if (that.isLogin) {
			this.getUserInfo();
			this.setVisit();
			this.getMenuData();
		}else{
			this.orderMenu.forEach((item, index) => {
				item.num = 0;
				if (item.title === '欠款') {
					item.debt_amount_text = '';
				}
			});
		}
	},
	// #ifdef MP || APP-PLUS
	onPageScroll(e) {
		if (e.scrollTop > 50) {
			this.isScrolling = true;
		} else if (e.scrollTop < 50) {
			this.isScrolling = false;
		}
	},
	// #endif
	onPullDownRefresh() {
		this.getCartNum();
		if (!this.m854f8) {
			this.getCopyRight();
		}
		this.getUserInfo();
		this.getMyMenus();
		this.setVisit();
		this.getMenuData();
		setTimeout(() => {
			uni.stopPullDownRefresh();
		}, 1000);
	},
	methods: {
		getCartNum: function() {
			if(!this.isLogin){
				return false
			}
			getCartCounts().then(res => {
				this.$store.commit('indexData/setCartNum', res.data.resultAll.count + '')
				let cartNum = res.data.resultAll.count;
				if (cartNum > 0) {
					uni.setTabBarBadge({
						index: 3,
						text: cartNum > 99 ? '99+' : cartNum + ''
					})
				} else {
					uni.hideTabBarRedDot({
						index: 3
					})
				}
			}).catch(err=>{
				return this.$util.Tips({
					title: err.msg
				});
			})
		},
		hello(res){
			this.codeImg = res.img.tempFilePath;
		},
		downloadCode() {
		  let that = this
		  // #ifdef H5
		  var canvas = document.getElementById("hah").getElementsByTagName("canvas")
		  var imgURL = canvas[0].toDataURL("image/png")
		  var dlLink = document.createElement('a');
		  dlLink.download = "店员推广码";
		  dlLink.href = imgURL;
		  dlLink.dataset.downloadurl = ["image/png", dlLink.download, dlLink.href].join(':');
		  document.body.appendChild(dlLink);
		  dlLink.click();
		  document.body.removeChild(dlLink);
		  // #endif
		  // #ifdef MP
		  uni.downloadFile({
		  	url: that.extension.code,
		  	success: (res) => {
		  		if (res.statusCode === 200) {
					uni.saveImageToPhotosAlbum({
						filePath: res.tempFilePath,
						success: function(res) {
							that.$util.Tips({
								title: '保存成功',
								icon: 'success'
							});
						},
						fail: function(res) {
										console.log(res)
							that.$util.Tips({
								title: '保存失败'
							});
						}
					});
		  		}
		  	}
		  });
		  // #endif
		  // #ifdef APP-PLUS
		  uni.saveImageToPhotosAlbum({
		  	filePath: that.codeImg,
		  	success: function(res) {
		  		that.$util.Tips({
		  			title: '保存成功',
		  			icon: 'success'
		  		});
		  	},
		  	fail: function(res) {
		  		that.$util.Tips({
		  			title: '保存失败'
		  		});
		  	}
		  });
		  // #endif
		},
		closeCode() {
		  this.isextension = false
		},
		isObjectData(obj) {
			return Object.keys(obj).length !== 0;
		},
		// #ifdef MP
		editSuccess() {
			this.editModal = false;
			this.getUserInfo();
		},
		closeEdit() {
			this.editModal = false;
		},
		// #endif
		getCopyRight() {
			copyRight()
				.then((res) => {
					let data = res.data;
					if (!data.copyrightContext && !data.copyrightImage) {
						data.copyrightImage = '';
					}
					uni.setStorageSync('copyNameInfo', data.copyrightContext);
					uni.setStorageSync('copyImageInfo', data.copyrightImage);
					this.m854f8 = data.copyrightContext;
					this.m6d38d = data.copyrightImage;
				})
				.catch((err) => {
					return this.$util.Tips({
						title: err.msg
					});
				});
		},
		// 查看订单
		intoPage(url) {
			if (this.isLogin) {
				if (['/pages/users/user_vip/index', '/pages/annex/vip_grade_active/index'].includes(url) && !this.userInfo.member_func_status) {
					this.$util.Tips({
						title: '会员卡功能暂未开启'
					});
				} else{
					this.$util.JumpPath(url);
				}
			} else {
				toLogin();
			}
		},
		// 编辑页面
		goEdit() {
			if (this.isLogin == false || !this.userInfo.uid) {
				toLogin();
			} else {
				// #ifdef MP
				if (this.userInfo.is_default_avatar) {
					this.editModal = true;
					return;
				}
				// #endif
				uni.navigateTo({
					url: '/pages/users/user_set/index'
				});
			}
		},
		goDetail(item) {
			uni.navigateTo({
				url: `/pages/goods_details/index?id=${item.id}&fromPage='newVip'`
			});
		},
		comerPop() {
			this.isComerGift = false;
		},
		getNewcomerGift() {
			if (uni.getStorageSync('newcomerGift')) {
				return (this.isComerGift = false);
			}
			newcomerGift()
				.then((res) => {
					this.comerGift = res.data;
					if (Object.prototype.toString.call(this.comerGift) == '[object Object]') {
						if (res.data.coupon_count || res.data.product_count || res.data.register_give_money || res.data.first_order_discount || res.data.register_give_integral) {
							uni.setStorageSync('newcomerGift', true);
							this.isComerGift = true;
						}
					}
				})
				.catch((err) => {
					return this.$util.Tips({
						title: err
					});
				});
		},
		tapQrCode() {
			if(this.isLogin){
				uni.navigateTo({
					url: '/pages/users/user_member_code/index'
				});
			}else{
				toLogin();
			}
		},
		getRoutineUserInfo(e) {
			updateUserInfo({
				userInfo: e.detail.userInfo
			})
				.then((res) => {
					this.getUserInfo();
					return this.$util.Tips('更新用户信息成功');
				})
				.catch((err) => {
					return this.$util.Tips(err);
				});
		},
		// 记录会员访问
		setVisit() {
			setVisit({
				url: '/pages/user/index'
			}).then((res) => {});
		},
		// 授权回调
		onLoadFun(e) {
			this.getUserInfo(e);
			this.getMyMenus();
			this.setVisit();
		},
		// 绑定手机
		bindPhone() {
			uni.navigateTo({
				url: '/pages/users/user_phone/index'
			});
		},
		// 获取行销数据
		getMenuData() {
			getMenuData().then((res) => {
				this.orderAdminData = res.data;
			});
		},
		/**
		 * 获取个人用户信息
		 */
		getUserInfo() {
			let that = this;
			getUserInfo().then((res) => {
				that.userInfo = res.data;
				that.stu = res.data.svip_open;
				that.balanceStatus = res.data.balance_func_status;
				that.memberStatus = parseInt(res.data.member_func_status);
				that.vipStatus = res.data.vip_status;
				that.$store.commit('SETUID', res.data.uid);
				that.$store.commit('UPDATE_USERINFO', res.data);
				that.orderMenu.forEach((item, index) => {
					switch (item.title) {
						case '待付款':
						this.$set(item,'num',res.data.orderStatusNum.unpaid_count);
							break;
						case '欠款':
							this.$set(item, 'num', res.data.orderStatusNum.debt_order_count || 0);
							const pendingTotal = Number(res.data.orderStatusNum.debt_pending_total || 0);
							this.$set(item, 'debt_amount_text', pendingTotal > 0 ? `¥${pendingTotal}` : '');
							break;
						case '待发货':
						this.$set(item,'num',res.data.orderStatusNum.unshipped_count);
							break;
						case '待收货':
						this.$set(item,'num',res.data.orderStatusNum.received_count);
							break;
						case '待评价':
						this.$set(item,'num',res.data.orderStatusNum.evaluated_count);
							break;
						case '售后/退款':
						this.$set(item,'num',res.data.orderStatusNum.refunding_count);
							break;
					}
				});
			});
		},
		getMyMenus: function () {
			let that = this;
			getMenuList().then((res) => {
				this.diyData = res.data.diy_data;
				this.switchTab(this.diyData.order.style);
				this.my_banner_status = res.data.diy_data.my_banner_status;
				this.menu_status = res.data.diy_data.menu_status;
				this.service_status = res.data.diy_data.service_status;
				this.vip_type = res.data.diy_data.vip_type;
				this.newcomer_style = res.data.diy_data.newcomer_style;
				this.newcomer_status = res.data.diy_data.newcomer_status;
				this.imgUrls = res.data.routine_my_banner;
				this.routineContact = Number(res.data.routine_contact_type);
			});
		},
		switchTab(style) {
			let iconIndex = 0;
			this.orderMenu.forEach((item) => {
				if (item.title === '欠款') {
					item.icon = item.icon || 'icon-ic_money';
					return;
				}
				item.icon = this.orderStyle[style][iconIndex] || '';
				iconIndex++;
			});
		},
		goMenuPage(url, name) {
			if (this.isLogin) {
				let arr = url.split('@APPID=');
				if (arr.length > 1) {
					//#ifdef MP
					uni.navigateToMiniProgram({
						appId: arr[arr.length - 1],
						path: arr[0],
						envVersion: 'release',
						success: (res) => {
							console.log('打开成功', res);
						},
						fail: (err) => {
							console.log('sgdhgf', err);
						}
					});
					//#endif
					//#ifndef MP
					this.Tips({
						title: 'h5与app端不支持跳转外部小程序'
					});
					//#endif
				} else {
					if (url == '/pages/extension/customer_list/chat' || url == 'https://chat.mohe.net/chat/mobile') {
						this.$util.getCustomer(this.userInfo);
					} else {
						if (url.indexOf('http') === -1) {
							// #ifdef H5
							if (name && name === '订单核销') {
								return (window.location.href = `${location.origin}${url}`);
							}
							// #endif
							// #ifdef MP
							if (url != '#' && url == '/pages/users/user_set/index') {
								uni.openSetting({
									success: function (res) {}
								});
							}
							// #endif
							if (url == '/pages/store_spread/index') {
								storeCardApi()
									.then((res) => {
										this.isextension = true;
										this.$nextTick(function () {
											this.extension.code = res.data.url;
										});
									})
									.catch((err) => {
										uni.hideLoading();
										this.$util.Tips({
											title: err
										});
									});
							}
							if (url == '/pages/users/distributor/apply') {
								if (this.userInfo.promoter_status == -1) {
									uni.navigateTo({
										url: '/pages/users/distributor/apply'
									});
								} else {
									uni.navigateTo({
										url: '/pages/users/agent/state'
									});
								}
								return;
							}
							if (
								['/pages/goods_cate/goods_cate', '/pages/order_addcart/order_addcart', '/pages/user/index', '/pages/store_cate/store_cate', '/pages/index/index','/pages/store_list/index'].indexOf(url) == -1
							) {
								uni.navigateTo({
									url: url
								});
							} else {
								uni.reLaunch({
									url: url
								});
							}
						} else {
							// #ifdef H5
							this.$util.JumpPath(url);
							// #endif
							// #ifndef H5
							uni.navigateTo({
								url: `/pages/annex/web_view/index?url=${url}`
							});
							// #endif
						}
					}
				}
			}else{
				toLogin();
			}
		},
		// 绑定手机号码
		getPhoneNumber(e) {
			if (e.detail.errMsg == 'getPhoneNumber:ok') {
				Routine.getCode().then(code => {
					routineBindingPhoneNumber({
						code,
						encryptedData: e.detail.encryptedData,
						iv: e.detail.iv,
						uid: this.userInfo.uid,
					}).then(res => {
						this.getUserInfo();
						this.$util.Tips({
							title: res.msg,
							icon: 'success'
						});
					}).catch(err => {
						this.$util.Tips({
							title: err
						});
					});
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				});
			}
		}
	}
};
</script>

<style lang="scss" scoped>
::v-deep .uni-badge-left-margin .uni-badge--error {
	background-color: var(--view-theme) !important;
}
.footer-placeholder {
	height: calc(98rpx + constant(safe-area-inset-bottom));
	height: calc(98rpx + env(safe-area-inset-bottom));
	height: 98rpx;
}
.user-page {
	padding-bottom: calc(100rpx + constant(safe-area-inset-bottom));
	padding-bottom: calc(100rpx + env(safe-area-inset-bottom));
	padding-bottom: 100rpx;
}
.m0ee4    {
	text-align: center;
	color: #ccc;
	font-size: 22rpx;
	margin-top: 40rpx;
	.m6d38d {
		width: 219rpx;
		height: 74rpx;
		margin: 16rpx auto;
		display: block;
	}
	.support {
		width: 219rpx;
		height: 74rpx;
		margin: 54rpx auto;
		display: block;
	}
	.m854f8 {
		margin-top: 0rpx;
		color: #ccc;
		font-size: 20rpx;
		margin-bottom: 20rpx;
	}
}
</style>
