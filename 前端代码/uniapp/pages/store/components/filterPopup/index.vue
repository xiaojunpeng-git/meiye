<template>
	<view :style="colorStyle">
		<tui-drawer :mode="mode" :visible="visible" @close="closeDrawer">
			<view class="acea-row row-column container">
				<!-- #ifdef MP -->
				<view :style="{height: getHeight.barTop+'px'}"></view>
				<view :style="{height: getHeight.barHeight+'px'}" class="head-box acea-row row-center-wrapper">筛选</view>
				<!-- #endif -->
				<!-- #ifndef MP -->
				<view class="head-box acea-row row-center-wrapper">筛选</view>
				<!-- #endif -->
				<scroll-view scroll-y="true" class="scroll-view">
					<view class="d-container">
						<!-- 品牌 -->
						<view class="box brand-box">
							<tui-collapse :index="brandIndex" :current="brandCurrent" @click="brandCollapse">
								<template v-slot:title>
									<tui-list-cell>品牌</tui-list-cell>
								</template>
								<template v-slot:content>
									<view class="box-list acea-row">
										<view class="box-list acea-row">
											<view class="list acea-row row-center-wrapper line1"
												:class="{'bgcolor':spanIndex.indexOf(index)>-1}"
												v-for="(item,index) in list" :key="index"
												@click="changeSpan(index,item)">
												{{item.brand_name}}
											</view>
										</view>
									</view>
								</template>
							</tui-collapse>
						</view>
						<!-- 分类 -->
						<view class="box">
							<view class="font">分类</view>
							<scroll-view scroll-x="true" class="scroll-x-view" v-if="level == 3">
								<view class="item" :class="{ on: navOneActive == index }"
									v-for="(item,index) in categoryList" :key="item.id" @click="tapNavOne(item,index)">
									<view class="img-box">
										<image :src="item.pic" class="img"></image>
									</view>
									<view class="title">{{item.cate_name}}</view>
								</view>
							</scroll-view>
							<template v-for="(item,index) in categoryErList">
								<tui-collapse :key="item.id" :index="index" :current="item.current"
									:disabled="item.disabled" @click="change">
									<template v-slot:title>
										<tui-list-cell>{{item.cate_name}}</tui-list-cell>
									</template>
									<template v-slot:content>
										<view class="box-list acea-row">
											<view class="list acea-row row-center-wrapper"
												v-for="(data,indexn) in item.children" :key="indexn"
												@click="categoryFn(data,item)">
												<view class="acea-row row-center-wrapper"
													:class="{bgcolor:data.pid===sortIndex[0] && data.id===sortIndex[1]}">
													{{data.cate_name}}
												</view>
											</view>
										</view>
									</template>
								</tui-collapse>
							</template>
						</view>
					</view>
				</scroll-view>
				<!-- 底部按钮 -->
				<view class="btn-box acea-row">
					<view class="btn" @click="submit(2)">
						重置
					</view>
					<view class="btn btnColor" @click="submit(1)">
						确认
					</view>
				</view>
				<view class="safe-area-inset-bottom"></view>
			</view>
		</tui-drawer>
		<!-- 确认 -->
	</view>
</template>

<script>
	import colors from "@/mixins/color";
	import tuiDrawer from "../thorui/tui-drawer.vue"
	import tuiCollapse from "../thorui/tui-collapse.vue"
	import tuiListCell from "../thorui/tui-list-cell.vue"
	let statusBarHeight = uni.getSystemInfoSync().statusBarHeight;

	export default {
		components: {
			tuiDrawer,
			tuiCollapse,
			tuiListCell
		},
		props: {
			storeCategory: {
				type: Array, // 分类数据
				default: () => []
			},
			storeBrand: {
				type: Array, //品牌数据
				default: () => []
			},
			level: {
				type: Number,
				default: 2
			}
		},
		mixins: [colors],
		data() {
			return {
				getHeight: this.$util.getWXStatusHeight(),
				statusBarHeight: statusBarHeight,
				visible: false,
				mode: "right",
				sortIndex: [],
				spanIndex: [],
				newList: [],
				open: 1,
				forArr: [],
				serchData: {
					sort: '', //new 最新, sales价值
					sort_type: '', // DESC 倒序 ASC 正序
					send: '',
					cate_id: [],
				},
				brandIndex: 0,
				brandCurrent: 0,
				navOneActive: -1,
				categoryList: [],
				categoryErList: [],
			}
		},
		computed: {
			storeArr() {
				return this.storeCategory
			},
			list() {
				if (this.open === 1) {
					return this.storeBrand.slice(0, 10)
				} else if (this.open === 2) {
					return this.storeBrand
				}
			},
		},
		watch: {
			storeCategory(val) {
				// for (let i = 0; i < val.length; i++) {
				// 	val[i].current = -1;
				// 	val[i].disabled = false;
				// 	if (val[i].children && val[i].children.length) {
				// 		for (let j = 0; j < val[i].children.length; j++) {
				// 			val[i].children[j].current = -1;
				// 			val[i].children[j].disabled = false;
				// 			if (val[i].children[j].children && val[i].children[j].children.length) {
								
				// 			}
				// 		}
				// 	}
				// }
				// console.log(val)
				this.categoryList = val;
				let categoryErList = [];
				if (this.level == 2) {
					categoryErList = this.categoryList;
				} else {
					categoryErList = this.categoryList[0].children;
				}
				for (let i = 0; i < categoryErList.length; i++) {
					categoryErList.current = -1
				}
				this.categoryErList = categoryErList;
				// let ids = [this.categoryErList[0].children[0].pid, this.categoryErList[0].children[0].id]
				// this.sortIndex = ids;
			},
		},
		mounted() {
			// this.newListArr()
			// this.openFn()
		},

		methods: {
			// newListArr() {
			//      // this.forArr = this.list
			//   if (this.open == 1) {
			//     this.$set(this.forArr,this.forArr.length,...this.list.slice(0, 10))

			//   } else {
			//     this.forArr = this.list
			//   }
			// },
			closeDrawer() {
				this.visible = false
			},
			// 点击展开
			openFn(num) {
				switch (num) {
					case 1:
						this.open = 2

						break;
					case 2:
						this.open = 1

						break;
				}
				// this.newListArr()
			},
			// 单选
			categoryFn(row, item) {
				this.sortIndex = [row.pid, row.id]
				// let cid = this.categoryList[this.navOneActive].id;
				let cid = item.pid;
				let sid = row.pid;
				let tid = row.id;
				if (this.level == 2) {
					cid = row.pid;
					sid = row.id;
					tid = 0;
				}
				let data = {
					cid: cid,
					sid: sid,
					tid: tid,
				}
				this.$emit('categoryChange', data)
			},
			change(e) {
				let index = e.index;
				let item = this.categoryErList[index];
				item.current = item.current == index ? -1 : index
			},
			// 多选
			changeSpan(index, row) {
				let arrIndex = this.spanIndex.indexOf(index);
				if (arrIndex > -1) {
					this.spanIndex.splice(arrIndex, 1);
					this.newList.splice(arrIndex, 1);
				} else {
					this.spanIndex.push(index);
					this.newList.push(row.id);
				}
				let result = this.newList.join(",")
				this.$emit('brandChange', result)
			},
			// 确认提交
			submit(val) {
				if (val == 2) {
					this.sortIndex = []
					this.spanIndex = []
					this.navOneActive = -1
				}
				this.$emit('submitFn', val)
			},
			brandCollapse(e) {
				this.brandCurrent = this.brandCurrent == e.index ? -1 : e.index;
			},
			tapNavOne(item, index) {
				this.navOneActive = index;
				this.categoryErList = this.categoryList[this.navOneActive].children;
				this.categoryFn(this.categoryList[index].children[0].children[0], this.categoryList[index].children[0]);
			}
		}
	}
</script>

<style lang="scss" scoped>
	.safe-area-inset-bottom {
		height: 0;
		height: constant(safe-area-inset-bottom);
		height: env(safe-area-inset-bottom);
	}

	.container {
		flex-wrap: nowrap;
		height: 100%;

		.head-box {
			height: 80rpx;
			font-weight: 500;
			font-size: 34rpx;
			color: #333333;
		}

		.scroll-view {
			flex: 1;
			min-height: 0;
		}

		.btn-box {
			padding: 20rpx 32rpx;

			.btn {
				flex: 1;
				height: 72rpx;
				border: 1rpx solid var(--view-theme);
				border-radius: 36rpx;
				margin-left: 14rpx;
				text-align: center;
				font-weight: 500;
				font-size: 26rpx;
				line-height: 72rpx;
				color: var(--view-theme);
				transform: rotateZ(360deg);

				&:first-child {
					margin-left: 0;
				}
			}

			.btnColor {
				background: var(--view-theme);
				color: #FFFFFF;
			}
		}
	}

	.bgcolor {
		background: var(--view-minorColorT) !important;
		border: 1px solid var(--view-theme);
		border-radius: 34rpx;
		color: var(--view-theme) !important;
		height: 100%;
		width: 100%;
	}

	.font {
		margin-top: 24rpx;
		font-size: 28rpx;
		font-weight: 500;
		color: #333333;
	}

	.font-live {
		font-size: 28rpx;
		font-weight: 500;
		color: #333333;
	}

	.font-right {
		margin-top: 30rpx;
		font-size: 20rpx;
		font-weight: 400;
		color: #666666;
	}

	.icon-xialazhankai {
		font-size: 20rpx;
		color: #666666;
		margin-left: 6rpx;
	}

	.icon-xiangshang {
		font-size: 20rpx;
		color: #666666;
		margin-left: 6rpx;
	}

	.d-container {
		padding: 0 32rpx;

		.box {
			.title {
				/* #ifndef MP */
				// margin-top: 30rpx;
				/* #endif */
				// display: flex;
				// justify-content: space-between;
				// padding: 24rpx 0;
			}

			.box-list {
				margin: 0 -26rpx 0 0;

				.list {
					width: 184rpx;
					height: 56rpx;
					border-radius: 28rpx;
					margin: 0 26rpx 24rpx 0;
					background: #F5F5F5;
					font-size: 24rpx;
					color: #333333;
				}
			}
		}
	}

	.scroll-x-view {
		white-space: nowrap;

		.item {
			display: inline-block;
			padding: 24rpx 0;
			margin-right: 8rpx;
			vertical-align: middle;

			&.on {
				.img-box {
					border-color: var(--view-theme);
				}

				.title {
					background: linear-gradient(90deg, var(--view-gradient) 0%, var(--view-theme) 100%);
					color: #FFFFFF;
				}
			}
		}

		.img-box {
			width: 104rpx;
			height: 104rpx;
			padding: 6rpx;
			border: 3rpx solid transparent;
			border-radius: 50%;
			margin: 0 auto;
		}

		.img {
			width: 92rpx;
			height: 92rpx;
			border-radius: 50%;
		}

		.title {
			width: 120rpx;
			height: 40rpx;
			border-radius: 20rpx;
			margin-top: 8rpx;
			text-align: center;
			font-size: 24rpx;
			line-height: 40rpx;
			color: #333333;
		}
	}
</style>