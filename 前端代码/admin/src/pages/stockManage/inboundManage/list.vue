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
			  <FormItem label="入库类型：">
			    <Select
			      v-model="formValidate.order_type"
			      placeholder="请选择"
			      clearable
			      @on-change="searchs"
			      class="input-add"
			    >
			      <Option value="1">采购入库</Option>
			      <Option value="2">其他入库</Option>
				  <Option value="3">退货入库</Option>
				  <Option value="5">盘盈入库</Option>
				  <Option value="4">残次品转良品</Option>
			    </Select>
			  </FormItem>
		      <FormItem label="入库单号：">
		        <Input
		          v-model="formValidate.keyword"
		          placeholder="请输入入库单号"
		          class="input-add"
		        ></Input>
		      </FormItem>
			  <FormItem label="入库日期：">
			    <DatePicker
			      :editable="false"
			      :clearable="true"
			      @on-change="onchangeInTime"
			      :value="inTimeVal"
			      format="yyyy/MM/dd"
			      type="daterange"
			      placement="bottom-start"
			      placeholder="自定义时间"
			      class="input-add mr14"
			      :options="options"
			    ></DatePicker>
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
				<Button @click="reset">重置</Button>
			  </FormItem>
		    </Form>
		  </div>
		</Card>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Button type="primary" @click="add">新建入库</Button>
			<Tooltip
			    content="本页至少选中一项"
			    :disabled="!!checkUidList.length && isAll==0"
			>
			  <Button
			      type="primary"
			      class="ml-10"
			      :disabled="!checkUidList.length && isAll==0"
				  @click="exports"
			  >导出入库明细</Button>
			</Tooltip>
			<!-- 用户列表表格 -->
			<vxe-table
			    ref="xTable"
			    class="mt25"
			    :loading="loading"
			    row-id="id"
			    :checkbox-config="{reserve: true}"
			    @checkbox-all="checkboxAll"
			    @checkbox-change="checkboxItem"
			    :data="orderList">
			  <vxe-column type="" width="0"></vxe-column>
			  <vxe-column type="checkbox" width="100">
			    <template #header>
			      <div>
			        <Dropdown transfer @on-click="allPages">
			          <a href="javascript:void(0)" class="acea-row row-middle">
			            <span>全选({{isAll==1?(total-checkUidList.length):checkUidList.length}})</span>
			            <Icon type="ios-arrow-down"></Icon>
			          </a>
			          <template #list>
			            <DropdownMenu>
			              <DropdownItem name="0">当前页</DropdownItem>
			              <DropdownItem name="1">所有页</DropdownItem>
			            </DropdownMenu>
			          </template>
			        </Dropdown>
			      </div>
			    </template>
			  </vxe-column>
			  <vxe-column field="order_id" title="入库单号" width="200"></vxe-column>
			  <vxe-column field="order_type" title="入库类型" width="200">
			    <template v-slot="{ row }">
					<div v-if="row.order_type == 1">采购入库</div>
					<div v-else-if="row.order_type == 2">其他入库</div>
					<div v-else-if="row.order_type == 3">退货入库</div>
					<div v-else-if="row.order_type == 5">盘盈入库</div>
					<div v-else-if="row.order_type == 4">残次品转良品</div>
					<div v-if="row.order_type == 3" @click="refundOrderInfo(row.refund_order_id)" class="fs-12 text-wlll-2d8cf0 cup">{{row.order_sn}}</div>
				</template>
			  </vxe-column>
			  <vxe-column field="stock_time" title="入库日期" min-width="100"></vxe-column>
			  <vxe-column field="admin_name" title="操作员" min-width="100">
				<template v-slot="{ row }">{{row.admin_name || '-'}}</template>
			  </vxe-column>
			  <vxe-column field="add_time" title="创建时间" min-width="150"></vxe-column>
			  <vxe-column field="remark" title="备注" min-width="120">
				<template v-slot="{ row }">{{row.remark || '-'}}</template>
			  </vxe-column>
			  <vxe-column field="action" title="操作" width="120" fixed="right">
			    <template v-slot="{ row }">
			      <a @click="orderInfo(row)">详情</a>
				  <Divider type="vertical" />
				  <a @click="mark(row)">备注</a>
			    </template>
			  </vxe-column>
			</vxe-table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            :current="formValidate.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="formValidate.limit"
            @on-page-size-change="pageChange"
            show-sizer
        />
      </div>
		</Card>
		<order-details ref="orderDetails"></order-details>
		<!-- 详情 -->
		<details-from
		  v-if="orderId>0"
		  ref="orderInfo"
		  :orderDatalist="orderDatalist"
		  :orderId="orderId"
		></details-from>
	</div>
</template>

<script>
	import Setting from '@/setting';
	import timeOptions from "@/utils/timeOptions";
	import { mapState } from "vuex";
	import {
		inventoryListApi,
		orderRemarkApi,
		productStockInOrderApi
	} from "@/api/stockManage";
	import {
	  getRefundDataInfo
	} from "@/api/order";
	import orderDetails from "../components/orderDetails.vue";
	import detailsFrom from "../../order/orderList/handle/orderDetails";
	import exportExcel from "@/utils/newToExcel.js";
	export default {
		name: "inboundList",
		components: {
			orderDetails,
			detailsFrom
		},
		data() {
			return {
				roterPre: Setting.roterPre,
				options: timeOptions,
				timeVal: '',
				inTimeVal: '',
				formValidate: {
					order_type:'',
					keyword:'',
					stock_time:'',
					add_time:'',
					page: 1,
					limit: 15,
				},
				orderList:[],
				total:0,
				loading:false,
				isAll: 0,
				isCheckBox: false,
				checkUidList: [],
				orderDatalist:{},
				orderId:0,
			}
		},
		computed: {
		  ...mapState("admin/layout", ["isMobile"]),
		  labelWidth() {
		    return this.isMobile ? undefined : 96;
		  },
		  labelPosition() {
		    return this.isMobile ? "top" : "right";
		  },
		},
		created () {
			this.inventoryList();
		},
		methods:{
			// 获取详情表单数据
			refundOrderInfo(id) {
				this.orderId = id;
			  getRefundDataInfo(id)
			    .then(res => {
			      this.$refs.orderInfo.activeName = "detail";
			      this.orderDatalist = res.data;
				  let that = this;
				  setTimeout(function(){
					  that.$refs.orderInfo.modals = true;
				  })
			    })
			    .catch((res) => {
			      this.$Message.error(res.msg);
			    });
			},
			allReset() {
			  this.isAll = 0;
			  this.isCheckBox = false;
			  this.$refs.xTable.setAllCheckboxRow(false);
			  this.checkUidList = [];
			},
			checkboxItem(e) {
			  let id = parseInt(e.row.id);
			  let index = this.checkUidList.indexOf(id);
			  if (index !== -1) {
			    this.checkUidList = this.checkUidList.filter((item) => item !== id);
			  } else {
			    this.checkUidList.push(id);
			  }
			},
			checkboxAll() {
			  // 获取选中当前值
			  let obj2 = this.$refs.xTable.getCheckboxRecords(true);
			  // 获取之前选中值
			  let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
			  if (
			    this.isAll == 0 &&
			    this.checkUidList.length <= obj.length &&
			    !this.isCheckBox
			  ) {
			    obj = [];
			  }
			  obj = obj.concat(obj2);
			  let ids = [];
			  obj.forEach((item) => {
			    ids.push(parseInt(item.id));
			  });
			  this.checkUidList = ids;
			  if (!obj2.length) {
			    this.isCheckBox = false;
			  }
			},
			allPages(e) {
			  this.isAll = e;
			  if (e == 0) {
			    this.$refs.xTable.toggleAllCheckboxRow();
			  } else {
			    if (!this.isCheckBox) {
			      this.$refs.xTable.setAllCheckboxRow(true);
			      this.isCheckBox = true;
			      this.isAll = 1;
			    } else {
			      this.$refs.xTable.setAllCheckboxRow(false);
			      this.isCheckBox = false;
			      this.isAll = 0;
			    }
			    this.checkUidList = [];
			  }
			},
			inventoryList(){
				this.loading = true;
				inventoryListApi(this.formValidate).then(res=>{
					let data = res.data;
					this.orderList = data.list;
					this.total = data.count;
					this.loading = false;
					this.$nextTick(function () {
					  if (this.isAll == 1) {
					    if (this.isCheckBox) {
					      let flag = false;
					      data.list.forEach((item) => {
					        this.checkUidList.forEach((j) => {
					          if (item.id == j) {
					            flag = true;
					          }
					        });
					      });
					      if (!flag) {
					        this.$refs.xTable.setAllCheckboxRow(true);
					      }
					    } else {
					      this.$refs.xTable.setAllCheckboxRow(false);
					    }
					  } else {
					    let obj = this.$refs.xTable.getCheckboxReserveRecords(true);
					    if (
					      !this.checkUidList.length ||
					      this.checkUidList.length <= obj.length
					    ) {
					      this.$refs.xTable.setAllCheckboxRow(false);
					    }
					  }
					});
				}).catch(err=>{
					this.loading = false;
					this.$Message.error(err.msg);
				})
			},
			pageChange(index) {
			  this.formValidate.page = index;
			  this.inventoryList();
			},
			// 添加
			add () {
			    this.$router.push({ path: this.roterPre + "/inbound/manage/add/" + 0 });
			},
			onchangeTime(e) {
			  this.timeVal = e;
			  this.formValidate.add_time = this.timeVal[0] ? this.timeVal.join("-") : "";
			  this.formValidate.page = 1;
			  this.inventoryList();
			  this.allReset();
			},
			onchangeInTime(e){
				this.inTimeVal = e;
				this.formValidate.stock_time = this.inTimeVal[0] ? this.inTimeVal.join("-") : "";
				this.formValidate.page = 1;
				this.inventoryList();
				this.allReset();
			},
			orderInfo(row){
				this.$refs.orderDetails.modals = true;
				this.$refs.orderDetails.inventoryInfo(row.id);
				this.$refs.orderDetails.inventoryDetail(row);
			},
			mark(row){
				this.$modalForm(orderRemarkApi(row.id)).then(() => {
					this.inventoryList();
					this.allReset();
				});
			},
			searchs(){
				this.formValidate.page = 1;
				this.inventoryList();
				this.allReset();
			},
			reset(){
				this.inTimeVal = '';
				this.timeVal = '';
				this.formValidate = {
					order_type:'',
					keyword:'',
					stock_time:'',
					add_time:'',
					page: 1,
					limit: 15,
				},
				this.inventoryList();
				this.allReset();
			},
			async exports() {
			  if (!this.checkUidList.length && !this.isAll)
			    return this.$Message.error("本页至少选中一项");
			  let [th, filekey, data, fileName] = [[], [], [], ""];
			  let excelData = JSON.parse(JSON.stringify(this.formValidate));
			  excelData.page = 1;
			  excelData.limit = 500;
			  excelData.ids = this.checkUidList.join(",");
			  excelData.all = this.isAll;
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
			    productStockInOrderApi(excelData).then((res) => {
			      return resolve(res.data);
			    });
			  });
			}
		}
	}
</script>

<style scoped lang="less">
	/deep/.vxe-table--render-default{
		font-size: 12px;
	}
</style>
