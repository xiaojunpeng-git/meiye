<template>
	<view class="setting-page">
		<!-- #ifdef H5 -->
		<page-nav-bar :title="pageTitle" theme="purple" @back="goBack">
			<view slot="right" class="h5-nav-confirm" @click="submit">确定</view>
		</page-nav-bar>
		<!-- #endif -->
		<!-- #ifdef H5 -->
		<scroll-view scroll-y class="form-scroll" :scroll-with-animation="true">
		<!-- #endif -->
		<!-- #ifndef H5 -->
		<view class="form-body">
		<!-- #endif -->
			<view class="form-section">
				<view class="form-row">
					<text class="form-label">目标名称</text>
					<input
						class="form-input"
						v-model="form.name"
						placeholder="请输入目标名称"
						placeholder-class="placeholder"
					/>
				</view>
				<view class="form-row" @click="goObjectSelect">
					<text class="form-label">目标门店</text>
					<view class="form-value" :class="{ 'has-value': form.object_name }">
						<text>{{ form.object_name || '请选择门店' }}</text>
						<uni-icons type="right" size="14" color="#ccc" />
					</view>
				</view>
				<view class="form-row" @click="openMonthModal">
					<text class="form-label">目标月份</text>
					<view class="form-value" :class="{ 'has-value': monthDisplayText }">
						<text>{{ monthDisplayText || '请选择月份' }}</text>
						<uni-icons type="right" size="14" color="#ccc" />
					</view>
				</view>
			</view>

			<view class="target-section">
				<view class="section-header">
					<text class="section-title">设置目标</text>
					<text class="section-hint">目标值为0代表不设置</text>
				</view>
				<view
					v-for="(m, idx) in form.metrics"
					:key="m.metric_key"
					class="metric-row"
				>
					<view
						class="metric-icon"
						:class="m.settingIconClass"
					>
						<uni-icons
							:type="m.settingUniType"
							:size="16"
							:color="m.settingIconColor"
						/>
					</view>
					<text class="metric-name">{{ m.metric_name }}</text>
					<input
						class="metric-input"
						type="number"
						:value="m.target_value"
						placeholder="0"
						placeholder-class="placeholder"
						:data-metric-idx="idx"
						@input="onMetricTargetInputTap"
					/>
					<text
						class="allocate-btn"
						:data-metric-idx="idx"
						@click.stop="onAllocateTap"
					>分配</text>
				</view>
			</view>

			<view
				v-for="(p, idx) in form.products"
				:key="p.card_key"
				class="product-target-row"
			>
				<view class="product-target-header">
					<view class="product-header-left">
						<text class="product-target-title">品项目标</text>
						<text class="product-target-count">已选{{ productItemCount(p) }}个品项</text>
					</view>
					<view class="product-target-actions">
						<text
							class="product-edit-btn"
							:data-product-idx="idx"
							@click="onProductEditTap"
						>编辑</text>
						<text
							class="product-delete-btn"
							:data-product-idx="idx"
							@click="onProductDeleteTap"
						>删除</text>
						<text
							class="allocate-btn"
							:data-product-idx="idx"
							@click="onProductAllocateTap"
						>分配</text>
					</view>
				</view>
				<view class="product-edit-section">
					<view class="product-edit-row">
						<text class="product-edit-label">目标名称</text>
						<input
							class="product-edit-input"
							:value="p.display_name"
							placeholder="请输入目标名称"
							placeholder-class="placeholder"
							:data-product-idx="idx"
							@input="onProductDisplayNameInputTap"
						/>
					</view>
					<view class="target-type-section">
						<text class="target-type-label">目标类型</text>
						<view class="target-type-group">
							<view
								v-for="t in productMetricTypesFor(p)"
								:key="t.key"
								class="target-type-item"
								:class="{ active: p.metric_key === t.key, disabled: t.disabled }"
								:data-product-idx="idx"
								:data-type-key="t.key"
								@click="onProductMetricTypeTap"
							>
								<text>{{ t.name }}</text>
							</view>
						</view>
					</view>
					<view class="product-edit-row product-edit-row-mt">
						<text class="product-edit-label">目标值</text>
						<input
							class="product-target-input"
							type="number"
							:value="p.target_value"
							placeholder="请输入目标值"
							placeholder-class="placeholder"
							:data-product-idx="idx"
							@input="onProductTargetInputTap"
						/>
					</view>
				</view>
			</view>
		<!-- #ifdef H5 -->
		</scroll-view>
		<!-- #endif -->
		<!-- #ifndef H5 -->
		</view>
		<!-- #endif -->

		<view class="bottom-bar">
			<button class="add-product-btn" @click="onProductAddTap">
				<uni-icons type="plusempty" size="14" color="#8B5CF6" />
				<text class="add-product-text">编辑品项目标</text>
			</button>
			<button class="submit-btn" :loading="saving" @click="submit">确定</button>
		</view>

		<!-- 选择月份 -->
		<view v-if="monthModalVisible" class="modal-mask" @click="closeMonthModal" />
		<view class="modal-panel" :class="{ show: monthModalVisible }">
			<view class="modal-header">
				<text class="modal-title">选择月份</text>
				<view class="modal-close" @click="closeMonthModal">
					<uni-icons type="closeempty" size="22" color="#999" />
				</view>
			</view>
			<view class="picker-wrap">
				<view class="picker-highlight" />
				<view class="picker-divider" />
				<picker-view
					class="picker-view"
					:value="pickerValue"
					indicator-style="height: 44px;"
					@change="onPickerChange"
				>
					<picker-view-column>
						<view
							v-for="(y, i) in yearPickerList"
							:key="i"
							class="picker-item"
						>{{ y }}年</view>
					</picker-view-column>
					<picker-view-column>
						<view
							v-for="(label, i) in monthPickerLabels"
							:key="i"
							class="picker-item"
						>{{ label }}</view>
					</picker-view-column>
				</picker-view>
			</view>
			<button class="modal-btn" @click="confirmMonth">确定</button>
		</view>
	</view>
</template>

<script>
import { targetDetail, targetSave, targetMetricOptions, targetStoreOptions } from '@/api/target.js';
import uniIcons from '@/uni_modules/uni-icons/components/uni-icons/uni-icons.vue';
import pageNavBar from '../components/page-nav-bar.vue';
import {
	getTargetYearOptions,
	getTargetYearRange,
	clampTargetYear,
	getMetricSettingStyle,
	withMetricSettingStyle,
	MONTH_PICKER_LABELS,
	buildProductAllocateRefKey,
	clearAllocateDraft,
	getAllocateDraft,
	setAllocateDraft,
	toTargetInt,
	sanitizeTargetInputValue,
	getApiErrorMessage,
	getAllocationMismatchMessage,
	normalizeAllocateItemsForSave,
	resolveAllocationsForSubmit,
	getObjectDisplayName,
	resolveDefaultObjectFromOptions,
	consumeTargetObjectSelect,
	loadTargetObjectCache,
	isSpecificStoreObject,
	applyTargetNativeNavBar,
	targetNavigateBack,
} from '../common/util.js';

export default {
	components: { uniIcons, pageNavBar },
	data() {
		const y = clampTargetYear(new Date().getFullYear());
		const month = new Date().getMonth() + 1;
		const { years } = getTargetYearRange();
		const yi = years.indexOf(y) >= 0 ? years.indexOf(y) : 0;
		return {
			id: 0,
			saving: false,
			monthModalVisible: false,
			monthPickerLabels: MONTH_PICKER_LABELS,
			pickerValue: [yi, month - 1],
			productMetricTypes: [],
			form: {
				name: '',
				object_type: 1,
				object_id: 0,
				object_name: '',
				store_id: 0,
				time_type: 1,
				year: y,
				month,
				period_start: 0,
				period_end: 0,
				metrics: [],
				products: [],
			},
		};
	},
	computed: {
		pageTitle() {
			return this.id ? '编辑目标' : '设置目标';
		},
		yearPickerList() {
			return getTargetYearRange().years;
		},
		monthDisplayText() {
			if (!this.form.year || !this.form.month) return '';
			return `${this.form.year}年${this.form.month}月`;
		},
	},
	onLoad(options) {
		this.id = parseInt(options.id || 0, 10);
		applyTargetNativeNavBar(this.id ? '编辑目标' : '设置目标');
		this.loadProductMetricTypes();
		if (this.id) {
			this.loadDetail();
		} else {
			this.initDefaultMetrics();
			this.initDefaultStore();
		}
	},
	onShow() {
		applyTargetNativeNavBar(this.pageTitle);
		const obj = consumeTargetObjectSelect();
		if (obj && isSpecificStoreObject(obj)) {
			this.applyFormStoreObject(obj);
		}
		const allocResult = uni.getStorageSync('target_allocate_result');
		if (allocResult && allocResult.ref_key) {
			this.applyAllocateResult(allocResult);
			uni.removeStorageSync('target_allocate_result');
		}
		const productSelect = uni.getStorageSync('target_product_select');
		if (productSelect && productSelect.products && productSelect.products.length) {
			this.applyProductSelect(productSelect);
			uni.removeStorageSync('target_product_select');
		}
	},
	methods: {
		readDatasetIdx(e, name) {
			const ds = (e && e.currentTarget && e.currentTarget.dataset) || {};
			const val = ds[name];
			if (val === undefined || val === null || val === '') return -1;
			const n = parseInt(val, 10);
			return Number.isNaN(n) ? -1 : n;
		},
		readDatasetStr(e, name) {
			const ds = (e && e.currentTarget && e.currentTarget.dataset) || {};
			const val = ds[name];
			return val === undefined || val === null ? '' : String(val);
		},
		onMetricTargetInputTap(e) {
			const idx = this.readDatasetIdx(e, 'metricIdx');
			if (idx < 0) return;
			this.onMetricTargetInput(idx, e);
		},
		onProductTargetInputTap(e) {
			const idx = this.readDatasetIdx(e, 'productIdx');
			if (idx < 0) return;
			this.onProductTargetInput(idx, e);
		},
		onProductDisplayNameInputTap(e) {
			const idx = this.readDatasetIdx(e, 'productIdx');
			if (idx < 0) return;
			const obj = this.form.products[idx];
			if (!obj) return;
			const str = String(e?.detail?.value ?? e?.target?.value ?? '');
			this.$set(obj, 'display_name', str);
		},
		onAllocateTap(e) {
			const idx = this.readDatasetIdx(e, 'metricIdx');
			if (idx >= 0) this.onAllocate(idx);
		},
		onProductEditTap(e) {
			const idx = this.readDatasetIdx(e, 'productIdx');
			if (idx >= 0) this.goProductSelect('edit', idx);
		},
		onProductDeleteTap(e) {
			const idx = this.readDatasetIdx(e, 'productIdx');
			if (idx >= 0) this.removeProduct(idx);
		},
		onProductAllocateTap(e) {
			const idx = this.readDatasetIdx(e, 'productIdx');
			if (idx >= 0) this.onAllocateProduct(idx);
		},
		onProductMetricTypeTap(e) {
			const productIdx = this.readDatasetIdx(e, 'productIdx');
			const typeKey = this.readDatasetStr(e, 'typeKey');
			if (productIdx >= 0 && typeKey) {
				this.selectProductMetricType(productIdx, typeKey);
			}
		},
		onProductAddTap() {
			this.goProductSelect('add');
		},
		metricStyle(key) {
			return getMetricSettingStyle(key);
		},
		mapMetricRow(m) {
			return withMetricSettingStyle({
				metric_key: m.metric_key || m.key,
				metric_name: m.metric_name || m.name,
				target_value: m.target_value != null ? this.formatTargetValueStr(m.target_value) : '',
				unit: m.unit,
				metric_type: m.metric_type || 1,
				allocations: m.allocations || [],
			});
		},
		onMetricTargetInput(idx, e) {
			const obj = this.form.metrics[idx];
			if (!obj) return;
			this.onTargetValueInput(obj, 'target_value', e);
		},
		onProductTargetInput(idx, e) {
			const obj = this.form.products[idx];
			if (!obj) return;
			this.onTargetValueInput(obj, 'target_value', e);
		},
		onTargetValueInput(obj, key, e) {
			const str = String(e?.detail?.value ?? e?.target?.value ?? '');
			const val = sanitizeTargetInputValue(str);
			const prev = obj[key] != null ? String(obj[key]) : '';
			this.$set(obj, key, val);
			if (key === 'target_value' && val !== prev) {
				this.$set(obj, 'allocations', []);
				const draftKey =
					obj.metric_key ||
					obj.allocate_ref_key ||
					(obj.product_items && obj.product_items[0]
						? buildProductAllocateRefKey(
								obj.product_items[0].product_id,
								obj.metric_key,
								this.form.products.indexOf(obj)
						  )
						: '');
				if (draftKey) {
					setAllocateDraft(draftKey, []);
				}
			}
		},
		formatTargetValueStr(val) {
			const n = toTargetInt(val);
			return n > 0 ? String(n) : '';
		},
		loadProductMetricTypes() {
			targetMetricOptions().then((res) => {
				this.productMetricTypes = res.data.product_metric_types || [];
			});
		},
		normalizeProduct(p) {
			const items =
				p.product_items && p.product_items.length
					? p.product_items
					: p.product_id
						? [
								{
									product_id: p.product_id,
									product_name: p.product_name,
									price: p.price,
									image: p.image,
								},
						  ]
						: [];
			const first = items[0] || {};
			const name = first.product_name || p.product_name || '';
			return {
				...p,
				card_key: p.card_key || `card_${p.id || first.product_id || Date.now()}`,
				item_type: p.item_type || 'project',
				product_items: items,
				product_id: first.product_id || p.product_id,
				product_name: first.product_name || p.product_name,
				display_name: p.display_name || (name ? `${name}目标` : '品项目标'),
				metric_key: p.metric_key || 'revenue',
				target_value: p.target_value != null ? this.formatTargetValueStr(p.target_value) : '',
				allocations: p.allocations || [],
				allocate_ref_key: p.allocate_ref_key || '',
			};
		},
		productItemCount(p) {
			const items = p.product_items && p.product_items.length ? p.product_items : [];
			return items.length || (p.product_id ? 1 : 0);
		},
		productMetricTypesFor(p) {
			const itemType = p.item_type || 'project';
			const all = this.productMetricTypes || [];
			if (itemType === 'project') {
				return all;
			}
			return all.map((t) => ({
				...t,
				disabled: !['revenue', 'count'].includes(t.key),
			}));
		},
		selectProductMetricType(productIdx, typeKey) {
			const p = this.form.products[productIdx];
			if (!p) return;
			const t = (this.productMetricTypesFor(p) || []).find((item) => item.key === typeKey);
			if (!t || t.disabled) return;
			p.metric_key = t.key;
			p.metric_name = t.name;
			p.unit = t.unit;
		},
		applyProductSelect(data) {
			const { mode, edit_index, item_type, first_product_name, products } = data;
			const list = products || [];
			if (!list.length) return;
			const firstName = first_product_name || list[0].product_name || '商品';
			const prev = mode === 'edit' && edit_index >= 0 ? this.form.products[edit_index] : null;
			const card = this.normalizeProduct({
				card_key: prev?.card_key || `card_${Date.now()}`,
				item_type: item_type || 'project',
				product_items: list,
				display_name: `${firstName}目标`,
				metric_key: prev?.metric_key || 'revenue',
				metric_name: prev?.metric_name || '销售收入',
				unit: prev?.unit || '元',
				target_value: prev?.target_value != null ? String(prev.target_value) : '',
				allocations: prev?.allocations || [],
				allocate_ref_key: prev?.allocate_ref_key || '',
			});
			const allowed = this.productMetricTypesFor(card).filter((t) => !t.disabled);
			if (!allowed.find((t) => t.key === card.metric_key) && allowed.length) {
				card.metric_key = allowed[0].key;
				card.metric_name = allowed[0].name;
				card.unit = allowed[0].unit;
			}
			if (mode === 'edit' && edit_index >= 0) {
				this.$set(this.form.products, edit_index, card);
			} else {
				this.form.products.push(card);
			}
		},
		goBack() {
			targetNavigateBack();
		},
		initDefaultMetrics() {
			targetMetricOptions().then((res) => {
				const core = res.data.core_metrics || [];
				this.form.metrics = core.map((m) =>
					this.mapMetricRow({
						metric_key: m.key,
						metric_name: m.name,
						target_value: '',
						unit: m.unit,
						metric_type: 1,
					})
				);
			});
		},
		initDefaultStore() {
			const cached = loadTargetObjectCache();
			if (isSpecificStoreObject(cached)) {
				this.applyFormStoreObject(cached);
				return;
			}
			if (cached && !isSpecificStoreObject(cached)) {
				return;
			}
			targetStoreOptions()
				.then((res) => {
					const options = res.data || [];
					const stores = options.filter((o) => Number(o.object_type) === 1);
					const isStoreManager =
						stores.length === 1 && !options.some((o) => Number(o.object_type) === 2);
					if (!isStoreManager) return;
					const def = resolveDefaultObjectFromOptions(options);
					if (!isSpecificStoreObject(def)) return;
					this.applyFormStoreObject(def);
				})
				.catch(() => {});
		},
		applyFormStoreObject(obj) {
			if (!isSpecificStoreObject(obj)) return;
			this.form.object_name = getObjectDisplayName(obj);
			this.form.object_type = obj.object_type || 1;
			this.form.object_id = obj.id || 0;
			this.form.store_id = obj.id || 0;
		},
		loadDetail() {
			targetDetail(this.id).then((res) => {
				const d = res.data || {};
				this.form = {
					name: d.name,
					object_type: d.object_type,
					object_id: d.store_id || 0,
					object_name: d.object_name,
					store_id: d.store_id || 0,
					time_type: d.time_type || 1,
					year: clampTargetYear(d.year),
					month: d.month || 1,
					period_start: d.period_start,
					period_end: d.period_end,
					metrics: (d.metrics || []).map((m) => this.mapMetricRow(m)),
					products: (d.products || []).map((p) => this.normalizeProduct(p)),
				};
				this.syncPickerIndex();
			});
		},
		getAllocateStoreId() {
			if (this.form.store_id) return this.form.store_id;
			if (this.form.object_type === 1 && this.form.object_id) {
				return this.form.object_id;
			}
			return 0;
		},
		goAllocatePage(query) {
			const storeId = this.getAllocateStoreId();
			const q = Object.keys(query)
				.map((k) => `${k}=${encodeURIComponent(query[k])}`)
				.join('&');
			uni.navigateTo({
				url: `/pages/admin/target/allocate/index?${q}&store_id=${storeId}&target_id=${this.id || 0}`,
			});
		},
		applyAllocateResult(result) {
			const { ref_key, items } = result;
			if (!ref_key) return;
			if (ref_key.indexOf('product_') === 0) {
				const idx = this.findProductIndexByRefKey(ref_key);
				if (idx >= 0) {
					this.$set(this.form.products[idx], 'allocations', items || []);
					this.$set(this.form.products[idx], 'allocate_ref_key', ref_key);
				}
				return;
			}
			const m = this.form.metrics.find((x) => x.metric_key === ref_key);
			if (m) {
				this.$set(m, 'allocations', items || []);
			}
		},
		findProductIndexByRefKey(refKey) {
			const m = refKey.match(/^product_idx_(\d+)_/);
			if (m) {
				const idx = parseInt(m[1], 10);
				if (this.form.products[idx]) return idx;
			}
			const m2 = refKey.match(/^product_(\d+)_/);
			if (m2) {
				const pid = parseInt(m2[1], 10);
				return this.form.products.findIndex((p) => p.product_id === pid);
			}
			return this.form.products.findIndex((p) => p.allocate_ref_key === refKey);
		},
		syncPickerIndex() {
			const yi = this.yearPickerList.indexOf(this.form.year);
			this.pickerValue = [
				yi >= 0 ? yi : 0,
				Math.max(0, Math.min(11, (this.form.month || 1) - 1)),
			];
		},
		openMonthModal() {
			this.syncPickerIndex();
			this.monthModalVisible = true;
		},
		closeMonthModal() {
			this.monthModalVisible = false;
		},
		onPickerChange(e) {
			this.pickerValue = e.detail.value;
		},
		confirmMonth() {
			const [yi, mi] = this.pickerValue;
			this.form.year = this.yearPickerList[yi] || clampTargetYear(new Date().getFullYear());
			this.form.month = mi + 1;
			this.form.time_type = 1;
			this.closeMonthModal();
		},
		goObjectSelect() {
			uni.navigateTo({ url: '/pages/admin/target/select/object?mode=single&storeOnly=1' });
		},
		getCardProductItems(card) {
			if (!card) return [];
			if (card.product_items && card.product_items.length) {
				return card.product_items.map((p) => ({ ...p }));
			}
			if (card.product_id) {
				return [
					{
						product_id: card.product_id,
						product_name: card.product_name,
						price: card.price,
						image: card.image,
					},
				];
			}
			return [];
		},
		goProductSelect(mode = 'add', editIndex = -1) {
			if (!this.form.object_name) {
				return uni.showToast({ title: '请选择目标门店', icon: 'none' });
			}
			const storeId = this.getAllocateStoreId();
			uni.removeStorageSync('target_product_select_preset');
			let itemType = 'project';
			if (mode === 'edit' && editIndex >= 0) {
				const card = this.form.products[editIndex];
				const selected = this.getCardProductItems(card);
				itemType = card?.item_type || 'project';
				if (selected.length) {
					uni.setStorageSync('target_product_select_preset', {
						item_type: itemType,
						selected,
					});
				}
			}
			uni.navigateTo({
				url: `/pages/admin/target/select/product?from=targetSetting&mode=${mode}&edit_index=${editIndex}&store_id=${storeId}&item_type=${encodeURIComponent(itemType)}&t=${Date.now()}`,
			});
		},
		removeProduct(idx) {
			this.form.products.splice(idx, 1);
		},
		onAllocate(metricIdx) {
			const m = this.form.metrics[metricIdx];
			if (!m) return;
			if (!m.target_value || m.target_value === '0') {
				return uni.showToast({ title: '请先输入目标值', icon: 'none' });
			}
			if (!this.form.object_name) {
				return uni.showToast({ title: '请选择目标门店', icon: 'none' });
			}
			this.goAllocatePage({
				metric: m.metric_name,
				metric_name: m.metric_name,
				target: toTargetInt(m.target_value),
				target_value: toTargetInt(m.target_value),
				unit: m.unit || '',
				metric_key: m.metric_key,
				ref_key: m.metric_key,
				allocate_type: 1,
			});
		},
		onAllocateProduct(productIdx) {
			const p = this.form.products[productIdx];
			if (!p) return;
			if (!p.target_value || p.target_value === '0') {
				return uni.showToast({ title: '请先输入目标值', icon: 'none' });
			}
			if (!this.form.object_name) {
				return uni.showToast({ title: '请选择目标门店', icon: 'none' });
			}
			const idx = productIdx;
			const pid =
				(p.product_items && p.product_items[0]?.product_id) || p.product_id || 0;
			const refKey =
				p.allocate_ref_key || buildProductAllocateRefKey(pid, p.metric_key, idx);
			this.goAllocatePage({
				metric: p.display_name || p.product_name || '品项目标',
				metric_name: p.metric_name || '',
				target: toTargetInt(p.target_value),
				target_value: toTargetInt(p.target_value),
				unit: p.unit || '',
				ref_key: refKey,
				allocate_type: 2,
			});
		},
		submit() {
			if (!this.form.name.trim()) {
				return uni.showToast({ title: '请输入目标名称', icon: 'none' });
			}
			if (!this.form.object_name) {
				return uni.showToast({ title: '请选择目标门店', icon: 'none' });
			}
			if (!this.monthDisplayText) {
				return uni.showToast({ title: '请选择月份', icon: 'none' });
			}
			const draft = getAllocateDraft();
			const useDraft = !this.id;
			const metrics = [];
			for (const m of this.form.metrics.filter(
				(x) => x.target_value !== '' && x.target_value !== '0'
			)) {
				const raw = resolveAllocationsForSubmit(
					m.allocations,
					m.metric_key,
					draft,
					useDraft
				);
				const err = getAllocationMismatchMessage(
					raw,
					m.target_value,
					m.metric_name
				);
				if (err) {
					return uni.showToast({ title: err, icon: 'none' });
				}
				metrics.push({
					metric_key: m.metric_key,
					metric_name: m.metric_name,
					target_value: toTargetInt(m.target_value),
					unit: m.unit,
					metric_type: m.metric_type || 1,
					allocations: normalizeAllocateItemsForSave(raw),
				});
			}
			const products = [];
			for (const card of this.form.products.filter(
				(p) => p.target_value !== '' && p.target_value !== '0'
			)) {
				const cardIdx = this.form.products.indexOf(card);
				const items =
					card.product_items && card.product_items.length
						? card.product_items
						: card.product_id
							? [
									{
										product_id: card.product_id,
										product_name: card.product_name,
									},
							  ]
							: [];
				const refKey =
					card.allocate_ref_key ||
					buildProductAllocateRefKey(
						items[0]?.product_id || 0,
						card.metric_key,
						cardIdx
					);
				const raw = resolveAllocationsForSubmit(
					card.allocations,
					refKey,
					draft,
					useDraft
				);
				const err = getAllocationMismatchMessage(
					raw,
					card.target_value,
					card.display_name || card.product_name || '品项目标'
				);
				if (err) {
					return uni.showToast({ title: err, icon: 'none' });
				}
				const allocations = normalizeAllocateItemsForSave(raw);
				items.forEach((item, itemIdx) => {
					products.push({
						product_id: item.product_id,
						product_name: item.product_name,
						display_name: card.display_name || '',
						metric_key: card.metric_key,
						metric_name: card.metric_name,
						target_value: toTargetInt(card.target_value),
						unit: card.unit,
						allocate_ref_key: refKey,
						allocations: itemIdx === 0 ? allocations : [],
					});
				});
			}
			if (!metrics.length && !products.length) {
				return uni.showToast({ title: '请至少填写一个指标', icon: 'none' });
			}
			this.saving = true;
			const storeId = this.getAllocateStoreId();
			targetSave({
				id: this.id,
				store_id: storeId,
				name: this.form.name,
				object_type: this.form.object_type,
				object_name: this.form.object_name,
				time_type: this.form.time_type,
				year: this.form.year,
				month: this.form.month,
				period_start: this.form.period_start,
				period_end: this.form.period_end,
				metrics,
				products,
			})
				.then(() => {
					clearAllocateDraft();
					uni.showToast({ title: '保存成功' });
					setTimeout(() => uni.navigateBack(), 500);
				})
				.catch((e) => {
					uni.showToast({ title: getApiErrorMessage(e, '保存失败'), icon: 'none' });
				})
				.finally(() => {
					this.saving = false;
				});
		},
	},
};
</script>

<style scoped lang="scss">
@import '../common/target-form.scss';

.setting-page {
	position: relative;
	overflow: hidden;
	min-height: 100vh;
	background: #f5f5f5;
	padding-bottom: calc(160rpx + env(safe-area-inset-bottom));
}

.form-scroll {
	height: calc(100vh - 200rpx);
}

/* 小程序使用页面原生滚动，避免 scroll-view 固定高度导致滑动卡顿 */
.form-body {
	box-sizing: border-box;
}

.form-section {
	background: #fff;
	padding: 0 32rpx;
	border-bottom: 1rpx solid #f5f5f5;
}

.form-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 24rpx 0;
	border-bottom: 1rpx solid #f5f5f5;
}

.form-row:last-child {
	border-bottom: none;
}

.form-label {
	font-size: 30rpx;
	color: #333;
	width: 160rpx;
	flex-shrink: 0;
}

.form-input {
	flex: 1;
	font-size: 30rpx;
	text-align: right;
	min-height: 72rpx;
	height: 72rpx;
	line-height: 72rpx;
}

.form-value {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 16rpx;
	font-size: 30rpx;
	color: #999;
}

.form-value.has-value {
	color: #333;
}

.placeholder {
	color: #ccc;
}

.target-section {
	background: #fff;
	margin-top: 24rpx;
}

.section-header {
	display: flex;
	align-items: center;
	padding: 32rpx;
	border-bottom: 1rpx solid #f5f5f5;
}

.section-title {
	font-size: 30rpx;
	font-weight: 500;
	color: #333;
}

.section-hint {
	font-size: 24rpx;
	color: #999;
	margin-left: 16rpx;
}

.metric-row {
	display: flex;
	align-items: center;
	padding: 32rpx;
	border-bottom: 1rpx solid #f5f5f5;
	gap: 24rpx;
}

.metric-row:last-child {
	border-bottom: none;
}

.metric-icon {
	width: 72rpx;
	height: 72rpx;
	border-radius: 16rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.metric-icon.revenue {
	background: #f5f3ff;
}
.metric-icon.consumption {
	background: #eff6ff;
}
.metric-icon.new {
	background: #f0fdf4;
}
.metric-icon.old {
	background: #fef3c7;
}
.metric-icon.service {
	background: #fdf2f8;
}
.metric-icon.appoint {
	background: #f0fdfa;
}
.metric-icon.booking {
	background: #eef2ff;
}
.metric-icon.goods {
	background: #fff7ed;
}

.metric-name {
	flex: 1;
	font-size: 30rpx;
	color: #333;
}

.metric-input {
	width: 200rpx;
	padding: 16rpx 24rpx;
	border: 1rpx solid #e0e0e0;
	border-radius: 12rpx;
	font-size: 28rpx;
	text-align: right;
	min-height: 72rpx;
	height: 72rpx;
	line-height: 72rpx;
	box-sizing: border-box;
}

.allocate-btn {
	font-size: 28rpx;
	color: #8b5cf6;
	flex-shrink: 0;
	white-space: nowrap;
}

.product-target-row {
	background: #fff;
	margin-top: 24rpx;
	padding: 32rpx;
	box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.06);
	border-radius: 24rpx;
	margin-left: 24rpx;
	margin-right: 24rpx;
}

.product-target-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 24rpx;
	padding-bottom: 24rpx;
	border-bottom: 1rpx solid #f5f5f5;
}

.product-header-left {
	display: flex;
	align-items: center;
	gap: 16rpx;
	flex-wrap: wrap;
}

.product-target-title {
	font-size: 30rpx;
	font-weight: 500;
	color: #333;
}

.product-target-count {
	font-size: 26rpx;
	color: #8b5cf6;
	background: #f5f3ff;
	padding: 8rpx 20rpx;
	border-radius: 24rpx;
}

.product-target-actions {
	display: flex;
	align-items: center;
	gap: 24rpx;
	flex-shrink: 0;
}

.product-edit-btn {
	font-size: 28rpx;
	color: #3b82f6;
}

.product-delete-btn {
	font-size: 28rpx;
	color: #ef4444;
}

.product-edit-section {
	background: #f8f9fa;
	border-radius: 16rpx;
	padding: 24rpx;
}

.product-edit-row {
	display: flex;
	align-items: center;
	margin-bottom: 24rpx;
}

.product-edit-row-mt {
	margin-bottom: 0;
	margin-top: 0;
}

.product-edit-label {
	font-size: 28rpx;
	color: #666;
	width: 160rpx;
	flex-shrink: 0;
}

.product-edit-input {
	flex: 1;
	padding: 20rpx 24rpx;
	border: 1rpx solid #e0e0e0;
	border-radius: 12rpx;
	font-size: 28rpx;
	background: #fff;
	min-height: 72rpx;
	box-sizing: border-box;
}

.product-target-input {
	flex: 1;
	padding: 20rpx 24rpx;
	border: 1rpx solid #e0e0e0;
	border-radius: 12rpx;
	font-size: 28rpx;
	text-align: right;
	background: #fff;
	min-height: 72rpx;
	box-sizing: border-box;
}

.target-type-section {
	display: flex;
	align-items: center;
	gap: 16rpx;
	margin-bottom: 24rpx;
}

.target-type-label {
	font-size: 28rpx;
	color: #666;
	width: 160rpx;
	flex-shrink: 0;
}

.target-type-group {
	flex: 1;
	display: flex;
	gap: 12rpx;
}

.target-type-item {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 12rpx 4rpx;
	border: 1rpx solid #e8e8e8;
	border-radius: 12rpx;
	background: #fff;
	min-height: 56rpx;
}

.target-type-item text {
	font-size: 22rpx;
	color: #666;
	white-space: nowrap;
}

.target-type-item.active {
	border-color: #8b5cf6;
	background: #8b5cf6;
}

.target-type-item.active text {
	color: #fff;
}

.target-type-item.disabled {
	opacity: 0.4;
	pointer-events: none;
}

.bottom-bar {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	background: #fff;
	padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));
	border-top: 1rpx solid #f0f0f0;
	display: flex;
	align-items: center;
	gap: 24rpx;
	z-index: 10;
}

.add-product-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8rpx;
	padding: 0 32rpx;
	height: 88rpx;
	line-height: 88rpx;
	background: #fff;
	border: 1rpx solid #8b5cf6;
	color: #8b5cf6;
	border-radius: 16rpx;
	font-size: 28rpx;
	margin: 0;
	flex-shrink: 0;
}

.add-product-btn::after {
	border: none;
}

.add-product-text {
	color: #8b5cf6;
	font-size: 28rpx;
}

.submit-btn {
	flex: 1;
	background: #8b5cf6;
	color: #fff;
	height: 88rpx;
	line-height: 88rpx;
	padding: 0;
	margin: 0;
	border: none;
	border-radius: 16rpx;
	font-size: 32rpx;
	font-weight: 500;
}

.submit-btn::after {
	border: none;
}

.modal-mask {
	position: fixed;
	left: 0;
	right: 0;
	top: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	z-index: 100;
}

.modal-panel {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	background: #fff;
	border-radius: 40rpx 40rpx 0 0;
	padding: 40rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));
	z-index: 101;
	transform: translateY(100%);
	transition: transform 0.3s;
}

.modal-panel.show {
	transform: translateY(0);
}

.modal-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 32rpx;
}

.modal-title {
	font-size: 34rpx;
	font-weight: 600;
}

.modal-close {
	padding: 8rpx;
}

.picker-wrap {
	position: relative;
	height: 480rpx;
	margin-bottom: 32rpx;
}

.picker-view {
	height: 480rpx;
}

.picker-item {
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 32rpx;
	color: #999;
	line-height: 44px;
	height: 44px;
}

.picker-highlight {
	position: absolute;
	left: 0;
	right: 0;
	top: 50%;
	height: 88rpx;
	margin-top: -44rpx;
	background: rgba(139, 92, 246, 0.1);
	border-radius: 16rpx;
	pointer-events: none;
	z-index: 1;
}

.picker-divider {
	position: absolute;
	left: 50%;
	top: 0;
	bottom: 0;
	width: 1rpx;
	background: #e0e0e0;
	z-index: 2;
	pointer-events: none;
}

.modal-btn {
	width: 100%;
	background: #8b5cf6;
	color: #fff;
	height: 88rpx;
	line-height: 88rpx;
	border: none;
	border-radius: 16rpx;
	font-size: 32rpx;
	font-weight: 500;
	padding: 0;
	margin: 0;
}

.modal-btn::after {
	border: none;
}
</style>
