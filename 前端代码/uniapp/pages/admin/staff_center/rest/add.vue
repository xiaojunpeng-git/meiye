<template>
	<view class="add-page" :style="colorStyle">
		<view class="form-card">
			<view class="row acea-row row-between-wrapper" @click="openCalendar">
				<text>休息日期</text>
				<view class="value">{{ dateLabel }}<text class="iconfont icon-ic_rightarrow"></text></view>
			</view>
			<view class="row acea-row row-between-wrapper">
				<text>休息班次</text>
				<picker :range="typeList" :value="typeIndex" @change="onTypeChange">
					<view class="value">{{ typeList[typeIndex] || '选择班次' }}<text class="iconfont icon-ic_rightarrow"></text></view>
				</picker>
			</view>
			<view class="shift-tip" v-if="!shiftOptions.length && !loading">请先在收银台「排班管理 → 班次管理」中添加班次</view>
			<textarea class="textarea" v-model="remark" maxlength="100" placeholder="备注（选填）"></textarea>
			<view class="submit-btn" @click="submit">提交</view>
		</view>
		<uni-calendar :insert="false" ref="calendar" @confirm="onDateChange" />
	</view>
</template>

<script>
import uniCalendar from '@/components/uni-calendar/uni-calendar.vue';
import { staffTeacherRestSave, staffTeacherRestShiftOptions } from '@/api/store.js';
import colors from '@/mixins/color.js';

export default {
	components: { uniCalendar },
	mixins: [colors],
	data() {
		return {
			date: '',
			dateLabel: '选择日期',
			shiftOptions: [],
			typeList: ['选择班次'],
			typeIndex: 0,
			remark: '',
			loading: false
		};
	},
	onLoad() {
		this.loadShiftOptions();
	},
	methods: {
		loadShiftOptions() {
			this.loading = true;
			staffTeacherRestShiftOptions().then(res => {
				this.shiftOptions = res.data?.list || [];
				this.typeList = ['选择班次'].concat(this.shiftOptions.map(item => item.label || item.name || '班次'));
				this.typeIndex = 0;
			}).catch(err => {
				this.shiftOptions = [{
					shift_id: 0,
					name: '整天',
					label: '整天',
					is_full_day: 1
				}];
				this.typeList = ['选择班次', '整天'];
				this.$util.Tips({ title: err.msg || err || '班次加载失败' });
			}).finally(() => {
				this.loading = false;
			});
		},
		openCalendar() {
			this.$refs.calendar.open();
		},
		onDateChange(e) {
			this.date = e.fulldate;
			this.dateLabel = this.date;
		},
		onTypeChange(e) {
			this.typeIndex = Number(e.detail.value);
		},
		submit() {
			if (!this.date) return this.$util.Tips({ title: '请选择休息日期' });
			if (this.typeIndex <= 0) return this.$util.Tips({ title: '请选择休息班次' });
			const option = this.shiftOptions[this.typeIndex - 1];
			if (!option) return this.$util.Tips({ title: '请选择休息班次' });
			staffTeacherRestSave({
				date: this.date,
				shift_id: Number(option.shift_id) || 0,
				is_full_day: Number(option.is_full_day) || 0,
				remark: this.remark
			}).then(res => {
				this.$util.Tips({ title: res.msg || '添加成功' }, () => uni.navigateBack());
			}).catch(err => {
				this.$util.Tips({ title: err.msg || err || '添加失败' });
			});
		}
	}
};
</script>

<style scoped lang="scss">
.add-page {
	min-height: 100vh;
	background: #f5f5f5;
	padding: 24rpx;
}
.form-card {
	background: #fff;
	border-radius: 16rpx;
	padding: 0 30rpx 40rpx;
}
.row {
	padding: 30rpx 0;
	border-bottom: 1rpx solid #f0f0f0;
	font-size: 28rpx;
}
.value {
	color: #666;
}
.shift-tip {
	padding: 20rpx 0 0;
	font-size: 24rpx;
	color: #999;
	line-height: 36rpx;
}
.textarea {
	margin-top: 24rpx;
	width: 100%;
	height: 180rpx;
	background: #fafafa;
	border-radius: 12rpx;
	padding: 20rpx;
	box-sizing: border-box;
	font-size: 28rpx;
}
.submit-btn {
	margin-top: 40rpx;
	height: 86rpx;
	line-height: 86rpx;
	text-align: center;
	background: #07cd9a;
	color: #fff;
	border-radius: 43rpx;
}
</style>
