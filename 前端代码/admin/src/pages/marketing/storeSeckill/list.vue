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
          <FormItem label="是否开启：">
            <Select
                placeholder="请选择"
                clearable
                v-model="tableFrom.status"
                @on-change="userSearchs"
                class="input-add"
            >
              <Option value="1">开启</Option>
              <Option value="0">关闭</Option>
            </Select>
          </FormItem>
          <FormItem label="商品搜索：" label-for="store_name">
            <Input
                placeholder="请输入秒杀名称，ID"
                v-model="tableFrom.store_name"
                @on-search="userSearchs"
                class="input-add"
            />
            <Button type="primary" @click="userSearchs()" class="ml-14">查询</Button>
            <Button @click="reset" class="ml-14">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 操作 -->
      <Button
          v-auth="['marketing-store_seckill-create']"
          type="primary"
          @click="add"
          class="mr10"
      >添加秒杀活动</Button
      >
      <!-- 秒杀活动-表格 -->
      <Table
          :columns="columns1"
          :data="tableList"
          :loading="loading"
          highlight-row
          no-userFrom-text="暂无数据"
          no-filtered-userFrom-text="暂无筛选结果"
          class="ivu-mt"
      >
        <template slot-scope="{ row }" slot="days">
          <div>{{row.start_day}} - {{row.end_day}}</div>
        </template>
        <template slot-scope="{ row }" slot="times">
          <div>
            <div class="time" v-for="j in row.time_list">{{j.start_time}} - {{j.end_time}};</div>
          </div>
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
            <span slot="open">开启</span>
            <span slot="close">关闭</span>
          </i-switch>
        </template>
        <template slot-scope="{ row, index }" slot="action">
          <a @click="edit(row)">编辑</a>
          <Divider type="vertical" />
          <a @click="copy(row)">复制</a>
          <Divider type="vertical" />
          <a @click="del(row, '删除秒杀商品', index)">删除</a>
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
  seckillListApi,
  seckillStatusApi,
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
          title: "活动名称",
          key: "name",
          minWidth: 150,
        },
        {
          title: "活动日期",
          slot: "days",
          minWidth: 150,
        },
        {
          title: "秒杀场次",
          slot: "times",
          minWidth: 100,
        },
        {
          title: "参与商品",
          key: "product_count",
          minWidth: 100,
        },
        {
          title: "活动状态",
          slot: "start_name",
          minWidth: 100,
        },
        {
          title: "是否开启",
          slot: "status",
          minWidth: 100,
        },
        {
          title: "创建时间",
          key: "add_time",
          minWidth: 130,
        },
        {
          title: "操作",
          slot: "action",
          fixed: "right",
          width: 140,
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
            if (!this.tableList.length) {
              this.tableFrom.page =
                  this.tableFrom.page == 1 ? 1 : this.tableFrom.page - 1;
            }
            this.getList();
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
      seckillListApi(this.tableFrom)
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
      seckillStatusApi(data)
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
.ivu-mt .time~.time{
  margin-top 6px;
}
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