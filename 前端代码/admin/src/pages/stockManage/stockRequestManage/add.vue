<template>
	<div class="form-submit">
		<div class="i-layout-page-header">
			<PageHeader class="product_tabs" hidden-breadcrumb>
				<div slot="title">
					<router-link :to="{ path: `${roterPre}/stock/request` }">
						<div class="font-sm after-line">
							<span class="iconfont iconfanhui"></span>
							<span class="pl10">返回</span>
						</div>
					</router-link>
					<span class="mr20 ml16">{{ editId > 0 ? '编辑请货单' : '新建请货单' }}</span>
				</div>
			</PageHeader>
		</div>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Alert type="warning" show-icon>
				操作注意事项
				<template slot="desc">
					<div>1. 确认申请只通知供货门店，不会变动库存；确认调拨后才会改双方库存。</div>
					<div>2. 双方门店须已有同源商品（同平台源 + 同规格），否则无法请货。</div>
					<div>3. 请货门店与供货门店不能相同。</div>
					<div>4. 仅草稿可编辑/删除；确认申请后可驳回、取消或转调拨。</div>
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
				<FormItem label="请货门店：" prop="request_store_id">
					<Select
						v-model="formValidate.request_store_id"
						placeholder="请选择请货门店"
						filterable
						class="w50"
						@on-change="onStoreChange"
					>
						<Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
					</Select>
				</FormItem>
				<FormItem label="供货门店：" prop="supply_store_id">
					<Select
						v-model="formValidate.supply_store_id"
						placeholder="请选择供货门店"
						filterable
						class="w50"
						@on-change="onStoreChange"
					>
						<Option v-for="item in storeList" :value="item.id" :key="'s' + item.id">{{ item.name }}</Option>
					</Select>
				</FormItem>
				<FormItem label="备注：">
					<Input v-model="formValidate.remark" placeholder="请输入备注" class="w50" />
				</FormItem>
				<FormItem label="请货商品：" required>
					<div class="acea-row row-middle mb10">
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
								:max="999999"
								:precision="0"
								class="priceBox"
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
		</Card>
		<Card
			:bordered="false"
			dis-hover
			class="fixed-card"
			:style="{ left: `${!menuCollapse ? '236px' : isMobile ? '0' : '60px'}` }"
		>
			<Form>
				<FormItem>
					<Button type="primary" :loading="saving" @click="handleSubmit">保存草稿</Button>
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
		stockRequestSaveApi,
		stockRequestInfoApi,
		stockRequestSharedSkusApi
	} from '@/api/stockRequestTransfer';

	export default {
		name: 'stockRequestAdd',
		data() {
			return {
				roterPre: Setting.roterPre,
				editId: 0,
				storeList: [],
				skuKeyword: '',
				skuList: [],
				skuTotal: 0,
				skuLoading: false,
				saving: false,
				selectedMap: {},
				formValidate: {
					request_store_id: null,
					supply_store_id: null,
					remark: '',
					page: 1,
					limit: 50
				},
				ruleValidate: {
					request_store_id: [{ required: true, type: 'number', message: '请选择请货门店', trigger: 'change' }],
					supply_store_id: [{ required: true, type: 'number', message: '请选择供货门店', trigger: 'change' }]
				},
				skuColumns: [
					{ title: '商品', key: 'product_name', minWidth: 160 },
					{ title: '规格', key: 'suk', minWidth: 100 },
					{ title: '单位', key: 'stock_unit', width: 80 },
					{ title: '请货店库存', key: 'store_a_stock', width: 110 },
					{ title: '供货店库存', key: 'store_b_stock', width: 110 },
					{ title: '申请数量', slot: 'qty', width: 130 },
					{ title: '操作', slot: 'action', width: 80 }
				]
			};
		},
		computed: {
			...mapState('admin/layout', ['isMobile', 'menuCollapse']),
			labelWidth() {
				return this.isMobile ? undefined : 110;
			},
			labelPosition() {
				return this.isMobile ? 'top' : 'right';
			}
		},
		created() {
			this.editId = Number(this.$route.params.id || 0);
			this.getStores();
			if (this.editId > 0) {
				this.loadInfo();
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
				this.skuList = [];
				this.selectedMap = {};
				this.skuTotal = 0;
			},
			loadInfo() {
				stockRequestInfoApi(this.editId).then(res => {
					const data = res.data || {};
					this.formValidate.request_store_id = data.request_store_id;
					this.formValidate.supply_store_id = data.supply_store_id;
					this.formValidate.remark = data.remark || '';
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
				}).catch(err => {
					this.$Message.error(err.msg || '加载详情失败');
				});
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
			handleSubmit() {
				this.$refs.formValidate.validate(valid => {
					if (!valid) return;
					if (this.formValidate.request_store_id === this.formValidate.supply_store_id) {
						return this.$Message.warning('请货门店与供货门店不能相同');
					}
					this.cacheQty();
					const details = [];
					Object.keys(this.selectedMap).forEach(key => {
						const [pid, suk] = key.split('|');
						const qty = this.selectedMap[key];
						if (qty > 0) {
							details.push({ pid: Number(pid), suk, qty });
						}
					});
					this.skuList.forEach(row => {
						const key = `${row.pid}|${row.suk}`;
						if (Number(row.qty) > 0 && !this.selectedMap[key]) {
							details.push({ pid: row.pid, suk: row.suk, qty: Number(row.qty) });
						}
					});
					const uniq = {};
					details.forEach(d => {
						uniq[`${d.pid}|${d.suk}`] = d;
					});
					const finalDetails = Object.values(uniq);
					if (!finalDetails.length) {
						return this.$Message.warning('请至少填写一项申请数量');
					}
					this.saving = true;
					stockRequestSaveApi(this.editId || 0, {
						request_store_id: this.formValidate.request_store_id,
						supply_store_id: this.formValidate.supply_store_id,
						remark: this.formValidate.remark,
						details: finalDetails
					}).then(res => {
						this.saving = false;
						this.$Message.success(res.msg || '保存成功');
						this.$router.push({ path: `${this.roterPre}/stock/request` });
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
