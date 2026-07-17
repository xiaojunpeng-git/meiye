<template>
	<Modal
		:value="value"
		:title="pageTitle"
		width="1274"
		:mask-closable="false"
		:styles="{ top: '40px' }"
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
					<FormItem label="来源：" v-if="requestId > 0">
						<span>请货转调拨（请货单ID：{{ requestId }}）</span>
					</FormItem>
					<Row :gutter="16">
						<Col :xs="24" :sm="12">
							<FormItem label="调出方：" prop="from_select">
								<Select
									v-model="formValidate.from_select"
									placeholder="请选择调出门店或总部仓"
									filterable
									transfer
									:disabled="requestId > 0 || editId > 0"
									@on-change="onFromSelectChange"
								>
									<Option value="hq" key="hq">总部仓</Option>
									<Option v-for="item in storeList" :value="'s' + item.id" :key="'f' + item.id">{{ item.name }}</Option>
								</Select>
							</FormItem>
						</Col>
						<Col :xs="24" :sm="12">
							<FormItem label="调入门店：" prop="to_store_id">
								<Select
									v-model="formValidate.to_store_id"
									placeholder="请选择调入门店"
									filterable
									transfer
									:disabled="requestId > 0 || editId > 0"
									@on-change="onStoreChange"
								>
									<Option v-for="item in storeList" :value="item.id" :key="'t' + item.id">{{ item.name }}</Option>
								</Select>
							</FormItem>
						</Col>
					</Row>
					<Row :gutter="16">
						<Col :xs="24" :sm="12">
							<FormItem label="调拨人：" prop="transfer_staff_id">
								<Select
									v-model="formValidate.transfer_staff_id"
									placeholder="请选择调出/调入门店员工"
									filterable
									clearable
									transfer
									:disabled="!formValidate.from_select || !formValidate.to_store_id"
									:loading="staffLoading"
								>
									<Option v-for="item in staffList" :value="item.id" :key="item.id">{{ item.staff_name }}</Option>
								</Select>
							</FormItem>
						</Col>
						<Col :xs="24" :sm="12">
							<FormItem label="调拨时间：" prop="transfer_date">
								<DatePicker
									v-model="formValidate.transfer_date"
									type="date"
									placeholder="请选择调拨时间"
									class="w100"
									transfer
								/>
							</FormItem>
						</Col>
					</Row>
					<FormItem v-if="createAdminName" label="创建人：">
						<Input :value="createAdminName" disabled />
					</FormItem>
					<FormItem label="备注：">
						<Input v-model="formValidate.remark" placeholder="请输入备注" />
					</FormItem>
				</Col>
				<Col :xs="24" :sm="24" :md="8">
					<Alert type="warning" show-icon class="tips-alert">
						操作注意事项
						<template slot="desc">
							<div>1. 确认调拨才会改库存；保存草稿不会动库存。</div>
							<div>2. 只有输入调拨数量的产品，才会有调拨记录。</div>
						</template>
					</Alert>
				</Col>
			</Row>
			<FormItem :label="requestId > 0 ? '调拨明细：' : '调拨商品：'" required>
				<div class="acea-row row-middle mb10 sku-toolbar" v-if="requestId <= 0">
					<Input
						v-model="skuKeyword"
						placeholder="搜索商品名称/条码"
						class="input-add"
						@on-enter="searchSkus"
					/>
					<Button type="primary" class="ml14" @click="searchSkus">查询 <span class="enter-key">↵</span></Button>
					<Button class="ml14" :loading="skuLoading" @click="loadSharedSkus">加载同源商品</Button>
					<span class="sku-tip">加载调出方（门店或总部仓）与调入门店同源商品</span>
				</div>
				<Table :columns="skuColumns" :data="skuList" :loading="skuLoading" size="small" max-height="320">
					<template slot-scope="{ row, index }" slot="qty">
						<InputNumber
							:value="skuList[index] ? skuList[index].qty : 0"
							:min="0"
							:max="row.max_qty != null ? Number(row.max_qty) : 999999"
							:precision="qtyPrecision(row)"
							:step="qtyPrecision(row) > 0 ? 0.01 : 1"
							class="priceBox"
							@on-change="val => onQtyChange(index, row, val)"
						/>
					</template>
					<template slot-scope="{ row }" slot="action" v-if="innerRequestId <= 0">
						<a @click="removeSku(row)">移除</a>
					</template>
				</Table>
				<div class="acea-row row-right page" v-if="requestId <= 0 && skuTotal > formValidate.limit">
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
			<Button class="ml14" :loading="saving" :disabled="confirming" @click="handleSubmit(false)">保存草稿</Button>
			<Button type="primary" class="ml14" :loading="confirming" :disabled="saving" @click="handleSubmit(true)">确认调拨</Button>
		</div>
	</Modal>
</template>

<script>
	import { merchantStoreListApi, merchantStaffSelect } from '@/api/setting';
	import {
		stockTransferSaveApi,
		stockTransferInfoApi,
		stockTransferConfirmApi,
		stockRequestInfoApi,
		stockRequestSharedSkusApi
	} from '@/api/stockRequestTransfer';

	export default {
		name: 'stockTransferFormModal',
		props: {
			value: { type: Boolean, default: false },
			editId: { type: Number, default: 0 },
			requestId: { type: Number, default: 0 }
		},
		data() {
			return {
				innerRequestId: 0,
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
					from_select: null,
					from_party_type: 'store',
					from_store_id: 0,
					to_store_id: null,
					to_party_type: 'store',
					transfer_staff_id: null,
					transfer_date: new Date(),
					remark: '',
					page: 1,
					limit: 50
				},
				ruleValidate: {
					from_select: [{ required: true, type: 'string', message: '请选择调出方', trigger: 'change' }],
					to_store_id: [{ required: true, type: 'number', message: '请选择调入门店', trigger: 'change' }],
					transfer_staff_id: [{ required: true, type: 'number', message: '请选择调拨人', trigger: 'change' }],
					transfer_date: [{ required: true, type: 'date', message: '请选择调拨时间', trigger: 'change' }]
				}
			};
		},
		computed: {
			pageTitle() {
				if (this.innerRequestId > 0) return '请货转调拨';
				return this.editId > 0 ? '编辑调拨单' : '新建自由调拨';
			},
			skuColumns() {
				const cols = [
					{ title: '商品', key: 'product_name', minWidth: 140 },
					{ title: '规格', key: 'suk', minWidth: 90 },
					{ title: '单位', key: 'stock_unit', width: 70 }
				];
				if (this.innerRequestId > 0) {
					cols.push(
						{ title: '申请数量', key: 'req_qty', width: 90 },
						{ title: '已调拨', key: 'transferred_qty', width: 90 },
						{ title: '剩余', key: 'remain_qty', width: 90 }
					);
				} else {
					cols.push(
						{ title: '调出方库存', key: 'store_a_stock', width: 100 },
						{ title: '调入店库存', key: 'store_b_stock', width: 100 }
					);
				}
				cols.push({ title: '调拨数量', slot: 'qty', width: 120 });
				if (this.innerRequestId <= 0) {
					cols.push({ title: '操作', slot: 'action', width: 70 });
				}
				return cols;
			}
		},
		watch: {
			value(val) {
				if (val) this.openForm();
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
				this.innerRequestId = Number(this.requestId) || 0;
				this.formValidate = {
					from_select: null,
					from_party_type: 'store',
					from_store_id: 0,
					to_store_id: null,
					to_party_type: 'store',
					transfer_staff_id: null,
					transfer_date: new Date(),
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
				} else if (this.innerRequestId > 0) {
					this.loadFromRequest();
				}
			},
			getStores() {
				merchantStoreListApi().then(res => {
					this.storeList = res.data || [];
				}).catch(err => {
					this.$Message.error(err.msg || '门店列表加载失败');
				});
			},
			applyFromParty(partyType, storeId) {
				const party = partyType === 'hq' ? 'hq' : 'store';
				this.formValidate.from_party_type = party;
				this.formValidate.from_store_id = party === 'hq' ? 0 : (Number(storeId) || 0);
				this.formValidate.from_select = party === 'hq' ? 'hq' : ('s' + this.formValidate.from_store_id);
			},
			onFromSelectChange(val) {
				if (val === 'hq') {
					this.applyFromParty('hq', 0);
				} else if (val && String(val).indexOf('s') === 0) {
					this.applyFromParty('store', String(val).slice(1));
				} else {
					this.applyFromParty('store', 0);
					this.formValidate.from_select = null;
				}
				this.onStoreChange();
			},
			loadStaff(keepStaffId) {
				const fromParty = this.formValidate.from_party_type || 'store';
				const a = fromParty === 'hq' ? 0 : (Number(this.formValidate.from_store_id) || 0);
				const b = Number(this.formValidate.to_store_id) || 0;
				if (!b || (fromParty === 'store' && !a)) {
					this.staffList = [];
					this.formValidate.transfer_staff_id = null;
					return Promise.resolve();
				}
				this.staffLoading = true;
				const storeIds = fromParty === 'hq' ? [b] : Array.from(new Set([a, b]));
				return Promise.all(storeIds.map(storeId =>
					merchantStaffSelect({ store_id: storeId }).then(res => this.normalizeStaffList(res.data)).catch(() => [])
				)).then(lists => {
					const map = {};
					lists.flat().forEach(item => {
						if (item && item.id) map[item.id] = item;
					});
					this.staffList = Object.values(map);
					const keep = Number(keepStaffId || 0);
					const exists = this.staffList.some(item => Number(item.id) === keep);
					this.formValidate.transfer_staff_id = exists ? keep : null;
					this.staffLoading = false;
				}).catch(() => {
					this.staffLoading = false;
					this.staffList = [];
					this.formValidate.transfer_staff_id = null;
				});
			},
			normalizeStaffList(raw) {
				const list = Array.isArray(raw) ? raw : ((raw && raw.list) || []);
				return list.map(item => ({
					id: Number(item.value != null ? item.value : item.id),
					staff_name: item.label || item.staff_name || ''
				})).filter(item => item.id > 0);
			},
			onStoreChange() {
				if (this.innerRequestId > 0) return;
				this.skuList = [];
				this.selectedMap = {};
				this.skuTotal = 0;
				this.loadStaff();
			},
			loadInfo() {
				stockTransferInfoApi(this.editId).then(res => {
					const data = res.data || {};
					this.innerRequestId = Number(data.request_id || 0);
					this.applyFromParty(data.from_party_type, data.from_store_id);
					this.formValidate.to_store_id = data.to_store_id;
					this.formValidate.to_party_type = 'store';
					this.formValidate.remark = data.remark || '';
					this.formValidate.transfer_date = data.transfer_date ? new Date(data.transfer_date) : new Date();
					this.createAdminName = data.create_admin_name || '';
					if (this.innerRequestId > 0) {
						this.loadFromRequest(true, data.transfer_staff_id);
					} else {
						this.skuList = (data.details || []).map(d => ({
							pid: d.pid,
							suk: d.suk,
							product_name: d.product_name || `商品${d.pid}`,
							stock_unit: d.stock_unit,
							store_a_stock: '-',
							store_b_stock: '-',
							qty: Number(d.qty) || 0
						}));
						this.skuList.forEach(d => {
							this.selectedMap[`${d.pid}|${d.suk}`] = Number(d.qty) || 0;
						});
						this.loadStaff(data.transfer_staff_id);
					}
				}).catch(err => {
					this.$Message.error(err.msg || '加载详情失败');
				});
			},
			loadFromRequest(keepQty, keepStaffId) {
				const rid = this.innerRequestId;
				stockRequestInfoApi(rid).then(res => {
					const data = res.data || {};
					this.applyFromParty(data.supply_party_type, data.supply_store_id);
					this.formValidate.to_store_id = data.request_store_id;
					this.formValidate.to_party_type = 'store';
					if (!keepQty && !this.formValidate.remark) {
						this.formValidate.remark = `请货转调拨：${data.order_sn || rid}`;
					}
					const oldMap = { ...this.selectedMap };
					this.skuList = (data.details || []).map(d => {
						const remain = Number(d.remain_qty != null ? d.remain_qty : (Number(d.qty) - Number(d.transferred_qty || 0)));
						const key = `${d.pid}|${d.suk}`;
						let qty = remain > 0 ? remain : 0;
						if (keepQty && oldMap[key] != null) qty = oldMap[key];
						else if (this.editId > 0 && oldMap[key] != null) qty = oldMap[key];
						return {
							pid: d.pid,
							suk: d.suk,
							product_name: d.product_name,
							stock_unit: d.stock_unit,
							request_detail_id: d.id,
							req_qty: d.qty,
							transferred_qty: d.transferred_qty,
							remain_qty: remain,
							max_qty: remain,
							qty
						};
					}).filter(d => Number(d.remain_qty) > 0 || (keepQty && Number(d.qty) > 0));
					this.loadStaff(keepStaffId);
					if (this.editId > 0) {
						stockTransferInfoApi(this.editId).then(tr => {
							const details = (tr.data && tr.data.details) || [];
							details.forEach(d => {
								this.selectedMap[`${d.pid}|${d.suk}`] = Number(d.qty) || 0;
							});
							this.skuList.forEach(row => {
								const key = `${row.pid}|${row.suk}`;
								if (this.selectedMap[key] != null) row.qty = this.selectedMap[key];
							});
							if (!keepStaffId && tr.data) {
								this.loadStaff(tr.data.transfer_staff_id);
							}
						});
					}
				}).catch(err => {
					this.$Message.error(err.msg || '加载请货单失败');
				});
			},
			searchSkus() {
				this.formValidate.page = 1;
				this.loadSharedSkus();
			},
			loadSharedSkus() {
				const fromParty = this.formValidate.from_party_type || 'store';
				const a = fromParty === 'hq' ? 0 : this.formValidate.from_store_id;
				const b = this.formValidate.to_store_id;
				if (!b || (fromParty === 'store' && !a)) {
					return this.$Message.warning('请先选择调出方和调入门店');
				}
				if (fromParty === 'store' && a === b) {
					return this.$Message.warning('调出门店与调入门店不能相同');
				}
				this.skuLoading = true;
				stockRequestSharedSkusApi({
					store_a: a,
					store_b: b,
					party_a: fromParty,
					party_b: 'store',
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
							product_name: item.product_name,
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
			qtyPrecision(row) {
				return Number(row && row.decimal_scale) > 0 ? 2 : 0;
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
				if (this.innerRequestId > 0) {
					return this.skuList
						.filter(row => Number(row.qty) > 0)
						.map(row => ({
							request_detail_id: row.request_detail_id,
							pid: row.pid,
							suk: row.suk,
							qty: Number(row.qty)
						}));
				}
				const uniq = {};
				this.skuList.forEach(row => {
					const qty = Number(row.qty) || 0;
					if (qty > 0) {
						uniq[`${row.pid}|${row.suk}`] = { pid: Number(row.pid), suk: row.suk, qty };
					}
				});
				Object.keys(this.selectedMap).forEach(key => {
					const qty = Number(this.selectedMap[key]) || 0;
					if (qty > 0) {
						const [pid, suk] = key.split('|');
						uniq[key] = { pid: Number(pid), suk, qty };
					}
				});
				return Object.values(uniq);
			},
			parseTransferDate(value) {
				if (!value) return '';
				if (value instanceof Date) {
					const y = value.getFullYear();
					const m = `${value.getMonth() + 1}`.padStart(2, '0');
					const d = `${value.getDate()}`.padStart(2, '0');
					return `${y}-${m}-${d}`;
				}
				return value;
			},
			saveDraft(details) {
				const fromParty = this.formValidate.from_party_type || 'store';
				return stockTransferSaveApi(this.editId || 0, {
					request_id: this.innerRequestId || 0,
					from_store_id: fromParty === 'hq' ? 0 : this.formValidate.from_store_id,
					from_party_type: fromParty,
					to_store_id: this.formValidate.to_store_id,
					to_party_type: 'store',
					transfer_staff_id: this.formValidate.transfer_staff_id,
					transfer_date: this.parseTransferDate(this.formValidate.transfer_date),
					remark: this.formValidate.remark,
					details
				}).then(res => {
					const id = (res.data && res.data.id) || this.editId;
					return { res, id: Number(id) };
				});
			},
			handleSubmit(andConfirm) {
				this.$refs.formValidate.validate(valid => {
					if (!valid) return;
					const fromParty = this.formValidate.from_party_type || 'store';
					if (fromParty === 'store' && this.formValidate.from_store_id === this.formValidate.to_store_id) {
						return this.$Message.warning('调出门店与调入门店不能相同');
					}
					const details = this.buildDetails();
					if (!details.length) {
						return this.$Message.warning('请至少填写一项调拨数量');
					}
					const run = () => {
						const loadingKey = andConfirm ? 'confirming' : 'saving';
						this[loadingKey] = true;
						return this.saveDraft(details).then(({ res, id }) => {
							if (andConfirm && id) {
								return stockTransferConfirmApi(id).then(cres => {
									this[loadingKey] = false;
									this.$Message.success(cres.msg || '确认调拨成功');
									this.handleClose();
									this.$emit('success');
								});
							}
							this[loadingKey] = false;
							this.$Message.success(res.msg || '保存成功');
							this.handleClose();
							this.$emit('success');
						}).catch(err => {
							this[loadingKey] = false;
							this.$Message.error(err.msg || '操作失败');
							return Promise.reject(err);
						});
					};
					if (andConfirm) {
						this.$Modal.confirm({
							title: '确认调拨',
							content: '将先保存草稿并确认调拨，确认后将立即调整双方库存（总部仓或门店）。确定继续？',
							onOk: () => run()
						});
					} else {
						run();
					}
				});
			}
		}
	};
</script>

<style scoped lang="less">
	.mb10 { margin-bottom: 10px; }
	.ml14 { margin-left: 14px; }
	.w100 { width: 100%; }
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
