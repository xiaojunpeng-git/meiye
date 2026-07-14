<template>
	<view class="pagebox">
		<!-- #ifdef MP -->
		<NavBar titleText="数据统计" :iconColor="iconColor" :textColor="iconColor" :isScrolling="isScrolling" showBack></NavBar>
		<!-- #endif -->
		<view class="headerBg">
			<view :style="{ height: `${getHeight.barTop}px` }"></view>
			<view :style="{ height: `${getHeight.barHeight}px` }"></view>
			<view class="inner"></view>
		</view>
		<view class="order-index" ref="container">
			<view class="header">
				<view class="">销售额(元)</view>
				<view class="money">{{ after_price }}</view>
				<view class="info">
					较昨日同比增长：{{ increase_time_status == 1 ? '' : '-' }}{{ growth_rate }}%
					<text :class="['iconfont', increase_time_status == 1 ? 'icon-ic_up2' : 'icon-ic_down2']"></text>
				</view>
				<view class="picker">
					<picker mode="selector" :range="array" @change="bindPickerChange">
						<view>{{ array[index] }}<text class="iconfont icon-ic_downarrow"></text></view>
					</picker>
				</view>
			</view>
			<view class="wrapper">
				<view class="list acea-row">
					<view class="item">
						<view class="num">{{ after_number }}</view>
						<view>订单数</view>
					</view>
					<view class="item">
						<view class="num">{{ after_pay_number }}</view>
						<view>支付人数</view>
					</view>
					<view class="item">
						<view class="num">{{ today_visits }}</view>
						<view>浏览量</view>
					</view>
				</view>
			</view>
			<div class="chart">
				<view class="title">
					销售订单趋势图
				</view>
				<canvas canvas-id="canvasMix" id="canvasMix" class="charts" disable-scroll @touchstart="touchMix" @touchmove="moveMix" @touchend="touchEndMix">
				</canvas>
			</div>
			<view class="public-wrapper">
				<view class="title">
					详细数据
				</view>
				<view class="nav acea-row row-between-wrapper">
					<view class="data">日期</view>
					<view class="browse">订单数</view>
					<view class="turnover">成交额</view>
					<view class="visit">浏览量</view>
				</view>
				<view class="conter">
					<view class="item acea-row row-between-wrapper" v-for="(item, index) in list" :key="index">
						<view class="data">{{ item.time }}</view>
						<view class="browse">{{ item.count }}</view>
						<view class="turnover">¥ {{ item.price }}</view>
						<view class="visit">{{ item.visit }}</view>
					</view>
				</view>
			</view>
			<Loading :loaded="loaded" :loading="loading"></Loading>
		</view>
		<view class="safe-area-inset-bottom"></view>
	</view>
</template>

<script>
	let _self;
	let canvaMix = null;
	import {
		getStatisticsInfo,
		getStatisticsMonth,
		getOrderTime,
		getOrderChart,
	} from "@/api/admin";
	import Loading from '@/components/Loading/index.vue'
	import uCharts from '../components/ucharts/ucharts'
	// #ifdef MP || APP-PLUS
	import NavBar from '@/components/NavBar.vue';
	// #endif
	export default {
		name: 'adminOrder',
		components: {
			Loading,
			// #ifdef MP ||APP-PLUS
			NavBar,
			// #endif
		},
		data() {
			return {
				iconColor: '#FFFFFF',
				isScrolling: false,
				getHeight: this.$util.getWXStatusHeight(),
				census: {},
				list: [],
				where: {
					page: 1,
					limit: 15
				},
				loaded: false,
				loading: false,
				after_price: 0, // 销售额
				after_number: 0, // 订单数
				after_pay_number: 0, // 支付人数
				today_visits: 0, // 浏览量
				growth_rate: 0, // 增长率
				increase_time: 0, // 较昨日同比增长
				increase_time_status: 1, // 1 增长 2 减少
				cWidth: '',
				cHeight: '',
				pixelRatio: 1,
				textarea: '',
				index: 0,
				array: ['今天', '近7天', '近30天'],
				arrays: [1, 7, 30],
			}
		},
		onShow() {
			_self = this;
			this.cWidth = uni.upx2px(710);
			this.cHeight = uni.upx2px(500);
			this.getOrderTime();
			this.getOrderChart();
			this.getIndex();
			this.getList();
		},
		onPageScroll(e) {
			// #ifdef MP
			if (e.scrollTop > 50) {
				this.isScrolling = true;
				this.iconColor = '#333333';
			} else if (e.scrollTop < 50) {
				this.isScrolling = false;
				this.iconColor = '#FFFFFF';
			}
			// #endif
		},
		beforeMount() {
			canvaMix = null
		},
		methods: {
			bindPickerChange: function(e) {
				console.log('picker发送选择改变，携带值为', e.detail.value)
				this.index = e.detail.value
				this.getOrderTime();
				this.getOrderChart();
			},
			getOrderTime() {
				getOrderTime({
					type: this.arrays[this.index],
				}).then(res => {
					const {
						after_number,
						after_pay_number,
						after_price,
						today_visits,
						growth_rate,
						increase_time,
						increase_time_status,
					} = res.data;
					this.after_number = after_number;
					this.after_pay_number = after_pay_number;
					this.after_price = after_price;
					this.today_visits = today_visits;
					this.growth_rate = growth_rate;
					this.increase_time = increase_time;
					this.increase_time_status = increase_time_status;
				});
			},
			getOrderChart() {
				getOrderChart({
					type: this.arrays[this.index],
				}).then(res => {
					const data = res.data;
					let Mix = {
						categories: [],
						series: []
					};
					let series = [{
						"name": '销售额',
						"type": "line",
						"data": [],
					}];
					data.forEach(({
						num,
						price,
						time
					}) => {
						series[0].data.push(price);
						Mix.categories.push(time);
					});
					Mix.series = series;
					this.chartData = data;
					if (canvaMix) {
						canvaMix.updateData(Mix);
					} else {
						this.showLineA("canvasMix", Mix);
					}
				});
			},
			// 创建charts
			showLineA(canvasId, chartData) {
				let _self = this
				canvaMix = new uCharts({
					$this: _self,
					canvasId: canvasId,
					type: 'line',
					fontSize: 11,
					padding: [5, 5, 0, 5],
					legend: {
						show: true,
						position: 'bottom',
						float: 'center',
						padding: 5,
						lineHeight: 11,
						margin: 6,
					},
					background: '#FFFFFF',
					pixelRatio: _self.pixelRatio,
					categories: chartData.categories,
					series: chartData.series,
					animation: true,
					enableScroll: true, //开启图表拖拽功能
					xAxis: {
						disableGrid: false,
						type: 'grid',
						gridType: 'dash',
						itemCount: 6,
						scrollShow: true,
						scrollAlign: 'left',
					},
					yAxis: {
						data: [{
							calibration: true,
							position: 'left',
							title: '销售额(元)',
							titleFontSize: 12,
							format: (val) => {
								return val.toFixed(0)
							}
						}, ],
						showTitle: true,
						gridType: 'dash',
						dashLength: 4,
						splitNumber: 7,
					},
					width: _self.cWidth * _self.pixelRatio,
					height: _self.cHeight * _self.pixelRatio,
					dataLabel: true,
					dataPointShape: true,
				});
			},
			touchMix(e) {
				canvaMix.scrollStart(e);
			},
			moveMix(e) {
				canvaMix.scroll(e);
			},
			touchEndMix(e) {
				var index = canvaMix.getCurrentDataIndex(e);
				canvaMix.scrollEnd(e);
				//下面是toolTip事件，如果滚动后不需要显示，可不填写
				canvaMix.touchLegend(e);
				canvaMix.showToolTip(e, {
					textList: [{
							text: this.chartData[index].time,
							color: null
						},
						{
							text: "销售额：" + this.chartData[index].price,
							color: "#1890FF"
						},
						{
							text: "订单量：" + this.chartData[index].num,
							color: "#91CB74"
						}
					]

				});
			},
			getIndex: function() {
				var that = this;
				getStatisticsInfo().then(
					res => {
						that.census = res.data;
					},
					err => {
						that.$util.Tips({
							title: err
						})
					}
				);
			},
			getList: function() {
				var that = this;
				if (that.loading || that.loaded) return;
				that.loading = true;
				getStatisticsMonth(that.where).then(
					res => {
						that.loading = false;
						that.loaded = res.data.length < that.where.limit;
						that.list.push.apply(that.list, res.data);
						that.where.page = that.where.page + 1;
					},
					error => {
						that.$util.Tips({
							title: error
						})
					},
					300
				);
			}
		},
		onReachBottom() {
			this.getList()
		}
	}
</script>

<style lang="scss" scoped>
	.pagebox {
		position: relative;
		overflow: hidden;
	}

	.safe-area-inset-bottom {
		height: 0;
		height: constant(safe-area-inset-bottom);
		height: env(safe-area-inset-bottom);
	}

	.headerBg {
		position: absolute;
		top: 0;
		left: -25%;
		width: 150%;
		border-bottom-right-radius: 100%;
		border-bottom-left-radius: 100%;
		background: linear-gradient(270deg, #01ABF8 0%, #2A7EFB 100%);

		.inner {
			height: 356rpx;
		}
	}

	/*订单首页*/
	.order-index {
		position: relative;
		padding: 0 20rpx;
	}

	.order-index .header {
		position: relative;
		padding: 24rpx 0 40rpx 20rpx;
		font-size: 28rpx;
		line-height: 40rpx;
		color: #FFFFFF;

		.picker {
			position: absolute;
			top: 24rpx;
			right: 0;
			height: 48rpx;
			padding: 0 20rpx;
			border-radius: 24rpx;
			background: rgba(255, 255, 255, 0.3);
			text-align: center;
			font-size: 24rpx;
			line-height: 48rpx;
			color: #FFFFFF;

			.iconfont {
				margin-left: 10rpx;
				font-size: 24rpx;
			}
		}
	}

	.order-index .header .money {
		margin-top: 26rpx;
		font-family: SemiBold;
		font-size: 80rpx;
		line-height: 80rpx;
	}

	.order-index .header .info {
		margin-top: 30rpx;

		.iconfont {
			margin-left: 6rpx;
			font-size: 28rpx;
		}
	}

	.order-index .wrapper {
		background-color: #fff;
		border-radius: 24rpx;
	}

	.order-index .wrapper .list .item {
		flex: 1;
		padding: 36rpx 0 26rpx;
		text-align: center;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999;
	}

	.order-index .wrapper .list .item .num {
		margin-bottom: 8rpx;
		font-family: SemiBold;
		font-size: 36rpx;
		color: #333;
	}

	.public-wrapper .title {
		font-weight: 500;
		font-size: 30rpx;
		line-height: 42rpx;
		color: #333333;
		padding: 32rpx 0 40rpx 24rpx;
	}

	.public-wrapper {
		background-color: #fff;
		border-radius: 24rpx;
		margin-top: 20rpx;
		margin-bottom: 20rpx;
	}

	.public-wrapper .nav {
		padding: 0 40rpx;
		line-height: 34rpx;
		font-size: 24rpx;
		color: #999;
	}

	.public-wrapper .data {
		flex: 1;
		text-align: left;
	}

	.public-wrapper .browse {
		flex: 1;
	}

	.public-wrapper .turnover {
		flex: 1;
	}

	.public-wrapper .visit {
		flex: 1;
		text-align: right;
	}

	.public-wrapper .conter {
		padding: 0 40rpx;
	}

	.public-wrapper .conter .item {
		border-bottom: 1px solid #F1F1F1;
		height: 74rpx;
		font-size: 24rpx;
	}

	.chart {
		border-radius: 24rpx;
		margin-top: 20rpx;
		background: #FFFFFF;

		.title {
			padding: 32rpx 0 16rpx 24rpx;
			font-weight: 500;
			font-size: 30rpx;
			line-height: 42rpx;
			color: #333333;
		}

		.chart-title {
			padding: 40rpx 0 6rpx 42rpx;
			font-size: 22rpx;
			line-height: 26rpx;
			color: #999999;
		}

		.charts {
			width: 100%;
			height: 514rpx;
		}
	}

	.cover-view {
		position: fixed;
		top: 0;
		right: 0;
		bottom: 0;
		left: 0;
	}
</style>