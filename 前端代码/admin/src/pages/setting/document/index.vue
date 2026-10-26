<template>
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 筛选条件 -->
        <Form
          ref="specsFrom"
          inline
          :model="specsFrom"
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
        >
          <FormItem label="打印机名称：">
            <Input
              v-model="specsFrom.keyword"
              placeholder="请输入打印机名称"
              class="input-add"
            ></Input>
          </FormItem>
          <FormItem label="平台选择：">
            <Select class="input-add" v-model="specsFrom.plat_type">
              <Option
                v-for="(item, i) in optionsList"
                :value="item.value"
                :key="i"
                >{{ item.label }}
              </Option>
            </Select>
            <Button type="primary" @click="specsSearchs">查询</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 相关操作 -->
      <Row type="flex">
        <Col v-bind="grid">
          <Button type="primary" @click="add">添加打印机</Button>
        </Col>
      </Row>
      <!-- 商品参数表格 -->
      <Table
        :columns="columns"
        :data="list"
        ref="table"
        class="mt25"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="type">
          <!-- 平台 -->
          <span v-if="row.plat_type == 1">易联云</span>
          <span v-if="row.plat_type == 2">飞鹅云</span>
        </template>
        <template slot-scope="{ row }" slot="account">
          <!-- 平台 -->
          <span v-if="row.plat_type == 1">{{ row.yly_user_id }}</span>
          <span v-if="row.plat_type == 2">{{ row.fey_user }}</span>
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
          <a @click="setting(row.id)">设计</a>
          <Divider type="vertical" />
          <a @click="edit(row)">编辑</a>
          <Divider type="vertical" />
          <a @click="del(row, '删除打印机', index)">删除</a>
        </template>
      </Table>
      <div class="acea-row row-right page">
        <Page
          :total="total"
          show-elevator
          show-total
          @on-change="pageChange"
          :page-size="specsFrom.limit"
          :current="specsFrom.page"
        />
      </div>
    </Card>
    <addTicket
      ref="ticket"
      :formData="currentRow"
      @printerList="getList"
    ></addTicket>
  </div>
</template>

<script>
import addTicket from './addTicket.vue';
import { mapState } from 'vuex';
import { printList, printForm, printSetStatus } from '@/api/setting';
import Setting from '@/setting';

export default {
  name: 'specs',
  components: {
    addTicket,
  },
  data() {
    return {
      grid: {
        xl: 10,
        lg: 10,
        md: 12,
        sm: 24,
        xs: 24,
      },
      loading: false,
      optionsList: [
        {
          value: '0',
          label: '全部',
        },
        {
          value: '1',
          label: '易联云',
        },
        {
          value: '2',
          label: '飞鹅云',
        },
      ],
      columns: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
        },
        {
          title: '打印机名称',
          key: 'name',
          minWidth: 100,
        },
        {
          title: '平台',
          slot: 'type',
          minWidth: 100,
        },
        {
          title: '应用账号',
          slot: 'account',
          minWidth: 100,
        },
        {
          title: '打印联数',
          key: 'print_num',
          width: 200,
        },
        {
          title: '创建时间',
          key: 'add_time',
          width: 200,
        },
        {
          title: '打印开关',
          slot: 'status',
          width: 200,
        },
        {
          title: '操作',
          slot: 'action',
          fixed: 'right',
          width: 140,
        },
      ],
      specsFrom: {
        page: 1,
        limit: 15,
        keyword: '',
        plat_type: '0',
      },
      list: [],
      total: 0,
      currentRow: {},
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
    this.getList();
  },
  methods: {
    specsSearchs() {
      this.specsFrom.page = 1;
      this.list = [];
      this.getList();
    },

    // 单位列表
    getList() {
      this.loading = true;
      printList(this.specsFrom)
        .then((res) => {
          let data = res.data;
          this.list = data.list;
          this.total = data.count;
          this.loading = false;
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    pageChange(index) {
      this.specsFrom.page = index;
      this.getList();
    },
    // 修改是否显示
    onchangeIsShow(row) {
      let data = {
        id: row.id,
        status: row.status,
      };
      printSetStatus(data)
        .then(async (res) => {
          this.$Message.success(res.msg);
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 添加
    add() {
      this.$refs.ticket.react();
      this.$refs.ticket.modals = true;
      this.$refs.ticket.id = 0;
    },
    //修改
    edit(row) {
      this.currentRow = row;
      this.$refs.ticket.modals = true;
      this.$refs.ticket.id = row.id;
    },
    // 设计
    setting(id) {
      this.$router.push({
        path: `${Setting.roterPre}/setting/shop/document/content`,
        query: { id },
      });
    },
    // 删除
    del(row, tit, num) {
      let delfromData = {
        title: tit,
        num: num,
        url: `print/del/${row.id}`,
        method: 'DELETE',
        ids: '',
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.list.splice(num, 1);
          if (!this.list.length) {
            this.specsFrom.page =
              this.specsFrom.page == 1 ? 1 : this.specsFrom.page - 1;
          }
          this.getList();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
  },
};
</script>

<style scoped lang="stylus">
.input-add {
  width: 250px;
  margin-right: 14px;
}
</style>