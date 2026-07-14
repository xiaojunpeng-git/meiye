<template>
  <!-- 营销-中奖记录 -->
  <div>
    <Card :bordered="false" dis-hover class="ivu-mt" :padding="0">
      <div class="new_card_pd">
        <!-- 查询条件 -->
        <Form
          ref="tableFrom"
          inline
          :model="tableFrom"
          :label-width="labelWidth"
          :label-position="labelPosition"
          @submit.native.prevent
        >
          <FormItem label="活动类型：">
            <Select
              v-model="tableFrom.factor"
              class="input-add"
              clearable
              @on-change="userSearch"
            >
              <Option
                v-for="item in lotteryList"
                :value="item.val"
                :key="item.val"
                >{{ item.text }}</Option
              >
            </Select>
          </FormItem>
          <FormItem label="奖品类型：">
            <Select
              v-model="tableFrom.type"
              class="input-add"
              clearable
              @on-change="userSearch"
            >
              <Option
                v-for="item in typeList"
                :value="item.val"
                :key="item.val"
                >{{ item.text }}</Option
              >
            </Select>
          </FormItem>
          <FormItem label="领取状态：">
            <Select
              v-model="tableFrom.is_receive"
              class="input-add"
              clearable
              @on-change="userSearch"
            >
              <Option value="0">未领取</Option>
              <Option value="1">已领取</Option>
            </Select>
          </FormItem>
          <FormItem label="活动信息：">
            <Input
              placeholder="请输入活动名称/ID"
              v-model="tableFrom.keyword"
              class="input-add"
            />
            <Button type="primary" @click="userSearch" class="ml-14"
              >查询</Button
            >
            <!-- <Button @click="reset" class="ml-14">重置</Button> -->
            <Button class="ml-14" @click="exportHandle">导出</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <!-- 中奖记录-表格 -->
      <Table
        :columns="columns1"
        :data="tableList"
        :loading="loading"
        highlight-row
        no-userFrom-text="暂无数据"
        no-filtered-userFrom-text="暂无筛选结果"
      >
        <template slot-scope="{ row }" slot="is_fail">
          <Icon
            type="md-checkmark"
            v-if="row.is_fail === 1"
            color="#0092DC"
            size="14"
          />
          <Icon type="md-close" v-else color="#ed5565" size="14" />
        </template>
        <template slot-scope="{ row }" slot="user">
          <a @click="showUserInfo(row)" v-if="row.user">{{
            row.user.nickname
          }}</a>
          <span
            style="color: #ed4014"
            v-if="row.user && row.user.delete_time != null"
          >
            (已注销)</span
          >
        </template>
        <template slot-scope="{ row }" slot="mark">
          <span>{{ row.deliver_info.mark }}</span>
        </template>
        <template slot-scope="{ row }" slot="receive_info">
          <div v-if="row.receive_info.name">
            <div>姓名：{{ row.receive_info.name }}</div>
            <div>电话：{{ row.receive_info.phone }}</div>
            <div>地址：{{ row.receive_info.address }}</div>
            <div v-if="row.receive_info.mark">
              备注：{{ row.receive_info.mark }}
            </div>
          </div>
        </template>
        <template slot-scope="{ row }" slot="prize">
          <div class="prize">
            <span v-if="row.prize.type == 5"
              >优惠券：{{ row.prize.prize_name || '' }}</span
            >
            <span v-if="row.prize.type == 2">积分：{{ row.prize.num }}</span>
            <span v-if="row.prize.type == 6"
              >商品：{{ row.prize.prize_name || '' }}</span
            >
            <span v-if="row.prize.type == 4">红包：{{ row.prize.num }}</span>
            <span v-if="row.prize.type == 3">余额：{{ row.prize.num }}</span>
          </div>
        </template>
        <template slot-scope="{ row }" slot="action">
          <a @click="tapMark(row)">备注</a>
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
    <!--备注-->
    <Modal v-model="modals" scrollable title="备注" :closable="false">
      <Form
        ref="formValidate"
        :model="markForm"
        :label-width="80"
        @submit.native.prevent
      >
        <FormItem label="备注：" prop="remark">
          <Input
            v-model="markForm.mark"
            maxlength="200"
            show-word-limit
            type="textarea"
            placeholder="请输入备注"
          />
        </FormItem>
      </Form>
      <div slot="footer">
        <Button type="primary" @click="putRemark">提交</Button>
        <Button @click="closeRemark">关闭</Button>
      </div>
    </Modal>
    <!-- 用户信息 -->
    <user-details ref="userDetails" fromType="order"></user-details>
  </div>
</template>

<script>
import { mapState } from 'vuex';
import {
  recordList,
  lotteryRecordDeliver,
  luckRecordExportApi,
} from '@/api/lottery';
import exportExcel from '@/utils/newToExcel.js';
import userDetails from '@/pages/user/list/handle/userDetails';
export default {
  name: 'lotteryRecordList',
  components: {
    userDetails,
  },
  data() {
    return {
      modals: false,
      loading: false,
      markForm: {
        id: '',
        mark: '',
      },
      lotteryList: [
        { text: '积分抽奖', val: '1' },
        { text: '订单支付', val: '3' },
        { text: '订单评价', val: '4' },
        { text: '关注公众号', val: '5' },
      ],
      typeList: [
        { text: '积分', val: '2' },
        { text: '余额', val: '3' },
        { text: '红包', val: '4' },
        { text: '优惠券', val: '5' },
        { text: '商品', val: '6' },
      ],
      columns1: [
        {
          title: 'ID',
          key: 'id',
          width: 80,
        },
        {
          title: '用户信息',
          slot: 'user',
          minWidth: 90,
        },
        {
          title: '活动名称',
          minWidth: 130,
          render: (h, params) => {
            return h('div', params.row.lottery.name);
          },
        },
        {
          title: '活动类型',
          minWidth: 130,
          render: (h, params) => {
            let type = '';
            switch (params.row.lottery.factor) {
              case 1:
                type = '积分抽奖';
                break;
              case 3:
                type = '订单支付';
                break;
              case 4:
                type = '订单评价';
                break;
              case 5:
                type = '关注公众号';
                break;
            }
            return h('div', type);
          },
        },
        {
          title: '奖品名称',
          minWidth: 130,
          render: (h, params) => {
            return h('div', params.row.prize.name || '');
          },
        },
        {
          title: '奖品详情',
          slot: 'prize',
          minWidth: 130,
        },
        {
          title: '领取状态',
          minWidth: 100,
          render: (h, params) => {
            return h('div', params.row.is_receive ? '已领取' : '未领取');
          },
        },
        {
          title: '收货信息',
          slot: 'receive_info',
          minWidth: 130,
        },
        {
          title: '备注',
          slot: 'mark',
          minWidth: 100,
        },
        {
          title: '操作',
          slot: 'action',
          minWidth: 130,
        },
      ],
      tableList: [],
      grid: {
        xl: 6,
        lg: 10,
        md: 12,
        sm: 24,
        xs: 24,
      },
      tableFrom: {
        type: '',
        factor: '',
        keyword: '',
        is_receive: '',
        page: 1,
        limit: 15,
      },
      total: 0,
      modelType: 1,
      lottery_id: '',
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
    this.tableFrom.keyword = this.$route.query.id || '';
    this.getList();
  },
  methods: {
    clear() {
      this.markForm = {
        id: '',
        mark: '',
      };
    },
    tapMark(row) {
      this.markForm.id = row.id;
      this.markForm.mark = row.deliver_info.mark;
      this.modals = true;
    },
    closeRemark() {
      this.modals = false;
      this.clear();
    },
    putRemark() {
      lotteryRecordDeliver(this.markForm)
        .then(() => {
          this.$Message.success('操作成功');
          this.modals = false;
          this.getList();
          this.clear();
        })
        .catch((err) => {
          this.$Message.error(err.msg);
        });
    },
    // 列表
    getList() {
      this.loading = true;
      recordList(this.tableFrom)
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
    userSearch() {
      this.tableFrom.page = 1;
      this.getList();
    },
    // 重置
    // reset() {
    //   this.tableFrom = {
    //     type: '',
    //     factor: '',
    //     keyword: '',
    //     page: 1,
    //     limit: 15,
    //   };
    //   this.getList();
    // },
    getExcelData(tableFrom) {
      return new Promise((resolve, reject) => {
        luckRecordExportApi(tableFrom).then((res) => {
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
      const tableFrom = { ...this.tableFrom, page: 1 };

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
          resData.export.forEach((item) => {
            if (Array.isArray(item.address)) {
              item.address = '';
            } else {
              item.address = [
                `姓名：${item.address.name || '-'}`,
                `电话：${item.address.phone || '-'}`,
                `地址：${item.address.address || '-'}`,
              ].join('，');
            }
          });
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
    showUserInfo(row) {
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.activeName = 'info';
      this.$refs.userDetails.getDetails(row.uid);
    },
  },
};
</script>

<style scoped lang="stylus">
.prize {
  display: flex;
  align-items: center;
}
</style>
