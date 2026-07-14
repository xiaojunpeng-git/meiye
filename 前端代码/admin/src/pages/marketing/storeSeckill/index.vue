<template>
  <!-- 营销-秒杀商品 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding= "0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
            ref="tableFrom"
            :model="tableFrom"
            :label-width="labelWidth"
            :label-position="labelPosition"
            inline
            @submit.native.prevent
        >
          <FormItem label="活动状态：" clearable>
            <Select
                v-model="tableFrom.start_status"
                placeholder="请选择"
                clearable
                @on-change="userSearchs()"
                class="input-add"
            >
              <Option :value="'0'">未开始</Option>
              <Option :value="1">进行中</Option>
              <Option :value="-1">已结束</Option>
            </Select>
          </FormItem>
          <FormItem label="上架状态：">
            <Select
                placeholder="请选择"
                clearable
                v-model="tableFrom.status"
                @on-change="userSearchs"
                class="input-add"
            >
              <Option value="1">上架</Option>
              <Option value="0">下架</Option>
            </Select>
          </FormItem>
          <FormItem label="商品搜索：" label-for="store_name">
            <Input
                placeholder="请输入秒杀ID"
                v-model="tableFrom.store_name"
                @on-search="userSearchs"
                class="input-add"
            />
            <Button type="primary" @click="userSearchs()" class="ml-14">查询</Button>
            <Button @click="reset" class="ml-14">重置</Button>
            <Button
                v-auth="['export-storeSeckill']"
                class="export ml-14"
                @click="exports">导出</Button
            >
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 秒杀商品-表格 -->
      <Table
          :columns="columns1"
          :data="tableList"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
          class="ivu-mt"
      >
        <template slot-scope="{ row }" slot="image">
          <viewer>
            <div class="tabBox_img">
              <img v-lazy="row.image" />
            </div>
          </viewer>
        </template>
        <template slot-scope="{ row }" slot="title">
          <Tooltip max-width="200" placement="bottom">
            <span class="line2">{{row.title}}</span>
            <p slot="content">{{row.title}}</p>
          </Tooltip>
        </template>
        <template slot-scope="{ row }" slot="info">
          <Tooltip max-width="200" placement="bottom">
            <span class="line2">{{row.info}}</span>
            <p slot="content">{{row.info}}</p>
          </Tooltip>
        </template>
        <template slot-scope="{ row }" slot="start_name">
          <Tag color="orange" size="medium" v-show="row.start_name === '未开始'">{{row.start_name}}</Tag>
          <Tag color="green" size="medium" v-show="row.start_name === '进行中'">{{row.start_name}}</Tag>
          <Tag color="default" size="medium" v-show="row.start_name === '已结束'">{{row.start_name}}</Tag>
        </template>
        <template slot-scope="{ row }" slot="stop_time">
          <!--后台让换字段-->
          <span> {{ row._stop_time }}</span>
        </template>
        <template slot-scope="{ row }" slot="status">
          <i-switch
              v-model="row.status"
              :value="row.status"
              :true-value="1"
              :false-value="0"
              @on-change="onchangeIsShow(row)"
              size="large"
          >
            <span slot="open">上架</span>
            <span slot="close">下架</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="viewInfo(row)">统计</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
            :total="total"
            :current="tableFrom.page"
            show-elevator
            show-total
            @on-change="pageChange"
            :page-size="tableFrom.limit"
        />
      </div>
    </Card>
  </div>
</template>

<script>
import { mapState } from "vuex";
import {
  seckillProductList,
  seckillProductStatus,
  storeSeckillApi,
} from "@/api/marketing";
import { formatDate } from "@/utils/validate";
import exportExcel from "@/utils/newToExcel.js";
import Setting from '@/setting';
export default {
  name: "storeSeckill",
  filters: {
    formatDate(time) {
      if (time !== 0) {
        let date = new Date(time * 1000);
        return formatDate(date, "yyyy-MM-dd");
      }
    },
  },
  data() {
    return {
      roterPre: Setting.roterPre,
      loading: false,
      columns1: [
        {
          title: "ID",
          key: "id",
          width: 80,
        },
        {
          title: "商品图片",
          slot: "image",
          minWidth: 90,
        },
        {
          title: "划线价",
          key: "ot_price",
          minWidth: 100,
        },
        {
          title: "秒杀价",
          key: "price",
          minWidth: 100,
        },
        {
          title: "限量",
          key: "quota_show",
          minWidth: 80,
        },
        {
          title: "限量剩余",
          key: "quota",
          minWidth: 80,
        },
        {
          title: "活动名称",
          key: "activity_name",
          minWidth: 100,
        },
        {
          title: "活动状态",
          slot: "start_name",
          minWidth: 100,
        },
        {
          title: "结束时间",
          slot: "stop_time",
          minWidth: 130,
        },
        {
          title: "状态",
          slot: "status",
          minWidth: 100,
        },
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          width: 100,
        },
      ],
      tableList: [],
      grid: {
        xl: 7,
        lg: 10,
        md: 12,
        sm: 24,
        xs: 24,
      },
      tableFrom: {
        start_status: "",
        status: "",
        store_name: "",
        page: 1,
        limit: 15,
      },
      total: 0,
    };
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
  created() {
    this.getList();
  },
  methods: {
    // 添加
    add() {
      this.$router.push({ path: this.roterPre + "/marketing/store_seckill/create" });
    },
    // 数据导出；
    async exports() {
      let [th, filekey, data, fileName] = [[], [], [], ""];
      //   let fileName = "";
      let excelData = JSON.parse(JSON.stringify(this.tableFrom));
      excelData.page = 1;
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
        storeSeckillApi(excelData).then((res) => {
          return resolve(res.data);
        });
      });
    },
    // 编辑
    edit(row) {
      this.$router.push({
        path: this.roterPre + "/marketing/store_seckill/create/" + row.id + "/0",
      });
    },
    // 一键复制
    copy(row) {
      this.$router.push({
        path: this.roterPre + "/marketing/store_seckill/create/" + row.id + "/1",
      });
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `marketing/seckill/${row.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
          .then((res) => {
            this.$Message.success(res.msg);
            this.tableList.splice(num, 1);
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
    viewInfo(row) {
      this.$router.push({
        path: this.roterPre+'/marketing/store_seckill/statistics/' + row.id,
      });
    },
    // 列表
    getList() {
      this.loading = true;
      this.tableFrom.start_status = this.tableFrom.start_status || "";
      this.tableFrom.status = this.tableFrom.status || "";
      seckillProductList(this.tableFrom)
          .then(async (res) => {
            let data = res.data;
            this.tableList = data.list;
            this.total = res.data.count;
            this.loading = false;
          })
          .catch((res) => {
            this.loading = false;
            this.$Message.error(res.msg);
          });
    },
    pageChange(index) {
      this.tableFrom.page = index;
      this.getList();
    },
    // 表格搜索
    userSearchs() {
      this.tableFrom.page = 1;
      this.getList();
    },
    // 重置
    reset() {
      this.tableFrom = {
        start_status: "",
        status: "",
        store_name: "",
        page: 1,
        limit: 15,
      };
      this.getList();
    },
    // 修改是否显示
    onchangeIsShow(row) {
      let data = {
        id: row.id,
        status: row.status,
      };
      seckillProductStatus(data)
          .then(async (res) => {
            this.$Message.success(res.msg);
            this.getList()
          })
          .catch((res) => {
            this.$Message.error(res.msg);
          });
    },
  },
};
</script>

<style scoped lang="stylus">
.line2{
  max-height 36px;
}
.tabBox_img {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  cursor: pointer;

  img {
    width: 100%;
    height: 100%;
  }
}
</style>
