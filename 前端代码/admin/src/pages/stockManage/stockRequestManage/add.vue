<template>
	<Modal
		:value="value"
		:title="editId > 0 ? '编辑请货单' : '新建请货单'"
		width="1274"
		:mask-closable="false"
		:styles="{ top: '40px' }"
		class-name="stock-request-form-modal"
		@on-cancel="handleClose"
	>
		<Form
			v-if="value"
			class="formValidate"
			ref="formValidate"
			:model="formValidate"
			:rules="ruleValidate"
			:label-width="110"
			label-position="right"
			@submit.native.prevent
		>
			<Row :gutter="24">
				<Col :xs="24" :sm="24" :md="16">
					<Row :gutter="16">
						<Col :xs="24" :sm="12">
							<FormItem label="请货门店：" prop="request_store_id">
								<Select
									v-model="formValidate.request_store_id"
									placeholder="请选择请货门店"
									filterable
									transfer
									@on-change="onRequestStoreChange"
								>
									<Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
								</Select>
							</FormItem>
						</Col>
						<Col :xs="24" :sm="12">
							<FormItem label="供货门店：" prop="supply_store_id">
								<Select
									v-model="formValidate.supply_store_id"
									placeholder="请选择供货门店"
									filterable
									transfer
									@on-change="onSupplyStoreChange"
								>
									<Option v-for="item in storeList" :value="item.id" :key="'s' + item.id">{{ item.name }}</Option>
								</Select>
							</FormItem>
						</Col>
					</Row>
					<Row :gutter="16">
						<Col :xs="24" :sm="12">
							<FormItem label="请货时间：" prop="request_date">
								<DatePicker
									v-model="formValidate.request_date"
									type="date"
									placeholder="请选择请货时间"
									class="w100"
									transfer
								/>
							</FormItem>
						</Col>
						<Col :xs="24" :sm="12">
							<FormItem label="请货人：" prop="request_staff_id">
								<Select
									v-model="formValidate.request_staff_id"
									placeholder="请选择请货门店员工"
									filterable
									clearable
									transfer
									:disabled="!formValidate.request_store_id"
									:loading="staffLoading"
								>
									<Option
										v-for="item in staffList"
										:value="item.id"
										:key="item.id"
									>{{ item.staff_name }}</Option>
								</Select>
							</FormItem>
						</Col>
					</Row>
					<FormItem label="备注：">
						<Input v-model="formValidate.remark" placeholder="请输入备注" />
					</FormItem>
					<FormItem v-if="createAdminName" label="创建人：">
						<Input :value="createAdminName" disabled />
					</FormItem>
				</Col>
				<Col :xs="24" :sm="24" :md="8">
					<Alert type="warning" show-icon class="tips-alert">
						操作注意事项
						<template slot="desc">
							<div>1. 确认申请只通知供货门店，不会变动库存；确认调拨后才会改双方库存。</div>
							<div>2. 只有输入申请数量的产品，才会有请货记录。</div>
						</template>
					</Alert>
				</Col>
			</Row>
			<FormItem label="请货商品：" required>
				<div class="acea-row row-middle mb10 sku-toolbar">
					<Input
						v-model="skuKeyword"
						placeholder="搜索商品名称/条码"
						class="input-add"
						@on-enter="searchSkus"
					/>
					<Button type="primary" class="ml14" @click="searchSkus">查询 <span class="enter-key">↵</span></Button>
					<Button class="ml14" :loading="skuLoading" @click="loadSharedSkus">加载同源商品</Button>
					<span class="sku-tip">加载请货门店和供货门店都有的产品资料的产品</span>
				</div>
				<Table :columns="skuColumns" :data="skuList" :loading="skuLoading" size="small" max-height="320">
					<template slot-scope="{ row, index }" slot="qty">
						<InputNumber
							:value="skuList[index] ? skuList[index].qty : 0"
							:min="0"
							:max="999999"
							:precision="0"
							class="priceBox"
							@on-change="val => onQtyChange(index, row, val)"
						/>
					</template>
					<template slot-scope="{ row }" slot="action">
						<a @click="removeSku(row)">移除</a>
					</template>
				</Table>
				<div class="acea-row row-right page" v-if="skuTotal > formValidate.limit">
					<Page
						:total="skuTotal"
						:current="formValidate.page"
						:page-size="formValidate.limit"
						@on-change="skuPageChange"
					/>
				</div>
			</FormItem>
		</Form>
		<div slot="footer">
			<Button @click="handleClose">取消</Button>
			<Button type="primary" class="ml14" :loading="saving" :disabled="confirming" @click="handleSubmit">保存草稿</Button>
			<Button type="success" class="ml14" :loading="confirming" :disabled="saving" @click="handleConfirmApply">确认申请</Button>
		</div>
	</Modal>
</template>

<script>
	import { merchantStoreListApi, merchantStaffSelect } from '@/api/setting';
	import {
		stockRequestSaveApi,
		stockRequestInfoApi,
		stockRequestSharedSkusApi,
		stockRequestConfirmApi
	} from '@/api/stockRequestTransfer';

	export default {
		name: 'stockRequestFormModal',
		props: {
			value: { type: Boolean, default: false },
			editId: { type: Number, default: 0 }
		},
		data() {
			return {
				storeList: [],
				staffList: [],
				staffLoading: false,
				skuKeyword: '',
				skuList: [],
				skuTotal: 0,
				skuLoading: false,
				saving: false,
				confirming: false,
				selectedMap: {},
				createAdminName: '',
				formValidate: {
					request_store_id: null,
					supply_store_id: null,
					request_staff_id: null,
					request_date: new Date(),
					remark: '',
					page: 1,
					limit: 50
				},
				ruleValidate: {
					request_store_id: [{ required: true, type: 'number', message: '请选择请货门店', trigger: 'change' }],
					supply_store_id: [{ required: true, type: 'number', message: '请选择供货门店', trigger: 'change' }],
					request_staff_id: [{ required: true, type: 'number', message: '请选择请货人', trigger: 'change' }],
					request_date: [{ required: true, type: 'date', message: '请选择请货时间', trigger: 'change' }]
				},
				skuColumns: [
					{ title: '商品', key: 'product_name', minWidth: 140 },
					{ title: '规格', key: 'suk', minWidth: 90 },
					{ title: '单位', key: 'stock_unit', width: 70 },
					{ title: '请货店库存', key: 'store_a_stock', width: 100 },
					{ title: '供货店库存', key: 'store_b_stock', width: 100 },
					{ title: '申请数量', slot: 'qty', width: 120 },
					{ title: '操作', slot: 'action', width: 70 }
				]
			};
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
				this.skuKeyword = '';
				this.skuList = [];
				this.skuTotal = 0;
				this.selectedMap = {};
				this.staffList = [];
				this.createAdminName = '';
				this.formValidate = {
					request_store_id: null,
					supply_store_id: null,
					request_staff_id: null,
					request_date: new Date(),
					remark: '',
					page: 1,
					limit: 50
				};
				this.$nextTick(() => {
					this.$refs.formValidate && this.$refs.formValidate.resetFields();
				});
			},
			openForm() {
				this.resetForm();
				this.getStores();
				if (this.editId > 0) {
					this.loadInfo();
				}
			},
			getStores() {
				merchantStoreListApi().then(res => {
					this.storeList = res.data || [];
				}).catch(err => {
					this.$Message.error(err.msg || '门店列表加载失败');
				});
			},
			loadStaff(storeId, keepStaffId) {
				if (!storeId) {
					this.staffList = [];
					this.formValidate.request_staff_id = null;
					return Promise.resolve();
				}
				this.staffLoading = true;
				return merchantStaffSelect({ store_id: storeId }).then(res => {
					this.staffList = this.normalizeStaffList(res.data);
					const keep = Number(keepStaffId || 0);
					const exists = this.staffList.some(item => Number(item.id) === keep);
					this.formValidate.request_staff_id = exists ? keep : null;
					this.staffLoading = false;
				}).catch(err => {
					this.staffLoading = false;
					this.staffList = [];
					this.formValidate.request_staff_id = null;
					this.$Message.error(err.msg || '员工列表加载失败');
				});
			},
			normalizeStaffList(raw) {
				const list = Array.isArray(raw) ? raw : ((raw && raw.list) || []);
				return list.map(item => ({
					id: Number(item.value != null ? item.value : item.id),
					staff_name: item.label || item.staff_name || ''
				})).filter(item => item.id > 0);
			},
			onRequestStoreChange(val) {
				this.skuList = [];
				this.selectedMap = {};
				this.skuTotal = 0;
				this.loadStaff(val);
			},
			onSupplyStoreChange() {
				this.skuList = [];
				this.selectedMap = {};
				this.skuTotal = 0;
			},
			parseRequestDate(value) {
				if (!value) return '';
				if (value instanceof Date) {
					const y = value.getFullYear();
					const m = `${value.getMonth() + 1}`.padStart(2, '0');
					const d = `${value.getDate()}`.padStart(2, '0');
					return `${y}-${m}-${d}`;
				}
				return value;
			},
			loadInfo() {
				stockRequestInfoApi(this.editId).then(res => {
					const data = res.data || {};
					this.formValidate.request_store_id = data.request_store_id;
					this.formValidate.supply_store_id = data.supply_store_id;
					this.formValidate.remark = data.remark || '';
					this.formValidate.request_date = data.request_date ? new Date(data.request_date) : new Date();
					this.createAdminName = data.create_admin_name || '';
					const details = data.details || [];
					this.skuList = details.map(d => ({
						pid: d.pid,
						suk: d.suk,
						product_name: d.product_name,
						stock_unit: d.stock_unit,
						store_a_stock: '-',
						store_b_stock: '-',
						qty: Number(d.qty) || 0
					}));
					details.forEach(d => {
						this.selectedMap[`${d.pid}|${d.suk}`] = Number(d.qty) || 0;
					});
					this.loadStaff(data.request_store_id, data.request_staff_id);
				}).catch(err => {
					this.$Message.error(err.msg || '加载详情失败');
				});
			},
			searchSkus() {
				this.formValidate.page = 1;
				this.loadSharedSkus();
			},
			loadSharedSkus() {
				const a = this.formValidate.request_store_id;
				const b = this.formValidate.supply_store_id;
				if (!a || !b) {
					return this.$Message.warning('请先选择请货门店和供货门店');
				}
				if (a === b) {
					return this.$Message.warning('请货门店与供货门店不能相同');
				}
				this.skuLoading = true;
				stockRequestSharedSkusApi({
					store_a: a,
					store_b: b,
					keyword: this.skuKeyword,
					page: this.formValidate.page,
					limit: this.formValidate.limit
				}).then(res => {
					const list = res.data.list || [];
					this.skuTotal = res.data.count || 0;
					this.skuList = list.map(item => {
						const key = `${item.pid}|${item.suk}`;
						return {
							...item,
							qty: this.selectedMap[key] != null ? this.selectedMap[key] : 0
						};
					});
					this.skuLoading = false;
				}).catch(err => {
					this.skuLoading = false;
					this.$Message.error(err.msg || '加载同源商品失败');
				});
			},
			skuPageChange(page) {
				this.cacheQty();
				this.formValidate.page = page;
				this.loadSharedSkus();
			},
			onQtyChange(index, row, val) {
				const qty = Number(val) || 0;
				if (this.skuList[index]) {
					this.$set(this.skuList[index], 'qty', qty);
				}
				const key = `${row.pid}|${row.suk}`;
				if (qty > 0) {
					this.$set(this.selectedMap, key, qty);
				} else {
					this.$delete(this.selectedMap, key);
				}
			},
			cacheQty() {
				this.skuList.forEach(row => {
					const key = `${row.pid}|${row.suk}`;
					const qty = Number(row.qty) || 0;
					if (qty > 0) {
						this.$set(this.selectedMap, key, qty);
					} else {
						this.$delete(this.selectedMap, key);
					}
				});
			},
			removeSku(row) {
				const key = `${row.pid}|${row.suk}`;
				this.$delete(this.selectedMap, key);
				this.skuList = this.skuList.filter(item => `${item.pid}|${item.suk}` !== key);
			},
			buildDetails() {
				this.cacheQty();
				const details = [];
				Object.keys(this.selectedMap).forEach(key => {
					const [pid, suk] = key.split('|');
					const qty = Number(this.selectedMap[key]) || 0;
					if (qty > 0) {
						details.push({ pid: Number(pid), suk, qty });
					}
				});
				return details;
			},
			validateBeforeSave() {
				if (this.formValidate.request_store_id === this.formValidate.supply_store_id) {
					this.$Message.warning('请货门店与供货门店不能相同');
					return null;
				}
				if (!this.formValidate.request_staff_id) {
					this.$Message.warning('请选择请货人');
					return null;
				}
				const finalDetails = this.buildDetails();
				if (!finalDetails.length) {
					this.$Message.warning('请至少填写一项申请数量');
					return null;
				}
				return finalDetails;
			},
			saveDraft(finalDetails) {
				return stockRequestSaveApi(this.editId || 0, {
					request_store_id: this.formValidate.request_store_id,
					supply_store_id: this.formValidate.supply_store_id,
					request_staff_id: this.formValidate.request_staff_id,
					request_date: this.parseRequestDate(this.formValidate.request_date),
					remark: this.formValidate.remark,
					details: finalDetails
				}).then(res => {
					const id = (res.data && res.data.id) || this.editId;
					return { res, id: Number(id) };
				});
			},
			handleSubmit() {
				this.$refs.formValidate.validate(valid => {
					if (!valid) return;
					const finalDetails = this.validateBeforeSave();
					if (!finalDetails) return;
					this.saving = true;
					this.saveDraft(finalDetails).then(({ res }) => {
						this.saving = false;
						this.$Message.success(res.msg || '保存成功');
						this.handleClose();
						this.$emit('success');
					}).catch(err => {
						this.saving = false;
						this.$Message.error(err.msg || '保存失败');
					});
				});
			},
			handleConfirmApply() {
				this.$refs.formValidate.validate(valid => {
					if (!valid) return;
					const finalDetails = this.validateBeforeSave();
					if (!finalDetails) return;
					this.$Modal.confirm({
						title: '确认申请',
						content: '将先保存草稿并确认申请，通知供货门店，不会变动库存。确定继续？',
						onOk: () => {
							this.confirming = true;
							return this.saveDraft(finalDetails).then(({ id }) => {
								if (!id) {
									throw { msg: '保存成功但未返回单据ID' };
								}
								return stockRequestConfirmApi(id);
							}).then(res => {
								this.confirming = false;
								this.$Message.success(res.msg || '已确认申请');
								this.handleClose();
								this.$emit('success');
							}).catch(err => {
								this.confirming = false;
								this.$Message.error((err && err.msg) || '操作失败');
								return Promise.reject(err);
							});
						}
					});
				});
			}
		}
	};
</script>

<style scoped lang="less">
	.w100 { width: 100%; }
	.mb10 { margin-bottom: 10px; }
	.ml14 { margin-left: 14px; }
	.page { margin-top: 12px; }
	.priceBox { width: 100px; }
	.tips-alert { margin-bottom: 12px; }
	.sku-toolbar { flex-wrap: wrap; }
	.enter-key { margin-left: 2px; font-weight: 600; }
	.sku-tip {
		margin-left: 10px;
		color: #999;
		font-size: 12px;
		line-height: 1.4;
	}
</style>
