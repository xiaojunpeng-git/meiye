<template>
	<view class="zzx-calendar">
		<view class="calendar-heander acea-row row-center-wrapper">
			<view class="title">预约时间</view>
			<view class="iconfont icon-ic_left2" :class="ymArr?'':'text-w111-ddd'" @click="daysPre(1)"></view>
			<view class="dates">{{timeStr}}</view>
			<view class="iconfont icon-ic_right2" @click="daysNext(1)"></view>
			<view class="back-today" @click="goback" v-if="showBack">
				返回今日
			</view>
		</view>
		<view class="calendar-weeks">
			<view class="calendar-week" v-for="(week, index) in weeks" :key="index">
				{{week}}
			</view>
		</view>
		<view class="calendar-content">
			<view class="calendar-swiper" :style="{
			   width: '100%',
			   height: sheight
		   }" :indicator-dots="false" :autoplay="false" :duration="duration" :current="current" @change="changeSwp"
				:circular="true">
				<view class="calendar-item" v-for="sitem in swiper" :key="sitem">
					<view class="calendar-days">
						<template v-if="sitem === current">
							<view class="calendar-day" v-for="(item,index) in days" :key="index" :class="{
								'day-hidden': !item.show
							}" @click="clickItem(item)">
								<view class="date" :class="[
									item.isToday ? todayClass : '',
									dotListData.indexOf(item.fullDate)==-1?'text-w111-ccc':'',
									item.fullDate === selectedDate ? checkedClass : ''
									]">
									<text v-if="item.isToday">今</text>
									<text v-else>{{item.time.getDate()>9?item.time.getDate():'0'+item.time.getDate()}}</text>
								</view>
							</view>
						</template>
						<template v-else>
							<template v-if="current - sitem === 1 || current-sitem ===-2">
								<view class="calendar-day" v-for="(item,index) in predays" :key="index" :class="{
										'day-hidden': !item.show
									}">
									<view class="date" :class="[
											item.isToday ? todayClass : ''
											]">
										{{item.time.getDate()}}
									</view>
								</view>
							</template>
							<template v-else>
								<view class="calendar-day" v-for="(item,index) in nextdays" :key="index" :class="{
										'day-hidden': !item.show
									}">
									<view class="date" :class="[
											item.isToday ? todayClass : ''
											]">
										{{item.time.getDate()}}
									</view>
								</view>
							</template>
						</template>
					</view>
				</view>
			</view>
			<view class="mode-change" @click="changeMode">
				<view class="iconfont" :class="weekMode ? 'icon-ic_downarrow' : 'icon-ic_uparrow'">
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import {
		gegerateDates,
		dateEqual,
		formatDate
	} from './generateDates.js';
	export default {
		props: {
			duration: {
				type: Number,
				default: 500
			},
			dotList: {
				type: Array, /// 打点日期列表
				default () {
					return []
				}
			},
			reservationDefaultDate:{
				type: String,
				default: ''
			},
			showBack: {
				type: Boolean, // 是否返回今日
				default: false
			},
			todayClass: {
				type: String, // 今日的自定义样式class
				default: 'is-today'
			},
			checkedClass: {
				type: String, // 选中日期的样式class
				default: 'is-checked'
			},
			dotStyle: {
				type: Object, // 打点日期的自定义样式
				default () {
					return {
						background: '#c6c6c6'
					}
				}
			}
		},
		watch: {
			dotList: function(newvalue) {
				const days = this.days.slice(0);
				this.days = days;
			},
			reservationDefaultDate: function(newvalue){
				this.selectedDate = newvalue
			}
		},
		computed: {
			ymArr() {
				const nowY = new Date().getFullYear()
				const nowM = new Date().getMonth() + 1
				return nowY<this.currentYear || (nowY==this.currentYear && parseInt(nowM)<parseInt(this.currentMonth));
			},
			dotListData(){
				let data = [];
				this.dotList.forEach(item=>{
					data.push(item.date)
				})
				return data
			},
			sheight() {
				// 根据年月判断有多少行
				// 判断该月有多少天
				let h = '70rpx';
				if (!this.weekMode) {
					const d = new Date(this.currentYear, this.currentMonth, 0);
					const days = d.getDate(); // 判断本月有多少天
					let day = new Date(d.setDate(1)).getDay();
					if (day === 0) {
						day = 7;
					}
					const pre = 8 - day;
					const rows = Math.ceil((days - pre) / 7) + 1;
					h = 70 * rows + 'rpx'
				}
				return h
			},
			timeStr() {
				let str = '';
				const d = new Date(this.currentYear, this.currentMonth - 1, this.currentDate);
				const y = d.getFullYear();
				const m = (d.getMonth() + 1) <= 9 ? `0${d.getMonth()+1}` : d.getMonth() + 1;
				str = `${y}年${m}月`;
				return str;
			},
			predays() {
				let pres = [];
				if (this.weekMode) {
					const d = new Date(this.currentYear, this.currentMonth - 1, this.currentDate)
					d.setDate(d.getDate() - 7);
					pres = gegerateDates(d, 'week')
				} else {
					const d = new Date(this.currentYear, this.currentMonth - 2, 1)
					pres = gegerateDates(d, 'month')
				}
				return pres;
			},
			nextdays() {
				let nexts = [];
				if (this.weekMode) {
					const d = new Date(this.currentYear, this.currentMonth - 1, this.currentDate)
					d.setDate(d.getDate() + 7);
					nexts = gegerateDates(d, 'week')
				} else {
					const d = new Date(this.currentYear, this.currentMonth, 1)
					nexts = gegerateDates(d, 'month')
				}
				return nexts;
			}
		},
		data() {
			return {
				weeks: ['一', '二', '三', '四', '五', '六', '日'],
				current: 1,
				currentYear: '',
				currentMonth: '',
				currentDate: '',
				days: [],
				weekMode: true,
				swiper: [1],
				selectedDate: ''
			};
		},
		methods: {
			changeSwp(e) {
				const pre = this.current;
				const current = e.target.current;
				/* 根据前一个减去目前的值我们可以判断是下一个月/周还是上一个月/周 
				 *current - pre === 1, -2时是下一个月/周
				 *current -pre === -1, 2时是上一个月或者上一周
				 */
				this.current = current;
				if (current - pre === 1 || current - pre === -2) {
					this.daysNext();
				} else {
					this.daysPre();
				}
			},
			// 初始化日历的方法
			initDate(cur) {
				let date = ''
				if (cur) {
					date = new Date(cur)
				} else {
					date = new Date()
				}
				this.currentDate = date.getDate() // 今日日期 几号
				this.currentYear = date.getFullYear() // 当前年份
				this.currentMonth = date.getMonth() + 1 // 当前月份
				this.currentWeek = date.getDay() === 0 ? 7 : date.getDay() // 1...6,0   // 星期几
				const nowY = new Date().getFullYear() // 当前年份
				const nowM = new Date().getMonth() + 1
				const nowD = new Date().getDate() // 今日日期 几号
				const nowW = new Date().getDay();
				this.days = [];
				let days = [];
				if (this.weekMode) {
					days = gegerateDates(date, 'week');
				} else {
					days = gegerateDates(date, 'month');
				}
				this.days = days;
				//  派发事件,时间发生改变
				let obj = {
					start: '',
					end: ''
				};
				if (this.weekMode) {
					obj.start = this.days[0].time;
					obj.end = this.days[6].time
				} else {
					const start = new Date(this.currentYear, this.currentMonth - 1, 1);
					const end = new Date(this.currentYear, this.currentMonth, 0);
					obj.start = start;
					obj.end = end;
				}
				this.$emit('days-change', obj)
			},
			//  上一个
			daysPre(num) {
				if(!this.ymArr) return
				if (this.weekMode && !num) {
					const d = new Date(this.currentYear, this.currentMonth - 1, this.currentDate);
					d.setDate(d.getDate() - 7);
					this.initDate(d);
				} else {
					const d = new Date(this.currentYear, this.currentMonth - 2, 1);
					this.initDate(d);
				}
				this.$emit('dateChange', this.currentYear + '-' + (this.currentMonth<10?'0'+this.currentMonth:this.currentMonth)); //传给调用模板页面去拿新数据
			},
			//  下一个
			daysNext(num) {
				if (this.weekMode && !num) {
					const d = new Date(this.currentYear, this.currentMonth - 1, this.currentDate);
					d.setDate(d.getDate() + 7);
					this.initDate(d);
				} else {
					const d = new Date(this.currentYear, this.currentMonth, 1);
					this.initDate(d);
				}
				this.$emit('dateChange', this.currentYear + '-' + (this.currentMonth<10?'0'+this.currentMonth:this.currentMonth)); //传给调用模板页面去拿新数据
			},
			changeMode() {
				const premode = this.weekMode;
				let isweek = false;
				if (premode) {
					isweek = !!this.days.find(item => item.fullDate === this.selectedDate)
				}
				this.weekMode = !this.weekMode;
				let d = new Date(this.currentYear, this.currentMonth - 1, this.currentDate)
				const sel = new Date(this.selectedDate.replace('-', '/').replace('-', '/'));
				const isMonth = sel.getFullYear() === this.currentYear && (sel.getMonth() + 1) === this.currentMonth;
				if ((this.selectedDate && isMonth) || isweek) {
					d = new Date(this.selectedDate.replace('-', '/').replace('-', '/'))
				}
				this.initDate(d)
			},
			// 点击日期
			clickItem(e) {
				if(this.dotListData.indexOf(e.fullDate)==-1) return
				this.selectedDate = e.fullDate;
				this.$emit('dayChange', e.fullDate);
			},
			goback() {
				const d = new Date();
				this.initDate(d);
			}
		},
		created() {
			this.initDate();
		},
		mounted() {}
	}
</script>

<style lang="scss" scoped>
	.text-w111-ccc{
		color: #ccc !important;
	}
	.zzx-calendar {
		width: 710rpx;
		height: auto;
		background-color: #ffffff;
		margin: 20rpx auto 0 auto;
		border-radius: 16rpx;
		padding-top: 26rpx;

		.calendar-heander {
			text-align: center;
			height: 60rpx;
			line-height: 60rpx;
			position: relative;
			font-size: 30rpx;
			
			.iconfont{
				width: 52rpx;
				height: 52rpx;
				text-align: center;
				line-height: 52rpx;
				font-size: 24rpx;
			}
			
			.dates {
				font-size: 28rpx;
				font-family: PingFang SC, PingFang SC;
				font-weight: 500;
				color: #333;
			}
			
			.title{
				position: absolute;
				left: 0;
				font-size: 28rpx;
				color: #333;
				padding-left: 24rpx;
			}

			.back-today {
				position: absolute;
				right: 0;
				width: 100rpx;
				height: 30rpx;
				line-height: 30rpx;
				font-size: 20rpx;
				top: 15rpx;
				border-radius: 15rpx 0 0 15rpx;
				color: #ffffff;
				background-color: #FF6633;
			}
		}

		.calendar-weeks {
			width: 100%;
			display: flex;
			flex-flow: row nowrap;
			height: 60rpx;
			line-height: 60rpx;
			justify-content: center;
			align-items: center;
			font-size: 30rpx;
			margin: 26rpx 0 14rpx 0;

			.calendar-week {
				width: calc(100% / 7);
				height: 100%;
				text-align: center;
				font-size: 28rpx;
			}
		}

		swiper {
			width: 100%;
			height: 60rpx;
		}

		.calendar-content {
			min-height: 60rpx;
		}

		.calendar-swiper {
			min-height: 70rpx;
			transition: height ease-out 0.3s;
		}

		.calendar-item {
			margin: 0;
			padding: 0;
			height: 100%;
		}

		.calendar-days {
			display: flex;
			flex-flow: row wrap;
			width: 100%;
			height: 100%;
			overflow: hidden;
			font-size: 28rpx;

			.calendar-day {
				width: calc(100% / 7);
				height: 70rpx;
				text-align: center;
				display: flex;
				flex-flow: column nowrap;
				justify-content: flex-start;
				align-items: center;
			}
		}

		.day-hidden {
			visibility: hidden;
		}

		.mode-change {
			display: flex;
			justify-content: center;
			margin-top: 10rpx;
			
			.iconfont{
				width: 80rpx;
				height: 60rpx;
				line-height: 32rpx;
				text-align: center;
			}

			.mode-arrow-top {
				width: 0;
				height: 0;
				border-left: 12rpx solid transparent;
				border-right: 12rpx solid transparent;
				border-bottom: 10rpx solid #FF6633;
			}

			.mode-arrow-bottom {
				width: 0;
				height: 0;
				border-left: 12rpx solid transparent;
				border-right: 12rpx solid transparent;
				border-top: 10rpx solid #FF6633;
			}
		}

		.is-today {
			background: #ffffff;
			border-radius: 50%;
			color: var(--view-theme);
		}

		.is-checked {
			color: var(--view-theme) !important;
			background: var(--view-minorColorT);
		}

		.date {
			width: 50rpx;
			height: 50rpx;
			line-height: 50rpx;
			margin: 0 auto;
			border-radius: 50rpx;
			font-size: 28rpx;
		}

		.dot-show {
			margin-top: 4rpx;
			width: 10rpx;
			height: 10rpx;
			background: #c6c6c6;
			border-radius: 10rpx;
		}
	}
</style>