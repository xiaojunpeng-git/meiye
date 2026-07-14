<template>
	<view class="intro-page" :style="colorStyle">
		<view class="form-card">
			<view class="title">个人简介</view>
			<textarea
				class="textarea"
				v-model="intro"
				maxlength="500"
				placeholder="填写您的简介，将展示在预约选老师页面"
				placeholder-class="placeholder"
			></textarea>
			<view class="count">{{ intro.length }}/500</view>
			<view class="submit-btn" @click="submit">保存</view>
		</view>
	</view>
</template>

<script>
import { staffTeacherCenter, staffTeacherUpdate } from '@/api/store.js';
import colors from '@/mixins/color.js';

export default {
	mixins: [colors],
	data() {
		return {
			intro: ''
		};
	},
	onShow() {
		staffTeacherCenter().then(res => {
			this.intro = res.data?.staff_intro || '';
		});
	},
	methods: {
		submit() {
			staffTeacherUpdate({ staff_intro: this.intro }).then(res => {
				this.$util.Tips({ title: res.msg || '保存成功' }, () => uni.navigateBack());
			}).catch(err => {
				this.$util.Tips({ title: err.msg || err || '保存失败' });
			});
		}
	}
};
</script>

<style scoped lang="scss">
.intro-page {
	min-height: 100vh;
	background: #f5f5f5;
	padding: 24rpx;
}
.form-card {
	background: #fff;
	border-radius: 16rpx;
	padding: 30rpx;
}
.title {
	font-size: 30rpx;
	font-weight: 600;
	margin-bottom: 20rpx;
}
.textarea {
	width: 100%;
	height: 360rpx;
	background: #fafafa;
	border-radius: 12rpx;
	padding: 20rpx;
	box-sizing: border-box;
	font-size: 28rpx;
}
.placeholder {
	color: #bbb;
}
.count {
	text-align: right;
	font-size: 24rpx;
	color: #999;
	margin-top: 12rpx;
}
.submit-btn {
	margin-top: 40rpx;
	height: 86rpx;
	line-height: 86rpx;
	text-align: center;
	background: #07cd9a;
	color: #fff;
	border-radius: 43rpx;
	font-size: 30rpx;
}
</style>
