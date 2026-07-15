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
				<view class="card-icon">
					<image :src="project.image" mode="aspectFill" class="card-icon-img"></image>
					<view v-if="project.is_card_upgraded" class="card-badge">卡升级</view>
					<view v-else-if="project.status == 2" class="card-badge">已过期</view>
				</view>
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
						<view class="card-icon">
							<image :src="project.image" mode="aspectFill" class="card-icon-img"></image>
							<view v-if="project.is_card_upgraded" class="card-badge">卡升级</view>
							<view v-else-if="project.status == 2" class="card-badge">已过期</view>
						</view>
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
	</view>
</template>

<script>
	import {
		userCardList,
	} from '@/api/user.js';
	import { userInfo as staffUserInfo } from '@/api/admin.js';
	import { mapGetters } from 'vuex';

	const CARD_CLASSES = ['card-gold', 'card-rose', 'card-sage', 'card-lavender'];

	export default {
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
	background: linear-gradient(180deg, #f8f6f3 0%, #f0ece5 100%);
	display: flex;
	flex-direction: column;
}

.status-bar {
	background: linear-gradient(135deg, #c9a86c 0%, #b8956a 100%);
}

.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 24rpx 32rpx;
	background: linear-gradient(135deg, #c9a86c 0%, #b8956a 100%);
	box-shadow: 0 8rpx 40rpx rgba(201, 168, 108, 0.3);
	flex-shrink: 0;
}

.back-btn {
	width: 72rpx;
	height: 72rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	background: rgba(255, 255, 255, 0.2);
	border-radius: 50%;
	flex-shrink: 0;

	.iconfont {
		font-size: 36rpx;
		color: #fff;
	}
}

.header-content {
	flex: 1;
	text-align: center;
}

.header-title {
	font-size: 40rpx;
	font-weight: 700;
	color: #fff;
	letter-spacing: 2rpx;
}

.filter-tabs {
	display: flex;
	gap: 12rpx;
	flex-shrink: 0;
}

.filter-tab {
	padding: 12rpx 28rpx;
	border-radius: 32rpx;
	font-size: 26rpx;
	font-weight: 500;
	color: rgba(255, 255, 255, 0.8);
	background: rgba(255, 255, 255, 0.15);
	border: 1rpx solid rgba(255, 255, 255, 0.2);

	&.active {
		color: #c9a86c;
		background: #fff;
		border-color: #fff;
		box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.1);
	}
}

.content {
	flex: 1;
	height: 0;
	padding: 24rpx 32rpx 0;
	box-sizing: border-box;
}

.category-section {
	margin-bottom: 24rpx;
	background: #fff;
	border-radius: 32rpx;
	overflow: hidden;
	box-shadow: 0 8rpx 30rpx rgba(0, 0, 0, 0.05);
}

.category-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 36rpx 40rpx;
	background: linear-gradient(90deg, #fff 0%, #faf8f5 100%);
	border-bottom: 1rpx solid transparent;

	&.expanded {
		border-bottom-color: #f0ece5;
	}
}

.category-header-left {
	display: flex;
	align-items: center;
	gap: 24rpx;
}

.category-icon {
	width: 72rpx;
	height: 72rpx;
	border-radius: 20rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 32rpx;
	font-weight: 600;
	background: linear-gradient(135deg, #c9a86c 0%, #b8956a 100%);
	color: #fff;
}

.category-title {
	font-size: 32rpx;
	font-weight: 600;
	color: #333;
}

.category-count {
	font-size: 24rpx;
	color: #999;
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
		color: #c9a86c;
	}

	&.expanded {
		transform: rotate(180deg);
	}
}

.category-content {
	max-height: 0;
	overflow: hidden;
	transition: max-height 0.4s ease, padding 0.4s ease;
	padding: 0 32rpx;
	background: #faf8f5;

	&.expanded {
		max-height: 10000rpx;
		padding: 32rpx;
	}
}

.project-card {
	border-radius: 28rpx;
	padding: 36rpx;
	margin-bottom: 24rpx;
	display: flex;
	align-items: center;
	gap: 28rpx;
	box-shadow: 0 8rpx 30rpx rgba(0, 0, 0, 0.08);
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
		background: linear-gradient(135deg, rgba(255, 255, 255, 0.2) 0%, transparent 50%);
		pointer-events: none;
	}
}

.card-gold {
	background: linear-gradient(135deg, #d4b896 0%, #c9a86c 50%, #b8956a 100%);
}

.card-rose {
	background: linear-gradient(135deg, #e8b4b8 0%, #d4a5a9 50%, #c49a9e 100%);
}

.card-sage {
	background: linear-gradient(135deg, #a8c5b5 0%, #8fb5a0 50%, #7aa58b 100%);
}

.card-lavender {
	background: linear-gradient(135deg, #c5b8d4 0%, #b0a0c4 50%, #9a88b0 100%);
}

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
	font-size: 34rpx;
	font-weight: 700;
	color: #fff;
	margin-bottom: 16rpx;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	text-shadow: 0 2rpx 4rpx rgba(0, 0, 0, 0.1);
}

.card-desc {
	font-size: 26rpx;
	color: rgba(255, 255, 255, 0.95);
	display: flex;
	align-items: center;
	gap: 12rpx;

	&::before {
		content: '';
		display: inline-block;
		width: 12rpx;
		height: 12rpx;
		background: rgba(255, 255, 255, 0.8);
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
	font-size: 26rpx;
	color: #fff;
	font-weight: 600;
	background: rgba(255, 255, 255, 0.2);
	padding: 8rpx 20rpx;
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
