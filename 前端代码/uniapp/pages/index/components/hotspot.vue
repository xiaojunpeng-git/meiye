<template>
	<view :style="[hotspotWrapStyle]">
		<view class="hotspot">
			<view v-if="isEntryGrid" class="quick-entry-grid" :style="[imageRadius]">
				<view v-for="(item, index) in dataConfig.picStyle.list" :key="item.number" class="quick-entry" @click="goPage(item.link)">
					<text class="quick-entry__number">0{{ index + 1 }}</text>
					<text class="quick-entry__title">{{ item.name }}</text>
					<text class="iconfont icon-ic_rightarrow quick-entry__arrow"></text>
				</view>
			</view>
			<block v-else>
				<image :src="dataConfig.picStyle.url" mode="widthFix" class="image" :style="[imageRadius]"></image>
				<view v-for="(item, index) in dataConfig.picStyle.list" :key="item.number" :style="{
					top: `${item.starY}rpx`,
					left: `${item.starX}rpx`,
					width: `${item.areaWidth}rpx`,
					height: `${item.areaHeight}rpx`,
				}" class="area" @click="goPage(item.link)">
					<!-- 图片热区仅保留配置中的点击范围和跳转；名称用于装修管理，不叠加显示在图片上。 -->
				</view>
			</block>
		</view>
	</view>
</template>

<script>
	export default {
		props: {
			dataConfig: {
				type: Object,
				default: () => {}
			},
			isSortType: {
				type: String | Number,
				default: 0
			}
		},
		data() {
			return {}
		},
		computed: {
			isEntryGrid() {
				return Number(this.dataConfig.layoutConfig && this.dataConfig.layoutConfig.tabVal) === 1;
			},
			imageRadius() {
				let borderRadius = `${this.dataConfig.fillet.val * 2}rpx`;
				if (this.dataConfig.fillet.type) {
					borderRadius =
						`${this.dataConfig.fillet.valList[0].val * 2}rpx ${this.dataConfig.fillet.valList[1].val * 2}rpx ${this.dataConfig.fillet.valList[3].val * 2}rpx ${this.dataConfig.fillet.valList[2].val * 2}rpx`;
				}
				return {
					'border-radius': borderRadius,
				};
			},
			hotspotWrapStyle() {
				return {
					'padding': `${this.dataConfig.topConfig.val*2}rpx ${this.dataConfig.prConfig.val*2}rpx ${this.dataConfig.bottomConfig.val*2}rpx`,
					'margin-top': `${this.dataConfig.mbConfig.val*2}rpx`,
					'background': this.dataConfig.bottomBgColor.color[0].item,
					'overflow':'hidden'
				};
			},
		},
		methods: {
			goPage(link) {
				this.$util.JumpPath(link);
			},
		},
	}
</script>

<style lang="scss" scoped>
	.hotspot {
		position: relative;

		.image {
			display: block;
			width: 100%;
		}

		.area {
			position: absolute;
			box-sizing: border-box;
		}

		.quick-entry-grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 14rpx;
			padding: 16rpx;
			background: linear-gradient(135deg, #fffdfb 0%, #f6e9e6 100%);
			box-shadow: 0 14rpx 34rpx rgba(88, 45, 54, .08);
		}

		.quick-entry {
			position: relative;
			display: flex;
			align-items: center;
			min-height: 94rpx;
			padding: 18rpx 22rpx;
			box-sizing: border-box;
			border: 1rpx solid rgba(123, 41, 65, .08);
			border-radius: 22rpx;
			background: rgba(255, 255, 255, .78);
			box-shadow: 0 7rpx 16rpx rgba(98, 52, 62, .05);
			color: #5f3040;

			&:last-child:nth-child(odd) { grid-column: 1 / -1; }
			&:active { transform: scale(.985); background: #fff8f6; }
		}

		.quick-entry__number {
			flex: none;
			width: 44rpx;
			color: #bd8793;
			font-family: Georgia, serif;
			font-size: 22rpx;
			font-style: italic;
		}

		.quick-entry__title {
			flex: 1;
			overflow: hidden;
			font-size: 25rpx;
			font-weight: 600;
			line-height: 34rpx;
			white-space: nowrap;
			text-overflow: ellipsis;
		}

		.quick-entry__arrow { flex: none; color: #a36d7b; font-size: 20rpx; }
	}
</style>
