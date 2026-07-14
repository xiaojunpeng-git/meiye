<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
        <Form
        ref="pagination"
        :model="pagination"
        :label-width="labelWidth"
        :label-position="labelPosition"
        @submit.native.prevent
      >
         <Row type="flex" class="mt10">
             <Col  class="ivu-text-left ml15">
              <FormItem label="下单时间：">
                <DatePicker
                    :editable="false"
                    @on-change="onchangeTime"
                    :value="timeVal"
                    format="yyyy-MM"
                    type="month"
                    placement="bottom-end"
                    placeholder="自定义时间"
                    class="input-width"
                ></DatePicker>
               <Button type="primary" class="ml10 search"  @click="orderSearch">搜索</Button>
               <Button type="primary" class="ml10 search"  @click="exports">导出</Button>
            </FormItem>
            </Col>
         </Row>
      </Form>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <Table
        :columns="thead"
        :data="tbody"
        ref="table"
        class="mt10"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template v-for="(item,index) in thead" slot-scope="{ row, index }"  :slot="item.slot">
          <a @click="toUrl(item.date_range,row.name)">{{ row[item.slot] }}</a>
        </template>
      </Table>
    </Card>
  </div>
</template>

<script>
import { mapState } from 'vuex'
import {
  orderData,receiveColumn
} from '@/api/report'
import exportExcel from "@/utils/newToExcel.js";
export default {
  components: { },
  data() {
    return {
      grid: {
        xl: 7,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      thead: [
        {
          title: '支付方式',
          align: 'center',
          key: 'name',
          minWidth: 150,
        },
        {
          title: '支付金额',
          align: 'center',
          key: 'total_money',
          minWidth: 130,
        }
      ],
      tbody: [],
      num: [],
      orderDatalist: null,
      loading: false,
      FromData: null,
      total: 0,
      orderId: 0,
      animal: 1,
      pagination: {
        page: 1,
        limit: 15,
        order_id: '',
        data: '',
        refund_type: 'all',
      },
      options: {
        shortcuts: [
          {
            text: '今天',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                new Date(
                  new Date().getFullYear(),
                  new Date().getMonth(),
                  new Date().getDate()
                )
              )
              return [start, end]
            },
          },
          {
            text: '昨天',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 1
                  )
                )
              )
              end.setTime(
                end.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 1
                  )
                )
              )
              return [start, end]
            },
          },
          {
            text: '最近7天',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 6
                  )
                )
              )
              return [start, end]
            },
          },
          {
            text: '最近30天',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 29
                  )
                )
              )
              return [start, end]
            },
          },
		  {
		    text: "上月",
		    value() {
		      const end = new Date();
		      const start = new Date();
		  	const day = new Date(start.getFullYear(), start.getMonth(), 0).getDate();
		      start.setTime(
		        start.setTime(
		          new Date(new Date().getFullYear(), new Date().getMonth()-1, 1)
		        )
		      );
		  	end.setTime(
		  	  end.setTime(
		  	    new Date(new Date().getFullYear(), new Date().getMonth()-1, day)
		  	  )
		  	);
		      return [start, end];
		    },
		  },
          {
            text: '本月',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(
                  new Date(new Date().getFullYear(), new Date().getMonth(), 1)
                )
              )
              return [start, end]
            },
          },
          {
            text: '本年',
            value() {
              const end = new Date()
              const start = new Date()
              start.setTime(
                start.setTime(new Date(new Date().getFullYear(), 0, 1))
              )
              return [start, end]
            },
          },
        ],
      },
      timeVal: [],
      modal: false,
      qrcode: null,
      name: '',
      spin: false,
      rowActive: {},
      refundReasonList: [],
      refund_reason: -1,
	     benefitsInfo:{}
    }
  },
  computed: {
    ...mapState('order', ['orderChartType']),
    // ...mapState("admin/layout", ["isMobile"]),
    labelWidth() {
      return this.isMobile ? undefined : 75
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right'
    },
  },
  created() {
    this.getCurrentDate();
    this.getOrderList()
  },
  methods: {
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], '']
      // let fileName = "";
      let excelData = JSON.parse(JSON.stringify(this.pagination));
      excelData.page = 1
      excelData.is_excel=1;
      let lebData = await this.getExcelData(excelData)
      if (!fileName) fileName = lebData.filename
      if (!filekey.length) {
        filekey = lebData.filekey
      }
       if (!th.length) th = lebData.header
        data = data.concat(lebData.export)
        exportExcel(th, filekey, fileName, data)
        return
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        orderData(excelData).then((res) => {
          return resolve(res.data)
        })
      })
    },
    toUrl(date_range,payType){
      this.$router.push({
        path: '/store/order/index', // 基础路径，不拼接任何参数
        query: {
          payType: payType, // 支付类型
          dateRange: date_range // 日期字符串（含/也不影响）
        }
      });
    },
    getColumn(){
      let  that=this;
      receiveColumn({date:this.pagination.date}).then(async (res) => {
        that.thead=res.data;
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    getCurrentDate() {
      const now = new Date();
      const y = now.getFullYear();
      const m = (now.getMonth() + 1).toString().padStart(2, '0');
      this.timeVal = `${y}-${m}`;
      this.pagination.date=this.timeVal;
      this.getColumn();
    },
    // 具体日期搜索()；
    onchangeTime(e) {
      this.pagination.page = 1
      this.timeVal = e
      this.pagination.date = e;
      this.getColumn();
      this.getOrderList()
    },
    // 订单列表
    getOrderList() {
      this.loading = true
      orderData(this.pagination)
        .then((res) => {
          this.loading = false
          this.tbody = res.data
          this.num = num
        })
        .catch((err) => {
          this.loading = false
          this.$Message.error(err.msg)
        })
    },
    // 分页
    pageChange(index) {
      this.pagination.page = index
      this.getOrderList()
    },
    // 订单搜索
    orderSearch() {
      this.pagination.page = 1
      this.getOrderList()
    }
  },
}
</script>

<style lang="stylus" scoped>
	/deep/.ivu-select-selected-value {
	font-size: 12px !important;}
.code {
  position: relative;
}

.QRpic {
  width: 180px;
  height: 259px;

  img {
    width: 100%;
    height: 100%;
  }
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
    width: 60%;
    font-size: 12px !important;
    margin: 0 2px 0 10px;
    letter-spacing: 1px;
    padding: 5px 0;
    box-sizing: border-box;
  }
}

.pictrue-box {
  display: flex;
  align-item: center;
}

.pictrue {
  width: 25px;
  height: 25px;
}
</style>
