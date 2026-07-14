<template>
	<Drawer width="1000" v-model="modals">
		<div>
			<div class="acea-row row-middle">
			    <Icon
			      custom="iconfont icondingdan"
			      size="60"
				  class="mr-12 text-wlll-1890FF"
			    />
				<div>
					<div class="fs-16 fw-500 text-wlll-000-85">{{name}}单</div>
					<div class="fs-13 text-606266 mt-2">{{name}}单号：{{orderInfo.order_id}}</div>
				</div>
			</div>
			<div class="section acea-row row-between pr-47 mt-15">
			    <div v-if="activeRow.stock_type==1">
			        <div class="text--w111-666 fs-13 mb-5">入库类型</div>
			        <div v-if="orderInfo.order_type == 1" class="fs-14 text-wlll-000-85">采购入库</div>
			        <div v-else-if="orderInfo.order_type == 2" class="fs-14 text-wlll-000-85">其他入库</div>
			        <div v-else-if="orderInfo.order_type == 3" class="fs-14 text-wlll-000-85">退货入库</div>
			        <div v-else-if="orderInfo.order_type == 5" class="fs-14 text-wlll-000-85">盘盈入库</div>
			        <div v-else-if="orderInfo.order_type == 4" class="fs-14 text-wlll-000-85">残次品转良品</div>
			    </div>
				<div v-else-if="activeRow.stock_type==2">
				    <div class="text--w111-666 fs-13 mb-5">出库类型</div>
				    <div v-if="orderInfo.order_type == 1" class="fs-14 text-wlll-000-85">销售出库</div>
				    <div v-else-if="orderInfo.order_type == 2" class="fs-14 text-wlll-000-85">过期退货</div>
				    <div v-else-if="orderInfo.order_type == 3" class="fs-14 text-wlll-000-85">试用出库</div>
				    <div v-else-if="orderInfo.order_type == 4" class="fs-14 text-wlll-000-85">报废出库</div>
				    <div v-else-if="orderInfo.order_type == 5" class="fs-14 text-wlll-000-85">良品转残次品</div>
					<div v-else-if="orderInfo.order_type == 6" class="fs-14 text-wlll-000-85">其他出库</div>
					<div v-else-if="orderInfo.order_type == 7" class="fs-14 text-wlll-000-85">盘亏出库</div>
				</div>
				<div v-else>
					<div class="text--w111-666 fs-13 mb-5">盘点状态</div>
					<div v-if="orderInfo.status==0">进行中</div>
					<div v-else>已完成</div>
				</div>
				<div v-if="activeRow.stock_type !=3">
				    <div class="text--w111-666 fs-13 mb-5">{{name}}日期</div>
				    <div class="fs-14 text-wlll-000-85">{{orderInfo.stock_time}}</div>
				</div>
				<div>
				    <div class="text--w111-666 fs-13 mb-5">操作员</div>
				    <div class="fs-14 text-wlll-000-85">{{orderInfo.admin_name || '-'}}</div>
				</div>
				<div>
				    <div class="text--w111-666 fs-13 mb-5">创建时间</div>
				    <div class="fs-14 text-wlll-000-85">{{orderInfo.add_time}}</div>
				</div>
				<div v-if="orderInfo.update_time">
				    <div class="text--w111-666 fs-13 mb-5">完成时间</div>
				    <div class="fs-14 text-wlll-000-85">{{orderInfo.update_time}}</div>
				</div>
				<div v-if="orderInfo.order_sn">
				    <div class="text--w111-666 fs-13 mb-5">关联订单号</div>
				    <div class="fs-14 text-wlll-000-85">{{orderInfo.order_sn}}</div>
				</div>
			</div>
			<div class="section">
			    <div class="name fs-15 text-wlll-303133 fw-500">{{name}}单备注</div>
			    <div class="fs-13 text-606266 mt-10 ml-13">备注：{{orderInfo.remark}}</div>
			</div>
			<div class="section">
			    <div class="name fs-15 text-wlll-303133 fw-500">{{name}}商品</div>
				<div class="mt-20 ml-13">
					<Form
					  ref="formValidate"
					  inline
					  :model="formValidate"
					  :label-width="75"
					  label-position="right"
					  @submit.native.prevent
					>
					  <FormItem label="商品搜索：" label-for="store_name">
						  <Input
						    v-model="formValidate.keyword"
						    placeholder="请输入"
						    element-id="name"
						    clearable
						    class="input-280 mr14"
						    maxlength="20"
						  >
						    <Select
						      v-model="formValidate.field_key"
						      slot="prepend"
						      style="width: 86px"
						      default-label="全部"
						    >
						      <Option value="store_name">商品名称</Option>
						      <Option value="product_id">商品ID</Option>
						      <Option value="code">商品编码</Option>
						      <Option value="bar_code">商品条形码</Option>
						    </Select>
						  </Input>
						  <Button type="primary" class="mr14" @click="searchs">查询</Button>
						  <Button @click="reset">重置</Button>
					  </FormItem>
					</Form>
					<Table
					  :columns="columns"
					  :data="goodsList"
					  ref="table"
					  :loading="loading"
					  highlight-row
					  no-userFrom-text="暂无数据"
					  no-filtered-userFrom-text="暂无筛选结果"
					>
					 <template slot-scope="{ row }" slot="product_name">
					   <Tooltip
					     :transfer="true"
					     theme="dark"
					     max-width="200"
					     :delay="300"
					     :content="row.product_name"
					   >
					     <div class="line2">{{ row.product_name }}</div>
					   </Tooltip>
					 </template>
					 <template slot-scope="{ row }" slot="sku">
					   <Tooltip
					     :transfer="true"
					     theme="dark"
					     max-width="200"
					     :delay="600"
					     :content="row.sku"
					   >
					     <div class="line2">{{ row.sku }}</div>
					   </Tooltip>
					 </template>
					 <template slot-scope="{ row }" slot="count_stock">
						 <div>{{ row.count_stock==-1?'-':row.count_stock }}</div>
					 </template>
					 <template slot-scope="{ row }" slot="change_stock">
						 <div v-if="row.change_stock<0" class="text--w111-e93323">{{ row.change_stock }}</div>
						 <div v-else-if="row.change_stock>0" class="text-wlll-39c15b">{{ row.change_stock }}</div>
						 <div v-else>{{ row.count_stock==-1?'-':row.change_stock }}</div>
					 </template>
					 <template slot-scope="{ row }" slot="defective_stock">
						 <div :class="row.defective_stock<0?'text--w111-e93323':''">{{ row.defective_stock }}</div>
					 </template>
					 <template slot-scope="{ row }" slot="count_defective_stock">
						 <div>{{ row.count_defective_stock==-1?'-':row.count_defective_stock }}</div>
					 </template>
					 <template slot-scope="{ row }" slot="change_defective_stock">
						 <div v-if="row.change_defective_stock<0" class="text--w111-e93323">{{ row.change_defective_stock }}</div>
						 <div v-else-if="row.change_defective_stock>0" class="text-wlll-39c15b">{{ row.change_defective_stock }}</div>
						 <div v-else>{{ row.count_defective_stock==-1?'-':row.change_defective_stock }}</div>
					 </template>
					</Table>
					<div class="acea-row row-right page">
					  <Page
					    :total="total"
					    :current="formValidate.page"
					    show-elevator
					    show-total
					    @on-change="pageChange"
					    :page-size="formValidate.limit"
					  />
					</div>
				</div>
			</div>
		</div>
	</Drawer>
</template>

<script>
	import {
		inventoryInfoApi,
		inventoryDetailApi
	} from "@/api/stockManage";
	import {
	  inventoryCountInfo
	} from '../components/tableName.js';
	export default {
		data () {
			return {
				modals: false,
				orderInfo: {},
				formValidate:{
					page:1,
					limit:20,
					field_key:'store_name',
					keyword:''
				},
				columns:[],
				headers: [
				  {
				    title: "ID",
				    key: "product_id",
				    width: 60,
				  },
				  {
				    title: "商品名称",
				    slot: "product_name",
				    minWidth: 200,
				  },
				  {
				    title: "商品规格",
				    slot: "sku",
				    minWidth: 200,
				  },
				  {
				    title: "商品条形码",
				    key: "bar_code",
				    width: 160,
				  }
				],
				columnsInGoods: [
					{
					  title: "良品入库数量",
					  key: "stock",
					  minWidth: 80,
					},
					{
					  title: "残次品入库数量",
					  slot: "defective_stock",
					  minWidth: 80
					}
				],
				columnsOutGoods: [
					{
					  title: "良品出库数量",
					  key: "stock",
					  minWidth: 80,
					},
					{
					  title: "残次品出库数量",
					  slot: "defective_stock",
					  minWidth: 80
					}
				],
				goodsList:[],
				total:0,
				activeRow:{},
				loading:false,
				name:'入库',//库存管理类型
			}
		},
		created () {
		},
		methods: {
			inventoryCountInfo(row){
				this.orderInfo = row;
				this.columns = inventoryCountInfo;
			},
			inventoryInfo(id){
				inventoryInfoApi(id).then(res=>{
					this.orderInfo = res.data;
					if(res.data.stock_type == 1){
						this.columns = [...this.headers,...this.columnsInGoods]
					}else{
						this.columns = [...this.headers,...this.columnsOutGoods]
					}
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			inventoryDetail(row){
				this.loading = true;
				this.activeRow = row;
				this.name = row.stock_type == 1?'入库':row.stock_type == 2?'出库':'盘点'
				this.formValidate.stock_type = row.stock_type;
				this.formValidate.order_id = row.id;
				inventoryDetailApi(this.formValidate).then(res=>{
					this.goodsList = res.data.list;
					this.total = res.data.count;
					this.loading = false;
				}).catch(err=>{
					this.loading = false;
					this.$Message.error(err.msg);
				})
			},
			pageChange(page){
				this.formValidate.page = page;
				this.inventoryDetail(this.activeRow);
			},
			searchs(){
				this.formValidate.page = 1;
				this.inventoryDetail(this.activeRow);
			},
			reset(){
				this.formValidate = {
					page:1,
					limit:20,
					field_key:'store_name',
					keyword:''
				}
				this.inventoryDetail(this.activeRow);
			}
		}
	}
</script>

<style lang="less" scoped>
	/deep/.ivu-drawer-body{
		padding: 30px 20px 30px 35px;
	}
	/deep/.ivu-form-item-content{
		display: flex;
	}
	/deep/.ivu-tooltip{
		padding-top: 5px;
	}
	/deep/.ivu-table th{
		padding-left: 5px !important;
		padding-right: 5px !important;
	}
	/deep/.ivu-table-header thead tr th:nth-of-type(1){
		padding-left:16px !important
	}
	/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value{
		font-size: 12px;
	}
	.section{
		border-bottom: 1px dashed #EEEEEE;
		padding-bottom: 25px;
		.name{
			padding-left: 10px;
			margin-top: 25px;
			border-left: 3px solid #1890FF;
			line-height: 16px;
		}
	}
</style>