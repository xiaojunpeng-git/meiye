<template>
  <Card :bordered="false" dis-hover class="ivu-mt">
    <div class="acea-row row-between-wrapper mb20">
      <div class="header-title">商品排行</div>
      <div class="acea-row">
        <div class="acea-row row-middle mr20" v-if="isAgentAdmin">
          <span class="font-sm">选择门店：</span>
          <Select
            clearable
            v-model="formValidate.store_id"
            @on-change="getList"
            class="width20"
          >
            <Option v-for="item in storeList" :value="item.id" :key="item.id">{{
              item.name
            }}</Option>
          </Select>
        </div>
        <div class="acea-row row-middle">
          <span class="font-sm">商品分类：</span>
          <el-cascader
            placeholder="请选择商品分类"
            class="mr20 width20"
            size="mini"
            v-model="formValidate.cate_id"
            :options="cateData"
            :props="props"
            @change="getList"
            filterable
            clearable
          >
          </el-cascader>
        </div>
        <!-- <div class="acea-row row-middle">
                    <span class="font-sm">统计类型：</span>
                    <Select v-model="formValidate.sort" clearable class="mr20 width20" @on-change="changeMenu">
                        <Option v-for="item in list" :value="item.val" :key="item.val">{{ item.name }}</Option>
                    </Select>
                  </div> -->
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
            :options="options"
            class="mr20 width20"
          ></DatePicker>
        </div>
        <div class="acea-row row-middle">
          <Button type="primary" class="mr20" @click="getList">查询</Button>
        </div>
      </div>
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
      <template slot-scope="{ row }" slot="image">
        <div class="tabBox_img" v-viewer>
          <img v-lazy="row.image" class="img-add" />
        </div>
      </template>
      <template slot-scope="{ row }" slot="profit">
        <span v-text="$tools.accMul(row.profit, 100).toFixed(2) + '%'"></span>
      </template>
      <template slot-scope="{ row }" slot="repeats">
        <span v-text="$tools.accMul(row.repeats, 100) + '%'"></span>
      </template>
      <template slot-scope="{ row }" slot="changes">
        <span>{{ $tools.accMul(row.changes, 100) + "%" }}</span>
      </template>
    </Table>
    <!-- 商品弹窗 -->
    <div v-if="isProductBox">
      <div class="bg" @click="isProductBox = false"></div>
      <goodsDetail :goodsId="goodsId"></goodsDetail>
    </div>
  </Card>
</template>

<script>
import { statisticProductListApi } from "@/api/statistic";
import { cascaderListApi } from "@/api/product";
import goodsDetail from "../components/goodsDetail";
import { formatDate } from "@/utils/validate";
export default {
  name: "productRanking",
  components: {
    goodsDetail,
  },
  props: {
    storeList: {
      type: Array,
      default: [],
    },
  },
  data() {
    return {
      isAgentAdmin: this.__isAgentPath(),
      validateFun: this.$validateFun,
      props: { emitPath: false, multiple: true, checkStrictly: true },
      cateData: [],
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
      formValidate: {
        limit: 10,
        page: 1,
        sort: "visit",
        data: "",
        cate_id: [],
        store_id: 0,
      },
      loading: false,
      tabList: [],
      total: 0,
      columns4: [
        {
          title: "商品图片",
          slot: "image",
          minWidth: 80,
        },
        {
          title: "商品名称",
          width: 180,
          key: "store_name",
        },
        {
          title: "浏览量",
          key: "visit",
          minWidth: 100,
          sortable: true,
        },
        {
          title: "访客数",
          key: "user",
          minWidth: 100,
          sortable: true,
        },
        {
          title: "加购件数",
          key: "cart",
          minWidth: 100,
          sortable: true,
        },
        {
          title: "下单件数",
          key: "orders",
          minWidth: 100,
          sortable: true,
        },
        {
          title: "支付件数",
          key: "pay",
          minWidth: 100,
        },
        {
          title: "支付金额",
          key: "price",
          minWidth: 100,
          sortable: true,
        },
        {
          title: "毛利率(%)",
          slot: "profit",
          minWidth: 100,
          sortable: true,
        },
        {
          title: "收藏数",
          key: "collect",
          minWidth: 100,
          sortable: true,
        },
        {
          title: "访客-支付转化率(%)",
          slot: "changes",
          minWidth: 125,
          sortable: true,
        },
      ],
      goodsId: "",
      isProductBox: false,
      list: [
        {
          val: "visit",
          name: "浏览量",
        },
        {
          val: "user",
          name: "访客数",
        },
        {
          val: "cart",
          name: "加购件数",
        },
        {
          val: "orders",
          name: "下单件数",
        },
        {
          val: "price",
          name: "支付金额",
        },
        {
          val: "profit",
          name: "毛利率",
        },
        {
          val: "collect",
          name: "收藏数",
        },
        {
          val: "changes",
          name: "访客-支付转化率",
        },
      ],
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
    this.formValidate.data =
      formatDate(start, "yyyy/MM/dd") + "-" + formatDate(end, "yyyy/MM/dd");
  },
  mounted() {
    this.getList();
    this.goodsCategory();
  },
  methods: {
    // 商品分类；
    goodsCategory() {
      cascaderListApi({
        type: 0,
        relation_id: 0,
      })
        .then((res) => {
          this.cateData = res.data;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 具体日期
    onchangeTime(e) {
      this.timeVal = e;
      this.formValidate.data = this.timeVal[0] ? this.timeVal.join("-") : "";
      this.name = this.formValidate.data;
      this.getList();
    },
    changeMenu(name) {
      this.formValidate.sort = name;
      this.getList();
    },
    // 列表
    getList() {
      this.loading = true;
      statisticProductListApi(this.formValidate)
        .then(async (res) => {
          let data = res.data;
          this.tabList = data;
          this.loading = false;
        })
        .catch((res) => {
          this.loading = false;
          this.$Message.error(res.msg);
        });
    },
    look(row) {
      this.goodsId = row.product_id;
      this.isProductBox = true;
    },
  },
};
</script>

<style scoped lang="less">
/deep/.el-input__inner {
  border-color: #dcdee2;
}
/deep/.el-input__prefix,
/deep/.el-input__suffix {
  color: #808695;
}
/deep/.el-cascader .el-input .el-icon-arrow-down {
  font-size: 12px;
  font-weight: bold;
}
/deep/.ivu-select-single .ivu-select-selection {
  height: 34px;
}
/deep/.ivu-select-single .ivu-select-selection .ivu-select-selected-value {
  height: 34px;
  line-height: 34px;
}
/deep/.ivu-input {
  height: 34px;
}
.img-add {
  width: 50px;
  height: 50px;
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
.font-sm {
  font-size: 12px;
}
</style>
<style scoped lang="stylus">
.bg
    position fixed
    left 0
    top 0
    width 100%
    height 100%
    background rgba(0,0,0,0.5)
    z-index: 11;
/deep/.happy-scroll-content
    width 100%
    .demo-spin-icon-load{
        animation: ani-demo-spin 1s linear infinite;
    }
    @keyframes ani-demo-spin {
        from { transform: rotate(0deg);}
        50%  { transform: rotate(180deg);}
        to   { transform: rotate(360deg);}
    }
    .demo-spin-col{
        height: 100px;
        position: relative;
        border: 1px solid #eee;
    }
</style>
