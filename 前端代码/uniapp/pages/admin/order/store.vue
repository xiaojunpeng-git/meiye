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
			<view class="wrapper">
				<view class="header acea-row row-between-wrapper">
					<view class="title">数据统计</view>
					<view class="tab">
					  <view class="box" :class="item.id== current ? 'on':''" v-for="item in dataList" @click="detailtab(item.id)">{{item.name}}</view>
					</view>
				</view>
				<view class="list acea-row">
					<view class="item" :class="orderType == item.type?'on':''" v-if="item.isManager" @click="orderNum(item.type)" v-for="item in statistics">
						<view>{{item.name}}</view>
						<view class="num">{{ item.price }}</view>
					</view>
				</view>
			    <view class="canvas">
					<canvas canvas-id="canvasMix" id="canvasMix" class="charts" disable-scroll @touchstart="touchMix" @touchmove="moveMix" @touchend="touchEndMix">
					</canvas>
				</view>
			</view>
			<view class="public-wrapper">
				<view class="title">
					详细数据
				</view>
				<view class="nav acea-row row-between-wrapper">
					<view class="num">日期</view>
					<view class="num" :class="[3,9,10].indexOf(orderType) == -1?'on':''">数量</view>
					<view class="num price" v-if="[3,9,10].indexOf(orderType) == -1">金额</view>
				</view>
				<view class="conter">
					<view class="item acea-row row-between-wrapper" v-for="(item, index) in list" :key="index">
						<view class="num">{{item.day}}</view>
						<view class="num" :class="[3,9,10].indexOf(orderType) == -1?'on':''">{{item.count}}</view>
						<view class="num price" v-if="[3,9,10].indexOf(orderType) == -1">{{item.price}}</view>
					</view>
				</view>
			</view>
		</view>
		<view class="safe-area-inset-bottom"></view>
	</view>
</template>

<script>
	let _self;
	let canvaMix = null;
	import {
		statisticsMenuApi,
		getStoreChart,
		getStatisticsListApi
	} from "@/api/admin";
	import uCharts from '../components/ucharts/ucharts'
	// #ifdef MP || APP-PLUS
	import NavBar from '@/components/NavBar.vue';
	// #endif
	export default {
		name: 'adminOrder',
		components: {
			// #ifdef MP ||APP-PLUS
			NavBar,
			// #endif
		},
		data() {
			return {
				iconColor: '#FFFFFF',
				isScrolling: false,
				getHeight: this.$util.getWXStatusHeight(),
				dataList:[
					{id:'today',name:'今日'},
					{id:'yesterday',name:'昨日'},
					{id:'month',name:'本月'}
				],
				current:'today',
				statistics:[],
				isManager:0,
				orderType:1,
				cWidth: '',
				cHeight: '',
				list: [],
				pixelRatio: 1
			}
		},
		onLoad(options) {
			this.isManager = parseInt(options.isManager);
			this.getstatisticsMenu();
			this.getOrderChart();
			this.cWidth = uni.upx2px(710);
			this.cHeight = uni.upx2px(500);
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
			orderNum(num){
				this.orderType = num;
				this.getOrderChart();
				this.getList();
			},
			getstatisticsMenu(){
				let data = {
					is_manager:this.isManager,
					data:this.current
				}
				statisticsMenuApi(data).then(res=>{
					let data = res.data;
					this.statistics = [
						{name:'销售额',price:data.price,type:1,isManager:1},
						{name:'配送订单额',price:data.send_price,type:2,isManager:this.isManager},
						{name:'收银订单额',price:data.cashier_price,type:5,isManager:1},
						{name:'核销订单额',price:data.writeoff_price,type:6,isManager:1},
						{name:'配送订单数',price:data.send_count,type:3,isManager:this.isManager},
						{name:'付费会员额',price:data.svip_price,type:7,isManager:1},
						{name:'充值订单额',price:data.recharge_price,type:8,isManager:1},
						{name:'退款订单额',price:data.refund_price,type:4,isManager:this.isManager},
						{name:'推广用户数',price:data.spread_count,type:9,isManager:1},
						{name:'激活会员卡',price:data.card_count,type:10,isManager:1}
					]
				}).catch(err=>{
					this.$util.Tips({
						title: err
					});
				})
			},
			getOrderChart() {
				let data = {
					is_manager:this.isManager,
					time:this.current
				}
				getStoreChart(this.orderType,data).then(res => {
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
						number,
						time
					}) => {
						series[0].data.push(number);
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
			//  统计菜单
			detailtab: function(type) {
				this.current = type
				this.getstatisticsMenu();
				this.getOrderChart();
				this.getList()
			},
			getList: function() {
				let data = {
					is_manager:this.isManager,
					time:this.current
				}
				getStatisticsListApi(this.orderType,data).then(res=>{
					this.list = res.data;
				}).catch(err=>{
					this.$util.Tips({
						title: err
					})
				})
			}
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
	
	.order-index .wrapper {
		background-color: #fff;
		border-radius: 24rpx;
		padding-top: 30rpx;
		margin-top: 40rpx;
		
		.canvas{
			padding: 0 40rpx;
			margin-top: 26rpx;
		}
		
		.header{
			padding: 0 24rpx;
			.title{
			   font-size: 30rpx;
			   font-weight: 500;
			}
			.tab{
				width: 288rpx;
				height: 48rpx;
				background: #F5F5F5;
				border-radius: 24rpx;
				display: flex;
				justify-content: space-between;
				font-weight: 400;
				color: #999999;
				font-size: 24rpx;
				.box{
					width: 96rpx;
					height: 100%;
					border-radius: 24rpx;
					text-align: center;
					line-height: 48rpx;
				}
				.on{
					background: #1890FF;
					color: #FFFFFF;
				}
			}
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
	
	.order-index .wrapper .list{
		margin-top: 16rpx;
		padding: 0 40rpx;
	}

	.order-index .wrapper .list .item {
		padding: 24rpx 0;
		text-align: left;
		font-size: 24rpx;
		line-height: 34rpx;
		color: #999;
		width: 150rpx;
		margin-right: 90rpx;
		&:nth-of-type(3n){
			margin-right: 0;
		}
		&.on{
			color: #2A7EFB;
			.num{
				color: #2A7EFB;
			}
		}
	}

	.order-index .wrapper .list .item .num {
		margin-top: 12rpx;
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
	}

	.public-wrapper .nav {
		padding: 0 40rpx;
		line-height: 34rpx;
		font-size: 24rpx;
		color: #999;
	}

	.public-wrapper .conter {
		padding: 0 40rpx;
	}

	.public-wrapper .conter .item,.public-wrapper .nav {
		border-bottom: 1px solid #F1F1F1;
		height: 74rpx;
		font-size: 24rpx;
		.num {
			flex: 1;
			word-break: break-all;
			
			&.price{
				text-align: right;
			}
			
			&.on{
				text-align: center;
			}
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