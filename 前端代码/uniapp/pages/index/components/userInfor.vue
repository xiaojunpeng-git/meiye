<template>
	<view :style="[componentStyle]">
		<view class="bg--w111-fff userInfor" :style="[userInfoStyle]" @click="goLogin">
			<view class="left acea-row row-middle">
				<view class="pictrue acea-row row-center-wrapper relative">
					<image :src="diyInfo.avatar" v-if="diyInfo.avatar && isLogin"></image>
					<image src="@/static/images/king.png" class="king" v-if="diyInfo.is_money_level"></image>
					<image v-if="!diyInfo.avatar && isLogin" :src="dataConfig.logoConfig.url || '@/static/images/f.png'"></image>
					<image v-if="!isLogin" :src="dataConfig.logoConfig.url || '@/static/images/f.png'"></image>
				</view>
				<view class="text">
					<view v-if="!isLogin" class="name">请点击登录</view>
					<view v-else class="name acea-row row-middle">
						<view class="nameCon line1" :style="[userColorTxt]">{{ diyInfo.nickname }}</view>
						<view class="lable" v-if="diyInfo.level>0" :style="[lableStyle]">
							<text class="iconfont icon-ic_crown1"></text>
							{{ diyInfo.vip_name }}
						</view>
					</view>
					<view class="acea-row row-middle" v-if="isLogin && diyInfo.level>0">
						<view class="progress" :style="[progressStyle]">
							<view class="bg-reds" :style="[bgRedsStyle]">
							</view>
						</view>
						<view class="percent" :style="[progressNumColorTxt]">{{diyInfo.exp?diyInfo.exp.split('.')[0]:0}}/{{diyInfo.next_exp || 0}}</view>
					</view>
					<view class="phone acea-row row-middle" v-if="isLogin && diyInfo.level<=0 && diyInfo.phone">
						<text :style="[progressNumColorTxt]">{{diyInfo.phone}}</text>
					</view>
				</view>
				<view v-if="isLogin" class="relative fs-26 lh-36rpx text--w111-999 right acea-row row-bottom">
					<template v-if="dataConfig.styleConfig.tabVal == 1">
						<view class="flex-1 min-w-0 item" v-if="checkType.indexOf(1) > -1" @click.stop="goIntegral">
							<view class="SemiBold fs-28 text--w111-333 num" :style="[numColorTxt]">{{diyInfo.integral||2}}</view>
							<view :style="[titleColorTxt]">积分</view>
						</view>
						<view class="flex-1 min-w-0 item" v-if="checkType.indexOf(2) > -1" @click.stop="goMoney">
							<view class="SemiBold fs-28 text--w111-333 num" :style="[numColorTxt]">{{diyInfo.now_money||6}}</view>
							<view :style="[titleColorTxt]">余额</view>
						</view>
						<view class="flex-1 min-w-0 item" v-if="checkType.indexOf(0) > -1" @click.stop="goCoupon">
							<view class="SemiBold fs-28 text--w111-333 num" :style="[numColorTxt]">{{diyInfo.coupon_num||0}}</view>
							<view :style="[titleColorTxt]">优惠券</view>
						</view>
						<view class="flex-1 min-w-0 item" v-if="checkType.indexOf(4) > -1" @click.stop="goCollection">
							<view class="SemiBold fs-28 text--w111-333 num" :style="[numColorTxt]">{{diyInfo.collectCount||0}}</view>
							<view :style="[titleColorTxt]">收藏</view>
						</view>
						<view class="flex-1 min-w-0 item" v-if="checkType.indexOf(5) > -1" @click.stop="goVisit">
							<view class="SemiBold fs-28 text--w111-333 num" :style="[numColorTxt]">{{diyInfo.visit_num||0}}</view>
							<view :style="[titleColorTxt]">浏览</view>
						</view>
						<view class="flex-1 min-w-0 item" v-if="(checkType.indexOf(3) > -1) || !isLogin" @click.stop="tapQrCode">
							<view class="iconfont icon-a-ic_QRcode" :style="[codeColorTxt]"></view>
							<view :style="[codeColorTxt]">会员码</view>
						</view>
					</template>
					<view class="flex-1 min-w-0 item" v-if="dataConfig.styleConfig.tabVal == 0 && checkType.indexOf(3) != -1" @click.stop="tapQrCode">
						<view class="iconfont icon-a-ic_QRcode" :style="[codeColorTxt]"></view>
						<view :style="[codeColorTxt]">会员码</view>
					</view>
				</view>
			</view>
			<view v-if="dataConfig.styleConfig.tabVal == 0 && isLogin" class="bottom acea-row row-middle">
				<view v-if="checkType.indexOf(1) != -1" class="item" @click.stop="goIntegral">
					<view class="num" :style="[numColorTxt]">{{diyInfo.integral||2}}</view>
					<view :style="[titleColorTxt]">积分</view>
				</view>
				<view v-if="checkType.indexOf(2) != -1" class="item" @click.stop="goMoney">
					<view class="num" :style="[numColorTxt]">{{diyInfo.now_money||6}}</view>
					<view :style="[titleColorTxt]">余额</view>
				</view>
				<view v-if="checkType.indexOf(0) != -1" class="item" @click.stop="goCoupon">
					<view class="num" :style="[numColorTxt]">{{diyInfo.coupon_num||0}}</view>
					<view :style="[titleColorTxt]">优惠券</view>
				</view>
				<view v-if="checkType.indexOf(4) != -1" class="item" @click.stop="goCollection">
					<view class="num" :style="[numColorTxt]">{{diyInfo.collectCount||0}}</view>
					<view :style="[titleColorTxt]">收藏</view>
				</view>
				<view v-if="checkType.indexOf(5) != -1" class="item" @click.stop="goVisit">
					<view class="num" :style="[numColorTxt]">{{diyInfo.visit_num||0}}</view>
					<view :style="[titleColorTxt]">浏览</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import {
		diyUserInfo
	} from '@/api/api.js';
	import {
		getlevelInfo,
		getRandCode
	} from '@/api/user.js';
	import {
		mapGetters
	} from 'vuex';
	export default {
		computed: {
			...mapGetters(['isLogin']),
			userColorTxt(){
				return{
					color:this.userColor
				}
			},
			numColorTxt(){
				return{
					color:this.numColor
				}
			},
			titleColorTxt(){
				return{
					color:this.titleColor
				}
			},
			codeColorTxt(){
				return{
					color:this.codeColor
				}
			},
			progressNumColorTxt(){
				return{
					color:this.progressNumColor
				}
			},
			lableStyle() {
				let styleObject = {
					'background': this.colorToRgba(this.currentLevelColor, 0.2),
					'color': this.currentLevelColor,
				};
				return styleObject;
			},
			componentStyle() {
				return {
					'padding': `${this.dataConfig.topConfig.val * 2}rpx ${this.dataConfig.prConfig.val * 2}rpx ${this.dataConfig.bottomConfig.val * 2}rpx`,
					'margin-top': `${this.dataConfig.mbConfig.val * 2}rpx`,
					'background': this.dataConfig.bottomBgColor.color[0].item,
				};
			},
			progressStyle() {
				let styleObject = {};
				if (this.dataConfig.toneConfig.tabVal) {
					styleObject['background'] = this.dataConfig.progressBgColor.color[0].item;
				}
				return styleObject;
			},
			bgRedsStyle() {
				let diyInfo = this.diyInfo;
				let styleObject = {
					'width': `${diyInfo.exp>diyInfo.next_exp?100:(this.$util.$h.Div(parseInt(diyInfo.exp), diyInfo.next_exp)*100 >=5? this.$util.$h.Div(parseInt(diyInfo.exp), diyInfo.next_exp)*100:5)}%`,
				};
				if (this.dataConfig.toneConfig.tabVal) {
					styleObject['background'] = `linear-gradient(90deg, ${this.dataConfig.progressColor.color[0].item} 0%, ${this.dataConfig.progressColor.color[1].item} 100%)`;
				}
				return styleObject;
			},
			userInfoStyle() {
				let borderRadius = `${this.dataConfig.fillet.val * 2}rpx`;
				if (this.dataConfig.fillet.type) {
					borderRadius =
						`${this.dataConfig.fillet.valList[0].val * 2}rpx ${this.dataConfig.fillet.valList[1].val * 2}rpx ${this.dataConfig.fillet.valList[3].val * 2}rpx ${this.dataConfig.fillet.valList[2].val * 2}rpx`;
				}
				return {
					'border-radius': borderRadius,
					'background': `linear-gradient(90deg, ${this.dataConfig.moduleColor.color[0].item} 0%, ${this.dataConfig.moduleColor.color[1].item} 100%)`,
				};
			},
		},
		name: 'userInfor',
		props: {
			dataConfig: {
				type: Object,
				default: () => ({})
			},
			isSortType: {
				type: [String, Number],
				default: 0
			}
		},
		mixins: [colors],
		data() {
			return {
				config: {
					bar: {
						code: '',
						color: ['#000'],
						bgColor: '#FFFFFF', // 背景色
						width: 480, // 宽度
						height: 110 // 高度
					},
					qrc: {
						code: '',
						size: 380, // 二维码大小
						level: 3, //等级 0～4
						bgColor: '#FFFFFF', //二维码背景色 默认白色
						border: {
							color: ['#eee', '#eee'], //边框颜色支持渐变色
							lineWidth: 3, //边框宽度
						},
						color: ['#333', '#333'], //边框颜色支持渐变色
					}
				},
				codeList: [{
					name: '会员码'
				}, {
					name: '付款码'
				}],
				codeIndex: 0,
				isCode: false,
				bgColor: '',
				textColor: '',
				progressColor: this.dataConfig.progressColor.color,
				mbCongfig: 0,
				prConfig: 0, //背景边距
				itemStyle: 0,
				checkType: this.dataConfig.checkboxInfo.type,
				diyInfo: {},
				currentLevelColor: '',
				userColor: this.dataConfig.userColor.color[0].item,
				numColor: this.dataConfig.numColor.color[0].item,
				titleColor: this.dataConfig.titleColor.color[0].item,
				codeColor: this.dataConfig.codeColor.color[0].item,
				progressNumColor: this.dataConfig.progressNumColor.color[0].item
			}
		},
		created() {
			if (this.isLogin) {
				this.getDiyUserInfo();
				this.getlevelInfo();
			}
		},
		watch: {
			isLogin: {
				handler: function(newV, oldV) {
					if (newV) {
						this.getDiyUserInfo();
					}
				},
				deep: true
			}
		},
		methods: {
			getCode() {
				getRandCode().then(res => {
					let code = res.data.code;
					this.config.bar.code = code;
					this.config.qrc.code = code;
				}).catch(err => {
					this.$util.Tips(err);
				})
			},
			tapQrCode() {
				uni.navigateTo({
					url: '/pages/users/user_member_code/index'
				})
			},
			closeCode() {
				this.isCode = false
				this.isextension = false
			},
			tapCode(index) {
				this.codeIndex = index;
				if (index == 1) {
					this.getCode();
				} else {
					let code = this.diyInfo.bar_code;
					this.config.bar.code = code;
					this.config.qrc.code = code;
				}
			},
			goIntegral() {
				uni.navigateTo({
					url: '/pages/users/user_integral/index'
				})
			},
			goMoney() {
				uni.navigateTo({
					url: '/pages/users/user_money/index'
				})
			},
			goCollection() {
				uni.navigateTo({
					url: '/pages/users/user_goods_collection/index'
				})
			},
			goVisit() {
				uni.navigateTo({
					url: '/pages/users/visit_list/index'
				})
			},
			goCoupon() {
				uni.navigateTo({
					url: '/pages/users/user_coupon/index'
				})
			},
			goLogin() {
				if (!this.isLogin) {
					this.$emit('changeLogin');
				}
			},
			getDiyUserInfo() {
				diyUserInfo().then(res => {
					this.diyInfo = res.data;
				}).catch(err => {
					this.$util.Tips({
						title: err
					});
				})
			},
			getlevelInfo() {
				getlevelInfo().then(res => {
					const {
						level_info,
						level_list
					} = res.data;
					const currentLevel = level_list.find(item => item.grade == level_info.grade);
					if (currentLevel) {
						this.currentLevelColor = currentLevel.color;
					}
				});
			},
			colorToRgba(str, n) {
				// 十六进制颜色值的正则表达式
				const reg = /^#([0-9a-fA-f]{3}|[0-9a-fA-f]{6})$/;
				let sColor = str.toLowerCase();
				// 十六进制颜色转换为RGB格式
				if (sColor && reg.test(sColor)) {
					if (sColor.length === 4) {
						let sColorNew = '#';
						for (let i = 1; i < 4; i += 1) {
							sColorNew += sColor.slice(i, i + 1).concat(sColor.slice(i, i + 1));
						}
						sColor = sColorNew;
					}
					// 处理六位颜色值
					const sColorChange = [];
					for (let k = 1; k < 7; k += 2) {
						sColorChange.push(parseInt(`0x${sColor.slice(k, k + 2)}`, 16));
					}
					return `rgba(${sColorChange.join(',')}, ${n})`;
				}
				return sColor;
			},
		}
	}
</script>

<style lang="scss">
	.userInfor {
		.mark {
			position: fixed;
			top: 0;
			left: 0;
			bottom: 0;
			right: 0;
			background: rgba(0, 0, 0, 0.5);
			z-index: 50;
		}

		.codePopup .icon-guanbi2 {
			margin-top: 75rpx !important;
		}

		&.pageOn {
			border-radius: 12rpx;
		}

		.right {
			&::before {
				content: "";
				position: absolute;
				top: 0;
				right: 20rpx;
				left: 20rpx;
				border-top: 1rpx solid #EEEEEE;
			}

			.item {
				padding: 34rpx 0;
				margin: 0;

				.num {
					font-weight: 500;
					margin-left: 8rpx;
				}
			}

			.iconfont {
				font-size: 40rpx;
				margin-bottom: 8rpx;
			}
		}

		.left {
			padding: 32rpx 20rpx;

			.pictrue {
				width: 90rpx;
				height: 90rpx;
				border: 1px solid #EEEEEE;
				border-radius: 50%;
				margin-right: 20rpx;

				image {
					width: 100%;
					height: 100%;
					border-radius: 50%;
				}
				
				.king{
					width: 36rpx;
					height: 36rpx;
					position: absolute;
					top:-18rpx;
					right: -8rpx;
				}
			}

			.text {
				flex: 1;
				font-weight: 400;
				//color: #333333;
				font-size: 28rpx;

				.name {
					margin-bottom: 8rpx;

					.nameCon {
						max-width: 190rpx;
						font-weight: bold;
					}

					.lable {
						height: 32rpx;
						padding: 0 8rpx;
						border-radius: 16rpx;
						margin-left: 8rpx;
						font-weight: 500;
						font-size: 20rpx;
						line-height: 32rpx;

						.iconfont {
							margin-right: 4rpx;
							font-size: 20rpx;
						}
					}
				}

				.progress {
					overflow: hidden;
					background-color: #EEEEEE;
					width: 120rpx;
					height: 14rpx;
					border-radius: 7rpx;
					position: relative;
					margin-right: 8rpx;

					.bg-reds {
						width: 0;
						height: 14rpx;
						border-radius: 7rpx;
						transition: width 0.6s ease;
						background: linear-gradient(90deg, var(--view-theme) 0%, var(--view-gradient) 100%);
					}
				}

				.percent {
					font-size: 20rpx;
					line-height: 20rpx;
					color: #666666;
				}

				.phone {
					font-weight: 400;
					//color: #666666;
					font-size: 20rpx;
					margin-top: 6rpx;

					.icon-shouji2 {
						margin-right: 4rpx;
						font-size: 20rpx;
					}
				}
			}

			.right::before {
				display: none;
			}

			.item {
				width: 108rpx;
				padding: 0;
				overflow: hidden;
				text-align: center;
				font-size: 22rpx;
				color: #999999;

				.num {
					margin: 0 0 12rpx;
					font-size: 32rpx;
				}

				.iconfont {
					font-weight: 500;
					color: #333333;
					font-size: 40rpx;
					margin: 0 0 12rpx;
				}
			}
		}

		.bottom {
			position: relative;

			&::before {
				content: "";
				position: absolute;
				top: 0;
				right: 20rpx;
				left: 20rpx;
				border-top: 1px solid #EEEEEE;
			}

			.item {
				flex: 1;
				padding: 42rpx 0 40rpx;
				text-align: center;
				font-weight: 500;
				font-size: 22rpx;
				line-height: 30rpx;
				color: #999999;
			}

			.num {
				margin: 0 0 12rpx;
				font-family: SemiBold;
				font-weight: 500;
				font-size: 32rpx;
				line-height: 32rpx;
				color: #333333;
			}
		}
	}
</style>