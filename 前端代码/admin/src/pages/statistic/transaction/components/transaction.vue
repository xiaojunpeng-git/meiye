<template>
  <!-- 交易概况 -->
  <Card :bordered="false" dis-hover class="ivu-mt">
    <div class="acea-row row-between-wrapper mb20">
      <div class="fs-16 text-wlll-303133">交易概况</div>
      <div class="acea-row">
        <div class="acea-row row-middle mr20" v-if="isAgentAdmin">
          <span class="font-sm">选择门店：</span>
          <Select
            clearable
            v-model="store_id"
            @on-change="onSeach"
            class="input-add"
          >
            <Option v-for="item in storeList" :value="item.id" :key="item.id">{{
              item.name
            }}</Option>
          </Select>
        </div>
        <div class="acea-row row-middle">
          <span class="font-sm">时间筛选：</span>
          <DatePicker
            :editable="false"
            @on-change="onchangeTime"
            :value="timeVal"
            format="yyyy/MM/dd"
            type="daterange"
            placement="bottom-start"
            placeholder="自定义时间"
            class="mr20 input-add"
            :options="options"
          ></DatePicker>
          <Button type="primary" class="mr20" @click="onSeach">查询</Button>
          <Button type="primary" @click="excel">导出</Button>
        </div>
      </div>
    </div>
    <div class="acea-row mb20">
      <div
        class="infoBox acea-row mb30"
        v-for="(item, index) in list"
        :key="index"
      >
        <div
          class="iconCrl mr15"
          :class="{
            one: index % 4 == 0,
            two: index % 4 == 1,
            three: index % 4 == 2,
            four: index % 4 == 3,
          }"
        >
          <i class="iconfont" :class="item.icon"></i>
        </div>
        <div class="info">
          <div class="sp1">
            <span v-text="item.name" class="mr5"></span>
            <Poptip word-wrap trigger="hover" placement="right-start">
              <Icon type="ios-information-circle-outline" />
              <div slot="content">{{ item.desc }}</div>
            </Poptip>
          </div>
          <span
            class="sp2"
            v-if="index === list.length - 1"
            v-text="
              item.money ? (parseInt(item.money * 100) / 100).toFixed(2) : 0
            "
          ></span>
          <span
            class="sp2"
            v-else
            v-text="
              item.money ? (parseInt(item.money * 100) / 100).toFixed(2) : 0
            "
          ></span>
          <span class="content-time spBlock"
            >环比增长：<i
              class="content-is"
              :class="Number(item.rate) >= 0 ? 'up' : 'down'"
              >{{ item.rate }}%</i
            ><Icon
              :color="Number(item.rate) >= 0 ? '#F5222D' : '#39C15B'"
              :type="
                Number(item.rate) >= 0 ? 'md-arrow-dropup' : 'md-arrow-dropdown'
              "
          /></span>
        </div>
      </div>
    </div>
    <echarts-new
      :option-data="optionData"
      :styles="style"
      height="100%"
      width="100%"
      v-if="optionData"
    ></echarts-new>
    <Spin size="large" fix v-if="spinShow"></Spin>
  </Card>
</template>

<script>
import { statisticBottomTradeApi, statisticTrendApi } from "@/api/statistic";
import echartsNew from "@/components/echartsNew/index";
import { formatDate } from "@/utils/validate";
export default {
  name: "transaction",
  components: {
    echartsNew,
  },
  props: {
    storeList: {
      type: Array,
      default: [],
    },
  },
  data() {
    return {
      grid: {
        xl: 8,
        lg: 8,
        md: 8,
        sm: 24,
        xs: 24,
      },
      isAgentAdmin: this.__isAgentPath(),
      options: {
        shortcuts: [
          {
            text: "今天",
            value() {
              const end = new Date();
              const start = new Date();
              start.setTime(
                new Date(
                  new Date().getFullYear(),
                  new Date().getMonth(),
                  new Date().getDate()
                )
              );
              return [start, end];
            },
          },
          {
            text: "昨天",
            value() {
              const end = new Date();
              const start = new Date();
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 1
                  )
                )
              );
              end.setTime(
                end.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 1
                  )
                )
              );
              return [start, end];
            },
          },
          {
            text: "最近7天",
            value() {
              const end = new Date();
              const start = new Date();
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth(),
                    new Date().getDate() - 6
                  )
                )
              );
              return [start, end];
            },
          },
          {
            text: "最近30天",
            value() {
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
              return [start, end];
            },
          },
          {
            text: "上月",
            value() {
              const end = new Date();
              const start = new Date();
              const day = new Date(
                start.getFullYear(),
                start.getMonth(),
                0
              ).getDate();
              start.setTime(
                start.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth() - 1,
                    1
                  )
                )
              );
              end.setTime(
                end.setTime(
                  new Date(
                    new Date().getFullYear(),
                    new Date().getMonth() - 1,
                    day
                  )
                )
              );
              return [start, end];
            },
          },
          {
            text: "本月",
            value() {
              const end = new Date();
              const start = new Date();
              start.setTime(
                start.setTime(
                  new Date(new Date().getFullYear(), new Date().getMonth(), 1)
                )
              );
              return [start, end];
            },
          },
          {
            text: "本年",
            value() {
              const end = new Date();
              const start = new Date();
              start.setTime(
                start.setTime(new Date(new Date().getFullYear(), 0, 1))
              );
              return [start, end];
            },
          },
        ],
      },
      name: "近30天",
      timeVal: [],
      dataTime: "",
      store_id: 0,
      list: {},
      optionData: {},
      style: { height: "400px" },
      getExcel: "",
      spinShow: false,
    };
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
    this.dataTime =
      formatDate(start, "yyyy/MM/dd") + "-" + formatDate(end, "yyyy/MM/dd");
  },
  mounted() {
    this.getStatistics();
  },
  methods: {
    onSeach() {
      this.getStatistics();
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.dataTime = this.timeVal[0] ? this.timeVal.join("-") : "";
      this.name = this.dataTime;
      this.getStatistics();
    },
    // 统计
    getStatistics() {
      this.spinShow = true;
      statisticBottomTradeApi({ data: this.dataTime, store_id: this.store_id })
        .then(async (res) => {
          const cardLists = res.data;
          const incons = [
            "iconyingyee",
            "iconjiaoyijine",
            "iconshangpinzhifujine",
            "icongoumaihuiyuanjine",
            "iconchongzhijianshu",
            "iconxianxiashouyinjine",
            "iconzhichujine",
            "iconyuezhifujine",
            "iconzhifuyongjinjine",
            "iconshangpintuikuanjine",
          ];
          for (var i = 0; i < cardLists.series.length; i++) {
            this.$set(cardLists.series[i], "icon", incons[i]);
          }
          this.list = cardLists.series;
          this.getExcel = cardLists.export;
          this.get(cardLists);
          this.spinShow = false;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
          this.spinShow = false;
        });
    },
    get(extract) {
      let dataList = extract.series.filter((item) => {
        return item.type === 1;
      });
      let legend = dataList.map((item) => {
        return item.name;
      });
      let col = [
        "#5B8FF9",
        "#5AD8A6",
        "#5D7092",
        "#F5222D",
        "#FFAB2B",
        "#B37FEB",
      ];
      let seriesData = [];
      dataList.map((item, index) => {
        let series = [];
        Object.keys(item.value).forEach((key) => {
          series.push(Number(item.value[key]));
        });
        seriesData.push({
          name: item.name,
          type: "line",
          data: series,
          itemStyle: {
            normal: {
              color: col[index],
            },
          },
          smooth: true,
        });
      });
      this.optionData = {
        tooltip: {
          trigger: "axis",
          axisPointer: {
            type: "cross",
            label: {
              backgroundColor: "#6a7985",
            },
          },
        },
        legend: {
          x: "center",
          data: legend,
        },
        grid: {
          left: "3%",
          right: "4%",
          bottom: "3%",
          containLabel: true,
        },
        toolbox: {
          show: true,
          right: "2%",
          feature: {
            saveAsImage: {
              name:
                "交易统计_" +
                formatDate(
                  new Date(Number(new Date().getTime())),
                  "yyyyMMddhhmmss"
                ),
            },
          },
        },
        xAxis: {
          type: "category",
          boundaryGap: true,
          axisLabel: {
            interval: 0,
            rotate: 40,
            textStyle: {
              color: "#000000",
            },
          },
          data: extract.x,
        },
        yAxis: {
          type: "value",
          axisLine: {
            show: false,
          },
          axisTick: {
            show: false,
          },
          axisLabel: {
            textStyle: {
              color: "#7F8B9C",
            },
          },
          splitLine: {
            show: true,
            lineStyle: {
              color: "#F5F7F9",
            },
          },
        },
        series: seriesData,
      };
    },
    excel() {
      window.location.href = this.getExcel;
    },
    // 统计图
    getTrend() {
      statisticTrendApi({ data: this.dataTime, store_id: this.store_id })
        .then(async (res) => {
          let legend = res.data.series.map((item) => {
            return item.name;
          });
          let xAxis = res.data.xAxis;
          let col = ["#5B8FF9", "#5AD8A6", "#5D7092", "#5D7092"];
          let series = [];
          res.data.series.map((item, index) => {
            series.push({
              name: item.name,
              type: "line",
              data: item.value,
              itemStyle: {
                normal: {
                  color: col[index],
                },
              },
            });
          });
          this.optionData = {
            tooltip: {
              trigger: "axis",
              axisPointer: {
                type: "cross",
                label: {
                  backgroundColor: "#6a7985",
                },
              },
            },
            legend: {
              x: "1px",
              y: "10px",
              data: legend,
            },
            grid: {
              left: "3%",
              right: "4%",
              bottom: "3%",
              containLabel: true,
            },
            toolbox: {
              show: true,
              right: "2%",
              feature: {
                saveAsImage: {},
              },
            },
            xAxis: {
              type: "category",
              boundaryGap: true,
              // axisTick:{
              //     show:false
              // },
              // axisLine:{
              //     show:false
              // },
              // splitLine: {
              //     show: false
              // },
              axisLabel: {
                interval: 0,
                rotate: 40,
                textStyle: {
                  color: "#000000",
                },
              },
              data: xAxis,
            },
            yAxis: {
              type: "value",
              splitLine: {
                show: false,
              },
              axisLine: {
                show: false,
              },
            },
            series: series,
          };
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
  },
};
</script>

<style scoped lang="less">
.one {
  background: #1890ff;
}
.two {
  background: #00c050;
}
.three {
  background: #ffab2b;
}
.four {
  background: #b37feb;
}
.up,
.el-icon-caret-top {
  color: #f5222d;
  font-size: 12px;
  opacity: 1 !important;
}

.down,
.el-icon-caret-bottom {
  color: #39c15b;
  font-size: 12px;
}
.curP {
  cursor: pointer;
}
.header {
  &-title {
    font-size: 16px;
    color: rgba(0, 0, 0, 0.85);
  }
  &-time {
    font-size: 12px;
    color: #000000;
    opacity: 0.45;
  }
}

.iconfont {
  font-size: 16px;
  color: #fff;
}

.iconCrl {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  text-align: center;
  line-height: 32px;
  opacity: 0.7;
}

.lan {
  background: #1890ff;
}

.iconshangpinliulanliang {
  color: #fff;
}

.infoBox {
  width: 20%;
  @media screen and (max-width: 1500px) {
    width: 25%;
  }
  @media screen and (max-width: 1200px) {
    width: 33%;
  }
  @media screen and (max-width: 900px) {
    width: 50%;
  }
}

.info {
  .sp1 {
    color: #666;
    font-size: 14px;
    display: block;
  }
  .sp2 {
    font-weight: 400;
    font-size: 30px;
    color: rgba(0, 0, 0, 0.85);
    display: block;
  }
  .sp3 {
    font-size: 12px;
    font-weight: 400;
    color: rgba(0, 0, 0, 0.45);
    display: block;
  }
}
</style>
