<template>
  <div v-resize="handleResize">
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
      >
        <FormItem label="时间筛选：">
          <Select
            v-model="formValidate.time_key"
            class="mr15"
            placeholder="请选择时间类型"
            clearable
            @on-change="searchs"
          >
            <Option value="add_time">交易时间</Option>
            <Option value="delivery_time">发货时间</Option>
            <Option value="write_time">核销时间</Option>
          </Select>
          <DatePicker
            :editable="false"
            :clearable="true"
            @on-change="onchangeTime"
            :value="timeVal"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-start"
            placeholder="自定义时间"
            style="width: 250px"
            class="mr15"
            :options="options"
          >
          </DatePicker>
          <Select
            v-model="formValidate.type"
            class="mr15"
            placeholder="请选择类型"
            clearable
            @on-change="searchs"
          >
            <Option value="0">全部订单</Option>
            <Option value="1">支付订单</Option>
            <Option value="2">充值订单</Option>
            <Option value="3">会员订单</Option>
            <Option value="4">退款订单</Option>
            <Option value="5">充值退款</Option>
            <Option value="6">核销业绩</Option>
          </Select>
          <Select
            v-model="formValidate.staff_id"
            placeholder="请选择店员"
            clearable
            @on-change="searchs"
          >
            <Option value="0">全部店员</Option>
            <Option :value="item.value" v-for="(item, index) in staff">{{
              item.label
            }}</Option>
          </Select>
        </FormItem>
      </Form>
    </Card>
    <Row :gutter="24" class="ivu-mt Box">
      <Col :xl="16" :lg="12" :md="24" :sm="24" :xs="24" class="cardpad">
        <echarts-from
          ref="userChart"
          :series="series"
          :echartsTitle="inlie"
          :infoList="infoList"
          v-if="infoList"
          :yAxisData="yAxisData"
        ></echarts-from>
      </Col>
      <Col :xl="8" :lg="12" :md="24" :sm="24" :xs="24" class="cardpad">
        <echarts-from
          ref="visitChart"
          :infoList="infoLists"
          :echartsTitle="circle"
        ></echarts-from>
      </Col>
    </Row>
    <Card :bordered="false" dis-hover class="ivu-mt box">
      <div class="acea-row row-middle row-between mb15">
        <div class="fonts">交易数据</div>
        <Button @click="exports">导出</Button>
      </div>
      <Table
        ref="selection"
        :columns="columns4"
        :data="tabList"
        :loading="loading"
        no-data-text="暂无数据"
        highlight-row
        no-filtered-data-text="暂无筛选结果"
      >
        <template slot-scope="{ row, index }" slot="user_nickname">
          <span>{{ row.uid ? row.user_nickname : '游客' }}</span>
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
import { mapState } from 'vuex';
import { storeFinanceflowApi } from '@/api/capital';
import {
  staffStatisticsApi,
  staffStatisticsHeaderApi,
  staffStatisticsExport,
} from '@/api/staff';
import echartsFrom from '@/components/echarts/index';
import timeOptions from '@/utils/timeOptions';
import { formatDate } from '@/utils/validate';
import exportExcel from '@/utils/newToExcel.js';
export default {
  name: 'achievement',
  components: { echartsFrom },
  data() {
    return {
      total: 0,
      loading: false,
      optionData: {},
      formValidate: {
        type: '0',
        staff_id: '0',
        data: 'yesterday',
        time_key: '',
        page: 1,
        limit: 20,
      },
      bing_data: [],
      bing_xdata: [],
      staff: [],
      timeVal: [],
      options: timeOptions,
      fromList: {
        title: '选择时间',
        custom: true,
        fromTxt: [
          { text: '昨天', val: 'yesterday' },
          { text: '今天', val: 'today' },
          { text: '最近7天', val: 'sevenday' },
          { text: '近30天', val: 'thirtyday' },
          { text: '本月', val: 'month' },
          { text: '本年', val: 'year' },
        ],
      },
      extractStatistics: {
        price: '11',
        brokerage_count: '23',
        priced: '34',
      },
      series: [],
      yAxisData: [],
      infoList: {},
      infoLists: {},
      circle: 'circle',
      inlie: 'inlies',
      columns4: [
        {
          title: '订单号',
          key: 'link_id',
          width: 200,
        },
        {
          title: '用户信息',
          width: 120,
          slot: 'user_nickname',
        },
        {
          title: '实际支付',
          key: 'pay_price',
          minWidth: 80,
        },
        {
          title: '订单类型',
          key: 'type_name',
          minWidth: 100,
        },
        {
          title: '支付方式',
          key: 'pay_type_name',
          minWidth: 100,
        },
        {
          title: '店员名称',
          key: 'staff_name',
          minWidth: 100,
        },
        {
          title: '下单时间',
          key: 'add_time',
          minWidth: 120,
        },
      ],
      tabList: [],
    };
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 80;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'left';
    },
  },
  created() {
    const end = new Date();
    const start = new Date();
    start.setTime(
      start.setTime(
        new Date(
          new Date().getFullYear(),
          new Date().getMonth(),
          new Date().getDate() - 29
        )
      )
    );
    this.timeVal = [start, end];
    this.formValidate.data =
      formatDate(start, 'yyyy/MM/dd') + '-' + formatDate(end, 'yyyy/MM/dd');
  },
  mounted() {
    this.staffApi();
    this.getList();
    this.getStatistics();
  },
  methods: {
    staffApi() {
      storeFinanceflowApi().then((res) => {
        this.staff = res.data;
      });
    },
    getList() {
      this.loading = true;
      staffStatisticsApi(this.formValidate).then((res) => {
        this.loading = false;
        this.tabList = res.data.list;
        this.total = res.data.count;
      });
    },
    searchs() {
      this.getList();
      this.getStatistics();
    },
    // 统计
    getStatistics() {
      if (!this.formValidate.staff_id) {
        this.formValidate.staff_id = 0;
      }
      if (!this.formValidate.type) {
        this.formValidate.type = 0;
      }
      staffStatisticsHeaderApi(this.formValidate)
        .then(async (res) => {
          if (this.formValidate.staff_id == 0) {
            this.infoList = res.data || {};
            this.infoLists = res.data || {};
            if (this.formValidate.staff_id == 0) {
              this.series = [
                {
                  data: res.data.series,
                  name: '金额（元）',
                  type: 'bar',
                  tooltip: true,
                  smooth: true,
                  symbol: 'none',
                  barWidth: 20,
                  areaStyle: {
                    normal: {
                      opacity: 0.2,
                    },
                  },
                },
              ];
            } else {
              this.series = [
                {
                  data: res.data.series,
                  name: '金额（元）',
                  type: 'line',
                  tooltip: true,
                  smooth: true,
                  symbol: 'none',
                  // barWidth : 20,
                  areaStyle: {
                    normal: {
                      opacity: 0.2,
                    },
                  },
                },
              ];
            }
          } else {
            this.infoLists = res.data.bing || {};
            this.infoList = res.data.order || {};
            (this.series = res.data.order.series || []),
              (this.yAxisData = [
                {
                  type: 'value',
                  name: '',
                  axisLine: {
                    show: false,
                  },
                  axisTick: {
                    show: false,
                  },
                  axisLabel: {
                    textStyle: {
                      color: '#7F8B9C',
                    },
                  },
                  splitLine: {
                    show: true,
                    lineStyle: {
                      color: '#F5F7F9',
                    },
                  },
                },
                {
                  type: 'value',
                  name: '',
                  axisLine: {
                    show: false,
                  },
                  axisTick: {
                    show: false,
                  },
                  axisLabel: {
                    textStyle: {
                      color: '#7F8B9C',
                    },
                  },
                  splitLine: {
                    show: true,
                    lineStyle: {
                      color: '#F5F7F9',
                    },
                  },
                },
              ]);
          }
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 选择时间
    selectChange(tab) {
      this.formValidate.page = 1;
      this.formValidate.data = tab;
      this.timeVal = [];
      this.getList();
      this.getStatistics();
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.formValidate.data = this.timeVal[0] ? this.timeVal.join('-') : '';
      if (e[0] == '') {
        this.formValidate.data = 'yesterday';
      }
      this.formValidate.page = 1;
      this.getList();
      this.getStatistics();
    },
    // 监听页面宽度变化，刷新表格
    handleResize() {
      if (this.infoList && this.series.length !== 0)
        this.$refs.userChart.handleResize();
      if (this.infoList) this.$refs.visitChart.handleResize();
    },
    //分页
    pageChange(status) {
      this.formValidate.page = status;
      this.getList();
    },
    beforeDestroy() {
      if (this.visitChart) {
        this.visitChart.dispose();
        this.visitChart = null;
      }
    },
    getExcelData(excelData) {
      return new Promise((resolve, reject) => {
        staffStatisticsExport(excelData).then((res) => {
          return resolve(res.data);
        });
      });
    },
    // 导出交易数据
    async exports() {
      let [th, filekey, fileName, data] = [[], [], '', []];
      let excelData = JSON.parse(JSON.stringify(this.formValidate));
      let lebData;
      excelData.page = 1;
      do {
        lebData = await this.getExcelData(excelData);
        if (!fileName) {
          fileName = lebData.filename;
        }
        if (!filekey.length) {
          filekey = lebData.filekey;
        }
        if (!th.length) {
          th = lebData.header;
        }
        data = data.concat(lebData.export);
        excelData.page++;
      } while (lebData.export.length);
      exportExcel(th, filekey, fileName, data);
    },
  },
};
</script>

<style scoped lang="less">
/deep/.ivu-col-span-xl-6 {
  display: block;
  flex: 0 0 25%;
  max-width: 20%;
}
/deep/.ivu-select {
  width: 250px;
}
.Box {
  background-color: #ffffff;
  margin: 0px !important;
  margin-top: 15px !important;
  padding-right: 10px !important;
  padding-top: 5px !important;
}
.box {
  padding-bottom: 10px;
}
.fonts {
  font-weight: bold;
}
.ivu-form-item {
  margin-bottom: 0px !important;
}
.time {
  margin-right: 20px;
}
.cardpad {
  padding: 0px !important;
  padding-right: 10px;
}
</style>
