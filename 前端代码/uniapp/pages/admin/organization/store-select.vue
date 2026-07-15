<template>
	<view class="select-page">
		<!-- #ifdef H5 -->
		<view class="h5-nav">
			<view class="h5-nav-back" @tap="goBack">‹</view>
			<view class="h5-nav-title">{{ pageTitle }}</view>
			<view class="h5-nav-confirm" @tap="confirm">确定</view>
		</view>
		<!-- #endif -->

		<view class="search-bar">
			<view class="search-input-wrap">
				<text class="search-icon">⌕</text>
				<input class="search-input" v-model="keyword" placeholder="搜索组织或门店" confirm-type="search" />
				<text v-if="keyword" class="search-clear" @tap="keyword = ''">清除</text>
			</view>
		</view>

		<view v-if="recentList.length && !keyword" class="recent-bar">
			<view class="recent-title">最近使用</view>
			<scroll-view scroll-x class="recent-scroll">
				<view
					v-for="item in recentList"
					:key="'r-' + item.id"
					class="recent-chip"
					:class="{ active: !!selectedStoreIds[item.id] }"
					@tap="toggleRecent(item)"
				>
					{{ item.name }}
				</view>
			</scroll-view>
		</view>

		<view class="mode-tip" v-if="isMultiple">
			{{ snapshotMode ? '创建/分配：确认后固化门店快照' : '分析筛选：实时跟随组织门店变化' }}
		</view>
		<view class="mode-tip" v-else>单选门店：组织仅可展开浏览，不可勾选</view>

		<view class="tree-body">
			<view v-if="loading" class="empty-tip">加载中...</view>
			<view v-else class="tree-container">
				<view v-for="(item, index) in flatNodes" :key="item.key + '-' + index">
					<view v-if="item.isStore" :class="item.rowClass" @tap="onFlatNodeTap(index)">
						<view class="level-store-icon">店</view>
						<view class="level-info">
							<view class="level-name">{{ item.name }}</view>
							<view class="level-desc">{{ item.desc }}</view>
						</view>
						<view class="check" :class="{ checked: item.checked }">✓</view>
					</view>
					<view v-else class="org-node">
						<view :class="item.rowClass" @tap="onFlatOrgTap(index)">
							<view class="org-toggle" :class="{ expanded: item.expanded }">›</view>
							<view class="org-icon">组</view>
							<view class="level-info">
								<view class="level-name">{{ item.name }}</view>
								<view class="level-desc">{{ item.desc }}</view>
							</view>
							<view
								v-if="isMultiple"
								class="check"
								:class="{ checked: item.checked, partial: item.partial }"
								@tap.stop="onFlatNodeTap(index)"
							>✓</view>
						</view>
					</view>
				</view>
			</view>
			<view v-if="!loading && !flatNodes.length" class="empty-tip">暂无匹配门店</view>
		</view>

		<view class="footer-bar">
			<view class="footer-left">
				<text class="footer-label">已选：</text>
				<text class="footer-summary">{{ selectedSummary }}</text>
			</view>
			<view class="footer-actions">
				<button class="btn-clear" @tap="clearAll">清空</button>
				<button class="btn-ok" @tap="confirm">确定</button>
			</view>
		</view>
	</view>
</template>

<script>
import { organizationScopeTree, organizationScopeResolve } from '@/api/organization.js';
import {
	MERCHANT_STORE_PICKER_RESULT_KEY,
	MERCHANT_STORE_PICKER_PRESET_KEY,
	isStoreNode,
	nodeKey,
	collectStoreIdsFromNode,
	buildStoreNameMap,
	filterTreeByKeyword,
	deriveSelectionPayload,
	loadRecentStores,
	pushRecentStores,
	formatSelectionSummary,
} from '@/components/merchantStorePicker/utils.js';

export default {
	data() {
		return {
			keyword: '',
			tree: [],
			flatNodes: [],
			expandedKeys: {},
			selectedStoreIds: {},
			selectedSingleId: 0,
			loading: true,
			mode: 'multiple',
			snapshotMode: false,
			realtimeMode: true,
			recentList: [],
			confirming: false,
		};
	},
	computed: {
		isMultiple() {
			return this.mode !== 'single';
		},
		pageTitle() {
			return this.isMultiple ? '选择组织/门店' : '选择门店';
		},
		displayTree() {
			return filterTreeByKeyword(this.tree, this.keyword);
		},
		searching() {
			return !!String(this.keyword || '').trim();
		},
		selectedSummary() {
			const map = buildStoreNameMap(this.tree);
			if (this.isMultiple) {
				const ids = this.getSelectedStoreIdList();
				return formatSelectionSummary(
					{ mode: 'multiple', resolved_store_ids: ids },
					map
				);
			}
			return formatSelectionSummary(
				{ mode: 'single', store_id: this.selectedSingleId },
				map
			);
		},
	},
	watch: {
		keyword() {
			this.rebuildFlatNodes();
		},
		selectedStoreIds: {
			deep: true,
			handler() {
				this.rebuildFlatNodes();
			},
		},
		selectedSingleId() {
			this.rebuildFlatNodes();
		},
	},
	onLoad(options) {
		this.mode = options.mode === 'single' ? 'single' : 'multiple';
		this.snapshotMode = String(options.snapshot || '') === '1';
		this.realtimeMode = !this.snapshotMode && String(options.realtime || '1') !== '0';
		this.recentList = loadRecentStores();
		this.restorePreset();
		this.loadTree();
		// #ifndef H5
		uni.setNavigationBarTitle({ title: this.pageTitle });
		// #endif
	},
	methods: {
		goBack() {
			uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/index/index' }) });
		},
		restorePreset() {
			let preset = null;
			try {
				preset = uni.getStorageSync(MERCHANT_STORE_PICKER_PRESET_KEY);
				uni.removeStorageSync(MERCHANT_STORE_PICKER_PRESET_KEY);
			} catch (e) {
				preset = null;
			}
			if (!preset || typeof preset !== 'object') return;
			if (preset.mode === 'single' || preset.mode === 'multiple') {
				this.mode = preset.mode;
			}
			if (preset.snapshot != null) this.snapshotMode = !!preset.snapshot;
			if (preset.realtime != null) this.realtimeMode = !!preset.realtime && !this.snapshotMode;
			if (this.isMultiple) {
				const map = {};
				(preset.resolved_store_ids || []).forEach((id) => {
					const sid = Number(id);
					if (sid > 0) map[sid] = true;
				});
				(preset.store_ids || []).forEach((id) => {
					const sid = Number(id);
					if (sid > 0) map[sid] = true;
				});
				this.selectedStoreIds = map;
			} else {
				this.selectedSingleId = Number(preset.store_id || (preset.resolved_store_ids || [])[0] || 0);
			}
		},
		loadTree() {
			this.loading = true;
			organizationScopeTree()
				.then((res) => {
					const data = (res && res.data) || res || {};
					this.tree = Array.isArray(data.tree) ? data.tree : [];
					this.expandTopLevel(this.tree);
					this.rebuildFlatNodes();
				})
				.catch((err) => {
					this.tree = [];
					this.flatNodes = [];
					uni.showToast({
						title: (err && (err.msg || err.message)) || '加载门店失败',
						icon: 'none',
					});
				})
				.finally(() => {
					this.loading = false;
				});
		},
		expandTopLevel(nodes) {
			(nodes || []).forEach((node) => {
				if (!isStoreNode(node)) {
					this.$set(this.expandedKeys, nodeKey(node), true);
				}
			});
		},
		isExpanded(node) {
			if (this.searching) return true;
			return !!this.expandedKeys[nodeKey(node)];
		},
		isNodeSelected(node) {
			if (!node) return false;
			if (!this.isMultiple) {
				return isStoreNode(node) && Number(node.id) === Number(this.selectedSingleId);
			}
			const ids = collectStoreIdsFromNode(node);
			if (!ids.length) return false;
			return ids.every((id) => !!this.selectedStoreIds[id]);
		},
		isNodePartial(node) {
			if (!this.isMultiple || !node || isStoreNode(node)) return false;
			const ids = collectStoreIdsFromNode(node);
			if (ids.length <= 1) return false;
			const selectedCount = ids.filter((id) => !!this.selectedStoreIds[id]).length;
			return selectedCount > 0 && selectedCount < ids.length;
		},
		buildFlatRow(node, depth) {
			const store = isStoreNode(node);
			return {
				key: nodeKey(node),
				isStore: store,
				name: String((node && node.name) || ''),
				desc: String((node && node.desc) || ''),
				depth,
				rowClass: (store ? 'store-row ' : 'org-header ') + 'depth-' + depth,
				expanded: this.isExpanded(node),
				checked: this.isNodeSelected(node),
				partial: !store && this.isNodePartial(node),
			};
		},
		rebuildFlatNodes() {
			const rows = [];
			const walk = (nodes, depth) => {
				(nodes || []).forEach((node) => {
					if (!node) return;
					rows.push(this.buildFlatRow(node, depth));
					if (!isStoreNode(node) && this.isExpanded(node)) {
						walk(node.children || [], depth + 1);
					}
				});
			};
			walk(this.displayTree, 0);
			this.flatNodes = rows;
		},
		findNodeByKey(key) {
			const walk = (nodes) => {
				for (let i = 0; i < (nodes || []).length; i++) {
					const node = nodes[i];
					if (nodeKey(node) === key) return node;
					const hit = walk(node.children || []);
					if (hit) return hit;
				}
				return null;
			};
			return walk(this.tree);
		},
		onFlatOrgTap(index) {
			const item = this.flatNodes[index];
			if (!item) return;
			const key = item.key;
			this.$set(this.expandedKeys, key, !this.expandedKeys[key]);
			this.rebuildFlatNodes();
		},
		onFlatNodeTap(index) {
			const item = this.flatNodes[index];
			if (!item) return;
			const node = this.findNodeByKey(item.key);
			if (!node) return;
			if (!this.isMultiple) {
				if (!isStoreNode(node)) {
					uni.showToast({ title: '请选择具体门店', icon: 'none' });
					return;
				}
				this.selectedSingleId = Number(node.id);
				return;
			}
			this.toggleMultiNode(node);
		},
		toggleMultiNode(node) {
			const ids = collectStoreIdsFromNode(node);
			if (!ids.length) return;
			const allSelected = ids.every((id) => !!this.selectedStoreIds[id]);
			const next = { ...this.selectedStoreIds };
			ids.forEach((id) => {
				if (allSelected) delete next[id];
				else next[id] = true;
			});
			this.selectedStoreIds = next;
		},
		toggleRecent(item) {
			const id = Number(item.id);
			if (!id) return;
			if (!this.isMultiple) {
				this.selectedSingleId = id;
				return;
			}
			const next = { ...this.selectedStoreIds };
			if (next[id]) delete next[id];
			else next[id] = true;
			this.selectedStoreIds = next;
		},
		getSelectedStoreIdList() {
			return Object.keys(this.selectedStoreIds)
				.map((id) => Number(id))
				.filter((id) => id > 0);
		},
		clearAll() {
			this.selectedStoreIds = {};
			this.selectedSingleId = 0;
		},
		confirm() {
			if (this.confirming) return;
			if (this.isMultiple) {
				this.confirmMultiple();
			} else {
				this.confirmSingle();
			}
		},
		confirmSingle() {
			const storeId = Number(this.selectedSingleId);
			if (!storeId) {
				uni.showToast({ title: '请选择门店', icon: 'none' });
				return;
			}
			this.confirming = true;
			organizationScopeResolve({
				store_ids: String(storeId),
				snapshot: this.snapshotMode ? 1 : 0,
			})
				.then((res) => {
					const data = (res && res.data) || {};
					const resolved = data.resolved_store_ids || [storeId];
					const map = buildStoreNameMap(this.tree);
					const payload = {
						mode: 'single',
						store_id: storeId,
						org_ids: [],
						store_ids: [storeId],
						excluded_store_ids: [],
						resolved_store_ids: resolved,
						realtime: !!data.realtime,
						snapshot: !!data.snapshot || this.snapshotMode,
						summary: map[storeId] || `门店${storeId}`,
						updated_at: data.updated_at || '',
					};
					pushRecentStores([{ id: storeId, name: payload.summary }]);
					this.writeResultAndBack(payload);
				})
				.catch((err) => {
					uni.showToast({
						title: (err && (err.msg || err.message)) || '校验门店失败',
						icon: 'none',
					});
				})
				.finally(() => {
					this.confirming = false;
				});
		},
		confirmMultiple() {
			const derived = deriveSelectionPayload(this.tree, this.selectedStoreIds);
			if (!derived.resolved_store_ids.length) {
				uni.showToast({ title: '请选择门店', icon: 'none' });
				return;
			}
			this.confirming = true;
			organizationScopeResolve({
				org_ids: derived.org_ids.join(','),
				store_ids: derived.store_ids.join(','),
				excluded_store_ids: derived.excluded_store_ids.join(','),
				snapshot: this.snapshotMode ? 1 : 0,
			})
				.then((res) => {
					const data = (res && res.data) || {};
					const resolved = data.resolved_store_ids || derived.resolved_store_ids;
					const map = buildStoreNameMap(this.tree);
					const payload = {
						mode: 'multiple',
						store_id: resolved.length === 1 ? resolved[0] : 0,
						org_ids: data.org_ids || derived.org_ids,
						store_ids: data.store_ids || derived.store_ids,
						excluded_store_ids: data.excluded_store_ids || derived.excluded_store_ids,
						resolved_store_ids: resolved,
						realtime: this.realtimeMode && !this.snapshotMode,
						snapshot: this.snapshotMode,
						summary: formatSelectionSummary(
							{ mode: 'multiple', resolved_store_ids: resolved },
							map
						),
						updated_at: data.updated_at || '',
					};
					pushRecentStores(
						resolved.slice(0, 5).map((id) => ({
							id,
							name: map[id] || `门店${id}`,
						}))
					);
					this.writeResultAndBack(payload);
				})
				.catch((err) => {
					uni.showToast({
						title: (err && (err.msg || err.message)) || '校验门店失败',
						icon: 'none',
					});
				})
				.finally(() => {
					this.confirming = false;
				});
		},
		writeResultAndBack(payload) {
			try {
				uni.setStorageSync(MERCHANT_STORE_PICKER_RESULT_KEY, payload);
			} catch (e) {
				/* ignore */
			}
			uni.$emit('merchant-store-picker-confirm', payload);
			uni.navigateBack({
				fail: () => {
					uni.showToast({ title: '已选择', icon: 'success' });
				},
			});
		},
	},
};
</script>

<style scoped lang="scss">
.select-page {
	min-height: 100vh;
	background: #f5f5f5;
	padding-bottom: calc(140rpx + env(safe-area-inset-bottom));
	box-sizing: border-box;
}
.h5-nav {
	display: flex;
	align-items: center;
	height: 88rpx;
	padding: 0 24rpx;
	background: #8b5cf6;
	color: #fff;
	position: sticky;
	top: 0;
	z-index: 20;
}
.h5-nav-back {
	width: 64rpx;
	font-size: 48rpx;
	line-height: 1;
}
.h5-nav-title {
	flex: 1;
	text-align: center;
	font-size: 32rpx;
	font-weight: 600;
}
.h5-nav-confirm {
	width: 80rpx;
	text-align: right;
	font-size: 28rpx;
}
.search-bar {
	background: #fff;
	padding: 20rpx 28rpx;
}
.search-input-wrap {
	display: flex;
	align-items: center;
	background: #f5f5f5;
	border-radius: 40rpx;
	padding: 14rpx 24rpx;
}
.search-icon {
	color: #999;
	margin-right: 12rpx;
}
.search-input {
	flex: 1;
	font-size: 28rpx;
}
.search-clear {
	color: #8b5cf6;
	font-size: 24rpx;
	padding-left: 16rpx;
}
.recent-bar {
	background: #fff;
	padding: 8rpx 28rpx 20rpx;
	border-bottom: 1rpx solid #f0f0f0;
}
.recent-title {
	font-size: 24rpx;
	color: #999;
	margin-bottom: 12rpx;
}
.recent-scroll {
	white-space: nowrap;
}
.recent-chip {
	display: inline-block;
	padding: 10rpx 22rpx;
	margin-right: 12rpx;
	border-radius: 28rpx;
	background: #f3f4f6;
	color: #555;
	font-size: 24rpx;
}
.recent-chip.active {
	background: #ede9fe;
	color: #7c3aed;
}
.mode-tip {
	padding: 16rpx 28rpx;
	font-size: 22rpx;
	color: #8b5cf6;
	background: #f5f3ff;
}
.tree-body {
	background: #fff;
	min-height: 240rpx;
}
.org-header,
.store-row {
	display: flex;
	align-items: center;
	padding: 28rpx 28rpx;
	border-bottom: 1rpx solid #f3f4f6;
}
.org-header.depth-1 { padding-left: 56rpx; }
.org-header.depth-2 { padding-left: 84rpx; }
.org-header.depth-3 { padding-left: 112rpx; }
.store-row {
	background: #fafafa;
}
.store-row.depth-1 { padding-left: 88rpx; }
.store-row.depth-2 { padding-left: 116rpx; }
.store-row.depth-3 { padding-left: 144rpx; }
.org-toggle {
	width: 48rpx;
	text-align: center;
	color: #999;
	margin-right: 4rpx;
}
.org-toggle.expanded {
	transform: rotate(90deg);
}
.org-icon,
.level-store-icon {
	width: 56rpx;
	height: 56rpx;
	border-radius: 12rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 22rpx;
	margin-right: 16rpx;
}
.org-icon {
	background: #eef2ff;
	color: #8b5cf6;
}
.level-store-icon {
	background: #ecfdf5;
	color: #16a34a;
}
.level-info {
	flex: 1;
	min-width: 0;
}
.level-name {
	font-size: 28rpx;
	color: #222;
}
.level-desc {
	font-size: 22rpx;
	color: #999;
	margin-top: 4rpx;
}
.check {
	width: 40rpx;
	height: 40rpx;
	border: 3rpx solid #d1d5db;
	border-radius: 50%;
	text-align: center;
	line-height: 34rpx;
	font-size: 22rpx;
	color: transparent;
	flex-shrink: 0;
}
.check.checked {
	background: #8b5cf6;
	border-color: #8b5cf6;
	color: #fff;
}
.check.partial {
	background: #ddd6fe;
	border-color: #8b5cf6;
	color: #8b5cf6;
}
.empty-tip {
	text-align: center;
	padding: 80rpx 24rpx;
	color: #999;
	font-size: 26rpx;
}
.footer-bar {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16rpx 24rpx calc(16rpx + env(safe-area-inset-bottom));
	background: #fff;
	box-shadow: 0 -4rpx 16rpx rgba(0, 0, 0, 0.06);
	z-index: 30;
}
.footer-left {
	flex: 1;
	min-width: 0;
	padding-right: 16rpx;
}
.footer-label {
	font-size: 24rpx;
	color: #999;
}
.footer-summary {
	font-size: 26rpx;
	color: #333;
}
.footer-actions {
	display: flex;
	align-items: center;
}
.btn-clear,
.btn-ok {
	margin: 0;
	height: 68rpx;
	line-height: 68rpx;
	padding: 0 28rpx;
	font-size: 26rpx;
	border-radius: 34rpx;
}
.btn-clear {
	background: #f3f4f6;
	color: #666;
	margin-right: 12rpx;
}
.btn-ok {
	background: #8b5cf6;
	color: #fff;
}
.btn-clear::after,
.btn-ok::after {
	border: none;
}
</style>
