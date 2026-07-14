<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15">
      <Form
        :model="vipData"
        :label-width="labelWidth"
        :label-position="labelPosition"
        class="tabform"
        @submit.native.prevent
      >
        <Row>
          <Col class="ivu-text-left mr">
            <FormItem label="创建时间：">
              <DatePicker
                :editable="false"
                :clearable="true"
                @on-change="onchangeTime"
                :value="timeVal"
                format="yyyy/MM/dd"
                type="datetimerange"
                placement="bottom-start"
                placeholder="自定义时间"
                style="width: 250px"
                :options="options"
              >
              </DatePicker>
            </FormItem>
          </Col>
		  <Col class="mr">
		    <FormItem label="会员类型：">
		      <Select
		        v-model="vipData.member_type"
		        clearable
		        @on-change="userSearchs"
		        style="width: 250px"
		      >
		        <Option
		          v-for="item in treeSelect"
		          :value="item.id"
		          :key="item.id"
		          >{{ item.label }}</Option
		        >
		      </Select>
		    </FormItem>
		  </Col>
          <Col class="ivu-text-left mr">
            <FormItem label="选择店员：">
              <Select
                v-model="vipData.staff_id"
                clearable
                @on-change="userSearchs"
                style="width: 250px"
              >
                <Option
                  v-for="item in staffData"
                  :value="item.value"
                  :key="item.value"
                  >{{ item.label }}
                </Option>
              </Select>
            </FormItem>
          </Col>
		  <Col class="mr">
		    <FormItem
		      label="用户搜索："
		      label-for="name"
		    >
		      <Input
		        enter-button
		        placeholder="请输入用户名"
		        v-model="vipData.name"
		        style="width:250px"
		   
		      />
		        <Button type="primary" class="ml20 search"  @click="userSearchs">搜索</Button>
		  					<Button
		  					  class="ml15 search"
		  					  icon="ios-share-outline"
		  					  @click="exports"
		  					  >导出</Button>
		    </FormItem>
		  </Col>
        </Row>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="mt15">
      <Table
        :columns="vipColumns"
        :data="tableList"
        class="ivu-mt"
        :loading="loading"
        no-data-text="暂无数据"
        highlight-row
        no-filtered-data-text="暂无筛选结果"
      >
        <template slot-scope="{ row, index }" slot="userInfo">
          <div class="tabBox" v-if="row.user">
            <div class="tabBox_img" v-viewer>
              <img v-lazy="row.user.avatar" />
            </div>
            <span class="tabBox_tit">{{ row.user.nickname }}</span>
          </div>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <template>
			<a @click="marker(row)">订单备注</a>
          </template>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="vipData.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="vipData.limit"
        />
      </div>
    </Card>
    <!-- 备注 -->
    <order-remark
      ref="remarks"
      :orderId="orderId"
      @submitFail="submitFail"
      :currentTab="'4'"
    ></order-remark>
  </div>
</template>

<script>
import { mapState } from 'vuex'
import Setting from "@/setting";
import orderRemark from '../orderList/components/orderRemark'
import exportExcel from '@/utils/newToExcel.js'
import timeOptions from '@/utils/timeOptions'
import {
  orderVip,
  orderExport
} from '@/api/order'
import { staffallInfo } from '@/api/staff'
export default {
  name: 'index',
  components: {
    orderRemark
  },
  data() {
    return {
      orderId: 0,
      options: timeOptions,
      staffData: [],
      timeVal: [],
	  vipData: {
		page: 1,
		limit: 15,
	    member_type: '',
		staff_id:'',
		name:'',
		add_time:''
	  },
      tableList: [],
      total: 0,
      loading: false,
      vipColumns: [
        {
          title: '订单号',
          key: 'order_id',
          minWidth: 180,
        },
        {
          title: '用户信息',
          slot: 'userInfo',
          minWidth: 200,
        },
        {
          title: '会员类型',
          key: 'member_type',
          minWidth: 100,
        },
        {
          title: '有效期限（天）',
          minWidth: 100,
          render: (h, params) => {
            return h(
              'span',
              params.row.vip_day === -1 ? '永久' : params.row.vip_day
            )
          },
        },
        {
          title: '支付金额',
          key: 'pay_price',
          minWidth: 100,
        },
        {
          title: '支付方式',
          key: 'pay_type',
          minWidth: 100,
        },
        {
          title: '购买时间',
          key: 'pay_time',
          minWidth: 130,
        },
        {
          title: '关联店员',
          key: 'staff_name',
          minWidth: 120,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          minWidth: 200,
          align: 'center',
        },
      ],
	  treeSelect: [
	    {
	      id: 'free',
	      label: '试用',
	    },
	    {
	      id: 'month',
	      label: '月卡',
	    },
	    {
	      id: 'quarter',
	      label: '季卡',
	    },
	    {
	      id: 'year',
	      label: '年卡',
	    },
	    {
	      id: 'ever',
	      label: '永久',
	    },
	  ]
    }
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 80
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right'
    },
  },
  created() {
	this.getVipOrder();
    this.staffList();
  },
  mounted() {},
  methods: {
	// 付费会员订单列表
	getVipOrder() {
	  this.loading = true
	  orderVip(this.vipData)
	    .then((res) => {
	      let data = res.data
	      this.tableList = data.list
	      this.total = data.count
	      this.loading = false
	    })
	    .catch((err) => {
	      this.loading = false
	      this.$Message.error(err.msg)
	    })
	},
    marker(row) {
      this.orderId = row.id
      this.$refs.remarks.modals = true
      this.$refs.remarks.getRemark(row.id)
    },
    // 修改成功
    submitFail() {
	  this.getVipOrder()
    },
    // 初始化数据
    clearData() {
	  this.vipData = {
	  	page: 1,
	  	limit: 15,
	    member_type: '',
	  	staff_id:'',
	  	name:'',
	  	add_time:''
	  }
    },
    // 店员列表
    staffList() {
      let data = {
        page: 0,
        limit: 0,
      }
      staffallInfo()
        .then((res) => {
          this.staffData = res.data
        })
        .catch((err) => {
          this.$Message.error(err.msg)
        })
    },
    // 搜索
    userSearchs() {
      this.vipData.page = 1
      this.getVipOrder()
    },
    pageChange(index) {
      this.vipData.page = index
      this.getVipOrder()
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e
      this.vipData.add_time = this.timeVal[0] ? this.timeVal.join("-") : "";
      this.vipData.page = 1
      this.getVipOrder()
    },
    // 数据导出；
    async exports() {
      let orderData = {
        orderType: 4,
        staff_id: this.vipData.staff_id,
		name: this.vipData.name,
		add_time: this.vipData.add_time,
		member_type: this.vipData.member_type
      }
      let [th, filekey, data, fileName] = [[], [], [], '']
      // let fileName = "";
      let excelData = JSON.parse(JSON.stringify(orderData))
      excelData.page = 1
      for (let i = 0; i < excelData.page + 1; i++) {
        let lebData = await this.getExcelData(excelData)
        if (!fileName) fileName = lebData.filename
        if (!filekey.length) {
          filekey = lebData.filekey
        }
        if (!th.length) th = lebData.header
        if (lebData.export.length) {
          data = data.concat(lebData.export)
          excelData.page++
        } else {
          exportExcel(th, filekey, fileName, data)
          return
        }
      }
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        orderExport(excelData, 3).then((res) => {
          return resolve(res.data)
        })
      })
    },
  },
}
</script>

<style lang="stylus" scoped>
/deep/.ivu-tabs-nav {
  height: 45px;
}
.search {
  width: 86px;
  height: 32px;
}

.tabBox {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;

  .tabBox_img {
    width: 36px;
    height: 36px;

    img {
      width: 100%;
      height: 100%;
    }
  }

  .tabBox_tit {
    max-width: 60%;
    font-size: 12px !important;
    margin: 0 2px 0 10px;
    letter-spacing: 1px;
    padding: 5px 0;
    box-sizing: border-box;
  }
}
</style>