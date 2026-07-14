<template>
	<div>
		<Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
			<div class="new_card_pd">
				<Form
					ref="formValidate"
					inline
					:model="formValidate"
					:label-width="labelWidth"
					:label-position="labelPosition"
					@submit.native.prevent
				>
					<FormItem label="状态：">
						<Select
							v-model="formValidate.status"
							placeholder="请选择"
							clearable
							@on-change="searchs"
							class="input-add"
						>
							<Option value="0">草稿</Option>
							<Option value="1">已申请</Option>
							<Option value="2">部分调拨</Option>
							<Option value="3">已完成</Option>
							<Option value="4">已驳回</Option>
							<Option value="5">已取消</Option>
						</Select>
					</FormItem>
					<FormItem label="请货门店：">
						<Select
							v-model="formValidate.request_store_id"
							placeholder="请选择"
							clearable
							filterable
							@on-change="searchs"
							class="input-add"
						>
							<Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
						</Select>
					</FormItem>
					<FormItem label="供货门店：">
						<Select
							v-model="formValidate.supply_store_id"
							placeholder="请选择"
							clearable
							filterable
							@on-change="searchs"
							class="input-add"
						>
							<Option v-for="item in storeList" :value="item.id" :key="'s' + item.id">{{ item.name }}</Option>
						</Select>
					</FormItem>
					<FormItem label="单号：">
						<Input
							v-model="formValidate.keyword"
							placeholder="请输入请货单号"
							class="input-add"
						></Input>
						<Button type="primary" class="ml14" @click="searchs">查询</Button>
						<Button class="ml14" @click="reset">重置</Button>
					</FormItem>
				</Form>
			</div>
		</Card>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<div class="op-tips">确认申请不会变动库存；确认调拨后才会改双方库存。双方门店须已有同源商品。</div>
			<Button type="primary" class="mt10" @click="add">新建请货</Button>
			<Table class="mt25" :columns="columns" :data="orderList" :loading="loading" :border="false">
				<template slot-scope="{ row }" slot="status">
					<Tag :color="statusColor(row.status)" size="medium">{{ row.status_name || '-' }}</Tag>
				</template>
				<template slot-scope="{ row }" slot="action">
					<a @click="showInfo(row)">详情</a>
					<template v-if="row.status == 0">
						<Divider type="vertical" />
						<a @click="edit(row)">编辑</a>
						<Divider type="vertical" />
						<a @click="confirmApply(row)">确认申请</a>
						<Divider type="vertical" />
						<a @click="cancelRow(row)">取消</a>
						<Divider type="vertical" />
						<a @click="deleteRow(row)">删除草稿</a>
					</template>
					<template v-if="row.status == 1 || row.status == 2">
						<Divider type="vertical" />
						<a @click="toTransfer(row)">转调拨</a>
						<Divider type="vertical" />
						<a @click="openReject(row)">驳回</a>
						<Divider type="vertical" />
						<a @click="cancelRow(row)">取消</a>
					</template>
				</template>
			</Table>
			<div class="acea-row row-right page">
				<Page
					:total="total"
					:current="formValidate.page"
					:page-size="formValidate.limit"
					show-elevator
					show-total
					@on-change="pageChange"
				/>
			</div>
		</Card>

		<Modal v-model="infoModal" title="请货单详情" width="860" footer-hide>
			<div v-if="infoData.id" class="info-box">
				<p>单号：{{ infoData.order_sn }}</p>
				<p>状态：{{ infoData.status_name }}</p>
				<p>请货门店：{{ infoData.request_store_name }}</p>
				<p>供货门店：{{ infoData.supply_store_name }}</p>
				<p>备注：{{ infoData.remark || '-' }}</p>
				<p>创建时间：{{ infoData.add_time || '-' }}</p>
				<p v-if="infoData.reject_reason">驳回原因：{{ infoData.reject_reason }}</p>
				<Table class="mt15" :columns="detailColumns" :data="infoData.details || []" size="small"></Table>
			</div>
		</Modal>

		<Modal v-model="rejectModal" title="驳回请货" @on-ok="submitReject">
			<Form :label-width="90">
				<FormItem label="驳回原因：">
					<Input v-model="rejectReason" type="textarea" :rows="3" placeholder="请输入驳回原因" />
				</FormItem>
			</Form>
		</Modal>
	</div>
</template>

<script>
	import Setting from '@/setting';
	import { mapState } from 'vuex';
	import { merchantStoreListApi } from '@/api/setting';
	import {
		stockRequestListApi,
		stockRequestInfoApi,
		stockRequestConfirmApi,
		stockRequestRejectApi,
		stockRequestCancelApi,
		stockRequestDeleteApi
	} from '@/api/stockRequestTransfer';

	export default {
		name: 'stockRequestList',
		data() {
			return {
				roterPre: Setting.roterPre,
				storeList: [],
				formValidate: {
					status: '',
					request_store_id: '',
					supply_store_id: '',
					keyword: '',
					page: 1,
					limit: 15
				},
				orderList: [],
				total: 0,
				loading: false,
				infoModal: false,
				infoData: {},
				rejectModal: false,
				rejectReason: '',
				rejectId: 0,
				columns: [
					{ title: '请货单号', key: 'order_sn', minWidth: 160 },
					{ title: '请货门店', key: 'request_store_name', minWidth: 120 },
					{ title: '供货门店', key: 'supply_store_name', minWidth: 120 },
					{ title: '状态', slot: 'status', minWidth: 100 },
					{ title: '备注', key: 'remark', minWidth: 120, render: (h, { row }) => h('span', row.remark || '-') },
					{ title: '创建时间', key: 'add_time', minWidth: 150 },
					{ title: '操作', slot: 'action', minWidth: 280, fixed: 'right' }
				],
				detailColumns: [
					{ title: '商品', key: 'product_name', minWidth: 140 },
					{ title: '规格', key: 'suk', minWidth: 100 },
					{ title: '单位', key: 'stock_unit', width: 80 },
					{ title: '申请数量', key: 'qty', width: 100 },
					{ title: '已调拨', key: 'transferred_qty', width: 100 },
					{ title: '剩余', key: 'remain_qty', width: 100 }
				]
			};
		},
		computed: {
			...mapState('admin/layout', ['isMobile']),
			labelWidth() {
				return this.isMobile ? undefined : 90;
			},
			labelPosition() {
				return this.isMobile ? 'top' : 'right';
			}
		},
		created() {
			this.getStores();
			this.getList();
		},
		methods: {
			statusColor(status) {
				const map = { 0: 'default', 1: 'blue', 2: 'orange', 3: 'green', 4: 'red', 5: 'default' };
				return map[status] || 'default';
			},
			getStores() {
				merchantStoreListApi().then(res => {
					this.storeList = res.data || [];
				}).catch(err => {
					this.$Message.error(err.msg || '门店列表加载失败');
				});
			},
			getList() {
				this.loading = true;
				stockRequestListApi(this.formValidate).then(res => {
					this.orderList = res.data.list || [];
					this.total = res.data.count || 0;
					this.loading = false;
				}).catch(err => {
					this.loading = false;
					this.$Message.error(err.msg || '加载失败');
				});
			},
			searchs() {
				this.formValidate.page = 1;
				this.getList();
			},
			reset() {
				this.formValidate = {
					status: '',
					request_store_id: '',
					supply_store_id: '',
					keyword: '',
					page: 1,
					limit: 15
				};
				this.getList();
			},
			pageChange(page) {
				this.formValidate.page = page;
				this.getList();
			},
			add() {
				this.$router.push({ path: `${this.roterPre}/stock/request/add/0` });
			},
			edit(row) {
				this.$router.push({ path: `${this.roterPre}/stock/request/add/${row.id}` });
			},
			toTransfer(row) {
				this.$router.push({
					path: `${this.roterPre}/stock/transfer/add/0`,
					query: { request_id: row.id }
				});
			},
			showInfo(row) {
				stockRequestInfoApi(row.id).then(res => {
					this.infoData = res.data || {};
					this.infoModal = true;
				}).catch(err => {
					this.$Message.error(err.msg || '获取详情失败');
				});
			},
			confirmApply(row) {
				this.$Modal.confirm({
					title: '确认申请',
					content: '确认后将通知供货门店，不会变动库存。确定继续？',
					onOk: () => {
						stockRequestConfirmApi(row.id).then(res => {
							this.$Message.success(res.msg || '已确认申请');
							this.getList();
						}).catch(err => {
							this.$Message.error(err.msg || '操作失败');
						});
					}
				});
			},
			openReject(row) {
				this.rejectId = row.id;
				this.rejectReason = '';
				this.rejectModal = true;
			},
			submitReject() {
				if (!this.rejectReason.trim()) {
					this.$Message.warning('请填写驳回原因');
					return Promise.reject();
				}
				return stockRequestRejectApi(this.rejectId, { reject_reason: this.rejectReason }).then(res => {
					this.$Message.success(res.msg || '已驳回');
					this.getList();
				}).catch(err => {
					this.$Message.error(err.msg || '操作失败');
					return Promise.reject();
				});
			},
			cancelRow(row) {
				this.$Modal.confirm({
					title: '取消请货',
					content: '确定取消该请货单？',
					onOk: () => {
						stockRequestCancelApi(row.id).then(res => {
							this.$Message.success(res.msg || '已取消');
							this.getList();
						}).catch(err => {
							this.$Message.error(err.msg || '操作失败');
						});
					}
				});
			},
			deleteRow(row) {
				this.$Modal.confirm({
					title: '删除草稿',
					content: '确定删除该草稿？删除后不可恢复。',
					onOk: () => {
						stockRequestDeleteApi(row.id).then(res => {
							this.$Message.success(res.msg || '删除成功');
							this.getList();
						}).catch(err => {
							this.$Message.error(err.msg || '操作失败');
						});
					}
				});
			}
		}
	};
</script>

<style scoped lang="less">
	.op-tips {
		color: #999;
		font-size: 12px;
		line-height: 1.6;
	}
	.mt10 { margin-top: 10px; }
	.mt15 { margin-top: 15px; }
	.mt25 { margin-top: 25px; }
	.ml14 { margin-left: 14px; }
	.page { margin-top: 20px; }
	.info-box p { margin-bottom: 6px; font-size: 13px; }
</style>
