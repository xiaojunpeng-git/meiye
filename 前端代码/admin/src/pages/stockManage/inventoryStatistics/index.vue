<template>
	<div>
		<Tabs class="menuTabs" :animated="false" :value="activeMenu" @on-click="tabClick">
		  <TabPane style="margin-right: 32px;" v-for="item in menuData" :label="item.name" :name="item.stock_type"></TabPane>
		</Tabs>
		<Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
		  <div class="new_card_pd">
		    <!-- 筛选条件 -->
		    <Form
		      ref="formValidate"
		      inline
		      :model="formValidate"
		      :label-width="labelWidth"
		      :label-position="labelPosition"
		      @submit.native.prevent
		    >
			  <inventory-scope-bar
			    ref="scopeBar"
			    :scope="formValidate.scope"
			    :store-id="formValidate.store_id"
			    @change="onScopeChange"
			  />
			  <FormItem label="商品信息：">
			    <Input
			      v-model="formValidate.keyword"
			      placeholder="请输入商品名称/ID/商品编码/条形码"
			      class="input-add"
			    ></Input>
			  </FormItem>
			  <FormItem label="时间选择：">
			    <DatePicker
			      :editable="false"
			      :clearable="true"
			      @on-change="onchangeTime"
			      :value="timeVal"
			      format="yyyy/MM/dd"
			      type="daterange"
			      placement="bottom-start"
			      placeholder="请选择业务时段"
			      class="input-add mr14"
			      :options="options"
			    ></DatePicker>
				<Button type="primary" class="mr14" @click="searchs">查询</Button>
				<Button @click="exports">导出</Button>
			  </FormItem>
		    </Form>
		  </div>
		</Card>
		<cards-data :cardLists="statisticsData" v-if="statisticsData.length"></cards-data>
		<Card :bordered="false" dis-hover :class="statisticsData.length?'':'ivu-mt'">
			<Table
			  :columns="tableColumns"
			  :data="goodsList"
			  ref="table"
			  class="ivu-mt"
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
	</div>
</template>

<script>
	import {
		inventorystatisticsApi,
		productstatisticsApi,
		overallStatisticsApi
	} from "@/api/stockManage";
	import {
	  inboundStatistics,
	  outboundStatistics
	} from '../components/tableName.js';
	import { mapState } from "vuex";
	import timeOptions from "@/utils/timeOptions";
	import exportExcel from "@/utils/newToExcel.js";
	import cardsData from "@/components/cards/cards";
	import inventoryScopeBar from '../components/inventoryScopeBar.vue';
	export default {
		data () {
			return {
				options: timeOptions,
				columns:inboundStatistics,
				menuData:[
					{name:'入库记录',stock_type:'1'},
					{name:'出库记录',stock_type:'2'}
				],
				activeMenu:'1',
				timeVal:'',
				goodsList:[],
				loading:false,
				formValidate:{
					page:1,
					limit:20,
					keyword:'',
					stock_time:'',
					stock_type:1,
					scope: 'hq',
					store_id: '',
				},
				total:0,
				statisticsData:[]
			}
		},
		components: {
		  cardsData,
		  inventoryScopeBar,
		},
		computed: {
		  ...mapState("admin/layout", ["isMobile"]),
		  labelWidth() {
		    return this.isMobile ? undefined : 96;
		  },
		  labelPosition() {
		    return this.isMobile ? "top" : "right";
		  },
		  tableColumns() {
		    const base = this.columns.slice();
		    if (this.formValidate.scope === 'all') {
		      base.splice(1, 0, {
		        title: '覆盖门店数',
		        key: 'store_count',
		        align: 'left',
		        minWidth: 110,
		      });
		    }
		    return base;
		  },
		},
		created () {
			this.inventoryproductList();
			this.overallStatistics();
		},
		methods: {
			onScopeChange(payload) {
				this.formValidate.scope = payload.scope;
				this.formValidate.store_id = payload.store_id;
				this.formValidate.page = 1;
				if (payload.scope === 'store' && !payload.store_id) {
					this.goodsList = [];
					this.total = 0;
					this.statisticsData = [];
					return;
				}
				this.inventoryproductList();
				this.overallStatistics();
			},
			overallStatistics(){
				if (this.formValidate.scope === 'store' && !this.formValidate.store_id) return;
				overallStatisticsApi(this.formValidate).then(res=>{
					(res.data || []).forEach(item=>{
						item.type = 1
						if(this.formValidate.stock_type==1){
							item.col = 4
						}else{
							item.col = 6
						}
					})
					this.statisticsData = res.data || [];
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			tabClick(e){
				this.columns = e==1?inboundStatistics:outboundStatistics
				this.activeMenu = e;
				this.timeVal = '';
				this.formValidate.stock_type = e;
				this.formValidate.keyword = '';
				this.formValidate.stock_time = '';
				this.formValidate.page = 1;
				this.inventoryproductList();
				this.overallStatistics();
			},
			onchangeTime(e){
				this.timeVal = e;
				this.formValidate.stock_time = this.timeVal[0] ? this.timeVal.join("-") : "";
				this.formValidate.page = 1;
				this.inventoryproductList();
				this.overallStatistics();
			},
			inventoryproductList(){
				if (this.formValidate.scope === 'store' && !this.formValidate.store_id) return;
				this.loading = true;
				inventorystatisticsApi(this.formValidate).then(res=>{
					this.goodsList = res.data.list;
					this.total = res.data.count;
					this.loading = false;
				}).catch(err=>{
					this.loading = false;
					this.$Message.error(err.msg);
				})
			},
			pageChange(e){
				this.formValidate.page = e;
				this.inventoryproductList();
			},
			searchs(){
				if (this.$refs.scopeBar && !this.$refs.scopeBar.validate()) return;
				this.formValidate.page = 1;
				this.inventoryproductList();
				this.overallStatistics();
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
			    productstatisticsApi(excelData).then((res) => {
			      return resolve(res.data);
			    });
			  });
			}
		}
	}
</script>

<style lang="less" scoped>
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
	.menuTabs{
		margin: 0 14px;
	}
	.menuTabs /deep/.ivu-tabs-bar{
		margin-bottom: 1px;
		border-bottom: 0;
	}
	.menuTabs /deep/.ivu-tabs-nav .ivu-tabs-tab{
		padding: 12px 0;
		margin-right: 32px;
	}
	.menuTabs /deep/.ivu-tabs-ink-bar{
		height: 0;
	}
	.menuTabs /deep/.ivu-tabs-nav .ivu-tabs-tab-active:before{
		content:'';
		position: absolute;
		width: 100%;
		height: 2px;
		background-color: #2d8cf0;
		bottom: 1px;
		
	}
</style>