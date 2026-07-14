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
							<Option value="1">已确认</Option>
							<Option value="2">已取消</Option>
						</Select>
					</FormItem>
					<FormItem label="调出门店：">
						<Select
							v-model="formValidate.from_store_id"
							placeholder="请选择"
							clearable
							filterable
							@on-change="searchs"
							class="input-add"
						>
							<Option v-for="item in storeList" :value="item.id" :key="item.id">{{ item.name }}</Option>
						</Select>
					</FormItem>
					<FormItem label="调入门店：">
						<Select
							v-model="formValidate.to_store_id"
							placeholder="请选择"
							clearable
							filterable
							@on-change="searchs"
							class="input-add"
						>
							<Option v-for="item in storeList" :value="item.id" :key="'t' + item.id">{{ item.name }}</Option>
						</Select>
					</FormItem>
					<FormItem label="单号：">
						<Input
							v-model="formValidate.keyword"
							placeholder="请输入调拨单号"
							class="input-add"
						></Input>
						<Button type="primary" class="ml14" @click="searchs">查询</Button>
						<Button class="ml14" @click="reset">重置</Button>
					</FormItem>
				</Form>
			</div>
		</Card>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<div class="op-tips">确认调拨才会改库存；冲销只能从已确认原单发起；双方门店须已有同源商品，不能自己调自己。</div>
			<Button type="primary" class="mt10" @click="add">新建自由调拨</Button>
			<Table class="mt25" :columns="columns" :data="orderList" :loading="loading" :border="false">
				<template slot-scope="{ row }" slot="status">
					<Tag :color="statusColor(row.status)" size="medium">{{ row.status_name || '-' }}</Tag>
				</template>
				<template slot-scope="{ row }" slot="type">
					<span v-if="row.is_reverse == 1">冲销单</span>
					<span v-else-if="row.request_id > 0">请货转调拨</span>
					<span v-else>自由调拨</span>
				</template>
				<template slot-scope="{ row }" slot="action">
					<a @click="showInfo(row)">详情</a>
					<template v-if="row.status == 0">
						<Divider type="vertical" />
						<a @click="edit(row)">编辑</a>
						<Divider type="vertical" />
						<a @click="confirmRow(row)">确认</a>
						<Divider type="vertical" />
						<a @click="cancelRow(row)">取消草稿</a>
						<Divider type="vertical" />
						<a @click="deleteRow(row)">删除</a>
					</template>
					<template v-if="row.status == 1 && row.is_reverse != 1">
						<Divider type="vertical" />
						<a @click="openReverse(row)">冲销</a>
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

		<Modal v-model="infoModal" title="调拨单详情" width="900" footer-hide>
			<div v-if="infoData.id" class="info-box">
				<p>单号：{{ infoData.order_sn }}</p>
				<p>状态：{{ infoData.status_name }}</p>
				<p>调出门店：{{ infoData.from_store_name }}</p>
				<p>调入门店：{{ infoData.to_store_name }}</p>
				<p>关联请货：{{ infoData.request_id || '-' }}</p>
				<p>备注：{{ infoData.remark || '-' }}</p>
				<p>创建时间：{{ infoData.add_time || '-' }}</p>
				<p>确认时间：{{ infoData.confirm_time || '-' }}</p>
				<Table class="mt15" :columns="detailColumns" :data="infoData.details || []" size="small"></Table>
			</div>
		</Modal>

		<Modal v-model="reverseModal" title="调拨冲销" width="860" @on-ok="submitReverse">
			<div class="op-tips mb10">冲销只能从已确认原单发起；填写冲销数量（不超过可冲销数量），确认后将反向调整库存。</div>
			<Table :columns="reverseColumns" :data="reverseDetails" size="small">
				<template slot-scope="{ row }" slot="qty">
					<InputNumber
						v-model="row.reverse_qty"
						:min="0"
						:max="Number(row.reversible_qty) || 0"
						:precision="0"
						class="priceBox"
					/>
				</template>
			</Table>
		</Modal>
	</div>
</template>

<script>
	import Setting from '@/setting';
	import { mapState } from 'vuex';
	import { merchantStoreListApi } from '@/api/setting';
	import {
		stockTransferListApi,
		stockTransferInfoApi,
		stockTransferConfirmApi,
		stockTransferCancelApi,
		stockTransferDeleteApi,
		stockTransferReverseApi
	} from '@/api/stockRequestTransfer';

	export default {
		name: 'stockTransferList',
		data() {
			return {
				roterPre: Setting.roterPre,
				storeList: [],
				formValidate: {
					status: '',
					from_store_id: '',
					to_store_id: '',
					keyword: '',
					page: 1,
					limit: 15
				},
				orderList: [],
				total: 0,
				loading: false,
				infoModal: false,
				infoData: {},
				reverseModal: false,
				reverseId: 0,
				reverseDetails: [],
				columns: [
					{ title: '调拨单号', key: 'order_sn', minWidth: 160 },
					{ title: '类型', slot: 'type', minWidth: 100 },
					{ title: '调出门店', key: 'from_store_name', minWidth: 120 },
					{ title: '调入门店', key: 'to_store_name', minWidth: 120 },
					{ title: '状态', slot: 'status', minWidth: 90 },
					{ title: '备注', key: 'remark', minWidth: 120, render: (h, { row }) => h('span', row.remark || '-') },
					{ title: '创建时间', key: 'add_time', minWidth: 150 },
					{ title: '操作', slot: 'action', minWidth: 260, fixed: 'right' }
				],
				detailColumns: [
					{ title: '平台商品ID', key: 'pid', width: 100 },
					{ title: '规格', key: 'suk', minWidth: 100 },
					{ title: '单位', key: 'stock_unit', width: 80 },
					{ title: '调拨数量', key: 'qty', width: 100 },
					{ title: '已冲销', key: 'reversed_qty', width: 100 },
					{ title: '可冲销', key: 'reversible_qty', width: 100 }
				],
				reverseColumns: [
					{ title: '平台商品ID', key: 'pid', width: 100 },
					{ title: '规格', key: 'suk', minWidth: 100 },
					{ title: '原调拨数量', key: 'qty', width: 100 },
					{ title: '已冲销', key: 'reversed_qty', width: 90 },
					{ title: '可冲销', key: 'reversible_qty', width: 90 },
					{ title: '本次冲销', slot: 'qty', width: 130 }
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
				const map = { 0: 'default', 1: 'green', 2: 'red' };
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
				stockTransferListApi(this.formValidate).then(res => {
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
					from_store_id: '',
					to_store_id: '',
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
				this.$router.push({ path: `${this.roterPre}/stock/transfer/add/0` });
			},
			edit(row) {
				this.$router.push({ path: `${this.roterPre}/stock/transfer/add/${row.id}` });
			},
			showInfo(row) {
				stockTransferInfoApi(row.id).then(res => {
					this.infoData = res.data || {};
					this.infoModal = true;
				}).catch(err => {
					this.$Message.error(err.msg || '获取详情失败');
				});
			},
			confirmRow(row) {
				this.$Modal.confirm({
					title: '确认调拨',
					content: '确认后将立即调整双方门店库存，确定继续？',
					onOk: () => {
						stockTransferConfirmApi(row.id).then(res => {
							this.$Message.success(res.msg || '确认调拨成功');
							this.getList();
						}).catch(err => {
							this.$Message.error(err.msg || '操作失败');
						});
					}
				});
			},
			cancelRow(row) {
				this.$Modal.confirm({
					title: '取消草稿',
					content: '确定取消该调拨草稿？',
					onOk: () => {
						stockTransferCancelApi(row.id).then(res => {
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
						stockTransferDeleteApi(row.id).then(res => {
							this.$Message.success(res.msg || '删除成功');
							this.getList();
						}).catch(err => {
							this.$Message.error(err.msg || '操作失败');
						});
					}
				});
			},
			openReverse(row) {
				stockTransferInfoApi(row.id).then(res => {
					const data = res.data || {};
					this.reverseId = data.id;
					this.reverseDetails = (data.details || []).map(d => ({
						...d,
						reverse_qty: Number(d.reversible_qty) > 0 ? Number(d.reversible_qty) : 0
					}));
					this.reverseModal = true;
				}).catch(err => {
					this.$Message.error(err.msg || '获取详情失败');
				});
			},
			submitReverse() {
				const details = this.reverseDetails
					.filter(d => Number(d.reverse_qty) > 0)
					.map(d => ({ detail_id: d.id, qty: Number(d.reverse_qty) }));
				if (!details.length) {
					this.$Message.warning('请至少填写一项冲销数量');
					return Promise.reject();
				}
				return stockTransferReverseApi(this.reverseId, {
					details,
					auto_confirm: 1
				}).then(res => {
					this.$Message.success(res.msg || '冲销成功');
					this.getList();
				}).catch(err => {
					this.$Message.error(err.msg || '冲销失败');
					return Promise.reject();
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
	.mb10 { margin-bottom: 10px; }
	.ml14 { margin-left: 14px; }
	.page { margin-top: 20px; }
	.priceBox { width: 100px; }
	.info-box p { margin-bottom: 6px; font-size: 13px; }
</style>
