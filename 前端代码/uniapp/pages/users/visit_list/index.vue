<template>
	<!-- 浏览记录 -->
	<view>
		<view class="record" :style="colorStyle" v-if="visitList.length">
			<view class="w-full h-96 fixed-lt flex-between-center rd-b-24rpx bg--w111-fff fs-28 px-24 z-10">
				<view>共 <text class="SemiBold font-num">{{count}}</text> 件商品</view>
				<view v-if="!isShowChecked" @click="switchTap">管理</view>
				<view v-else @click="switchTap">取消</view>
			</view>
			<view class="h-96"></view>
			<view class="list mt-20">
				<checkbox-group @change="checkboxChange">
					<view class="rd-24rpx bg--w111-fff mb-20" v-for="(item,index) in visitList" :key="index">
						<view class="pt-32 pr-24 pl-24 mb-24 fs-32 lh-44rpx fw-500">
							<text>{{item.time}}</text>
						</view>
						<checkbox-group @change="(e)=>{picCheckbox(e,index)}">
							<view class="px-24">
								<view class="grid-column-3 grid-gap-x-22rpx grid-gap-y-32rpx pb-32">
									<view class="w-full" v-for="(j,jindex) in item.picList" :key="jindex" @click.stop="goDetails(j)">
										<view class="relative">
											<easy-loadimage :image-src="j.image" width="220rpx" height="220rpx" borderRadius="16rpx"></easy-loadimage>
											<checkbox v-if="isShowChecked" :value="(j.id).toString()" :checked="j.checked" class="checkbox" />
											<view class="masks flex-center" v-if="!isShowChecked && j.stock<=0">
												<view class="bg">
													<view>暂时</view>
													<view>售罄</view>
												</view>
											</view>
											<view class="masks flex-center" v-if="!isShowChecked && !j.is_show">
												<view class="bg">
													<view>暂时</view>
													<view>下架</view>
												</view>
											</view>
										</view>
										<view class="mt-20">
											<BaseMoney :money="j.product_price" symbolSize="24" integerSize="30" decimalSize="30" color="#333333"></BaseMoney>
										</view>
									</view>
								</view>
							</view>
						</checkbox-group>
					</view>
				</checkbox-group>
				<view class='loadingicon flex-center'>
					<text class='loading iconfont icon-jiazai' :hidden='loading==false'></text>{{loadTitle}}
				</view>
			</view>
			<view class="pb-safe" v-if="isShowChecked">
				<view class="h-96"></view>
			</view>
			<view class="w-full fixed-lb pb-safe bg--w111-fff" v-if="isShowChecked">
				<view class="w-full px-32 h-96 flex-between-center" >
					<checkbox-group @change="checkboxAllChange">
						<view class="acea-row row-middle">
							<checkbox value="all" :checked="isAllSelect" />
							<text class='checkAll'>全选</text>
						</view>
					</checkbox-group>
					<view class="acea-row row-middle">
						<view class="bnt flex-center" @click="collect">移至收藏</view>
						<view class="bnt on flex-center" @click="del">删除</view>
					</view>
				</view>
			</view>
		</view>
		<view class='px-20 mt-20' v-else-if="!visitList.length">
			<emptyPage title="暂无记录，去看点别的吧～" src="/statics/images/noOrder.gif"></emptyPage>
			<recommend v-if='hostProduct.length' :hostProduct="hostProduct"></recommend>
		</view>
		<home :isHide="homeHide" @homehide="onhomehide"></home>
	</view>
</template>
<script>
	import {
		getVisitList,
		getProductHot,
		deleteVisitList,
		collectAdd
	} from '@/api/store.js';
	import {
		mapGetters
	} from "vuex";
	import {
		toLogin
	} from '@/libs/login.js';
	import recommend from '@/components/recommend';
	import home from '@/components/home';
	import colors from '@/mixins/color.js';
	import emptyPage from '@/components/emptyPage.vue';
	import {
		HTTP_REQUEST_URL
	} from '@/config/app';
	export default {
		components: {
			recommend,
			home,
			emptyPage
		},
		mixins: [colors],
		data() {
			return {
				isShowChecked: 0,
				count: 0,
				times: [],
				isAllSelect: false,
				hostProduct: [],
				loadTitle: '加载更多',
				loading: false,
				loadend: false,
				visitList: [],
				limit: 21,
				page: 1,
				isAuto: false, //没有授权的不会自动授权
				isShowAuth: false, //是否隐藏授权
				hotScroll: false,
				hotPage: 1,
				hotLimit: 10,
				isItemAll: [],
				imgHost: HTTP_REQUEST_URL,
        homeHide: false
			};
		},
		computed: mapGetters(['isLogin']),
		onLoad() {
			this.get_host_product();
			this.loadend = false;
			this.page = 1;
			this.visitList = [];
			if (this.isLogin) {
				this.get_user_visit_list();
			} else {
				toLogin()
			}
		},
		onShow() {
			uni.removeStorageSync('form_type_cart');
			this.times = [];
			this.loadend = false;
			this.page = 1;
			this.visitList = [];
			this.get_user_visit_list();
		},
		methods: {
			goDetails(item) {
				if (this.isShowChecked || !item.is_show) return false;
				uni.navigateTo({
					url: '/pages/goods_details/index?id=' + item.product_id
				})
			},
			switchTap() {
				this.isShowChecked = !this.isShowChecked;
			},
			collect() {
				let ids = [];
				this.visitList.forEach(item => {
					item.picList.forEach(j => {
						if (j.checked) {
							ids.push(j.product_id)
						}
					})
				})
				if (!ids.length) {
					return this.$util.Tips({
						title: '请选择收藏商品'
					});
				}
				collectAdd(ids).then(res => {
					return this.$util.Tips({
						title: res.msg
					});
				})
			},
			del() {
				let ids = [];
				this.visitList.forEach(item => {
					item.picList.forEach(j => {
						if (j.checked) {
							ids.push(j.product_id)
						}
					})
				})
				if (!ids.length) {
					return this.$util.Tips({
						title: '请选择删除商品'
					});
				}
				deleteVisitList({
					ids
				}).then(res => {
					this.times = [];
					this.loadend = false;
					this.page = 1;
					this.$set(this, 'visitList', []);
					this.get_user_visit_list();
					return this.$util.Tips({
						title: res.msg
					});
				})
			},
			picCheckbox(event, index) {
				let that = this,
					picTime = event.detail.value;
				that.visitList[index].picList.forEach(j => {
					if (picTime.indexOf(j.id + '') !== -1) {
						j.checked = true;
					} else {
						j.checked = false;
					}
				})
				if (that.visitList[index].picList.length == picTime.length) {
					that.visitList[index].checked = true;
				} else {
					that.visitList[index].checked = false;
				}
				let visitObj = [];
				that.visitList.forEach(item => {
					if (item.checked) {
						visitObj.push(item.time)
					} else {
						if (visitObj.indexOf(item.time) !== -1) {
							visitObj.remove(item.time);
						}
					}
				})
				if (visitObj.length == that.visitList.length) {
					that.isAllSelect = true;
				} else {
					that.isAllSelect = false;
				}
			},
			checkboxChange(event) {
				let that = this,
					timeList = event.detail.value;
				that.isItemAll = timeList;
				that.visitList.forEach((item, index) => {
					if (timeList.indexOf(item.time) !== -1) {
						item.checked = true;
					} else {
						item.checked = false;
					}
					item.picList.forEach(j => {
						if (item.checked) {
							j.checked = true;
						} else {
							j.checked = false;
						}
					})
				})
				if (timeList.length === that.visitList.length) {
					that.isAllSelect = true;
				} else {
					that.isAllSelect = false;
				}
			},
			forGoods(val) {
				let that = this;
				if (!that.visitList.length) return
				that.visitList.forEach((item) => {
					if (val) {
						item.checked = true;
					} else {
						item.checked = false;
					}
					item.picList.forEach(j => {
						if (val) {
							j.checked = true;
						} else {
							j.checked = false;
						}
					})
				})
			},
			checkboxAllChange(event) {
				let value = event.detail.value;
				if (value.length) {
					this.isAllSelect = true;
					this.forGoods(1)
				} else {
					this.isAllSelect = false;
					this.forGoods(0)
				}
			},
			// 授权关闭
			authColse: function(e) {
				this.isShowAuth = e
			},
			onLoadFun() {
				this.get_user_visit_list();
				this.isShowAuth = false
			},
			/**
			 * 获取记录产品
			 */
			get_user_visit_list: function() {
				let that = this;
				if (this.loading) return;
				if (this.loadend) return;
				that.loading = true;
				that.loadTitle = "";
				getVisitList({
					page: that.page,
					limit: that.limit
				}).then(res => {
					this.count = res.data.count;
					for (let i = 0; i < res.data.time.length; i++) {
						if (this.times.indexOf(res.data.time[i]) == -1) {
							this.times.push(res.data.time[i])
							this.visitList.push({
								time: res.data.time[i],
								picList: []
							})
						}
					}
					for (let x = 0; x < this.times.length; x++) {
						this.visitList[x].checked = this.isAllSelect ? true : false;
						for (let j = 0; j < res.data.list.length; j++) {
							if (this.times[x] === res.data.list[j].time_key) {
								if (this.isAllSelect) {
									res.data.list[j].checked = true;
								} else {
									res.data.list[j].checked = false;
								}
								this.visitList[x].picList.push(res.data.list[j])
							}
						}
					}
					let loadend = res.data.list.length < that.limit;
					that.loadend = loadend;
					that.loadTitle = loadend ? '没有更多内容啦~' : '加载更多';
					that.page = that.page + 1;
					that.loading = false;
				}).catch(err => {
					that.loading = false;
					that.loadTitle = "加载更多";
				});
			},
			/**
			 * 获取我的推荐
			 */
			get_host_product: function() {
				let that = this;
				if (that.hotScroll) return
				getProductHot(
					that.hotPage,
					that.hotLimit,
				).then(res => {
					that.hotPage++
					that.hotScroll = res.data.length < that.hotLimit
					that.hostProduct = that.hostProduct.concat(res.data)
				});
			},
      onhomehide() {
				this.homeHide = false;
			}
		},
		onPageScroll(e) {
			uni.$emit('scroll');
      this.homeHide = true;
		},
		onReachBottom() {
			if (this.visitList.length) {
				this.get_user_visit_list();
			} else {
				this.get_host_product();
			}
		}
	}
</script>
<style lang="scss">
	.record .list ::v-deep checkbox .uni-checkbox-input {
		margin-right: 0;
		background-color: rgba(0, 0, 0, 0.1);
		border: 1px solid #eee;
	}

	.record .list ::v-deep checkbox .wx-checkbox-input {
		background-color: rgba(0, 0, 0, 0.1);
		border: 1px solid #eee;
	}
	.bnt {
		height: 64rpx;
		padding: 0 32rpx;
		border-radius: 32rpx;
		border: 1rpx solid var(--view-theme);
		color: var(--view-theme);
		transform: rotateZ(360deg);
	
		&.on {
			margin-left: 20rpx;
			background-color: var(--view-theme);
			color: #FFFFFF;
		}
	}
	.masks {
		position: absolute;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background: rgba(0, 0, 0, 0.2);
		border-radius: 10rpx;
	
		.bg {
			width: 110rpx;
			height: 110rpx;
			background: #000000;
			opacity: 0.6;
			color: #fff;
			font-size: 22rpx;
			border-radius: 50%;
			padding: 22rpx 0;
			text-align: center;
		}
	}
	.checkbox {
		position: absolute;
		right: 16rpx;
		top: 16rpx;
	}
</style>