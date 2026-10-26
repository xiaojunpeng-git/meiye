<template>

	<view class="select-page">

		<!-- #ifdef H5 -->
		<page-nav-bar title="选择商品" theme="purple" @back="goBack">
			<view slot="right" class="h5-nav-confirm" @click="confirm">确定</view>
		</page-nav-bar>
		<!-- #endif -->

		<view class="search-box">

			<view class="search-input-wrapper">

				<uni-icons type="search" size="16" color="#999" />

				<input

					class="search-input"

					v-model="keyword"

					placeholder="请输入商品名称/ID"

					placeholder-class="placeholder"

					confirm-type="search"

					@confirm="onSearch"

					@input="onSearchInput"

				/>

			</view>

		</view>



		<view class="product-type-filter">

			<text class="type-filter-title">品项类型</text>

			<view class="type-radio-group">

				<view
					v-for="t in itemTypes"
					:key="t.key"
					class="type-radio-item"
					:class="{ active: itemType === t.key }"
					:data-type-key="t.key"
					@click="onItemTypeTap"
				>

					<uni-icons :type="typeIcon(t.key)" size="14" :color="itemType === t.key ? '#8B5CF6' : '#666'" />

					<text>{{ t.name }}</text>

				</view>

			</view>

		</view>



		<scroll-view

			scroll-y

			class="category-tree"

			:lower-threshold="80"

			@scrolltolower="onScrollToLower"

		>

			<view v-if="loading" class="empty-tip">加载中...</view>

			<view v-else-if="!categories.length" class="empty-tip">暂无商品</view>

			<view
				v-for="cat in categories"
				:key="cat.category_id"
				class="category-item"
			>
				<view
					class="category-header"
					:data-category-id="cat.category_id"
					@click="onCategoryHeaderTap"
				>

					<view class="category-icon" :class="cat.icon_theme">

						<uni-icons :type="categoryIcon(cat.icon_theme)" size="16" :color="categoryIconColor(cat.icon_theme)" />

					</view>

					<text class="category-name">{{ cat.category_name }}</text>

					<text
						class="category-select-all"
						:data-category-id="cat.category_id"
						@click.stop="onCategorySelectAllTap"
					>{{ categorySelectAllLabel(cat.category_id) }}</text>

					<uni-icons

						type="right"

						size="14"

						color="#999"

						class="category-arrow"

						:class="{ expanded: expandedMap[cat.category_id] }"

					/>

				</view>

				<view class="category-products" :class="{ show: expandedMap[cat.category_id] }">

					<view v-if="cat.loading && !cat.products.length" class="product-loading-tip">加载中...</view>

					<view v-else-if="cat.loaded && !cat.products.length" class="product-loading-tip">该分类暂无商品</view>

					<view
						v-for="p in cat.products"
						:key="p.product_id"
						class="product-item"
						:data-product-id="p.product_id"
						@click="onProductTap"
					>

						<view class="product-checkbox" :class="{ checked: isSelected(p.product_id) }">

							<text v-if="isSelected(p.product_id)" class="check-mark">✓</text>

						</view>

						<view class="product-info">

							<text class="product-name">{{ p.product_name }}</text>

							<text class="product-price">¥{{ formatPrice(p.price) }}</text>

						</view>

					</view>

					<view
						v-if="cat.products.length && cat.has_more"
						class="load-more"
						:data-category-id="cat.category_id"
						@click.stop="onLoadMoreTap"
					>

						<text v-if="cat.loading">加载中...</text>

						<text v-else>加载更多</text>

					</view>

					<view v-else-if="cat.loaded && cat.products.length && !cat.has_more" class="load-more done">

						已加载全部

					</view>

				</view>

			</view>

		</scroll-view>



		<view class="bottom-bar">
			<view class="selected-count-wrap" @click="openSelectedModal">
				<text class="selected-count">
					已选 <text class="selected-num">{{ selectedList.length }}</text> 个
				</text>
				<uni-icons
					v-if="selectedList.length"
					type="up"
					size="14"
					color="#8B5CF6"
					class="selected-arrow"
				/>
			</view>
			<button class="confirm-btn" :disabled="!selectedList.length" @click="confirm">确定</button>
		</view>

		<view v-if="selectedModalVisible" class="selected-modal-mask" @click="closeSelectedModal">
			<view class="selected-modal-sheet" @click.stop="noop">
				<view class="selected-modal-head">
					<text class="selected-modal-title">已选商品（{{ selectedList.length }}）</text>
					<view class="selected-modal-close" @click="closeSelectedModal">
						<uni-icons type="closeempty" size="18" color="#666" />
					</view>
				</view>
				<scroll-view scroll-y class="selected-modal-body">
					<view v-if="!selectedList.length" class="selected-empty">暂无已选商品</view>
					<view
						v-for="p in selectedList"
						:key="p.product_id"
						class="selected-item"
					>
						<view class="selected-item-info">
							<text class="selected-item-name">{{ p.product_name }}</text>
							<text class="selected-item-price">¥{{ formatPrice(p.price) }}</text>
						</view>
						<view
							class="selected-item-remove"
							:data-product-id="p.product_id"
							@click.stop="onRemoveSelectedTap"
						>
							<uni-icons type="trash" size="18" color="#999" />
						</view>
					</view>
				</scroll-view>
				<view class="selected-modal-foot">
					<button class="selected-modal-btn" @click="closeSelectedModal">完成</button>
				</view>
			</view>
		</view>

	</view>

</template>



<script>

import uniIcons from '@/uni_modules/uni-icons/components/uni-icons/uni-icons.vue';

import {

	targetProductSelectCategories,

	targetProductSelectProducts,

} from '@/api/target.js';

import { getApiErrorMessage, applyTargetNativeNavBar, targetNavigateBack } from '../common/util.js';
import pageNavBar from '../components/page-nav-bar.vue';



const PAGE_SIZE = 20;



const ICON_THEME_COLOR = {

	hair: '#8B5CF6',

	beauty: '#EC4899',

	body: '#22C55E',

	head: '#3B82F6',

	cooperation: '#F59E0B',

	product: '#F97316',

};



function emptyCategoryState(cat) {

	return {

		...cat,

		products: [],

		page: 0,

		has_more: false,

		loading: false,

		loaded: false,

	};

}



export default {

	components: { uniIcons, pageNavBar },

	data() {

		return {

			from: '',

			mode: 'add',

			editIndex: -1,

			storeId: 0,

			keyword: '',

			itemType: 'project',

			itemTypes: [

				{ key: 'project', name: '项目' },

				{ key: 'card', name: '卡项' },

				{ key: 'product', name: '产品' },

			],

			categories: [],

			expandedMap: {},

			selectedMap: {},

			loading: false,

			searchTimer: null,

			selectedModalVisible: false,

		};

	},

	computed: {

		selectedList() {

			return Object.values(this.selectedMap);

		},

	},

	onLoad(options) {

		this.from = options.from || '';

		this.mode = options.mode || 'add';

		this.editIndex = parseInt(options.edit_index || -1, 10);

		this.storeId = parseInt(options.store_id || 0, 10);

		if (options.item_type) {

			this.itemType = options.item_type;

		}

		const preset = uni.getStorageSync('target_product_select_preset');

		if (preset && preset.selected) {

			preset.selected.forEach((p) => {

				if (p.product_id) {

					this.$set(this.selectedMap, p.product_id, p);

				}

			});

			if (preset.item_type) {

				this.itemType = preset.item_type;

			}

			uni.removeStorageSync('target_product_select_preset');

		}

		this.loadCategories();

	},

	onShow() {
		applyTargetNativeNavBar('选择商品');
	},

	methods: {

		typeIcon(key) {

			const map = { project: 'star', card: 'wallet', product: 'shop' };

			return map[key] || 'shop';

		},

		categoryIcon(theme) {

			const map = {

				hair: 'star',

				beauty: 'heart',

				body: 'person',

				head: 'headphones',

				cooperation: 'hand-up',

				product: 'shop',

			};

			return map[theme] || 'list';

		},

		categoryIconColor(theme) {

			return ICON_THEME_COLOR[theme] || '#8B5CF6';

		},

		formatPrice(price) {

			const n = parseFloat(price);

			if (Number.isNaN(n)) return '0.00';

			return n.toFixed(2);

		},

		goBack() {

			targetNavigateBack();

		},

		onSearch() {

			this.loadCategories();

		},

		onSearchInput() {

			clearTimeout(this.searchTimer);

			this.searchTimer = setTimeout(() => {

				this.loadCategories();

			}, 400);

		},

		loadCategories() {

			this.loading = true;

			this.expandedMap = {};

			targetProductSelectCategories({

				store_id: this.storeId,

				item_type: this.itemType,

				keyword: this.keyword.trim(),

			})

				.then((res) => {

					const d = res.data || {};

					if (d.item_types && d.item_types.length) {

						this.itemTypes = d.item_types;

					}

					this.categories = (d.categories || []).map((c) => emptyCategoryState(c));

					if (this.keyword.trim()) {

						const map = {};

						this.categories.forEach((c) => {

							map[c.category_id] = true;

						});

						this.expandedMap = map;

						this.categories.forEach((cat) => {

							if (this.expandedMap[cat.category_id]) {

								this.loadCategoryProducts(cat.category_id, 1);

							}

						});

					}

				})

				.catch((e) => {

					uni.showToast({ title: getApiErrorMessage(e, '加载失败'), icon: 'none' });

				})

				.finally(() => {

					this.loading = false;

				});

		},

		readDatasetId(e, name) {
			const ds = (e && e.currentTarget && e.currentTarget.dataset) || {};
			const val = ds[name];
			if (val === undefined || val === null || val === '') return 0;
			const n = parseInt(val, 10);
			return Number.isNaN(n) ? 0 : n;
		},
		onItemTypeTap(e) {
			const key = ((e.currentTarget.dataset || {}).typeKey || '').trim();
			if (key) this.selectItemType(key);
		},
		onCategoryHeaderTap(e) {
			const categoryId = this.readDatasetId(e, 'categoryId');
			if (categoryId) this.toggleCategory(categoryId);
		},
		onCategorySelectAllTap(e) {
			const categoryId = this.readDatasetId(e, 'categoryId');
			if (categoryId) this.toggleCategoryAll(categoryId);
		},
		onProductTap(e) {
			const productId = this.readDatasetId(e, 'productId');
			if (productId) this.toggleProduct(productId);
		},
		onLoadMoreTap(e) {
			const categoryId = this.readDatasetId(e, 'categoryId');
			if (categoryId) this.loadMoreProducts(categoryId);
		},
		onRemoveSelectedTap(e) {
			const productId = this.readDatasetId(e, 'productId');
			if (productId) this.removeSelected(productId);
		},
		selectItemType(key) {

			if (this.itemType === key) return;

			this.itemType = key;

			this.selectedMap = {};

			this.expandedMap = {};

			this.loadCategories();

		},

		findCategoryIndex(categoryId) {

			return this.categories.findIndex((c) => c.category_id === categoryId);

		},

		getCategory(categoryId) {

			return this.categories.find((c) => c.category_id === categoryId) || null;

		},

		findProductById(productId) {

			for (let i = 0; i < this.categories.length; i++) {

				const hit = (this.categories[i].products || []).find(

					(p) => p.product_id === productId

				);

				if (hit) return hit;

			}

			return null;

		},

		toggleCategory(categoryId) {

			const cat = this.getCategory(categoryId);

			if (!cat) return;

			const id = cat.category_id;

			const expanded = !this.expandedMap[id];

			this.$set(this.expandedMap, id, expanded);

			if (expanded && !cat.loaded && !cat.loading) {

				this.loadCategoryProducts(categoryId, 1);

			}

		},

		loadCategoryProducts(categoryId, page) {

			const idx = this.findCategoryIndex(categoryId);

			if (idx < 0) return;

			const cat = this.categories[idx];

			if (cat.loading) return;

			if (page > 1 && !cat.has_more) return;



			this.$set(this.categories, idx, { ...cat, loading: true });



			targetProductSelectProducts({

				store_id: this.storeId,

				item_type: this.itemType,

				category_id: categoryId,

				keyword: this.keyword.trim(),

				page,

				limit: PAGE_SIZE,

			})

				.then((res) => {

					const d = res.data || {};

					const list = d.list || [];

					const cur = this.categories[idx];

					const products = page === 1 ? list : (cur.products || []).concat(list);

					this.$set(this.categories, idx, {

						...cur,

						products,

						page,

						has_more: !!d.has_more,

						loaded: true,

						loading: false,

					});

				})

				.catch((e) => {

					const cur = this.categories[idx];

					this.$set(this.categories, idx, { ...cur, loading: false });

					uni.showToast({ title: getApiErrorMessage(e, '加载失败'), icon: 'none' });

				});

		},

		onScrollToLower() {

			const expandedCats = this.categories.filter((c) => this.expandedMap[c.category_id]);

			if (!expandedCats.length) return;

			const cat = expandedCats[expandedCats.length - 1];

			if (cat.has_more && !cat.loading) {

				this.loadMoreProducts(cat.category_id);

			}

		},

		loadMoreProducts(categoryId) {

			const cat = this.categories.find((c) => c.category_id === categoryId);

			if (!cat || cat.loading || !cat.has_more) return;

			this.loadCategoryProducts(categoryId, cat.page + 1);

		},

		noop() {},

		isSelected(productId) {

			return !!this.selectedMap[productId];

		},

		toggleProduct(productId) {

			const p = this.findProductById(productId);

			if (!p) return;

			const id = p.product_id;

			if (this.selectedMap[id]) {

				this.$delete(this.selectedMap, id);

			} else {

				this.$set(this.selectedMap, id, { ...p });

			}

		},

		openSelectedModal() {

			if (!this.selectedList.length) {

				return uni.showToast({ title: '请先选择商品', icon: 'none' });

			}

			this.selectedModalVisible = true;

		},

		closeSelectedModal() {

			this.selectedModalVisible = false;

		},

		removeSelected(productId) {

			if (this.selectedMap[productId]) {

				this.$delete(this.selectedMap, productId);

			}

			if (!this.selectedList.length) {

				this.selectedModalVisible = false;

			}

		},

		categorySelectAllLabel(categoryId) {

			const cat = this.getCategory(categoryId);

			if (!cat) return '全选';

			const products = cat.products || [];

			if (!products.length) return '全选';

			const all = products.every((p) => this.isSelected(p.product_id));

			return all ? '取消' : '全选';

		},

		toggleCategoryAll(categoryId) {

			const cat = this.getCategory(categoryId);

			if (!cat) return;

			const products = cat.products || [];

			if (!products.length) {

				if (!cat.loaded && !cat.loading) {

					uni.showToast({ title: '请先展开加载商品', icon: 'none' });

				}

				return;

			}

			const all = products.every((p) => this.isSelected(p.product_id));

			if (all) {

				products.forEach((p) => {

					if (this.selectedMap[p.product_id]) {

						this.$delete(this.selectedMap, p.product_id);

					}

				});

			} else {

				products.forEach((p) => {

					this.$set(this.selectedMap, p.product_id, { ...p });

				});

			}

		},

		confirm() {

			if (!this.selectedList.length) {

				return uni.showToast({ title: '请至少选择一个商品', icon: 'none' });

			}

			const first = this.selectedList[0];

			uni.setStorageSync('target_product_select', {

				mode: this.mode,

				edit_index: this.editIndex,

				item_type: this.itemType,

				first_product_name: first.product_name || '',

				products: this.selectedList,

			});

			uni.navigateBack();

		},

	},

};

</script>



<style scoped lang="scss">

@import '../common/target-form.scss';



.select-page {

	position: relative;

	overflow: hidden;

	min-height: 100vh;

	background: #f5f5f5;

	padding-bottom: 160rpx;

}



.search-box {

	background: #fff;

	padding: 24rpx 32rpx;

	border-bottom: 1rpx solid #f0f0f0;

}



.search-input-wrapper {

	display: flex;

	align-items: center;

	background: #f5f5f5;

	border-radius: 40rpx;

	padding: 0 32rpx;

	min-height: 72rpx;

}



.search-input {

	flex: 1;

	margin-left: 16rpx;

	font-size: 28rpx;

	background: transparent;

}



.product-type-filter {

	background: #fff;

	padding: 24rpx 32rpx;

	border-bottom: 1rpx solid #f0f0f0;

}



.type-filter-title {

	font-size: 28rpx;

	color: #666;

	margin-bottom: 20rpx;

	display: block;

}



.type-radio-group {

	display: flex;

	gap: 24rpx;

}



.type-radio-item {

	flex: 1;

	display: flex;

	align-items: center;

	justify-content: center;

	gap: 12rpx;

	padding: 20rpx;

	border: 1rpx solid #e0e0e0;

	border-radius: 16rpx;

	font-size: 28rpx;

	color: #333;

}



.type-radio-item.active {

	border-color: #8b5cf6;

	background: #f5f3ff;

	color: #8b5cf6;

}



.category-tree {

	height: calc(100vh - 320rpx);
	/* #ifdef H5 */
	height: calc(100vh - 408rpx);
	/* #endif */

}



.category-item {

	background: #fff;

	border-bottom: 1rpx solid #f5f5f5;

}



.category-header {

	display: flex;

	align-items: center;

	padding: 32rpx;

}



.category-icon {

	width: 72rpx;

	height: 72rpx;

	border-radius: 16rpx;

	display: flex;

	align-items: center;

	justify-content: center;

	margin-right: 24rpx;

	flex-shrink: 0;

}



.category-icon.hair { background: #f5f3ff; }

.category-icon.beauty { background: #fdf2f8; }

.category-icon.body { background: #f0fdf4; }

.category-icon.head { background: #eff6ff; }

.category-icon.cooperation { background: #fef3c7; }

.category-icon.product { background: #fff7ed; }



.category-name {

	flex: 1;

	font-size: 30rpx;

	color: #333;

}



.category-select-all {

	font-size: 26rpx;

	color: #8b5cf6;

	margin-right: 24rpx;

	flex-shrink: 0;

}



.category-arrow {

	transition: transform 0.3s;

	flex-shrink: 0;

}



.category-arrow.expanded {

	transform: rotate(90deg);

}



.category-products {

	display: none;

	background: #fafafa;

}



.category-products.show {

	display: block;

}



.product-loading-tip {

	text-align: center;

	color: #999;

	padding: 32rpx;

	font-size: 26rpx;

}



.load-more {

	text-align: center;

	padding: 28rpx;

	font-size: 26rpx;

	color: #8b5cf6;

}



.load-more.done {

	color: #bbb;

}



.product-item {

	display: flex;

	align-items: center;

	padding: 24rpx 32rpx 24rpx 128rpx;

	border-bottom: 1rpx solid #f0f0f0;

}



.product-item:last-child {

	border-bottom: none;

}



.product-checkbox {

	width: 44rpx;

	height: 44rpx;

	border: 4rpx solid #ddd;

	border-radius: 50%;

	display: flex;

	align-items: center;

	justify-content: center;

	margin-right: 24rpx;

	flex-shrink: 0;

}



.product-checkbox.checked {

	background: #8b5cf6;

	border-color: #8b5cf6;

}



.check-mark {

	color: #fff;

	font-size: 24rpx;

	line-height: 1;

}



.product-info {

	flex: 1;

}



.product-name {

	font-size: 28rpx;

	color: #333;

	display: block;

	margin-bottom: 8rpx;

}



.product-price {

	font-size: 28rpx;

	color: #ff6b35;

}



.empty-tip {

	text-align: center;

	color: #999;

	padding: 80rpx 32rpx;

	font-size: 28rpx;

}



.bottom-bar {

	position: fixed;

	bottom: 0;

	left: 0;

	right: 0;

	background: #fff;

	padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));

	border-top: 1rpx solid #f0f0f0;

	display: flex;

	align-items: center;

	gap: 24rpx;

}



.selected-count-wrap {
	flex: 1;
	display: flex;
	align-items: center;
	gap: 8rpx;
	min-width: 0;
}

.selected-count {
	font-size: 28rpx;
	color: #666;
	flex-shrink: 0;
}

.selected-arrow {
	flex-shrink: 0;
}

.selected-num {
	color: #8b5cf6;
	font-weight: 600;
}

.selected-modal-mask {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	z-index: 300;
}

.selected-modal-sheet {
	position: absolute;
	left: 0;
	right: 0;
	bottom: 0;
	background: #fff;
	border-radius: 40rpx 40rpx 0 0;
	max-height: 70vh;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.selected-modal-head {
	flex-shrink: 0;
	padding: 32rpx 40rpx;
	border-bottom: 1rpx solid #f0f0f0;
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.selected-modal-title {
	font-size: 32rpx;
	font-weight: 600;
	color: #333;
}

.selected-modal-close {
	width: 56rpx;
	height: 56rpx;
	display: flex;
	align-items: center;
	justify-content: center;
}

.selected-modal-body {
	flex: 1;
	min-height: 0;
	max-height: calc(70vh - 220rpx);
	padding: 0 32rpx;
	box-sizing: border-box;
}

.selected-empty {
	text-align: center;
	color: #999;
	padding: 80rpx 0;
	font-size: 28rpx;
}

.selected-item {
	display: flex;
	align-items: center;
	padding: 28rpx 0;
	border-bottom: 1rpx solid #f5f5f5;
}

.selected-item:last-child {
	border-bottom: none;
}

.selected-item-info {
	flex: 1;
	min-width: 0;
	padding-right: 24rpx;
}

.selected-item-name {
	font-size: 28rpx;
	color: #333;
	display: block;
	margin-bottom: 8rpx;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.selected-item-price {
	font-size: 26rpx;
	color: #ff6b35;
}

.selected-item-remove {
	width: 64rpx;
	height: 64rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.selected-modal-foot {
	flex-shrink: 0;
	padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));
	border-top: 1rpx solid #f0f0f0;
}

.selected-modal-btn {
	height: 88rpx;
	line-height: 88rpx;
	background: #8b5cf6;
	color: #fff;
	border-radius: 16rpx;
	font-size: 32rpx;
	border: none;

	&::after {
		border: none;
	}
}

.confirm-btn {

	flex: 1;

	height: 88rpx;

	line-height: 88rpx;

	background: #8b5cf6;

	color: #fff;

	border-radius: 16rpx;

	font-size: 32rpx;

	border: none;

}



.confirm-btn[disabled] {

	background: #ccc;

}

</style>


