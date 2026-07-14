<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
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
              <Option value="user">用户模块</Option>
              <Option value="user_card">用户卡项模块</Option>
              <Option value="goods">商品模块</Option>
            </Select>
            <Button type="primary" @click="searchHandle" class="ml-14"
              >查询</Button
            >
            <Button @click="reset" class="ml-14">重置</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Table
        :columns="columns"
        :data="tableData"
        ref="table"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row, index }" slot="action">
          <a
            :style="{ cursor: row.status ? '' : 'not-allowed' }"
            @click="deleteHandle(row, '删除导入记录', index)"
            >删除</a
          >
          <Divider v-if="row.status && row.fail_count" type="vertical" />
          <a
            v-if="row.status && row.fail_count"
            :style="{ cursor: row.status ? '' : 'not-allowed' }"
            @click="errorDownHandle({ record_id: row.id, type: row.import_type })"
            >下载失败记录</a
          >
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
        type: ' ',
      },
      timeVal: [],
      columns: [
        {
          title: '报表名称',
          key: 'name',
          minWidth: 260,
        },
        {
          title: '业务模块',
          key: 'type',
          minWidth: 100,
          render: (h, params) => {
            return h(
              'span',
               params.row.import_type === 'user' ? '用户模块' : params.row.import_type === 'user_card'?'用户卡项模块':'商品模块'
            );
          },
        },
        {
          title: '导入状态',
          key: 'status',
          minWidth: 150,
          render: (h, params) => {
            if (params.row.status === 0) {
              return h('span', '正在导入');
            } else if (params.row.status === -1) {
              return h('span', '导入失败');
            } else if (params.row.status === 1) {
              return h('span', '导入成功');
            }
            return h('span', '未知状态');
          },
        },
        {
          title: '预计导入数',
          key: 'total_count',
          minWidth: 100,
        },
        {
          title: '实际导入数',
          minWidth: 100,
          render: (h, params) => {
            return h('span', params.row.total_count - params.row.fail_count - params.row.jump_count);
          },
        },
        {
          title: '失败数',
          key: 'fail_count',
          minWidth: 100,
        },
        {
          title: '下载次数',
          key: 'down_count',
          minWidth: 100,
        },
        {
          title: '操作人',
          key: 'admin_name',
          minWidth: 150,
        },
        {
          title: '创建时间',
          key: 'add_time',
          minWidth: 150,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          minWidth: 150,
        },
      ],
      tableData: [],
      loading: false,
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
    this.formData.type = this.$route.params.type || '';
    this.getList();
  },
  methods: {
    getList() {
      getImportList(this.formData).then((res) => {
        const { count, list } = res.data;
        this.total = count;
        this.tableData = list;
      });
    },
    dateChange(date) {
      this.timeVal = date;
      this.formData.data = date.join('-');
    },
    searchHandle(type) {
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
    deleteHandle(row, tit, num) {
      if (!row.status) {
        return;
      }
      let delfromData = {
        title: tit,
        num: num,
        url: `/export/import/del/${row.id}`,
        method: 'GET',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.tableData.splice(num, 1);
          if (!this.tableData.length) {
            this.formData.page =
              this.formData.page == 1 ? 1 : this.formData.page - 1;
          }
          this.getList();
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 下载失败记录
    errorDownHandle({ record_id, type }) {
      getImportErrorDown({
        record_id,
        type,
      }).then((res) => {
        const fileName = res.data.filename;
        const fileKey = res.data.filekey;
        const th = res.data.header;
        const exportData = res.data['export'];
        exportExcel(th, fileKey, fileName, exportData);
      });
    },
  },
};
</script>

<style lang="stylus" scoped></style>
