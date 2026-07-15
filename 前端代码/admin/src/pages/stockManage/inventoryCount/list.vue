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
			  <FormItem label="盘点单号：">
			    <Input
			      v-model="formValidate.keyword"
			      placeholder="请输入盘点单号"
			      class="input-add"
			    ></Input>
			  </FormItem>
			  <FormItem label="盘点状态：">
			    <Select
			      v-model="formValidate.status"
			      placeholder="请选择"
			      clearable
			      @on-change="searchs"
			      class="input-add"
			    >
			      <Option value="0">进行中</Option>
			      <Option value="1">已完成</Option>
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
				<Button @click="reset">重置</Button>
			  </FormItem>
		    </Form>
		  </div>
		</Card>
		<Card :bordered="false" dis-hover class="ivu-mt">
			<Button type="primary" @click="openForm()">新建盘点单</Button>
			<Tooltip
			    content="本页至少选中一项"
			    :disabled="!!checkUidList.length && isAll==0"
			>
			  <Button
			      type="primary"
			      class="ml-10"
			      :disabled="!checkUidList.length && isAll==0"
				  @click="exports"
			  >导出盘点明细</Button>
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
			  <vxe-column field="order_id" title="盘点单号" width="200"></vxe-column>
			  <vxe-column field="status" title="盘点状态" width="100">
			    <template v-slot="{ row }">
					<Tag color="red" size="medium" v-if="row.status == 0">进行中</Tag>
					<Tag color="green" size="medium" v-else>已完成</Tag>
				</template>
			  </vxe-column>
			  <vxe-column field="over_count_stock" title="良品盘盈数量" min-width="120">
				<template v-slot="{ row }">{{row.count_stock==-1?'-':row.over_count_stock}}</template>
			  </vxe-column>
			  <vxe-column field="loss_count_stock" title="良品盘亏数量" min-width="120">
				<template v-slot="{ row }">{{row.count_stock==-1?'-':row.loss_count_stock}}</template>
			  </vxe-column>
			  <vxe-column field="over_count_defective_stock" title="残次品盘盈数量" min-width="120">
				<template v-slot="{ row }">{{row.count_defective_stock==-1?'-':row.over_count_defective_stock}}</template>
			  </vxe-column>
			  <vxe-column field="loss_count_defective_stock" title="残次品盘亏数量" min-width="120">
				<template v-slot="{ row }">{{row.count_defective_stock==-1?'-':row.loss_count_defective_stock}}</template>
			  </vxe-column>
			  <vxe-column field="admin_name" title="操作员" min-width="150">
				<template v-slot="{ row }">{{row.admin_name || '-'}}</template>
			  </vxe-column>
			  <vxe-column field="add_time" title="创建时间" min-width="170"></vxe-column>
			  <vxe-column field="remark" title="备注" min-width="200">
				<template v-slot="{ row }">{{row.remark || '-'}}</template>
			  </vxe-column>
			  <vxe-column field="action" title="操作" width="160" fixed="right">
			    <template v-slot="{ row }">
				  <a @click="openForm(row.id)" v-if="row.status == 0">继续盘点</a>
				  <Divider type="vertical" v-if="row.status == 0" />
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
		<form-modal v-model="formModal" :edit-id="formEditId" @success="inventoryList" />
	</div>
</template>

<script>
	import timeOptions from "@/utils/timeOptions";
	import { mapState } from "vuex";
	import {
		inventoryCountListApi,
		countRemarkApi,
		productStockCountApi
	} from "@/api/stockManage";
	import orderDetails from "../components/orderDetails.vue";
	import exportExcel from "@/utils/newToExcel.js";
	import FormModal from './add';
	export default {
		name: "inventoryCountList",
		components: {
			orderDetails,
			FormModal
		},
		data() {
			return {
				options: timeOptions,
				timeVal: '',
				formValidate: {
					keyword:'',
					status:'',
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
				formModal: false,
				formEditId: 0
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
				inventoryCountListApi(this.formValidate).then(res=>{
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
			openForm(id = 0) {
				this.formEditId = Number(id) || 0;
				this.formModal = true;
			},
			onchangeTime(e) {
			  this.timeVal = e;
			  this.formValidate.add_time = this.timeVal[0] ? this.timeVal.join("-") : "";
			  this.formValidate.page = 1;
			  this.inventoryList();
			  this.allReset();
			},
			orderInfo(row){
				row.stock_type = 3;
				this.$refs.orderDetails.modals = true;
				this.$refs.orderDetails.inventoryCountInfo(row);
				this.$refs.orderDetails.inventoryDetail(row);
			},
			mark(row){
				this.$modalForm(countRemarkApi(row.id)).then(() => {
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
				this.timeVal = '';
				this.formValidate = {
					keyword:'',
					status:'',
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
			    productStockCountApi(excelData).then((res) => {
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
