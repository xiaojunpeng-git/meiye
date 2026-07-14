<template>
	<div class="form-submit">
		<div class="i-layout-page-header">
			<PageHeader class="product_tabs" hidden-breadcrumb>
				<div slot="title">
					<router-link :to="{ path: `${roterPre}/stock/transfer` }">
						<div class="font-sm after-line">
							<span class="iconfont iconfanhui"></span>
							<span class="pl10">返回</span>
						</div>
					</router-link>
					<span class="mr20 ml16">{{ pageTitle }}</span>
				</div>
			</PageHeader>
		</div>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Alert type="warning" show-icon>
				操作注意事项
				<template slot="desc">
					<div>1. 确认调拨才会改库存；保存草稿不会动库存。</div>
					<div>2. 双方门店须已有同源商品；调出门店与调入门店不能相同。</div>
					<div>3. 请货转调拨：数量不能超过请货剩余；自由调拨需选择双方同源规格。</div>
					<div>4. 冲销只能从已确认原单发起，不能冲销冲销单。</div>
				</template>
			</Alert>
			<Form
				class="formValidate mt20"
				ref="formValidate"
				:model="formValidate"
				:rules="ruleValidate"
				:label-width="labelWidth"
				:label-position="labelPosition"
				@submit.native.prevent
			>
				<FormItem label="来源：" v-if="requestId > 0">
					<span>请货转调拨（请货单ID：{{ requestId }}）</span>
				</FormItem>
				<FormItem label="调出门店：" prop="from_store_id">
					<Select
						v-model="formValidate.from_store_id"
						placeholder="请选择调出门店"
						filterable
						class="w50"
						:disabled="requestId > 0 || editId > 0"
						@on-change="onStoreChange"
					>
						<Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
					</Select>
				</FormItem>
				<FormItem label="调入门店：" prop="to_store_id">
					<Select
						v-model="formValidate.to_store_id"
						placeholder="请选择调入门店"
						filterable
						class="w50"
						:disabled="requestId > 0 || editId > 0"
						@on-change="onStoreChange"
					>
						<Option v-for="item in storeList" :value="item.id" :key="'t' + item.id">{{ item.name }}</Option>
					</Select>
				</FormItem>
				<FormItem label="备注：">
					<Input v-model="formValidate.remark" placeholder="请输入备注" class="w50" />
				</FormItem>
				<FormItem :label="requestId > 0 ? '调拨明细：' : '调拨商品：'" required>
					<div class="acea-row row-middle mb10" v-if="requestId <= 0">
						<Input
							v-model="skuKeyword"
							placeholder="搜索商品名称/条码"
							class="input-add"
							@on-enter="loadSharedSkus"
						/>
						<Button type="primary" class="ml14" @click="loadSharedSkus">加载同源商品</Button>
					</div>
					<Table :columns="skuColumns" :data="skuList" :loading="skuLoading" size="small" max-height="420">
						<template slot-scope="{ row }" slot="qty">
							<InputNumber
								v-model="row.qty"
								:min="0"
								:max="row.max_qty != null ? Number(row.max_qty) : 999999"
								:precision="0"
								class="priceBox"
							/>
						</template>
						<template slot-scope="{ row }" slot="action" v-if="requestId <= 0">
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
		</Card>
		<Card
			:bordered="false"
			dis-hover
			class="fixed-card"
			:style="{ left: `${!menuCollapse ? '236px' : isMobile ? '0' : '60px'}` }"
		>
			<Form>
				<FormItem>
					<Button :loading="saving" @click="handleSubmit(false)">保存草稿</Button>
					<Button type="primary" class="ml-10" :loading="saving" @click="handleSubmit(true)">保存并确认</Button>
				</FormItem>
			</Form>
		</Card>
	</div>
</template>

<script>
	import { mapState } from 'vuex';
	import Setting from '@/setting';
	import { merchantStoreListApi } from '@/api/setting';
	import {
		stockTransferSaveApi,
		stockTransferInfoApi,
		stockTransferConfirmApi,
		stockRequestInfoApi,
		stockRequestSharedSkusApi
	} from '@/api/stockRequestTransfer';

	export default {
		name: 'stockTransferAdd',
		data() {
			return {
				roterPre: Setting.roterPre,
				editId: 0,
				requestId: 0,
				storeList: [],
				skuKeyword: '',
				skuList: [],
				skuTotal: 0,
				skuLoading: false,
				saving: false,
				selectedMap: {},
				formValidate: {
					from_store_id: '',
					to_store_id: '',
					remark: '',
					page: 1,
					limit: 50
				},
				ruleValidate: {
					from_store_id: [{ required: true, type: 'number', message: '请选择调出门店', trigger: 'change' }],
					to_store_id: [{ required: true, type: 'number', message: '请选择调入门店', trigger: 'change' }]
				}
			};
		},
		computed: {
			...mapState('admin/layout', ['isMobile', 'menuCollapse']),
			labelWidth() {
				return this.isMobile ? undefined : 110;
			},
			labelPosition() {
				return this.isMobile ? 'top' : 'right';
			},
			pageTitle() {
				if (this.requestId > 0) return '请货转调拨';
				return this.editId > 0 ? '编辑调拨单' : '新建自由调拨';
			},
			skuColumns() {
				const cols = [
					{ title: '商品', key: 'product_name', minWidth: 150 },
					{ title: '规格', key: 'suk', minWidth: 100 },
					{ title: '单位', key: 'stock_unit', width: 80 }
				];
				if (this.requestId > 0) {
					cols.push(
						{ title: '申请数量', key: 'req_qty', width: 90 },
						{ title: '已调拨', key: 'transferred_qty', width: 90 },
						{ title: '剩余', key: 'remain_qty', width: 90 }
					);
				} else {
					cols.push(
						{ title: '调出店库存', key: 'store_a_stock', width: 110 },
						{ title: '调入店库存', key: 'store_b_stock', width: 110 }
					);
				}
				cols.push({ title: '调拨数量', slot: 'qty', width: 130 });
				if (this.requestId <= 0) {
					cols.push({ title: '操作', slot: 'action', width: 80 });
				}
				return cols;
			}
		},
		created() {
			this.editId = Number(this.$route.params.id || 0);
			this.requestId = Number(this.$route.query.request_id || 0);
			this.getStores();
			if (this.editId > 0) {
				this.loadInfo();
			} else if (this.requestId > 0) {
				this.loadFromRequest();
			}
		},
		methods: {
			getStores() {
				merchantStoreListApi().then(res => {
					this.storeList = res.data || [];
				}).catch(err => {
					this.$Message.error(err.msg || '门店列表加载失败');
				});
			},
			onStoreChange() {
				if (this.requestId > 0) return;
				this.skuList = [];
				this.selectedMap = {};
				this.skuTotal = 0;
			},
			loadInfo() {
				stockTransferInfoApi(this.editId).then(res => {
					const data = res.data || {};
					this.requestId = Number(data.request_id || 0);
					this.formValidate.from_store_id = data.from_store_id;
					this.formValidate.to_store_id = data.to_store_id;
					this.formValidate.remark = data.remark || '';
					if (this.requestId > 0) {
						this.loadFromRequest(true);
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
					}
				}).catch(err => {
					this.$Message.error(err.msg || '加载详情失败');
				});
			},
			loadFromRequest(keepQty) {
				const rid = this.requestId;
				stockRequestInfoApi(rid).then(res => {
					const data = res.data || {};
					this.formValidate.from_store_id = data.supply_store_id;
					this.formValidate.to_store_id = data.request_store_id;
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
						});
					}
				}).catch(err => {
					this.$Message.error(err.msg || '加载请货单失败');
				});
			},
			loadSharedSkus() {
				const a = this.formValidate.from_store_id;
				const b = this.formValidate.to_store_id;
				if (!a || !b) {
					return this.$Message.warning('请先选择调出和调入门店');
				}
				if (a === b) {
					return this.$Message.warning('调出门店与调入门店不能相同');
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
			cacheQty() {
				this.skuList.forEach(row => {
					const key = `${row.pid}|${row.suk}`;
					if (Number(row.qty) > 0) {
						this.selectedMap[key] = Number(row.qty);
					} else {
						delete this.selectedMap[key];
					}
				});
			},
			removeSku(row) {
				const key = `${row.pid}|${row.suk}`;
				delete this.selectedMap[key];
				this.skuList = this.skuList.filter(item => `${item.pid}|${item.suk}` !== key);
			},
			buildDetails() {
				this.cacheQty();
				if (this.requestId > 0) {
					return this.skuList
						.filter(row => Number(row.qty) > 0)
						.map(row => ({
							request_detail_id: row.request_detail_id,
							pid: row.pid,
							suk: row.suk,
							qty: Number(row.qty)
						}));
				}
				const details = [];
				Object.keys(this.selectedMap).forEach(key => {
					const [pid, suk] = key.split('|');
					const qty = this.selectedMap[key];
					if (qty > 0) details.push({ pid: Number(pid), suk, qty });
				});
				this.skuList.forEach(row => {
					if (Number(row.qty) > 0) {
						details.push({ pid: row.pid, suk: row.suk, qty: Number(row.qty) });
					}
				});
				const uniq = {};
				details.forEach(d => {
					uniq[`${d.pid}|${d.suk}`] = d;
				});
				return Object.values(uniq);
			},
			handleSubmit(andConfirm) {
				this.$refs.formValidate.validate(valid => {
					if (!valid) return;
					if (this.formValidate.from_store_id === this.formValidate.to_store_id) {
						return this.$Message.warning('调出门店与调入门店不能相同');
					}
					const details = this.buildDetails();
					if (!details.length) {
						return this.$Message.warning('请至少填写一项调拨数量');
					}
					const payload = {
						request_id: this.requestId || 0,
						from_store_id: this.formValidate.from_store_id,
						to_store_id: this.formValidate.to_store_id,
						remark: this.formValidate.remark,
						details
					};
					this.saving = true;
					stockTransferSaveApi(this.editId || 0, payload).then(res => {
						const newId = (res.data && res.data.id) || this.editId;
						if (andConfirm && newId) {
							return stockTransferConfirmApi(newId).then(cres => {
								this.saving = false;
								this.$Message.success(cres.msg || '确认调拨成功');
								this.$router.push({ path: `${this.roterPre}/stock/transfer` });
							});
						}
						this.saving = false;
						this.$Message.success(res.msg || '保存成功');
						this.$router.push({ path: `${this.roterPre}/stock/transfer` });
					}).catch(err => {
						this.saving = false;
						this.$Message.error(err.msg || '保存失败');
					});
				});
			}
		}
	};
</script>

<style scoped lang="less">
	.w50 { width: 50%; }
	.mb10 { margin-bottom: 10px; }
	.ml14 { margin-left: 14px; }
	.ml-10 { margin-left: 10px; }
	.page { margin-top: 16px; }
	.priceBox { width: 100px; }
	.fixed-card {
		position: fixed;
		right: 0;
		bottom: 0;
		left: 200px;
		z-index: 10;
		box-shadow: 0 -1px 4px rgba(0, 0, 0, 0.08);
	}
</style>
