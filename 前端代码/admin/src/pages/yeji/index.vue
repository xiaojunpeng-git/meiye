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
          <FormItem label="类型：">
            <Select
                v-model="formData.type"
                class="input-add"
                clearable
                placeholder="请选择"
                @on-change="searchHandle"
            >
              <Option value=" ">全部</Option>
              <Option value="1">充值</Option>
              <Option value="2">购卡</Option>
              <Option value="3">消耗</Option>
            </Select>
          </FormItem>
          <FormItem label="关联ID：">
            <Input
                placeholder="请输入关联ID"
                v-model="formData.link_id"
                class="input-add"
            />
          </FormItem>
          <FormItem label="订单ID：">
            <Input
                placeholder="请输入订单ID"
                v-model="formData.order_id"
                class="input-add"
            />
          </FormItem>
          <FormItem label="关键词：">
            <Input
                placeholder="请输入员工姓名/员工编号"
                v-model="formData.keyword"
                class="input-add"
            />
          </FormItem>
          <Button type="primary" @click="searchHandle" class="ml-14"
          >查询</Button
          >
          <Button @click="reset" class="ml-14">重置</Button>
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
        <template slot-scope="{ row, index }" slot="price">
               <span>{{ row.price }}</span>
<!--              <div v-if="row.type == 3">-->
<!--                     <div>单次消耗金额:{{ row.once_price }}</div>-->
<!--                     <div>消耗:{{ row.value}}次</div>-->
<!--              </div>-->
        </template>
        <template slot-scope="{ row, index }" slot="link_id">
               <span  v-if="row.type == 1">充值ID：{{ row.link_id }}</span>
               <span  v-if="row.type == 2">订单ID：{{ row.link_id }}</span>
               <span  v-if="row.type == 3">核销ID：{{ row.link_id }}</span>
<!--              <div v-if="row.type == 3">-->
<!--                     <div>单次消耗金额:{{ row.once_price }}</div>-->
<!--                     <div>消耗:{{ row.value}}次</div>-->
<!--              </div>-->
        </template>
        <template slot-scope="{ row, index }" slot="action">

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
import { yejiList } from '@/api/yeji';

export default {
  data() {
    return {
      options: timeOptions,
      formData: {
        page: 1,
        limit: 10,
        created_time: '',
        status: '',
        keyword: '',
        order_id: '',
        link_id: '',
        type: '',
      },
      timeVal: [],
      columns: [
        {
          title: '员工编号',
          key: 'staff_id',
          minWidth: 100,
        },
        {
          title: '员工姓名',
          key: 'staff_name',
          minWidth: 100,
        },
        {
          title: '职位',
          key: 'position_label',
          minWidth: 100,
        },
        {
          title: '职级',
          key: 'position_level_label',
          minWidth: 100,
        },
        {
          title: '类型',
          key: 'type',
          minWidth: 100,
          render: (h, params) => {
            if (params.row.type === 1) {
              return h('span', '充值');
            } else if (params.row.type === 2) {
              return h('span', '购卡');
            }else{
              return h('span', '消耗');
            }
          },
        },
        {
          title: '业绩',
          key: 'yeji',
          minWidth: 100,
        },
        {
          title: '关联id',
          slot: 'link_id',
          minWidth: 130,
        },
        {
          title: '商品编号',
          key: 'goods_id',
          minWidth: 100,
        },
        {
          title: '参与金额',
          slot: 'price',
          minWidth: 100
        },
        {
          title: '订单ID',
          key: 'order_id',
          minWidth: 80,
        },
        {
          title: '是否点客',
          key: 'is_dian',
          minWidth: 80,
          render: (h, params) => {
            if (params.row.is_dian === 0) {
              return h('span', '否');
            } else{
              return h('span', '是');
            }
          }
        },
        {
          title: '创建时间',
          key: 'created_time',
          minWidth: 130,
        }
        // {
        //   title: '操作',
        //   slot: 'action',
        //   fixed: 'right',
        //   minWidth: 150,
        // },
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
    this.formData.type = this.$route.query.type || '';
    this.formData.order_id = this.$route.query.order_id || '';
    this.formData.link_id = this.$route.query.link_id || '';
    this.formData.keyword = this.$route.query.keyword || '';
    this.getList();
  },
  methods: {
    getList() {
      yejiList(this.formData).then((res) => {
        const { count, list } = res.data;
        this.total = count;
        this.tableData = list;
      });
    },
    dateChange(date) {
      this.timeVal = date;
      this.formData.created_time = date.join('-');
    },
    searchHandle(type) {
      this.formData.page = 1;
      this.getList();
    },
    reset() {
      this.formData.page = 1;
      this.formData.created_time = '';
      this.timeVal = [];
      this.formData.status = '';
      this.formData.keyword = '';
      this.formData.type = '';
      this.getList();
    },
    pageChange(page) {
      this.formData.page = page;
      this.getList();
    }
  },
};
</script>

<style lang="stylus" scoped></style>
