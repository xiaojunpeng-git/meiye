<template>
	<div>
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
			  <FormItem label="商品信息：">
			    <Input
			      v-model="formValidate.keyword"
			      placeholder="请输入商品名称/ID/商品编码/条形码"
			      class="input-add"
			      @on-enter="searchs"
			    ></Input>
			  </FormItem>
			  <FormItem label="当前库存：">
			    <InputNumber
			      class="w-118 fs-12"
			      placeholder="开始"
			      :max="9999999999"
			      :min="0"
			      :precision="0"
			      v-model="stockStart"
			    />
			    ~
			    <InputNumber
			      class="w-118 fs-12"
			      placeholder="结尾"
			      :max="9999999999"
			      :min="0"
			      :precision="0"
			      v-model="stockEnd"
			    />
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
			      class="input-add"
			      :options="options"
			    ></DatePicker>
			  </FormItem>
			  <FormItem label="院装产品：">
			    <RadioGroup v-model="formValidate.salon_stock_enabled" @on-change="searchs">
			      <Radio label="">全部</Radio>
			      <Radio label="1">是</Radio>
			      <Radio label="0">否</Radio>
			    </RadioGroup>
			  </FormItem>
			  <FormItem label="库存展示：">
			    <RadioGroup v-model="formValidate.hide_zero" @on-change="searchs">
			      <Radio label="1">库存0不展示</Radio>
			      <Radio label="0">全部</Radio>
			    </RadioGroup>
			  </FormItem>
			  <FormItem :label-width="0">
			    <Button type="primary" @click="searchs">查询 <span class="enter-key">↵</span></Button>
			    <Button class="ml14" @click="reset">重置</Button>
			  </FormItem>
		    </Form>
		  </div>
		</Card>
		<cards-data :cardLists="statisticsData" v-if="statisticsData.length"></cards-data>
		<Card :bordered="false" dis-hover :class="statisticsData.length?'':'ivu-mt'">
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
			 <template slot-scope="{ row }" slot="store_name">
			   <Tooltip
			     :transfer="true"
			     theme="dark"
			     max-width="200"
			     :delay="300"
			     :content="row.store_name"
			   >
			     <div class="line2">{{ row.store_name }}</div>
			   </Tooltip>
			 </template>
			 <template slot-scope="{ row }" slot="suk">
			   <Tooltip
			     :transfer="true"
			     theme="dark"
			     max-width="200"
			     :delay="600"
			     :content="row.suk"
			   >
			     <div class="line2">{{ row.suk }}</div>
			   </Tooltip>
			 </template>
			 <template slot-scope="{ row }" slot="display_unit">
			   <span>{{ formatStockUnit(row.display_unit != null ? row.display_unit : row.stock_unit) }}</span>
			 </template>
			 <template slot-scope="{ row }" slot="is_salon_product">
			   <span>{{ row.is_salon_product ? '是' : '否' }}</span>
			 </template>
			 <template slot-scope="{ row }" slot="action">
				 <a @click="details(row)">库存记录</a>
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
	import Setting from '@/setting';
	import {
		inventoryAttrListApi,
		productAttrStatisticsApi
	} from "@/api/stockManage";
	import {
	  inventoryDetailsList
	} from '../components/tableName.js';
	import cardsData from "@/components/cards/cards";
	import timeOptions from "@/utils/timeOptions";
	import { formatStockUnit } from "@/utils/formatStockQty";
	import { mapState } from "vuex";
	export default {
		data () {
			return {
				options: timeOptions,
				routePre: Setting.routePre,
				columns:inventoryDetailsList,
				goodsList:[],
				loading:false,
				formValidate:{
					page:1,
					limit:20,
					keyword:'',
					stock_range:'',
					stock_time:'',
					hide_zero:'1',
					salon_stock_enabled: '',
				},
				stockStart:null,
				stockEnd:null,
				total:0,
				statisticsData:[],
				timeVal:''
			}
		},
		components: {
		  cardsData
		},
		computed: {
		  ...mapState("store/layout", ["isMobile"]),
		  labelWidth() {
		    return this.isMobile ? undefined : 96;
		  },
		  labelPosition() {
		    return this.isMobile ? "top" : "right";
		  },
		  stockRange() {
		    return (
		      (this.stockStart != null ? this.stockStart : "") +
		      "-" +
		      (this.stockEnd != null ? this.stockEnd : "")
		    );
		  },
		},
		created () {
			this.inventoryproductList();
			this.productAttrStatistics();
		},
		methods: {
			formatStockUnit,
			onchangeTime(e){
				this.timeVal = e;
				this.formValidate.stock_time = this.timeVal[0] ? this.timeVal.join("-") : "";
				this.formValidate.page = 1;
				//this.inventoryproductList();
				this.productAttrStatistics();
			},
			productAttrStatistics(){
				productAttrStatisticsApi(this.formValidate).then(res=>{
					res.data.forEach(item=>{
						item.type = 1
						item.col = 8
					})
					this.statisticsData = res.data;
				}).catch(err=>{
					this.$Message.error(err.msg);
				})
			},
			inventoryproductList(){
				this.loading = true;
				this.formValidate.stock_range = this.stockRange;
				inventoryAttrListApi(this.formValidate).then(res=>{
					this.goodsList = res.data.list;
					this.total = res.data.count;
					this.loading = false;
				}).catch(err=>{
					this.loading = false;
					this.$Message.error(err.msg);
				})
			},
			details(row){
				this.$router.push({ path: this.routePre + "/inventory/details/info",query: {product_id: row.product_id,unique:row.unique}});
			},
			pageChange(e){
				this.formValidate.page = e;
				this.inventoryproductList();
			},
			searchs(){
				this.formValidate.page = 1;
				this.inventoryproductList();
				this.productAttrStatistics();
			},
			reset(){
				this.formValidate = {
					page:1,
					limit:20,
					keyword:'',
					stock_range:'',
					stock_time:'',
					hide_zero:'1',
					salon_stock_enabled: '',
				}
				this.stockStart = null
				this.stockEnd = null
				this.timeVal = ''
				this.inventoryproductList();
				this.productAttrStatistics();
			}
		}
	}
</script>

<style lang="less" scoped>
	.enter-key { margin-left: 2px; font-weight: 600; }
	.ml14 { margin-left: 14px; }
	.tips-inline { font-size: 12px; color: #999; line-height: 1.4; margin-top: 4px; }
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
</style>