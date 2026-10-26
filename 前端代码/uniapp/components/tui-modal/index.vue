<template>
	<view class="tui-modal__container" :class="[show ? 'tui-modal-show' : '']" :style="{zIndex:zIndex}">
		<view class="tui-modal-box"
			:class="[fadeIn || show ? 'tui-modal-normal' : 'tui-modal-scale', show ? 'tui-modal-show' : '']">
			<view v-if="!custom">
				<view class="tui-modal-title" v-if="title">{{ title }}</view>
				<view class="tui-modal-content" :class="[title ? '' : 'tui-mtop']"
					>{{ content }}</view>
				<view class="tui-modalBtn-box">
					<view class="tui-modal-btn flex-center tui-modal-btn-cancel" @tap="handleClick(0)">{{cancelText}}</view>
					<view class="tui-modal-btn flex-center tui-modal-btn-confirm" @tap="handleClick(1)">{{confirmText}}</view>
				</view>
			</view>
			<view v-else>
				<slot></slot>
			</view>
		</view>
		<view v-if="isMask" class="tui-modal-mask" :class="[show ? 'tui-mask-show' : '']"
			:style="{zIndex:maskZIndex,background:maskColor}" @tap="handleClickCancel"
			@touchmove.stop.prevent></view>
	</view>
</template>

<script>
	export default {
		name: 'tuiModal',
		emits: ['click', 'cancel'],
		props: {
			//是否显示
			show: {
				type: Boolean,
				default: false
			},
			//标题
			title: {
				type: String,
				default: ''
			},
			//内容
			content: {
				type: String,
				default: ''
			},
			cancelText:{
				type: String,
				default: '取消'
			},
			confirmText:{
				type: String,
				default: '确定'
			},
			//点击遮罩 是否可关闭
			maskClosable: {
				type: Boolean,
				default: true
			},
			//是否显示mask
			isMask: {
				type: Boolean,
				default: true
			},
			maskColor: {
				type: String,
				default: 'rgba(0, 0, 0, 0.6)'
			},
			//淡入效果，自定义弹框插入input输入框时传true
			fadeIn: {
				type: Boolean,
				default: false
			},
			//自定义弹窗内容
			custom: {
				type: Boolean,
				default: false
			},
			//容器z-index
			zIndex: {
				type: Number,
				default: 9997
			},
			//mask z-index
			maskZIndex: {
				type: Number,
				default: 9990
			}
		},
		data() {
			return {};
		},
		methods: {
			handleClick(index) {
				if (!this.show) return;
				this.$emit('click', {
					index: Number(index)
				});
			},
			handleClickCancel() {
				if (!this.maskClosable) return;
				this.$emit('cancel');
			}
		}
	};
</script>

<style scoped>
	.tui-modal__container {
		width: 100%;
		height: 100%;
		position: fixed;
		left: 0;
		top: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		visibility: hidden;
	}

	.tui-modal-box {
		position: relative;
		opacity: 0;
		visibility: hidden;
		box-sizing: border-box;
		transition: all 0.3s ease-in-out;
		width:600rpx;
		padding: 40rpx;
		border-radius:32rpx;
		background-color: #fff;
		z-index: 9999;
	}

	.tui-modal-scale {
		transform: scale(0);
	}

	.tui-modal-normal {
		transform: scale(1);
	}

	.tui-modal-show {
		opacity: 1;
		visibility: visible;
	}

	.tui-modal-mask {
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		transition: all 0.3s ease-in-out;
		opacity: 0;
		visibility: hidden;
	}

	.tui-mask-show {
		visibility: visible;
		opacity: 1;
	}

	.tui-modal-title {
		text-align: center;
		font-size: 32rpx;
		line-height: 52rpx;
		color: #333;
		font-weight: 500;
	}

	.tui-modal-content {
		text-align: center;
		color: #666;
		font-size: 30rpx;
		line-height: 40rpx;
		margin: 24rpx auto 40rpx;
	}

	.tui-mtop {
		margin-top: 30rpx;
	}

	.tui-mbtm {
		margin-bottom: 30rpx;
	}

	.tui-modalBtn-box {
		width: 100%;
		display: flex;
		align-items: center;
		justify-content: space-between;
	}

	.tui-flex-column {
		flex-direction: column;
	}

	.tui-modal-btn {
		width: 244rpx;
		height: 72rpx;
		border-radius: 36rpx;
		font-size: 26rpx;
	}
	.tui-modal-btn-cancel{
		border: 1px solid var(--view-theme);
		color: var(--view-theme);
	}
	.tui-modal-btn-confirm{
		background-color: var(--view-theme);
		color: #fff;
	}
</style>
