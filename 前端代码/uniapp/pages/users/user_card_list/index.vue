<template>
	<view class="card-list-page">
		<!-- #ifdef MP || APP-PLUS -->
		<view class="status-bar" :style="{ height: statusBarHeight + 'px' }"></view>
		<!-- #endif -->
		<view class="header">
			<view class="back-btn" @click="goBack">
				<text class="iconfont icon-ic_leftarrow"></text>
			</view>
			<view class="header-content">
				<view class="header-title">{{ pageTitle }}</view>
			</view>
			<view class="filter-tabs">
				<view
					class="filter-tab"
					:class="{ active: currentFilter === 'valid' }"
					@click="switchFilter('valid')"
				>有效</view>
				<view
					class="filter-tab"
					:class="{ active: currentFilter === 'all' }"
					@click="switchFilter('all')"
				>全部</view>
			</view>
		</view>

		<scroll-view scroll-y class="content">
			<view v-if="loading" class="page-loading">
				<view class="loading-spinner"></view>
				<text class="loading-text">加载中...</text>
			</view>

			<view v-else-if="loadend && !hasDisplayContent" class="empty-tip">
				<view class="empty-icon">📋</view>
				<text>暂无卡包信息</text>
			</view>

			<template v-else>
			<view
				v-for="project in uncategorizedProjects"
				:key="project.key"
				class="project-card standalone-card"
				:class="[project.cardClass, { disabled: project.disabled }]"
				@click="goProject(project)"
			>
				<view class="card-info">
					<view class="card-name">{{ project.name }}</view>
					<view class="card-desc">{{ project.desc }}</view>
				</view>
				<view class="card-right">
					<view class="card-status">
						{{ project.remain > 0 ? `剩余${project.remain}次` : '核销完成' }}
					</view>
				</view>
			</view>

			<view
				v-for="(group, gIndex) in categoryGroups"
				:key="group.name"
				class="category-section"
			>
				<view
					class="category-header"
					:class="{ expanded: isCategoryExpanded(group.name) }"
					@click="toggleCategory(group.name)"
				>
					<view class="category-header-left">
						<view class="category-icon">{{ gIndex + 1 }}</view>
						<view class="category-title-wrapper">
							<view class="category-title">{{ group.name }}</view>
							<view class="category-count">
								{{ group.count }} 个项目 · 剩余 {{ group.totalRemain }} 次
							</view>
						</view>
					</view>
					<view class="category-arrow" :class="{ expanded: isCategoryExpanded(group.name) }">
						<text class="iconfont icon-ic_downarrow"></text>
					</view>
				</view>

				<view class="category-content" :class="{ expanded: isCategoryExpanded(group.name) }">
					<view
						v-for="project in group.projects"
						:key="project.key"
						class="project-card"
						:class="[project.cardClass, { disabled: project.disabled }]"
						@click="goProject(project)"
					>
						<view class="card-info">
							<view class="card-name">{{ project.name }}</view>
							<view class="card-desc">{{ project.desc }}</view>
						</view>
						<view class="card-right">
							<view class="card-status">
								{{ project.remain > 0 ? `剩余${project.remain}次` : '核销完成' }}
							</view>
						</view>
					</view>
				</view>
			</view>
			</template>
			<view class="pb-safe"></view>
		</scroll-view>
		<view :style="{ height: pdHeight * 2 + 96 + 'rpx' }" v-if="showBar"></view>
		<view class="safe-area-inset-bottom" v-if="showBar"></view>
		<pageFooter @newDataStatus="newDataStatus"></pageFooter>
	</view>
</template>

<script>
	import {
		userCardList,
	} from '@/api/user.js';
	import { userInfo as staffUserInfo } from '@/api/admin.js';
	import { mapGetters } from 'vuex';
	import pageFooter from '@/components/pageFooter/index.vue';

	const CARD_CLASSES = ['card-gold', 'card-rose', 'card-sage', 'card-lavender'];

	export default {
		components: {
			pageFooter,
		},
		data() {
			return {
				pageUid: '',
				viewUid: '',
				staffInfo: {},
				staffInfoLoaded: false,
				cardList: [],
				loading: true,
				loadend: false,
				currentFilter: 'valid',
				expandedCategories: {},
				statusBarHeight: 0,
				colorIndex: 0,
				showBar: false,
				pdHeight: 0,
			};
		},
		computed: {
			...mapGetters(['uid']),
			pageTitle() {
				return this.isViewingOther ? '客户项目' : '我的项目';
			},
			isViewingOther() {
				if (!this.pageUid) return false;
				return String(this.pageUid) !== String(this.uid || '');
			},
			projectList() {
				return this.buildProjects(this.cardList);
			},
			filteredProjects() {
				if (this.currentFilter === 'valid') {
					return this.projectList.filter((item) => {
						return item.remain > 0 && item.status == 1 && !item.is_card_upgraded;
					});
				}
				return this.projectList;
			},
			categoryGroups() {
				const map = {};
				const order = [];
				this.filteredProjects.forEach((project) => {
					if (project.uncategorized || !project.cate_name) {
						return;
					}
					const name = project.cate_name;
					if (!map[name]) {
						map[name] = {
							name,
							projects: [],
						};
						order.push(name);
					}
					map[name].projects.push(project);
				});
				return order.map((name) => {
					const group = map[name];
					return {
						name: group.name,
						projects: group.projects,
						count: group.projects.length,
						totalRemain: group.projects.reduce((sum, item) => sum + (Number(item.remain) || 0), 0),
					};
				});
			},
			uncategorizedProjects() {
				return this.filteredProjects.filter((project) => {
					return project.uncategorized || !project.cate_name;
				});
			},
			hasDisplayContent() {
				return this.uncategorizedProjects.length > 0 || this.categoryGroups.length > 0;
			},
		},
		onLoad(option) {
			this.pageUid = option.uid || '';
			// #ifdef MP || APP-PLUS
			const sys = uni.getSystemInfoSync();
			this.statusBarHeight = sys.statusBarHeight || 0;
			// #endif
			this.initPage();
		},
		methods: {
			newDataStatus(val, num) {
				this.showBar = !!val;
				this.pdHeight = num || 0;
			},
			initPage() {
				this.viewUid = this.pageUid || this.uid || '';
				if (this.isViewingOther) {
					this.ensureStaffInfo().finally(() => {
						this.getCardList();
					});
					return;
				}
				this.getCardList();
			},
			ensureStaffInfo() {
				const cached = this.$store.state.app.storeStaffInfo || {};
				if (cached && (cached.is_manager !== undefined || cached.is_butler !== undefined)) {
					this.staffInfo = cached;
					this.staffInfoLoaded = true;
					return Promise.resolve(cached);
				}
				return staffUserInfo().then((res) => {
					this.staffInfo = res.data || {};
					this.staffInfoLoaded = true;
					this.$store.commit('SET_STORE_STAFF_INFO', this.staffInfo);
				}).catch(() => {
					this.staffInfo = {};
					this.staffInfoLoaded = true;
				});
			},
			isUncategorizedProject(item) {
				if (!item) return true;
				if (item.uncategorized === true || item.uncategorized === 1 || item.uncategorized === '1') {
					return true;
				}
				if (item.uncategorized === false || item.uncategorized === 0 || item.uncategorized === '0') {
					return false;
				}
				return !item.cate_name;
			},
			buildProjects(cardList) {
				const projects = [];
				let colorIdx = 0;
				cardList.forEach((card) => {
					if (card.product_type == 5 && card.related_list && card.related_list.length) {
						card.related_list.forEach((related) => {
							projects.push(this.formatProject({
								key: `related-${related.id}`,
								holderId: card.id,
								cartInfoId: related.id,
								oid: card.oid,
								name: related.project_name || related.cart_info?.productInfo?.store_name || card.card_name,
								image: related.image || card.image,
								remain: related.write_surplus_times,
								write_times: related.write_times,
								duration: related.duration || 0,
								cate_id: related.cate_id || 0,
								cate_name: related.cate_name || '',
								uncategorized: this.isUncategorizedProject(related),
								product_type: related.product_type,
								status: card.status,
								is_card_upgraded: card.is_card_upgraded,
								cardClass: CARD_CLASSES[colorIdx % CARD_CLASSES.length],
								store_name: card.store_name,
							}));
							colorIdx++;
						});
					} else {
						projects.push(this.formatProject({
							key: `card-${card.id}`,
							holderId: card.id,
							cartInfoId: 0,
							oid: card.oid,
							name: card.card_name,
							image: card.image,
							remain: card.write_surplus_times,
							write_times: card.write_times,
							duration: 0,
							cate_id: card.cate_id || 0,
							cate_name: card.cate_name || '',
							uncategorized: this.isUncategorizedProject(card),
							product_type: card.product_type,
							status: card.status,
							is_card_upgraded: card.is_card_upgraded,
							cardClass: CARD_CLASSES[colorIdx % CARD_CLASSES.length],
							store_name: card.store_name,
							write_valid: card.write_valid,
							write_end: card.write_end,
						}));
						colorIdx++;
					}
				});
				return projects;
			},
			formatProject(raw) {
				const disabled = raw.status == 2 || raw.is_card_upgraded;
				let desc = '';
				if (raw.duration > 0) {
					desc = `${raw.duration}分钟`;
				} else if (raw.write_valid == 1) {
					desc = '有效期：永久有效';
				} else if (raw.write_end) {
					desc = `有效期至：${raw.write_end}`;
				} else if (raw.store_name) {
					desc = raw.store_name;
				}
				return {
					...raw,
					disabled,
					desc,
				};
			},
			getCardList() {
				this.loading = true;
				const params = {};
				if (this.viewUid) {
					params.uid = this.viewUid;
				}
				userCardList(params).then((res) => {
					this.cardList = res.data.list || [];
				}).catch((err) => {
					this.cardList = [];
					if (this.isViewingOther) {
						this.$util.Tips({ title: err || '没有权限查看客户卡包' });
					}
				}).finally(() => {
					this.loading = false;
					this.loadend = true;
				});
			},
			switchFilter(filter) {
				this.currentFilter = filter;
			},
			isCategoryExpanded(name) {
				return this.expandedCategories[name] !== false;
			},
			toggleCategory(name) {
				const current = this.isCategoryExpanded(name);
				this.$set(this.expandedCategories, name, !current);
			},
			goProject(project) {
				if (project.disabled) {
					return;
				}
				uni.navigateTo({
					url: `/pages/users/user_card/index?id=${project.holderId}`,
				});
			},
			goBack() {
				const pages = getCurrentPages();
				if (pages.length > 1) {
					uni.navigateBack({ delta: 1 });
				} else {
					uni.switchTab({ url: '/pages/user/index' });
				}
			},
		},
	};
</script>

<style lang="scss" scoped>
.card-list-page {
	min-height: 100vh;
	background: #fbf8f6;
	display: flex;
	flex-direction: column;
}

.status-bar {
	background: #fff8f5;
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 24rpx 32rpx 26rpx;
	background: linear-gradient(135deg, #fffaf8 0%, #f5e6e4 100%);
	border-bottom: 1rpx solid rgba(123, 41, 65, 0.08);
	box-shadow: 0 8rpx 28rpx rgba(94, 47, 58, 0.06);
	flex-shrink: 0;
}

.back-btn {
	width: 72rpx;
	height: 72rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(255, 255, 255, 0.82);
	border: 1rpx solid rgba(123, 41, 65, 0.08);
	border-radius: 50%;
	flex-shrink: 0;

	.iconfont {
		font-size: 36rpx;
		color: #6f2b40;
	}
}

.header-content {
	flex: 1;
	text-align: center;
}

.header-title {
	font-size: 38rpx;
	font-weight: 600;
	color: #4d3037;
	letter-spacing: 1rpx;
}

.filter-tabs {
	display: flex;
	gap: 10rpx;
	flex-shrink: 0;
}

.filter-tab {
	padding: 12rpx 24rpx;
	border-radius: 32rpx;
	font-size: 24rpx;
	font-weight: 500;
	color: #8d7379;
	background: rgba(255, 255, 255, 0.58);
	border: 1rpx solid rgba(123, 41, 65, 0.1);

	&.active {
		color: #fff;
		background: #7b2941;
		border-color: #7b2941;
		box-shadow: 0 6rpx 16rpx rgba(123, 41, 65, 0.18);
	}
}

.content {
	flex: 1;
	height: 0;
	padding: 28rpx 24rpx 0;
	box-sizing: border-box;
}

.category-section {
	margin-bottom: 20rpx;
	background: #fffdfb;
	border: 1rpx solid rgba(123, 41, 65, 0.06);
	border-radius: 28rpx;
	overflow: hidden;
	box-shadow: 0 12rpx 32rpx rgba(87, 44, 55, 0.06);
}

.category-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 28rpx 30rpx;
	background: linear-gradient(90deg, #fffdfb 0%, #fff7f5 100%);
	border-bottom: 1rpx solid transparent;

	&.expanded {
		border-bottom-color: rgba(123, 41, 65, 0.08);
	}
}

.category-header-left {
	display: flex;
	align-items: center;
	gap: 18rpx;
}

.category-icon {
	width: 64rpx;
	height: 64rpx;
	border-radius: 18rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 28rpx;
	font-weight: 600;
	background: #f2e2df;
	color: #fff;
	color: #7b2941;
}

.category-title {
	font-size: 30rpx;
	font-weight: 600;
	color: #4d3037;
}

.category-count {
	font-size: 22rpx;
	color: #947a80;
	margin-top: 4rpx;
}

.category-arrow {
	width: 48rpx;
	height: 48rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: transform 0.3s ease;

	.iconfont {
		font-size: 28rpx;
		color: #9c6270;
	}

	&.expanded {
		transform: rotate(180deg);
	}
}

.category-content {
	max-height: 0;
	overflow: hidden;
	transition: max-height 0.4s ease, padding 0.4s ease;
	padding: 0 20rpx;
	background: #fffaf8;

	&.expanded {
		max-height: 10000rpx;
		padding: 20rpx;
	}
}

.project-card {
	border-radius: 22rpx;
	padding: 26rpx 26rpx 24rpx;
	margin-bottom: 16rpx;
	display: flex;
	align-items: center;
	gap: 28rpx;
	border: 1rpx solid rgba(123, 41, 65, 0.06);
	box-shadow: 0 8rpx 22rpx rgba(87, 44, 55, 0.05);
	position: relative;
	overflow: hidden;

	&:last-child {
		margin-bottom: 0;
	}

	&.standalone-card {
		margin-bottom: 24rpx;
	}

	&.disabled {
		opacity: 0.65;
	}

	&::before {
		content: '';
		position: absolute;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background: linear-gradient(90deg, rgba(255, 255, 255, 0.6) 0%, transparent 48%);
		pointer-events: none;
	}
}

.card-gold { background: #fffaf4; border-left: 8rpx solid #c9a86c; }
.card-rose { background: #fff8f8; border-left: 8rpx solid #bb7888; }
.card-sage { background: #f8fbf9; border-left: 8rpx solid #83ab9c; }
.card-lavender { background: #fbf9fd; border-left: 8rpx solid #aa98bf; }

.card-icon {
	width: 112rpx;
	height: 112rpx;
	border-radius: 50%;
	overflow: hidden;
	flex-shrink: 0;
	background: #fff;
	border: 6rpx solid rgba(255, 255, 255, 0.5);
	box-shadow: 0 8rpx 24rpx rgba(0, 0, 0, 0.1);
	position: relative;
	z-index: 1;
}

.card-icon-img {
	width: 100%;
	height: 100%;
}

.card-badge {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(0, 0, 0, 0.5);
	color: #fff;
	font-size: 22rpx;
}

.card-info {
	flex: 1;
	min-width: 0;
	position: relative;
	z-index: 1;
}

.card-name {
	font-size: 31rpx;
	font-weight: 600;
	color: #4d3037;
	margin-bottom: 10rpx;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.card-desc {
	font-size: 23rpx;
	color: #8d747a;
	display: flex;
	align-items: center;
	gap: 12rpx;

	&::before {
		content: '';
		display: inline-block;
		width: 12rpx;
		height: 12rpx;
		background: #ba8b95;
		border-radius: 50%;
		flex-shrink: 0;
	}
}

.card-right {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	position: relative;
	z-index: 1;
	flex-shrink: 0;
}

.card-status {
	font-size: 23rpx;
	color: #7b2941;
	font-weight: 600;
	background: #f3e5e7;
	padding: 10rpx 18rpx;
	border-radius: 24rpx;
	white-space: nowrap;
}

.empty-tip {
	text-align: center;
	padding: 120rpx 40rpx;
	color: #999;
	font-size: 28rpx;
}

.page-loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 160rpx 40rpx 120rpx;
}

.loading-spinner {
	width: 64rpx;
	height: 64rpx;
	border: 6rpx solid #f0ece5;
	border-top-color: #c9a86c;
	border-radius: 50%;
	animation: card-list-spin 0.8s linear infinite;
}

.loading-text {
	margin-top: 24rpx;
	font-size: 28rpx;
	color: #999;
}

@keyframes card-list-spin {
	to {
		transform: rotate(360deg);
	}
}

.empty-icon {
	width: 120rpx;
	height: 120rpx;
	margin: 0 auto 32rpx;
	background: #f0ece5;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 56rpx;
}
</style>
