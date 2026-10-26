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
          <Row :gutter="24" type="flex" justify="end">
            <Col span="24" style="display: flex;gap: 20px">
<!--               <FormItem label="选择门店：">-->
<!--                 <Select-->
<!--                     clearable-->
<!--                     v-model="pagination.store_id"-->
<!--                     @on-change="search"-->
<!--                     class="input-add"-->
<!--                 >-->
<!--                   <Option-->
<!--                       v-for="item in storeList"-->
<!--                       :value="item.id"-->
<!--                       :key="item.id"-->
<!--                   >{{ item.name }}</Option-->
<!--                   >-->
<!--                 </Select>-->
<!--               </FormItem>-->
              <FormItem label="下单时间：">
                <DatePicker
                    :editable="false"
                    @on-change="onchangeTime"
                    :value="timeVal"
                    format="yyyy/MM/dd"
                    type="daterange"
                    placement="bottom-start"
                    placeholder="自定义时间"
                    style="width: 250px"
                    :options="options"
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
             <a @click="toUrl(item.slot,row.name)">{{ row[item.slot] }}</a>
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
import { staffListInfo } from '@/api/store';
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

      ],
      tbody: [],
      num: [],
      orderDatalist: null,
      loading: false,
      FromData: null,
      total: 0,
      orderId: 0,
      animal: 1,
      storeList:[],
      pagination: {
        page: 1,
        limit: 15,
        order_id: '',
        data: '',
        store_id:0,
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
    this.getColumn();
    this.getCurrentDate();
    this.getOrderList();
    this.allStore();
  },
  methods: {
    toUrl(storeId,payType){
      this.$router.push({
        name: 'store_order',
        query: {
          storeId: storeId,
          payType: payType,
          dateRange: this.pagination.data
        }
      });
    },
    getColumn(){
      let  that=this;
      receiveColumn({}).then(async (res) => {
        that.thead=res.data;
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    allStore(){
      staffListInfo().then(res=>{
        this.storeList = res.data;
      }).catch(err=>{
        this.$Message.error(res.msg);
      })
    },
    getCurrentDate() {
      // 不传参则用当前日期，传参则转为Date对象
      const targetDate = new Date(Date.now() - 24 * 60 * 60 * 1000);
      // 提取年、月、日并补零
      const year = targetDate.getFullYear();
      // 月份从0开始，需+1，padStart补零确保两位数
      const month = String(targetDate.getMonth() + 1).padStart(2, '0');
      const day = String(targetDate.getDate()).padStart(2, '0');
      this.timeVal=[`${year}/${month}/${day}`,`${year}/${month}/${day}`];
      this.pagination.data=`${year}/${month}/${day}-${year}/${month}/${day}`;
    },
    // 具体日期搜索()；
    onchangeTime(e) {
      this.pagination.page = 1
      this.timeVal = e
      this.pagination.data = this.timeVal[0] ? this.timeVal.join('-') : ''
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
    },
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
