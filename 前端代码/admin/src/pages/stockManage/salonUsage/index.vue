<template>
	<div>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Tabs v-model="tab" @on-click="onTabChange">
				<TabPane label="领用/退回明细" name="detail">
					<Form inline :label-width="96" @submit.native.prevent>
						<inventory-scope-bar
							ref="detailScope"
							mode="salon"
							:scope="detailFilter.scope"
							:store-id="detailFilter.store_id"
							@change="onDetailScopeChange"
						/>
						<FormItem label="动作：">
							<Select v-model="detailFilter.status" clearable class="input-add" @on-change="searchDetail">
								<Option value="1">领用</Option>
								<Option value="2">退回</Option>
							</Select>
						</FormItem>
						<FormItem label="核销ID：">
							<Input v-model="detailFilter.writeoff_id" clearable class="input-add" placeholder="核销记录ID" />
						</FormItem>
						<FormItem label="时间：">
							<DatePicker type="daterange" v-model="detailRange" format="yyyy-MM-dd" placeholder="选择日期" style="width: 220px" @on-change="onDetailRange" />
						</FormItem>
						<FormItem>
							<Button type="primary" @click="searchDetail">查询</Button>
							<Button class="ml14" @click="resetDetail">重置</Button>
						</FormItem>
					</Form>
					<div class="scope-label" v-if="detailScopeLabel">当前：{{ detailScopeLabel }}</div>
					<Table :columns="detailColumns" :data="detailList" :loading="detailLoading" :border="false"></Table>
					<div class="acea-row row-right page">
						<Page :total="detailTotal" :current="detailFilter.page" :page-size="detailFilter.limit" show-elevator show-total @on-change="detailPageChange" />
					</div>
				</TabPane>
				<TabPane label="耗材统计" name="stat">
					<Form inline :label-width="96" @submit.native.prevent>
						<inventory-scope-bar
							ref="statScope"
							mode="salon"
							:scope="statFilter.scope"
							:store-id="statFilter.store_id"
							@change="onStatScopeChange"
						/>
						<FormItem label="时间：">
							<DatePicker type="daterange" v-model="statRange" format="yyyy-MM-dd" placeholder="选择日期" style="width: 220px" @on-change="onStatRange" />
						</FormItem>
						<FormItem>
							<Button type="primary" @click="searchStat">查询</Button>
							<Button class="ml14" @click="resetStat">重置</Button>
						</FormItem>
					</Form>
					<div class="scope-label" v-if="statScopeLabel">当前：{{ statScopeLabel }}</div>
					<Table :columns="statColumns" :data="statList" :loading="statLoading" :border="false"></Table>
					<div class="acea-row row-right page">
						<Page :total="statTotal" :current="statFilter.page" :page-size="statFilter.limit" show-elevator show-total @on-change="statPageChange" />
					</div>
				</TabPane>
			</Tabs>
		</Card>
	</div>
</template>

<script>
	import { salonUsageListApi, salonUsageStatisticsApi } from '@/api/salonRecipe';
	import inventoryScopeBar from '../components/inventoryScopeBar.vue';

	export default {
		name: 'salonUsageReport',
		components: { inventoryScopeBar },
		data() {
			return {
				tab: 'detail',
				detailRange: [],
				statRange: [],
				detailFilter: { scope: 'all', store_id: '', status: '', writeoff_id: '', start_time: '', end_time: '', page: 1, limit: 15 },
				statFilter: { scope: 'all', store_id: '', start_time: '', end_time: '', page: 1, limit: 15 },
				detailList: [],
				detailTotal: 0,
				detailLoading: false,
				detailScopeLabel: '',
				statList: [],
				statTotal: 0,
				statLoading: false,
				statScopeLabel: '',
				detailColumns: [
					{ title: '时间', key: 'usage_time', minWidth: 150 },
					{ title: '门店', key: 'store_name', minWidth: 120 },
					{ title: '动作', key: 'status_name', width: 80 },
					{ title: '项目', key: 'project_name', minWidth: 140 },
					{ title: '项目规格', minWidth: 110, render: (h, p) => h('span', p.row.project_sku_name || p.row.project_unique || '-') },
					{ title: '耗材', key: 'consumable_name', minWidth: 140 },
					{ title: '规格', minWidth: 130, render: (h, p) => h('span', p.row.sku_name || p.row.consumable_unique || '-') },
					{ title: '数量', key: 'qty', width: 90 },
					{ title: '单位', key: 'stock_unit', width: 80 },
					{ title: '扣后余额', key: 'balance_stock', width: 100 },
					{ title: '核销单号', key: 'writeoff_id', width: 100 }
				],
				statColumns: [
					{ title: '耗材', key: 'consumable_name', minWidth: 160 },
					{ title: '规格', minWidth: 140, render: (h, p) => h('span', p.row.sku_name || p.row.consumable_unique || '-') },
					{ title: '单位', key: 'stock_unit', width: 90 },
					{ title: '领用量', key: 'taken_qty', width: 110 },
					{ title: '退回量', key: 'returned_qty', width: 110 },
					{ title: '净消耗', key: 'net_qty', width: 110 },
					{ title: '领用次数', key: 'taken_count', width: 100 },
					{ title: '退回次数', key: 'returned_count', width: 100 }
				]
			};
		},
		created() {
			const range = this.defaultRange();
			this.detailRange = [range.start, range.end];
			this.statRange = [range.start, range.end];
			this.detailFilter.start_time = range.start;
			this.detailFilter.end_time = range.end;
			this.statFilter.start_time = range.start;
			this.statFilter.end_time = range.end;
			this.getDetail();
		},
		methods: {
			defaultRange() {
				const fmt = d => {
					const m = ('0' + (d.getMonth() + 1)).slice(-2);
					const day = ('0' + d.getDate()).slice(-2);
					return d.getFullYear() + '-' + m + '-' + day;
				};
				const end = new Date();
				const start = new Date();
				start.setDate(start.getDate() - 30);
				return { start: fmt(start), end: fmt(end) };
			},
			onTabChange(name) {
				if (name === 'stat' && !this.statList.length) {
					this.getStat();
				}
			},
			onDetailRange(val) {
				this.detailFilter.start_time = val && val[0] ? val[0] : '';
				this.detailFilter.end_time = val && val[1] ? val[1] : '';
			},
			onStatRange(val) {
				this.statFilter.start_time = val && val[0] ? val[0] : '';
				this.statFilter.end_time = val && val[1] ? val[1] : '';
			},
			onDetailScopeChange(payload) {
				this.detailFilter.scope = payload.scope;
				this.detailFilter.store_id = payload.store_id;
				this.detailFilter.page = 1;
				if (payload.scope === 'store' && !payload.store_id) {
					this.detailList = [];
					this.detailTotal = 0;
					return;
				}
				this.getDetail();
			},
			onStatScopeChange(payload) {
				this.statFilter.scope = payload.scope;
				this.statFilter.store_id = payload.store_id;
				this.statFilter.page = 1;
				if (payload.scope === 'store' && !payload.store_id) {
					this.statList = [];
					this.statTotal = 0;
					return;
				}
				this.getStat();
			},
			getDetail() {
				if (this.detailFilter.scope === 'store' && !this.detailFilter.store_id) return;
				this.detailLoading = true;
				salonUsageListApi(this.detailFilter).then(res => {
					this.detailList = res.data.list || [];
					this.detailTotal = res.data.count || 0;
					this.detailScopeLabel = res.data.scope_label || '';
					this.detailLoading = false;
				}).catch(err => {
					this.detailLoading = false;
					this.$Message.error(err.msg || '加载失败');
				});
			},
			searchDetail() {
				if (this.$refs.detailScope && !this.$refs.detailScope.validate()) return;
				this.detailFilter.page = 1;
				this.getDetail();
			},
			resetDetail() {
				const range = this.defaultRange();
				this.detailRange = [range.start, range.end];
				this.detailFilter = { scope: 'all', store_id: '', status: '', writeoff_id: '', start_time: range.start, end_time: range.end, page: 1, limit: 15 };
				this.getDetail();
			},
			detailPageChange(page) {
				this.detailFilter.page = page;
				this.getDetail();
			},
			getStat() {
				if (this.statFilter.scope === 'store' && !this.statFilter.store_id) return;
				this.statLoading = true;
				salonUsageStatisticsApi(this.statFilter).then(res => {
					this.statList = res.data.list || [];
					this.statTotal = res.data.count || 0;
					this.statScopeLabel = res.data.scope_label || '';
					this.statLoading = false;
				}).catch(err => {
					this.statLoading = false;
					this.$Message.error(err.msg || '加载失败');
				});
			},
			searchStat() {
				if (this.$refs.statScope && !this.$refs.statScope.validate()) return;
				this.statFilter.page = 1;
				this.getStat();
			},
			statPageChange(page) {
				this.statFilter.page = page;
				this.getStat();
			},
			resetStat() {
				const range = this.defaultRange();
				this.statRange = [range.start, range.end];
				this.statFilter = { scope: 'all', store_id: '', start_time: range.start, end_time: range.end, page: 1, limit: 15 };
				this.getStat();
			}
		}
	};
</script>

<style scoped lang="less">
	.input-add { width: 160px; }
	.ml14 { margin-left: 14px; }
	.page { margin-top: 20px; }
	.scope-label { font-size: 12px; color: #999; margin: 0 0 10px; }
</style>
