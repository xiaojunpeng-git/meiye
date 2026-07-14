<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
          ref="orderData"
          :model="formData"
          :label-width="labelWidth"
          :label-position="labelPosition"
          class="tabform"
          inline
          @submit.native.prevent
        >
          <FormItem label="选择配送员：">
            <Select
              v-model="formData.delivery_uid"
              clearable
              filterable
              class="input-add"
              @on-change="orderSearch"
            >
              <Option
                v-for="item in deliveryList"
                :value="item.value"
                :key="item.value"
                >{{ item.label }}
              </Option>
            </Select>
          </FormItem>
          <FormItem label="选择时间：">
            <DatePicker
              :editable="false"
              @on-change="onchangeTime"
              :value="timeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="自定义时间"
              class="input-add"
              :options="options"
            ></DatePicker>
          </FormItem>
          <FormItem label="配送状态：">
            <Select
              v-model="formData.status"
              clearable
              class="input-add"
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
              v-model="formData.real_name"
              class="input-add"
            />
            <Button type="primary" @click="orderSearch" class="ml-14"
              >查询</Button
            >
            <Button class="ml-14" @click="exportHandle">导出</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt">
      <h3>配送订单趋势</h3>
      <echartsNew
        :option-data="optionData"
        :styles="style"
        height="100%"
        width="100%"
        v-if="optionData"
      ></echartsNew>
    </Card>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt">
      <Table
        :columns="columns"
        :data="data"
        ref="table"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="nickname">
          <a @click="showUserInfo(row)" v-if="row.uid"
            >{{ row.nickname }} /{{ row.uid }}</a
          >
          <span v-else
            >游客<span class="ml5">/{{ row.uid }}</span></span
          >
          <span style="color: #ed4014" v-if="row.delete_time != null"
            >(已注销)</span
          >
        </template>
        <template slot-scope="{ row }" slot="delivery_time">
          <div>{{ row.delivery_time | timeFormat }}</div>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          :current="formData.page"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="formData.limit"
          @on-page-size-change="limitChange"
          show-sizer
        />
      </div>
    </Card>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import timeOptions from '@/utils/timeOptions';
import exportExcel from '@/utils/newToExcel';
import {
  deliverySelectApi,
  deliveryStatisticsApi,
  deliveryExportStatisticsApi,
  deliveryStatisticsHeaderApi,
} from '@/api/setting';
import echartsNew from '@/components/echartsNew/index';
import dayjs from 'dayjs';

export default {
  components: {
    echartsNew,
  },
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
      options: timeOptions,
      deliveryList: [],
      timeVal: [],
      formData: {
        delivery_uid: '',
        real_name: '',
        status: '',
        data: '',
        page: 1,
        limit: 20,
      },
      style: { height: '400px' },
      optionData: {},
      spinShow: false,
      loading: false,
      columns: [
        {
          title: '订单号',
          key: 'order_id',
        },
        {
          title: '用户信息',
          slot: 'nickname',
        },
        {
          title: '订单实际支付',
          key: 'pay_price',
        },
        {
          title: '配送员信息',
          key: 'delivery_name',
        },
        {
          title: '配送员电话',
          key: 'delivery_id',
        },
        {
          title: '配送费',
          key: 'pay_postage',
        },
        {
          title: '配送费实际支付',
          key: 'pay_postage',
        },
        {
          title: '支付方式',
          key: 'pay_type_name',
        },
        {
          title: '送达时间',
          slot: 'delivery_time',
        },
      ],
      data: [],
      total: 0,
    };
  },
  computed: {
    ...mapState('admin/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  created() {
    this.getDeliverySelect();
    this.getTrend();
    this.getDeliveryStatistics();
  },
  methods: {
    getDeliverySelect() {
      deliverySelectApi()
        .then((res) => {
          this.deliveryList = res.data;
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    getDeliveryStatistics() {
      this.loading = true;
      deliveryStatisticsApi(this.formData)
        .then((res) => {
          this.loading = false;
          this.data = res.data.list;
          this.total = res.data.count;
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    onchangeTime(e) {
      this.timeVal = e;
      this.formData.data = this.timeVal.join('-');
      if (!e[0]) {
        this.formData.data = '';
      }
      this.orderSearch();
    },
    orderSearch() {
      this.getTrend();
      this.getDeliveryStatistics();
    },
    pageChange(index) {
      this.formData.page = index;
      this.getDeliveryStatistics();
    },
    limitChange(limit) {
      this.formData.limit = limit;
      this.getDeliveryStatistics();
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
      const tableFrom = { ...this.formData, page: 1 };

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
    // 统计图
    getTrend() {
      this.spinShow = true;
      deliveryStatisticsHeaderApi(this.formData)
        .then(async (res) => {
          let legend = res.data.series.map((item) => {
            return item.name;
          });
          let xAxis = res.data.xAxis;
          // let col = ['#5B8FF9', '#5AD8A6', '#FFAB2B', '#5D7092'];
          let series = [];
          res.data.series.map((item, index) => {
            series.push({
              name: item.name,
              type: 'line',
              data: item.data,
              // itemStyle: {
              //   normal: {
              //     color: col[index],
              //   },
              // },
              smooth: 0,
            });
          });
          this.optionData = {
            tooltip: {
              trigger: 'axis',
              axisPointer: {
                type: 'cross',
                label: {
                  backgroundColor: '#6a7985',
                },
              },
            },
            legend: {
              x: 'center',
              data: legend,
            },
            grid: {
              left: '3%',
              right: '4%',
              bottom: '3%',
              containLabel: true,
            },
            toolbox: {
              feature: {
                saveAsImage: {},
              },
              right: '5%',
            },
            xAxis: {
              type: 'category',
              boundaryGap: true,
              axisLabel: {
                interval: 0,
                rotate: 40,
                textStyle: {
                  color: '#000000',
                },
              },
              data: xAxis,
            },
            yAxis: {
              type: 'value',
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
            series: series,
          };
          this.spinShow = false;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
          this.spinShow = false;
        });
    },
  },
};
</script>

<style lang="stylus" scoped></style>