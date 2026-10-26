<template>
	<div class="scope-bar">
		<FormItem label="库存仓：">
			<RadioGroup v-model="innerScope" type="button" @on-change="onScopeChange">
				<Radio label="hq">总部仓</Radio>
				<Radio label="store">选择门店</Radio>
				<Radio label="all">全部库存仓</Radio>
			</RadioGroup>
		</FormItem>
		<FormItem v-if="innerScope === 'store'" label="">
			<OrganizationResourceSelector
				v-model="innerStoreId"
				resource="store"
				picker-mode="modal"
				:tree-mode="true"
				selection-mode="store_only"
				modal-title="选择库存仓门店"
				trigger-placeholder="请选择门店"
				placeholder="搜索门店名称"
				:clearable="true"
				@input="onStoreChange"
			/>
		</FormItem>
	</div>
</template>

<script>
	import OrganizationResourceSelector from '@/components/organization/OrganizationResourceSelector.vue';

	export default {
		name: 'InventoryScopeBar',
		components: { OrganizationResourceSelector },
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
			};
		},
		watch: {
			scope(v) {
				if (v && v !== this.innerScope) this.innerScope = v;
			},
			storeId(v) {
				if (v !== this.innerStoreId) this.innerStoreId = v;
			},
		},
		created() { this.emitChange(false); },
		methods: {
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
