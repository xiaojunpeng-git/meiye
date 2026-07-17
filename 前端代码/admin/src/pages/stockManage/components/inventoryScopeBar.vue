<template>
	<div class="scope-bar">
		<FormItem label="监管范围：">
			<RadioGroup v-model="innerScope" type="button" @on-change="onScopeChange">
				<Radio v-if="mode !== 'salon'" label="hq">总部仓</Radio>
				<Radio label="store">指定门店</Radio>
				<Radio label="all">全部门店汇总</Radio>
			</RadioGroup>
		</FormItem>
		<FormItem v-if="innerScope === 'store'" label="门店：">
			<Select
				v-model="innerStoreId"
				filterable
				clearable
				placeholder="请选择门店"
				class="input-add"
				@on-change="onStoreChange"
			>
				<Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
			</Select>
		</FormItem>
		<span v-if="hint" class="scope-hint">{{ hint }}</span>
	</div>
</template>

<script>
	import { merchantStoreListApi } from '@/api/setting';

	export default {
		name: 'InventoryScopeBar',
		props: {
			scope: { type: String, default: 'hq' },
			storeId: { type: [Number, String], default: '' },
			/** salon：院装页默认 all；库存页默认 hq */
			mode: { type: String, default: 'inventory' },
		},
		data() {
			return {
				innerScope: this.scope || (this.mode === 'salon' ? 'all' : 'hq'),
				innerStoreId: this.storeId || '',
				storeList: [],
			};
		},
		computed: {
			hint() {
				if (this.innerScope === 'hq') {
					return this.mode === 'salon' ? '总部仓无院装领用/退回数据' : '';
				}
				if (this.innerScope === 'store') {
					return this.mode === 'salon' ? '指定门店院装数据' : '';
				}
				return this.mode === 'salon' ? '仅汇总门店仓，不含总部仓' : '仅汇总门店仓，不含总部仓';
			},
		},
		watch: {
			scope(v) {
				if (v && v !== this.innerScope) this.innerScope = v;
			},
			storeId(v) {
				if (v !== this.innerStoreId) this.innerStoreId = v;
			},
		},
		created() {
			this.loadStores();
			this.emitChange(false);
		},
		methods: {
			loadStores() {
				merchantStoreListApi().then((res) => {
					this.storeList = res.data || [];
				}).catch(() => {
					this.storeList = [];
				});
			},
			onScopeChange() {
				if (this.innerScope !== 'store') {
					this.innerStoreId = '';
				}
				this.emitChange(true);
			},
			onStoreChange() {
				this.emitChange(true);
			},
			emitChange(notify) {
				const payload = {
					scope: this.innerScope,
					store_id: this.innerScope === 'store' ? (this.innerStoreId || '') : '',
				};
				this.$emit('input', payload);
				if (notify) this.$emit('change', payload);
			},
			/** 供父组件查询前校验 */
			validate() {
				if (this.innerScope === 'store' && !this.innerStoreId) {
					this.$Message.warning('请选择门店');
					return false;
				}
				return true;
			},
			getParams() {
				return {
					scope: this.innerScope,
					store_id: this.innerScope === 'store' ? (this.innerStoreId || '') : '',
				};
			},
		},
	};
</script>

<style lang="less" scoped>
	.scope-bar {
		display: inline;
	}
	.scope-hint {
		display: inline-block;
		margin-left: 8px;
		font-size: 12px;
		color: #999;
		line-height: 32px;
		vertical-align: middle;
	}
</style>
