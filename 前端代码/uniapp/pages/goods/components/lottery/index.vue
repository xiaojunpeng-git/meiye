<template>
	<view class="lottery_container">
		<view class="grid_wrap">
			<view class="lottery_wrap">
				<view class="lottery_grid">
					<view v-for="(item, index) in prizeData" :key="index" :class="{ active: current_index == index && index != 8 }" class="item" @click="luck_draw">
						<view :class="{in_line:index != 8 }" class="lottery-msg">
							<image v-if="index != 8" class="grid_img" mode='aspectFit' :src="item.image" alt="" />
							<text v-if="index !=8" class="name line1">
								{{ index == 8 ? '抽奖' : item.name }}
							</text>
							<view v-else class="flex-col flex-center lottery-click">
								<text class="fs-36">{{lotteryNum}}次</text>
								<text class="fs-20 lh-28rpx">抽奖机会</text>
							</view>
						</view>
					</view>
				</view>
			</view>
		</view>
	</view>

</template>

<script>
	import LotteryDraw from './js/grids_lottery.js';
	export default {
		data() {
			return {
				current_index: -1,
				lotteryBtn: true
			};
		},
		props: {
			prizeData: {
				type: Array,
				default: function() {
					return []
				}
			},
			lotteryType: {
				type: Number,
				default: 0
			},
			datatime: {
				type: Number,
				default: 0
			},
			lotteryNum: {
				type: Number,
				default: 0
			},
			isRotating: {
				type: Boolean,
				default: false
			},
			winingIndex: {
				type: Number,
				default: 0
			},
		},
		watch: {
			isRotating(newValue, oldValue) {
				let that = this;
				if (newValue) {
					let win = new LotteryDraw({
							domData: that.prizeData,
							startIndex: 3, //开始抽奖位置，从0开始
							totalCount: 3, //一共要转的圈数
							winingIndex: this.winingIndex, //中奖的位置，从0开始
							speed: 100 //抽奖动画的速度 [数字越大越慢,默认100]
						},
						function(index, count) {
							that.current_index = index;
							if (that.winingIndex == index && 3 ==
								count) {
								that.lotteryBtn = true
								that.$emit('luck_draw_finish', that.prizeData[index])
							}
						}
					);
				}
			}
		},
		mounted() {},

		methods: {
			luck_draw(event) {
				let timestamp = Date.parse(new Date()) / 1000;
				if (this.lotteryType == 1 && parseInt(timestamp) > parseInt(this.datatime)) {
					return this.$util.Tips({
						title: '您抽奖的有效时间已到期'
					}, function() {
						uni.switchTab({
							url: '/pages/index/index'
						})
					});
				}
				let index = event.currentTarget.dataset.index;
				if (this.lotteryBtn && index == 8) {
					this.lotteryBtn = false
				} else {
					return
				}
				let that = this;
				if (index == 8) {
					// 点击抽奖之后知道获奖位置，修改父组件中lottery_draw_param的值
					this.$emit('get_winingIndex', function(res) {
						let lottery_draw_param = res;
						let win = new LotteryDraw({
								domData: that.prizeData,
								...lottery_draw_param
							},
							function(index, count) {
								that.current_index = index;
								if (lottery_draw_param.winingIndex == index && lottery_draw_param.totalCount ==
									count) {
									that.lotteryBtn = true
									that.$emit('luck_draw_finish', that.prizeData[index])
								}
							}
						);
					});

				}
			}
		}
	};
</script>

<style scoped lang="scss">
	@import './css/grids_lottery.css';

	.lottery-msg {
		width: 100%;
		height: 100%;
		padding: 0 4rpx;
	}

	.lottery-click {
		width: 100%;
		height: 100%;
	}

	.lottery_grid .name {
		width: 100%;
		text-align: center;
	}
</style>