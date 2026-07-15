<template>
	<Modal
		:value="value"
		:title="editId > 0 ? '编辑配方' : '新建配方'"
		width="1274"
		:mask-closable="false"
		:styles="{ top: '40px' }"
		class-name="salon-recipe-form-modal"
		@on-cancel="handleClose"
	>
		<Form v-if="value" ref="form" :model="form" :label-width="110" @submit.native.prevent>
			<FormItem label="项目：" required>
				<div v-if="form.project_product_id" class="picked">
					<span class="pk-name">{{ form.project_name || ('#' + form.project_product_id) }}</span>
					<span class="pk-spec">{{ form.project_spec || form.project_unique }}</span>
					<a class="ml14" @click="openProject" v-if="!isEdit">重新选择</a>
				</div>
				<Button v-else type="primary" ghost @click="openProject">选择项目</Button>
				<div class="tips">院装配方绑定到具体的项目规格；同一项目规格在当前归属下只允许一条配方。</div>
			</FormItem>

			<FormItem label="状态：">
				<i-switch v-model="form.status" :true-value="1" :false-value="0" size="large">
					<span slot="open">启用</span>
					<span slot="close">停用</span>
				</i-switch>
			</FormItem>

			<FormItem label="耗材配方：" required>
				<Button type="primary" ghost @click="openConsumable">添加耗材</Button>
				<div class="tips">仅「可作为院装耗材」的商品可被选用；用量为「每核销1次」消耗的基本单位数量，支持最多4位小数。</div>
				<Table class="mt15" :columns="columns" :data="form.details" size="small" :border="true">
					<template slot-scope="{ row, index }" slot="qty">
						<InputNumber
							v-model="row.qty_per_writeoff"
							:min="0"
							:step="1"
							:precision="4"
							placeholder="单次用量"
							style="width: 140px"
						/>
					</template>
					<template slot-scope="{ row, index }" slot="action">
						<a class="danger" @click="removeDetail(index)">移除</a>
					</template>
				</Table>
			</FormItem>
		</Form>
		<div slot="footer">
			<Button @click="handleClose">取消</Button>
			<Button type="primary" class="ml14" :loading="saving" @click="submit">保存</Button>
		</div>
		<select-goods-box
			v-model="projectModal"
			:chooseType="96"
			:ischeckbox="false"
			@getProductId="onPickProject"
		></select-goods-box>
		<select-goods-box
			v-model="consumableModal"
			:chooseType="95"
			:ischeckbox="true"
			@getProductId="onPickConsumable"
		></select-goods-box>
	</Modal>
</template>

<script>
	import selectGoodsBox from '@/components/selectGoodsBox';
	import { salonRecipeInfoApi, salonRecipeSaveApi } from '@/api/salonRecipe';

	export default {
		name: 'salonRecipeFormModal',
		components: { selectGoodsBox },
		props: {
			value: { type: Boolean, default: false },
			editId: { type: Number, default: 0 }
		},
		data() {
			return {
				saving: false,
				projectModal: false,
				consumableModal: false,
				form: {
					project_product_id: 0,
					project_unique: '',
					project_name: '',
					project_spec: '',
					status: 1,
					details: []
				},
				columns: [
					{ title: '耗材', key: 'consumable_name', minWidth: 160 },
					{ title: '规格', key: 'consumable_spec', minWidth: 120 },
					{ title: '库存单位', key: 'stock_unit', width: 100 },
					{ title: '单次用量', slot: 'qty', width: 170 },
					{ title: '操作', slot: 'action', width: 90 }
				]
			};
		},
		computed: {
			isEdit() {
				return this.editId > 0;
			}
		},
		watch: {
			value(val) {
				if (val) {
					this.openForm();
				}
			}
		},
		methods: {
			handleClose() {
				this.$emit('input', false);
			},
			resetForm() {
				this.saving = false;
				this.projectModal = false;
				this.consumableModal = false;
				this.form = {
					project_product_id: 0,
					project_unique: '',
					project_name: '',
					project_spec: '',
					status: 1,
					details: []
				};
			},
			openForm() {
				this.resetForm();
				if (this.editId > 0) {
					this.loadInfo();
				}
			},
			loadInfo() {
				salonRecipeInfoApi(this.editId).then(res => {
					const d = res.data || {};
					this.form.project_product_id = d.project_product_id || 0;
					this.form.project_unique = d.project_unique || '';
					this.form.project_name = d.project_name || '';
					this.form.project_spec = d.project_unique || '';
					this.form.status = d.status == 0 ? 0 : 1;
					this.form.details = (d.details || []).map(item => ({
						consumable_product_id: item.consumable_product_id,
						consumable_unique: item.consumable_unique,
						consumable_name: item.consumable_name || '',
						consumable_spec: item.consumable_unique,
						stock_unit: item.stock_unit || '',
						qty_per_writeoff: Number(item.qty_per_writeoff) || 0
					}));
				}).catch(err => {
					this.$Message.error(err.msg || '加载失败');
				});
			},
			openProject() {
				this.projectModal = true;
			},
			openConsumable() {
				this.consumableModal = true;
			},
			onPickProject(list) {
				if (!list || !list.length) return;
				const p = list[0];
				this.form.project_product_id = p.product_id;
				this.form.project_unique = p.unique;
				this.form.project_name = p.store_names || '';
				this.form.project_spec = p.suk || p.unique;
			},
			onPickConsumable(list) {
				if (!list || !list.length) return;
				list.forEach(p => {
					const exist = this.form.details.some(
						d => d.consumable_product_id == p.product_id && d.consumable_unique == p.unique
					);
					if (exist) return;
					this.form.details.push({
						consumable_product_id: p.product_id,
						consumable_unique: p.unique,
						consumable_name: p.store_names || '',
						consumable_spec: p.suk || p.unique,
						stock_unit: p.stock_unit || '',
						qty_per_writeoff: 0
					});
				});
			},
			removeDetail(index) {
				this.form.details.splice(index, 1);
			},
			submit() {
				if (!this.form.project_product_id || !this.form.project_unique) {
					this.$Message.warning('请选择项目');
					return;
				}
				if (!this.form.details.length) {
					this.$Message.warning('请至少添加一项耗材');
					return;
				}
				for (const d of this.form.details) {
					if (!(Number(d.qty_per_writeoff) > 0)) {
						this.$Message.warning(`请填写「${d.consumable_name || d.consumable_unique}」的单次用量`);
						return;
					}
				}
				const payload = {
					project_product_id: this.form.project_product_id,
					project_unique: this.form.project_unique,
					status: this.form.status,
					details: this.form.details.map(d => ({
						consumable_product_id: d.consumable_product_id,
						consumable_unique: d.consumable_unique,
						qty_per_writeoff: d.qty_per_writeoff
					}))
				};
				this.saving = true;
				salonRecipeSaveApi(this.editId, payload).then(res => {
					this.saving = false;
					this.$Message.success(res.msg || '保存成功');
					this.$emit('success');
					this.handleClose();
				}).catch(err => {
					this.saving = false;
					this.$Message.error(err.msg || '保存失败');
				});
			}
		}
	};
</script>

<style scoped lang="less">
	.tips {
		margin-top: 6px;
		font-size: 12px;
		line-height: 18px;
		color: #999;
	}
	.picked {
		display: inline-flex;
		align-items: center;
	}
	.pk-name { font-weight: 500; }
	.pk-spec { margin-left: 10px; color: #666; }
	.ml14 { margin-left: 14px; }
	.mt15 { margin-top: 15px; }
	.danger { color: #ed4014; }
</style>
