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
							<Option value="1">启用中</Option>
							<Option value="0">已停用</Option>
						</Select>
						<Button type="primary" class="ml14" @click="searchs">查询</Button>
						<Button class="ml14" @click="reset">重置</Button>
					</FormItem>
				</Form>
			</div>
		</Card>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<div class="op-tips">配方按「项目」维护耗材用量；核销该项目时按配方自动扣减耗材，撤销核销时原量退回。仅「可作为院装耗材」的商品可被选用。配方修改不影响历史核销。</div>
			<Button type="primary" class="mt10" @click="openForm()">新建配方</Button>
			<Table class="mt25" :columns="columns" :data="orderList" :loading="loading" :border="false">
				<template slot-scope="{ row }" slot="status">
					<Tag :color="row.status == 1 ? 'green' : 'default'" size="medium">{{ row.status_name || '-' }}</Tag>
				</template>
				<template slot-scope="{ row }" slot="action">
					<a @click="openForm(row.id)">编辑</a>
					<Divider type="vertical" />
					<a @click="toggleStatus(row)">{{ row.status == 1 ? '停用' : '启用' }}</a>
					<Divider type="vertical" />
					<a class="danger" @click="deleteRow(row)">删除</a>
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
		<form-modal v-model="formModal" :edit-id="formEditId" @success="getList" />
	</div>
</template>

<script>
	import { mapState } from 'vuex';
	import {
		salonRecipeListApi,
		salonRecipeStatusApi,
		salonRecipeDeleteApi
	} from '@/api/salonRecipe';
	import FormModal from './add';

	export default {
		name: 'salonRecipeList',
		components: { FormModal },
		data() {
			return {
				formValidate: {
					status: '',
					page: 1,
					limit: 15
				},
				orderList: [],
				total: 0,
				loading: false,
				formModal: false,
				formEditId: 0,
				columns: [
					{ title: 'ID', key: 'id', width: 80 },
					{ title: '项目', key: 'project_name', minWidth: 180 },
					{ title: '耗材项数', key: 'consumable_count', width: 100 },
					{ title: '版本', key: 'version', width: 80 },
					{ title: '状态', slot: 'status', width: 100 },
					{ title: '更新时间', key: 'update_time', minWidth: 150 },
					{ title: '操作', slot: 'action', width: 200, fixed: 'right' }
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
			this.getList();
		},
		methods: {
			getList() {
				this.loading = true;
				salonRecipeListApi(this.formValidate).then(res => {
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
				this.formValidate = { status: '', page: 1, limit: 15 };
				this.getList();
			},
			pageChange(page) {
				this.formValidate.page = page;
				this.getList();
			},
			openForm(id = 0) {
				this.formEditId = Number(id) || 0;
				this.formModal = true;
			},
			toggleStatus(row) {
				const next = row.status == 1 ? 0 : 1;
				this.$Modal.confirm({
					title: next == 1 ? '启用配方' : '停用配方',
					content: next == 1 ? '启用后核销该项目将按配方扣料。确定？' : '停用后核销该项目将不再按此配方扣料，历史流水保留。确定？',
					onOk: () => {
						salonRecipeStatusApi(row.id, { status: next }).then(res => {
							this.$Message.success(res.msg || '操作成功');
							this.getList();
						}).catch(err => {
							this.$Message.error(err.msg || '操作失败');
						});
					}
				});
			},
			deleteRow(row) {
				this.$Modal.confirm({
					title: '删除配方',
					content: '确定删除该配方？删除后不可恢复，历史核销流水不受影响。',
					onOk: () => {
						salonRecipeDeleteApi(row.id).then(res => {
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
	.mt25 { margin-top: 25px; }
	.ml14 { margin-left: 14px; }
	.page { margin-top: 20px; }
	.danger { color: #ed4014; }
</style>
