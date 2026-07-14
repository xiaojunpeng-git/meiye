<template>
	<div>
		<div class="i-layout-page-header">
			<PageHeader class="product_tabs" hidden-breadcrumb>
				<div slot="title">
					<router-link :to="{ path: `${routePre}/inventory/details` }">
						<div class="font-sm after-line">
							<span class="iconfont iconfanhui"></span>
							<span class="pl10">返回</span>
						</div>
					</router-link>
					<span class="mr20 ml16 fs-18">出入库明细</span>
				</div>
			</PageHeader>
		</div>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<div class="acea-row row-middle">
				<div class="w-52 h-52">
					<img :src="goodsInfo.image" class="w-full h-full rd-4px"/>
				</div>
				<div class="ml-14 fs-12 info">
					<div class="text-wlll-515A6E line1">{{goodsInfo.store_name}}</div>
					<div class="acea-row row-middle mt10 text-wlll-909399">
						<div class="line1 max-50">{{goodsInfo.sku}}</div>
						<div v-if="goodsInfo.bar_code"><span class="line-box"></span><span>{{goodsInfo.bar_code}}</span></div>
					</div>
				</div>
			</div>
		</Card>
		<Card :bordered="false" dis-hover class="ivu-mt form">
			<Form
			  ref="formValidate"
			  inline
			  :model="formValidate"
			  :label-width="labelWidth"
			  :label-position="labelPosition"
			  @submit.native.prevent
			>
			  <FormItem label="单据编号：">
			    <Input
			      v-model="formValidate.keyword"
			      placeholder="请输入单据编号"
			      class="input-add"
			    ></Input>
			  </FormItem>
			  <FormItem label="变更类型：">
			    <Select
			      v-model="formValidate.order_type"
			      placeholder="请选择"
			      clearable
			      @on-change="searchs"
			      class="input-add"
			    >
			      <Option v-for="(item,index) in orderType" :value="item.val" :key="index">{{item.name}}</Option>
			    </Select>
			  </FormItem>
			  <FormItem label="创建时间：">
			    <DatePicker
			      :editable="false"
			      :clearable="true"
			      @on-change="onchangeTime"
			      :value="timeVal"
			      format="yyyy/MM/dd HH:mm:ss"
			      type="datetimerange"
			      placement="bottom-start"
			      placeholder="自定义时间"
			      class="input-add mr14"
			      :options="options"
			    ></DatePicker>
				<Button type="primary" class="mr14" @click="searchs">查询</Button>
				<Button @click="exports">导出</Button>
			  </FormItem>
			</Form>
		</Card>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Table
			  :columns="columns"
			  :data="goodsList"
			  ref="table"
			  class="ivu-mt"
			  :loading="loading"
			  highlight-row
			  no-userFrom-text="暂无数据"
			  no-filtered-userFrom-text="暂无筛选结果"
			>
			 <template slot-scope="{ row }" slot="order_id">
				<div class="text-wlll-2d8cf0 cup" @click="orderInfo(row)">{{row.order_id}}</div>
			 </template>
			 <template slot-scope="{ row }" slot="order_type">
				 <div v-for="(item,index) in orderType" :key="index" v-if="(row.stock_type == 1 && item.val == row.order_type) || (row.stock_type == 2 && item.val == '2'+row.order_type)">{{item.name}}</div>
			 </template>
			 <template slot-scope="{ row }" slot="stock">
				 <div v-if="row.stock>0" class="text-wlll-39c15b">{{row.stock}}</div>
				 <div v-else-if="row.stock<0" class="text--w111-e93323">{{row.stock}}</div>
				 <div v-else>{{row.stock}}</div>
			 </template>
			 <template slot-scope="{ row }" slot="defective_stock">
				 <div v-if="row.defective_stock>0" class="text-wlll-39c15b">{{row.defective_stock}}</div>
				 <div v-else-if="row.defective_stock<0" class="text--w111-e93323">{{row.defective_stock}}</div>
				 <div v-else>{{row.defective_stock}}</div>
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
		</Card>
		<order-details ref="orderDetails"></order-details>
	</div>
</template>

<script>
	import {
		mapState,
		mapMutations
	} from "vuex";
	import Setting from "@/setting";
	import timeOptions from "@/utils/timeOptions";
	import exportExcel from "@/utils/newToExcel.js";
	import orderDetails from "../components/orderDetails.vue";
	import {
		inventoryAttrInfoApi,
		inventoryAttrDetailsListApi,
		productStockDetailApi
	} from "@/api/stockManage";
	import {
	  inventoryDetails
	} from '../components/tableName.js';
	export default {
		name: "details",
		components: {
			orderDetails
		},
		data() {
			return {
				routePre: Setting.routePre,
				columns:inventoryDetails,
				orderType:[
					{name:'初始入库',val:'6'},
					{name:'采购入库',val:'1'},
					{name:'其他入库',val:'2'},
					{name:'退货入库',val:'3'},
					{name:'盘盈入库',val:'5'},
					{name:'残次品转良品',val:'4'},
					{name:'销售出库',val:'21'},
					{name:'过期退货',val:'22'},
					{name:'试用出库',val:'23'},
					{name:'报废出库',val:'24'},
					{name:'良品转残次品',val:'25'},
					{name:'其他出库',val:'26'},
					{name:'盘亏出库',val:'27'}
				],
				goodsInfo:{},
				product_id:0, //商品id
				formValidate:{
					page:1,
					limit: 15,
					keyword:'',
					order_type:'',
					add_time:'',
					unique:''
				},
				options: timeOptions,
				timeVal:'',
				loading:false,
				goodsList:[],
				total:0
			}
		},
		computed: {
			...mapState("store/layout", ["isMobile", "menuCollapse"]),
			labelWidth() {
				return this.isMobile ? undefined : 80;
			},
			labelPosition() {
				return this.isMobile ? "top" : "left";
			},
		},
		created() {
			let query = this.$route.query;
			this.formValidate.unique = query.unique;
			this.product_id = query.product_id;
			this.inventoryAttrInfo();
			this.inventoryAttrDetailsList();
		},
		mounted() {},
		methods: {
			orderInfo(row){
				this.$refs.orderDetails.modals = true;
				this.$refs.orderDetails.inventoryInfo(row.id);
				this.$refs.orderDetails.inventoryDetail(row);
			},
			onchangeTime(e) {
			  this.timeVal = e;
			  this.formValidate.add_time = this.timeVal[0] ? this.timeVal.join("-") : "";
			  this.formValidate.page = 1;
			  this.inventoryAttrDetailsList();
			},
			pageChange(e){
				this.formValidate.page = e;
				this.inventoryAttrDetailsList();
			},
			inventoryAttrInfo(){
				let data = {
					product_id:this.product_id,
					unique:this.formValidate.unique
				}
				inventoryAttrInfoApi(data).then(res=>{
					this.goodsInfo = res.data;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			inventoryAttrDetailsList(){
				this.loading = true;
				inventoryAttrDetailsListApi(this.formValidate).then(res=>{
					this.goodsList = res.data.list;
					this.total = res.data.count;
					this.loading = false;
				}).catch(err=>{
					this.loading = false;
					this.$Message.error(err.msg);
				})
			},
			searchs(){
				this.formValidate.page = 1;
				this.inventoryAttrDetailsList();
			},
			async exports() {
			  let [th, filekey, data, fileName] = [[], [], [], ""];
			  let excelData = JSON.parse(JSON.stringify(this.formValidate));
			  excelData.page = 1;
			  excelData.limit = 500;
			  for (let i = 0; i < excelData.page + 1; i++) {
			    let lebData = await this.getExcelData(excelData);
			    if (!fileName) fileName = lebData.filename;
			    if (!filekey.length) {
			      filekey = lebData.filekey;
			    }
			    if (!th.length) th = lebData.header;
			    if (lebData.export.length) {
			      data = data.concat(lebData.export);
			      excelData.page++;
			    } else {
			      exportExcel(th, filekey, fileName, data);
			      return;
			    }
			  }
			},
			getExcelData(excelData) {
			  return new Promise((resolve, reject) => {
			    productStockDetailApi(excelData).then((res) => {
			      return resolve(res.data);
			    });
			  });
			}
		}
	}
</script>

<style lang="less" scoped>
	.max-50{
		max-width: 50%;
	}
	.line-box{
		width: 1px;
		height: 12px;
		background-color: #bbb;
		vertical-align: text-top;
		margin: 0 7px;
		display: inline-block;
	}
	.info{
		width: calc(100% - 30px);
	}
	/deep/.ivu-form-inline .ivu-form-item{
		margin-right: 30px;
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
	.form{
		/deep/.ivu-card-body{
			padding: 20px 16px 0 16px;
		}
	}
</style>