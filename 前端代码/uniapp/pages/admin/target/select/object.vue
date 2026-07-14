<template>
	<view class="select-page">
		<!-- #ifdef H5 -->
		<page-nav-bar :title="pageTitle" theme="purple" @back="goBack">
			<view slot="right" class="h5-nav-confirm" @click="confirm">确定</view>
		</page-nav-bar>
		<!-- #endif -->
		<view class="search-bar">
			<view class="search-input-wrap">
				<text class="search-icon">⌕</text>
				<input
					class="search-input"
					v-model="keyword"
					placeholder="搜索区域或门店"
				/>
			</view>
		</view>
		<view class="tree-body-mp">
			<view v-if="loading" class="empty-tip">加载中...</view>
			<view v-else class="tree-container">
				<view
					v-for="(item, index) in flatNodes"
					:key="index"
				>
					<view
						v-if="item.isStore"
						:class="item.rowClass"
						@tap="onFlatNodeTap(index)"
					>
						<view class="level-3-icon">店</view>
						<view class="level-3-info">
							<view class="level-3-name">{{ item.name || '(无名)' }}</view>
							<view class="level-3-desc">{{ item.desc }}</view>
						</view>
						<view
							class="level-3-check"
							:class="{ checked: item.checked }"
							@tap.stop="onFlatNodeTap(index)"
						>✓</view>
					</view>
					<view v-else class="region-node">
						<view
							:class="item.rowClass"
							@tap="onFlatRegionTap(index)"
						>
							<view class="region-toggle" :class="{ expanded: item.expanded }">›</view>
							<view class="region-icon">区</view>
							<view class="region-info">
								<view class="region-name">{{ item.name || '(无名)' }}</view>
								<view class="region-desc">{{ item.desc }}</view>
							</view>
							<view
								class="region-check"
								:class="{ checked: item.checked, partial: item.partial }"
								@tap.stop="onFlatNodeTap(index)"
							>✓</view>
						</view>
					</view>
				</view>
			</view>
			<view v-if="!loading && !flatNodes.length" class="empty-tip">暂无匹配对象</view>
		</view>
		<view class="selected-bar">
			<view class="selected-info">
				<text class="selected-label">已选：</text>
				<text class="selected-name">{{ selectedSummary }}</text>
			</view>
			<button class="selected-btn" @tap="confirm">确定</button>
		</view>
	</view>
</template>

<script>
import { targetStoreOptionsTree } from '@/api/target.js';
import {
	getApiErrorMessage,
	getObjectDisplayName,
	resolveDefaultObjectFromOptions,
	findObjectInTree,
	loadTargetObjectCache,
	saveTargetObjectCache,
	filterObjectTreeByKeyword,
	flattenTargetObjectTree,
	parseTargetObjectTreeResponse,
	TARGET_OBJECT_SELECT_KEY,
	applyTargetNativeNavBar,
	targetNavigateBack,
	collectStoreIdsFromNode,
	buildStoreNameMapFromTree,
	isMultiStoreSelection,
	isSpecificStoreObject,
} from '../common/util.js';
import pageNavBar from '../components/page-nav-bar.vue';

export default {
	components: { pageNavBar },
	data() {
		return {
			keyword: '',
			tree: [],
			flatNodes: [],
			expandedKeys: {},
			selected: null,
			selectedStoreIds: {},
			loading: true,
			mode: 'multiple',
			storeOnly: false,
		};
	},
	computed: {
		isMultiple() {
			return this.mode !== 'single';
		},
		pageTitle() {
			return this.storeOnly ? '选择门店' : '选择目标对象';
		},
		displayTree() {
			return filterObjectTreeByKeyword(this.tree, this.keyword);
		},
		selectedSummary() {
			if (this.isMultiple) {
				const ids = this.getSelectedStoreIdList();
				if (!ids.length) return '请选择';
				if (ids.length === 1) {
					const map = buildStoreNameMapFromTree(this.tree);
					return map[ids[0]] || `门店${ids[0]}`;
				}
				return `已选${ids.length}家门店`;
			}
			return this.selected ? this.displayObjectName(this.selected) : '请选择';
		},
		searching() {
			return !!this.keyword.trim();
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
		selected() {
			this.rebuildFlatNodes();
		},
	},
	onLoad(options) {
		this.mode = options.mode === 'single' ? 'single' : 'multiple';
		this.storeOnly = options.storeOnly === '1' || options.mode === 'single';
		this.loadTree();
	},
	onShow() {
		applyTargetNativeNavBar(this.pageTitle);
	},
	methods: {
		loadTree() {
			this.loading = true;
			targetStoreOptionsTree()
				.then((res) => {
					const tree = parseTargetObjectTreeResponse(res);
					this.tree = Array.isArray(tree) ? tree : [];
					this.expandAllRegions(this.tree);
					this.restoreSelection();
					this.rebuildFlatNodes();
				})
				.catch((e) => {
					this.tree = [];
					this.flatNodes = [];
					uni.showToast({ title: getApiErrorMessage(e, '加载目标对象失败'), icon: 'none' });
				})
				.finally(() => {
					this.loading = false;
					this.$nextTick(() => {
						this.rebuildFlatNodes();
					});
				});
		},
		displayObjectName(item) {
			return getObjectDisplayName(item);
		},
		nodeKey(node) {
			if (!node) return '';
			return `${node.object_type}-${node.id}`;
		},
		isStoreLeaf(node) {
			return Number(node && node.object_type) === 1;
		},
		isNodeExpanded(node) {
			if (this.searching) return true;
			return !!this.expandedKeys[this.nodeKey(node)];
		},
		isNodeSelected(node) {
			if (!node) return false;
			if (!this.isMultiple) {
				return this.isSelected(node);
			}
			const ids = collectStoreIdsFromNode(node);
			if (!ids.length) return false;
			return ids.every((id) => !!this.selectedStoreIds[id]);
		},
		isNodePartial(node) {
			if (!this.isMultiple || !node) return false;
			const ids = collectStoreIdsFromNode(node);
			if (ids.length <= 1) return false;
			const selectedCount = ids.filter((id) => !!this.selectedStoreIds[id]).length;
			return selectedCount > 0 && selectedCount < ids.length;
		},
		isSelected(node) {
			if (!this.selected || !node) return false;
			return (
				String(this.selected.id) === String(node.id) &&
				String(this.selected.object_type) === String(node.object_type)
			);
		},
		expandAllRegions(nodes) {
			(nodes || []).forEach((node) => {
				if (Number(node && node.object_type) === 2) {
					this.$set(this.expandedKeys, this.nodeKey(node), true);
					this.expandAllRegions(node.children || []);
				}
			});
		},
		buildFlatRow(node, depth) {
			const isStore = this.isStoreLeaf(node);
			const depthClass = 'depth-' + depth;
			return {
				key: this.nodeKey(node),
				isStore,
				name: String((node && node.name) || ''),
				desc: String(isStore ? ((node && node.desc) || (node && node.label) || '') : ((node && node.desc) || '')),
				depth,
				rowClass: (isStore ? 'level-3-node ' : 'region-header ') + depthClass,
				expanded: this.isNodeExpanded(node),
				checked: this.isNodeSelected(node),
				partial: !isStore && this.isNodePartial(node),
			};
		},
		rebuildFlatNodes() {
			const rows = [];
			const walk = (nodes, depth) => {
				const list = nodes || [];
				for (let i = 0; i < list.length; i++) {
					const node = list[i];
					if (!node) continue;
					rows.push(this.buildFlatRow(node, depth));
					if (!this.isStoreLeaf(node) && this.isNodeExpanded(node)) {
						walk(node.children || [], depth + 1);
					}
				}
			};
			walk(this.displayTree, 0);
			this.flatNodes = rows.slice();
		},
		getSelectedStoreIdList() {
			return Object.keys(this.selectedStoreIds)
				.map((id) => Number(id))
				.filter((id) => id > 0);
		},
		restoreSelection() {
			const cached = loadTargetObjectCache();
			if (this.isMultiple) {
				if (cached && isMultiStoreSelection(cached)) {
					const map = {};
					(cached.store_ids || []).forEach((id) => {
						const sid = Number(id);
						if (sid > 0) map[sid] = true;
					});
					if (Object.keys(map).length) {
						this.selectedStoreIds = map;
						return;
					}
				}
				if (cached && Number(cached.object_type) === 1 && Number(cached.id) > 0) {
					this.selectedStoreIds = { [Number(cached.id)]: true };
					return;
				}
				const flat = flattenTargetObjectTree(this.tree);
				const def = resolveDefaultObjectFromOptions(flat);
				if (def && Number(def.object_type) === 1) {
					this.selectedStoreIds = { [Number(def.id)]: true };
				} else if (def && Number(def.object_type) === 2) {
					const ids = collectStoreIdsFromNode(
						findObjectInTree(this.tree, def) || def
					);
					if (ids.length) {
						const map = {};
						ids.forEach((id) => {
							map[id] = true;
						});
						this.selectedStoreIds = map;
					}
				}
				return;
			}
			const flat = flattenTargetObjectTree(this.tree);
			const cachedPick =
				cached && Number(cached.object_type) !== 3 && !isMultiStoreSelection(cached)
					? findObjectInTree(this.tree, cached)
					: null;
			const pick = cachedPick || resolveDefaultObjectFromOptions(flat);
			if (pick) {
				this.selectNodeByKey(this.nodeKey(pick));
				this.expandPathForSelection(pick);
			}
		},
		findNodeByKey(key) {
			if (!key) return null;
			const walk = (nodes) => {
				for (const node of nodes || []) {
					if (this.nodeKey(node) === key) return node;
					const hit = walk(node.children);
					if (hit) return hit;
				}
				return null;
			};
			return walk(this.tree);
		},
		onFlatNodeTap(index) {
			const item = this.flatNodes[index];
			if (!item || !item.key) return;
			this.onNodeKeyTap(item.key);
		},
		onFlatRegionTap(index) {
			const item = this.flatNodes[index];
			if (!item || !item.key) return;
			this.toggleRegionByKey(item.key);
		},
		onNodeKeyTap(key) {
			if (!key) return;
			const node = this.findNodeByKey(key);
			if (!node) return;
			if (this.isMultiple) {
				this.toggleMultiNode(node);
				return;
			}
			if (this.storeOnly && !this.isStoreLeaf(node)) {
				return uni.showToast({ title: '请选择具体门店', icon: 'none' });
			}
			this.selectNodeByKey(key);
		},
		toggleMultiNode(node) {
			const ids = collectStoreIdsFromNode(node);
			if (!ids.length) return;
			const allSelected = ids.every((id) => !!this.selectedStoreIds[id]);
			const next = { ...this.selectedStoreIds };
			ids.forEach((id) => {
				if (allSelected) {
					delete next[id];
				} else {
					next[id] = true;
				}
			});
			this.selectedStoreIds = next;
		},
		toggleRegionByKey(key) {
			if (this.searching) return;
			this.$set(this.expandedKeys, key, !this.expandedKeys[key]);
			this.rebuildFlatNodes();
		},
		selectNodeByKey(key) {
			const node = this.findNodeByKey(key);
			if (!node) return;
			this.selected = {
				id: node.id,
				name: node.name,
				object_type: node.object_type,
				label: node.label,
			};
		},
		expandPathForSelection(item) {
			if (!item || !this.tree.length) return;
			const targetKey = this.nodeKey(item);
			const walk = (nodes, ancestors) => {
				for (const node of nodes || []) {
					if (this.nodeKey(node) === targetKey) {
						ancestors.forEach((ancestorKey) => {
							this.$set(this.expandedKeys, ancestorKey, true);
						});
						return true;
					}
					if (walk(node.children, [...ancestors, this.nodeKey(node)])) {
						return true;
					}
				}
				return false;
			};
			walk(this.tree, []);
			this.rebuildFlatNodes();
		},
		goBack() {
			targetNavigateBack();
		},
		confirm() {
			if (this.isMultiple) {
				const storeIds = this.getSelectedStoreIdList();
				if (!storeIds.length) {
					return uni.showToast({ title: '请至少选择一家门店', icon: 'none' });
				}
				const nameMap = buildStoreNameMapFromTree(this.tree);
				const payload = {
					mode: 'multiple',
					store_ids: storeIds,
					_storeNameMap: nameMap,
				};
				uni.setStorageSync(TARGET_OBJECT_SELECT_KEY, payload);
				saveTargetObjectCache(payload);
				uni.navigateBack();
				return;
			}
			if (!this.selected) {
				return uni.showToast({ title: '请选择目标对象', icon: 'none' });
			}
			if (this.storeOnly && !isSpecificStoreObject(this.selected)) {
				return uni.showToast({ title: '请选择具体门店', icon: 'none' });
			}
			if (Number(this.selected.object_type) === 3) {
				return uni.showToast({ title: '请选择区域或门店', icon: 'none' });
			}
			uni.setStorageSync(TARGET_OBJECT_SELECT_KEY, this.selected);
			saveTargetObjectCache(this.selected);
			uni.navigateBack();
		},
	},
};
</script>

<style scoped lang="scss">
@import '../common/target-form.scss';

.select-page {
	position: relative;
	min-height: 100vh;
	background: #f5f5f5;
	padding-bottom: calc(120rpx + env(safe-area-inset-bottom));
}

.search-bar {
	background: #fff;
	padding: 24rpx 32rpx;
	border-bottom: 1rpx solid #f0f0f0;
}

.search-input-wrap {
	display: flex;
	align-items: center;
	padding: 16rpx 32rpx;
	background: #f5f5f5;
	border-radius: 48rpx;
}

.search-icon {
	color: #999;
	font-size: 32rpx;
	margin-right: 16rpx;
}

.search-input {
	flex: 1;
	@include target-text-input;
	background: transparent;
}

.tree-body-mp {
	box-sizing: border-box;
	min-height: 200rpx;
}

.tree-container {
	background: #fff;
}

.region-node {
	border-bottom: 1rpx solid #f5f5f5;
}

.region-header {
	display: flex;
	align-items: center;
	padding-top: 32rpx;
	padding-right: 32rpx;
	padding-bottom: 32rpx;
	padding-left: 32rpx;
	background: #fff;
}

.region-header.depth-0 { padding-left: 32rpx; }
.region-header.depth-1 { padding-left: 72rpx; }
.region-header.depth-2 { padding-left: 112rpx; }
.region-header.depth-3 { padding-left: 152rpx; }
.region-header.depth-4 { padding-left: 192rpx; }

.region-toggle {
	width: 64rpx;
	height: 64rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	margin-right: 8rpx;
	font-size: 28rpx;
	color: #999;
}

.region-toggle.expanded {
	transform: rotate(90deg);
}

.region-icon {
	width: 72rpx;
	height: 72rpx;
	border-radius: 16rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 26rpx;
	background: #eef2ff;
	color: #8b5cf6;
	margin-right: 24rpx;
}

.region-info {
	flex: 1;
	min-width: 0;
}

.region-name {
	font-size: 30rpx;
	font-weight: 500;
	color: #333;
	display: block;
	margin-bottom: 8rpx;
}

.region-desc {
	font-size: 24rpx;
	color: #999;
	display: block;
}

.region-check,
.level-3-check {
	width: 44rpx;
	height: 44rpx;
	border: 4rpx solid #ddd;
	border-radius: 50%;
	text-align: center;
	line-height: 36rpx;
	font-size: 24rpx;
	color: transparent;
	flex-shrink: 0;
}

.region-check.checked,
.level-3-check.checked {
	background: #8b5cf6;
	border-color: #8b5cf6;
	color: #fff;
}

.region-check.partial {
	background: #ddd6fe;
	border-color: #8b5cf6;
	color: #8b5cf6;
}

.level-3-node {
	display: flex;
	align-items: center;
	padding-top: 24rpx;
	padding-right: 32rpx;
	padding-bottom: 24rpx;
	padding-left: 32rpx;
	border-bottom: 1rpx solid #e8e8e8;
	background: #f5f5f5;
}

.level-3-node.depth-0 {
	padding-left: 32rpx;
	background: #fff;
}

.level-3-node.depth-1 { padding-left: 112rpx; }
.level-3-node.depth-2 { padding-left: 192rpx; }
.level-3-node.depth-3 { padding-left: 272rpx; }
.level-3-node.depth-4 { padding-left: 352rpx; }

.level-3-icon {
	width: 64rpx;
	height: 64rpx;
	border-radius: 16rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 24rpx;
	background: #f0fdf4;
	color: #22c55e;
	margin-right: 24rpx;
}

.level-3-info {
	flex: 1;
	min-width: 0;
}

.level-3-name {
	font-size: 28rpx;
	color: #333;
	display: block;
	margin-bottom: 4rpx;
}

.level-3-desc {
	font-size: 22rpx;
	color: #999;
	display: block;
}

.level-3-check {
	width: 36rpx;
	height: 36rpx;
	line-height: 28rpx;
}

.empty-tip {
	text-align: center;
	padding: 80rpx 32rpx;
	color: #999;
	font-size: 28rpx;
}

.selected-bar {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	background: #fff;
	padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));
	border-top: 1rpx solid #f0f0f0;
	display: flex;
	align-items: center;
	justify-content: space-between;
	z-index: 10;
}

.selected-info {
	display: flex;
	align-items: center;
	flex: 1;
	min-width: 0;
	margin-right: 24rpx;
}

.selected-label {
	font-size: 28rpx;
	color: #666;
	flex-shrink: 0;
	margin-right: 8rpx;
}

.selected-name {
	font-size: 30rpx;
	font-weight: 600;
	color: #333;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.selected-btn {
	padding: 0 48rpx;
	height: 72rpx;
	line-height: 72rpx;
	background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
	color: #fff;
	border: none;
	border-radius: 16rpx;
	font-size: 30rpx;
	font-weight: 500;
	flex-shrink: 0;

	&::after {
		border: none;
	}
}
</style>
