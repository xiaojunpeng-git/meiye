<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <Form
          ref="formData"
          :model="formData"
          :label-width="labelWidth"
          :label-position="labelPosition"
          class="tabform"
          inline
          @submit.native.prevent
        >
          <FormItem label="创建时间：">
            <DatePicker
              :editable="false"
              @on-change="dateChange"
              :value="timeVal"
              format="yyyy/MM/dd"
              type="daterange"
              placement="bottom-start"
              placeholder="自定义时间"
              class="input-add"
              :options="options"
            ></DatePicker>
          </FormItem>
          <FormItem label="导入状态：">
            <Select
              v-model="formData.status"
              class="input-add"
              clearable
              placeholder="请选择"
              @on-change="searchHandle"
            >
              <Option value=" ">全部</Option>
              <Option value="0">正在导入</Option>
              <Option value="-1">导入失败</Option>
              <Option value="1">导入成功</Option>
            </Select>
          </FormItem>
          <FormItem label="导入信息：">
            <Input
              placeholder="请输入报表名称/操作人"
              v-model="formData.keyword"
              class="input-add"
            />
          </FormItem>
          <FormItem label="业务模块：">
            <Select
              v-model="formData.type"
              class="input-add"
              clearable
              placeholder="请选择"
              @on-change="searchHandle"
            >
              <Option value="">全部</Option>
              <Option value="stock_initial_in">初始入库导入</Option>
              <Option value="stock_in">入库导入</Option>
              <Option value="stock_out">出库导入</Option>
              <Option value="goods">商品模块</Option>
            </Select>
            <Button type="primary" @click="searchHandle" class="ml-14">查询</Button>
            <Button @click="reset" class="ml-14">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <div class="op-tips mb10">
        仅显示本门店导入记录。库存导入失败请看「第 N 行」原因；可下载失败记录。同一文件内容成功后永久不可再导。
      </div>
      <Table
        :columns="columns"
        :data="tableData"
        ref="table"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="action">
          <a
            v-if="row.status && row.fail_count"
            @click="errorDownHandle({ record_id: row.id, type: row.import_type || row.type })"
            >下载失败记录</a
          >
          <span v-else class="tip">—</span>
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
        />
      </div>
    </Card>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import timeOptions from '@/utils/timeOptions';
import { getImportList, getImportErrorDown } from '@/api/system';
import exportExcel from '@/utils/newToExcel.js';

export default {
  data() {
    return {
      options: timeOptions,
      formData: {
        page: 1,
        limit: 10,
        data: '',
        status: '',
        keyword: '',
        type: '',
      },
      timeVal: [],
      columns: [
        { title: '报表名称', key: 'name', minWidth: 260 },
        {
          title: '业务模块',
          key: 'import_type',
          minWidth: 120,
          render: (h, params) => {
            const map = {
              goods: '商品模块',
              stock_initial_in: '初始入库导入',
              stock_in: '入库导入',
              stock_out: '出库导入',
            };
            const key = params.row.import_type || params.row.type;
            return h('span', map[key] || key || '-');
          },
        },
        {
          title: '导入状态',
          key: 'status',
          minWidth: 120,
          render: (h, params) => {
            if (params.row.status === 0) return h('span', '正在导入');
            if (params.row.status === -1) return h('span', '导入失败');
            if (params.row.status === 1) return h('span', '导入成功');
            return h('span', '未知状态');
          },
        },
        { title: '预计导入数', key: 'total_count', minWidth: 100 },
        {
          title: '失败数',
          key: 'fail_count',
          minWidth: 100,
        },
        { title: '操作人', key: 'admin_name', minWidth: 120 },
        { title: '创建时间', key: 'add_time', minWidth: 150 },
        { title: '操作', slot: 'action', fixed: 'right', minWidth: 120 },
      ],
      tableData: [],
      loading: false,
      total: 0,
    };
  },
  computed: {
    ...mapState('store/layout', ['isMobile']),
    labelWidth() {
      return this.isMobile ? undefined : 96;
    },
    labelPosition() {
      return this.isMobile ? 'top' : 'right';
    },
  },
  created() {
    this.getList();
  },
  methods: {
    getList() {
      this.loading = true;
      getImportList(this.formData)
        .then((res) => {
          const { count, list } = res.data;
          this.total = count;
          this.tableData = list;
        })
        .finally(() => {
          this.loading = false;
        });
    },
    dateChange(date) {
      this.timeVal = date;
      this.formData.data = date.join('-');
    },
    searchHandle() {
      this.formData.page = 1;
      this.getList();
    },
    reset() {
      this.formData.page = 1;
      this.formData.data = '';
      this.timeVal = [];
      this.formData.status = '';
      this.formData.keyword = '';
      this.formData.type = '';
      this.getList();
    },
    pageChange(page) {
      this.formData.page = page;
      this.getList();
    },
    errorDownHandle({ record_id, type }) {
      getImportErrorDown({ record_id, type }).then((res) => {
        exportExcel(res.data.header, res.data.filekey, res.data.filename, res.data.export);
      });
    },
  },
};
</script>

<style lang="stylus" scoped>
.op-tips
  color #999
  font-size 12px
  line-height 1.6
.mb10
  margin-bottom 10px
.tip
  color #ccc
</style>
