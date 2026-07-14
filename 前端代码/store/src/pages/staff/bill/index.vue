<template>
  <div v-resize="handleResize">
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Form
        ref="formValidate"
        :model="formValidate"
        :label-width="labelWidth"
        :label-position="labelPosition"
        inline
        @submit.native.prevent
      >
        <FormItem label="选择配送员：">
          <Select
            v-model="formValidate.delivery_uid"
            placeholder="请选择"
            clearable
            v-width="250"
            @on-change="searchs"
          >
            <Option :value="item.value" v-for="(item, index) in select">{{
              item.label
            }}</Option>
          </Select>
        </FormItem>
        <FormItem label="选择时间：">
          <DatePicker
            :editable="false"
            clearable
            @on-change="onchangeTime"
            :value="timeVal"
            format="yyyy/MM/dd"
            type="datetimerange"
            placement="bottom-start"
            placeholder="自定义时间"
            v-width="250"
            :options="options"
          >
          </DatePicker>
        </FormItem>
        <FormItem label="配送状态：">
          <Select
            v-model="formValidate.status"
            clearable
            v-width="250"
            @on-change="orderSearch"
          >
            <Option value="0">待付款</Option>
            <Option value="1">待配送</Option>
            <Option value="2">配送中</Option>
            <Option value="3">待评价</Option>
            <Option value="4">已完成</Option>
            <Option value="-2">已退款</Option>
          </Select>
        </FormItem>
        <FormItem label="搜索订单：">
          <Input
            placeholder="请输入配送订单号/原订单号"
            v-model="formValidate.real_name"
            v-width="250"
          />
          <Button type="primary" @click="orderSearch" class="ml-14"
            >查询</Button
          >
          <Button class="ml-14" @click="exportHandle">导出</Button>
        </FormItem>
      </Form>
    </Card>
    <Row :gutter="24" class="ivu-mt Box">
      <Col :xl="24" :lg="24" :md="24" :sm="24" :xs="24">
        <div class="ivu-pl-8 fonts">配送订单趋势</div>
        <echarts-from
          ref="visitChart"
          :series="series"
          :echartsTitle="inlie"
          :infoList="infoList"
          v-if="infoList"
          :yAxisData="yAxisData"
        ></echarts-from>
      </Col>
    </Row>
    <Card :bordered="false" dis-hover class="ivu-mt box">
      <div class="fonts">配送数据</div>
      <Table
        ref="selection"
        :columns="columns4"
        :data="tabList"
        :loading="loading"
        no-data-text="暂无数据"
        highlight-row
        no-filtered-data-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="uid">
          <span>{{ row.nickname }} / {{ row.uid }}</span>
        </template>
        <template slot-scope="{ row }" slot="delivery_time">
          <span>{{ row.delivery_time | timeFormat }}</span>
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
import {
  staffDeliveryStatisticsHeaderApi,
  staffDeliveryselectApi,
  deliveryStatisticsApi,
  deliveryExportStatisticsApi,
} from '@/api/staff'; //图表接口
import echartsFrom from '@/components/echarts/index';
import timeOptions from '@/utils/timeOptions';
import { formatDate } from '@/utils/validate';
import dayjs from 'dayjs';
import exportExcel from "@/utils/newToExcel.js";
export default {
  name: 'bill',
  components: { echartsFrom },
  filters: {
    timeFormat(value) {
      if (!value) {
        return '';
      }
      return dayjs(value * 1000).format('YYYY-MM-DD HH:mm');
    },
  },
  data() {
    return {
      total: 0,
      select: [],
      grid: {
        xl: 7,
        lg: 10,
        md: 12,
        sm: 24,
        xs: 24,
      },
      grids: {
        xl: 19,
        lg: 7,
        md: 12,
        sm: 24,
        xs: 24,
      },
      loading: false,
      optionData: {},
      formValidate: {
        delivery_uid: '',
        real_name: '',
        status: '',
        data: 'yesterday',
        page: 1,
        limit: 15,
      },
      options: timeOptions,
      timeVal: [],
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
      inlie: 'inlie',
      columns4: [
        {
          title: '订单号',
          key: 'order_id',
          width: 200,
        },
        {
          title: '用户信息',
          width: 120,
          slot: 'uid',
        },
        {
          title: '订单实际支付',
          key: 'pay_price',
          minWidth: 100,
        },
        {
          title: '配送员信息',
          key: 'delivery_name',
          minWidth: 100,
        },
        {
          title: '配送员电话',
          key: 'delivery_id',
          minWidth: 100,
        },
        {
          title: '配送费',
          key: 'pay_postage',
          minWidth: 100,
        },
        {
          title: '配送费实际支付',
          key: 'pay_postage',
          minWidth: 100,
        },
        {
          title: '支付方式',
          key: 'pay_type_name',
          minWidth: 100,
        },
        {
          title: '送达时间',
          slot: 'delivery_time',
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
    this.getStatistics();
    this.storeList();
    this.getList();
  },
  methods: {
    storeList() {
      staffDeliveryselectApi().then((res) => {
        this.select = res.data;
      });
    },
    getList() {
      this.loading = true;
      deliveryStatisticsApi(this.formValidate).then((res) => {
        this.tabList = res.data.list;
        this.total = res.data.count;
        this.loading = false;
      });
    },
    searchs() {
      this.getList();
      this.getStatistics();
    },
    // 统计
    getStatistics() {
      if (!this.formValidate.delivery_uid) {
        this.formValidate.delivery_uid = 0;
      }
      staffDeliveryStatisticsHeaderApi(this.formValidate)
        .then(async (res) => {
          this.infoList = res.data || {};
          this.series = this.infoList.series || [];
          this.yAxisData = [
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
          ];
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
      if (this.infoList) this.$refs.visitChart.handleResize();
    },
    //分页
    pageChange(status) {
      this.formValidate.page = status;
      this.getList();
    },
    getExcelData(tableFrom) {
      return new Promise((resolve, reject) => {
        deliveryExportStatisticsApi(tableFrom).then((res) => {
          return resolve(res.data);
        });
      });
    },
    // 导出
    async exportHandle() {
      let th = [];
      let filekey = [];
      let data = [];
      let fileName = '';
      const tableFrom = { ...this.formValidate, page: 1 };

      let hasMore = true;
      while (hasMore) {
        const resData = await this.getExcelData(tableFrom);
        if (!th.length) {
          th = resData.header || [];
        }
        if (!filekey.length) {
          filekey = resData.filekey || [];
        }
        if (!fileName) {
          fileName = resData.filename || '';
        }
        if (Array.isArray(resData.export) && resData.export.length) {
          // address 字段处理优化
          // resData.export.forEach((item) => {
          //   if (Array.isArray(item.address)) {
          //     item.address = '';
          //   } else {
          //     item.address = [
          //       `姓名：${item.address.name || '-'}`,
          //       `电话：${item.address.phone || '-'}`,
          //       `地址：${item.address.address || '-'}`,
          //     ].join('，');
          //   }
          // });
          data.push(...resData.export);
          hasMore = resData.export.length >= tableFrom.limit;
          if (hasMore) {
            tableFrom.page++;
          }
        } else {
          hasMore = false;
        }
      }
      if (data.length) {
        exportExcel(th, filekey, fileName, data);
      } else {
        this.$Message.warning('暂无可导出数据');
      }
    },
  },
};
</script>

<style scoped lang="less">
/deep/ .ivu-form-item-label {
  width: 90px !important;
  text-align: right;
}
/deep/ .ivu-form-item-content {
  margin-left: 90px !important;
}

.top {
  display: flex;
}
.Box {
  background-color: #ffffff;
  margin-left: 0px !important;
  margin-right: 0px !important;
  padding-top: 20px;
}
.fonts {
  margin-bottom: 10px;
  font-weight: bold;
}
.time {
  margin-right: 20px;
}
</style>
