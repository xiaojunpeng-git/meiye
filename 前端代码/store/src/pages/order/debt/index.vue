<template>
  <div>
    <Card :bordered="false" dis-hover class="mt15 ivu-mt" :padding="0">
      <div class="new_card_pd">
        <Form inline :label-width="80" @submit.native.prevent>
          <FormItem label="欠款时间：">
            <DatePicker
              type="daterange"
              format="yyyy/MM/dd"
              :value="timeVal"
              placement="bottom-start"
              placeholder="选择日期"
              class="input-add"
              @on-change="onTimeChange"
            />
            <Button class="ml-10" size="small" @click="quickTime('today')">今天</Button>
            <Button class="ml-6" size="small" @click="quickTime(3)">近3天</Button>
            <Button class="ml-6" size="small" @click="quickTime(7)">近7天</Button>
          </FormItem>
          <FormItem label="客户姓名：">
            <Input v-model="formData.keyword" placeholder="姓名/手机号" class="input-add" />
            <Button type="primary" class="ml-14" @click="search">搜索</Button>
          </FormItem>
        </Form>
      </div>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt">
      <Tabs v-model="statusTab" @on-click="search">
        <TabPane label="全部" name="all" />
        <TabPane label="待还款" name="0" />
        <TabPane label="已结清" name="1" />
        <TabPane label="已关闭" name="2" />
        <TabPane label="已作废" name="3" />
      </Tabs>
      <div v-loading="loading">
        <div v-for="row in list" :key="row.id" class="debt-block mb-20">
          <div class="debt-block-head acea-row row-between-wrapper">
            <div>
              <span>欠款时间：{{ row.add_time_label }}</span>
              <span class="ml-20">欠款单号：{{ row.debt_no }}</span>
              <span class="ml-20">交易单号：{{ row.order_sn }}</span>
            </div>
            <div class="head-actions">
              <a class="mr-16" @click="openDetail(row)">欠款详情</a>
              <a @click="openRemark(row)">备注</a>
            </div>
          </div>
          <Table :columns="itemColumns" :data="buildTableRows(row)" size="small" :show-header="true">
            <template slot-scope="{ row: item }" slot="user">
              <div class="acea-row row-middle" v-if="item._isFirst">
                <img
                  :src="item.user && item.user.avatar ? item.user.avatar : require('@/assets/images/yonghu.png')"
                  class="user-avatar"
                  :class="{ 'user-link': hasUserId(item) }"
                  @click="openUserDetail(item)"
                />
                <div>
                  <div class="acea-row row-middle">
                    <a
                      v-if="hasUserId(item)"
                      href="javascript:void(0)"
                      class="user-name-link"
                      @click.prevent="openUserDetail(item)"
                    >{{ item.user && item.user.nickname ? item.user.nickname : '游客' }}</a>
                    <span v-else>{{ item.user && item.user.nickname ? item.user.nickname : '游客' }}</span>
                    <Tag v-if="levelName(item.user && item.user.level)" color="gold" class="vip-tag">
                      {{ levelName(item.user && item.user.level) }}
                    </Tag>
                  </div>
                  <a
                    v-if="hasUserId(item) && item.user && item.user.phone"
                    href="javascript:void(0)"
                    class="sub user-phone-link"
                    @click.prevent="openUserDetail(item)"
                  >{{ item.user.phone }}</a>
                  <div v-else class="sub">{{ item.user && item.user.phone }}</div>
                </div>
              </div>
            </template>
            <template slot-scope="{ row: item }" slot="product">
              <div v-if="item.product_name" class="product-cell">
                <Tag v-if="productTypeLabel(item.product_type)" class="type-tag">
                  {{ productTypeLabel(item.product_type) }}
                </Tag>
                <span>{{ item.product_name }}</span>
              </div>
            </template>
            <template slot-scope="{ row: item }" slot="cartNum">
              <span v-if="item.product_name">x {{ item.cart_num || 1 }}</span>
            </template>
            <template slot-scope="{ row: item }" slot="amount">
              <div v-if="item._isFirst">
                <div>总欠款：¥{{ Number(row.total_debt || 0).toFixed(2) }}</div>
                <div class="pending">待还款：¥{{ Number(row.pending_debt || 0).toFixed(2) }}</div>
              </div>
            </template>
            <template slot-scope="{ row: item }" slot="action">
              <template v-if="item._isFirst && row.status === 0">
                <a class="mr-10" @click="repay(row)">还款</a>
                <a @click="closeDebt(row)">关闭欠款</a>
              </template>
              <span v-else-if="item._isFirst">-</span>
            </template>
          </Table>
        </div>
        <div v-if="!loading && !list.length" class="text-center p-40 text-muted">暂无数据</div>
      </div>
      <div class="acea-row row-right page mt-20">
        <Page
          :total="total"
          :current.sync="page"
          :page-size="limit"
          show-total
          show-elevator
          @on-change="getList"
        />
      </div>
    </Card>
    <order-debt-detail ref="orderDebtDetail" />
    <user-details ref="userDetails" from-type="order"></user-details>
    <debt-repay-flow ref="debtRepayFlow" @success="getList" />
  </div>
</template>

<script>
import { debtListApi, debtCloseApi } from '@/api/debt';
import { levelListApi } from '@/api/user';
import orderDebtDetail from '@/components/orderDebtDetail';
import userDetails from '@/pages/user/components/userDetails2';
import debtRepayFlow from '@/components/debtRepayFlow';

export default {
  name: 'storeDebtIndex',
  components: { orderDebtDetail, userDetails, debtRepayFlow },
  data() {
    return {
      loading: false,
      list: [],
      total: 0,
      page: 1,
      limit: 20,
      statusTab: 'all',
      timeVal: [],
      formData: {
        keyword: '',
      },
      levelList: [],
      itemColumns: [
        { title: '客户信息', slot: 'user', minWidth: 180 },
        { title: '商品信息', slot: 'product', minWidth: 180 },
        { title: '数量', slot: 'cartNum', width: 80 },
        { title: '欠款金额', slot: 'amount', minWidth: 140 },
        { title: '欠款门店', key: 'store_name', minWidth: 120 },
        { title: '收银员', key: 'staff_name', minWidth: 100 },
        { title: '欠款时长', key: 'debt_duration', width: 90 },
        { title: '状态', key: 'status_label', width: 90 },
        { title: '操作', slot: 'action', width: 140 },
      ],
    };
  },
  mounted() {
    this.loadLevels();
    this.getList();
  },
  methods: {
    loadLevels() {
      levelListApi({ page: 1, limit: '', title: '', is_show: 1 }).then((res) => {
        this.levelList = res.data.list || [];
      });
    },
    levelName(level) {
      if (level === null || level === undefined || level === '') return '';
      const item = this.levelList.find((row) => Number(row.id) === Number(level) || Number(row.grade) === Number(level));
      return item ? item.name : `VIP${level}`;
    },
    productTypeLabel(type) {
      const map = {
        0: '产品',
        4: '会员卡',
        5: '卡项',
        6: '服务',
      };
      return map[Number(type)] || '';
    },
    buildTableRows(row) {
      const items = row.items && row.items.length ? row.items : [{ product_name: '-', cart_num: 1 }];
      return items.map((item, index) => ({
        ...row,
        ...item,
        _isFirst: index === 0,
        store_name: row.store_name,
        staff_name: row.staff_name,
        debt_duration: row.debt_duration,
        status_label: row.status_label,
      }));
    },
    onTimeChange(val) {
      this.timeVal = val;
      this.search();
    },
    quickTime(type) {
      const end = new Date();
      const start = new Date();
      if (type !== 'today') {
        start.setDate(start.getDate() - (type - 1));
      }
      const fmt = (d) => `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}`;
      this.timeVal = [fmt(start), fmt(end)];
      this.search();
    },
    search() {
      this.page = 1;
      this.getList();
    },
    getList() {
      this.loading = true;
      const params = {
        page: this.page,
        limit: this.limit,
        keyword: this.formData.keyword,
      };
      if (this.statusTab !== 'all') params.status = this.statusTab;
      if (this.timeVal && this.timeVal[0] && this.timeVal[1]) {
        const s = new Date(this.timeVal[0]).getTime() / 1000;
        const e = new Date(this.timeVal[1]).getTime() / 1000 + 86399;
        params.time = [s, e];
      }
      debtListApi(params)
        .then((res) => {
          this.list = res.data.list || [];
          this.total = res.data.count || 0;
        })
        .catch((err) => {
          this.$Message.error(err.msg || '加载失败');
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openDetail(row) {
      this.$refs.orderDebtDetail.open(row);
    },
    hasUserId(row) {
      return Number(row && row.uid) > 0;
    },
    openUserDetail(row) {
      if (!this.hasUserId(row)) return;
      this.$refs.userDetails.modals = true;
      this.$refs.userDetails.getDetails(Number(row.uid));
      this.$refs.userDetails.changeType('card_holder');
    },
    openRemark(row) {
      const content = row.remark || '暂无备注';
      this.$Modal.info({
        title: '备注',
        content,
      });
    },
    closeDebt(row) {
      this.$Modal.confirm({
        title: '关闭欠款',
        content: '确定关闭该欠款记录吗？关闭后状态将变为已关闭。',
        onOk: () => {
          debtCloseApi(row.id).then(() => {
            this.$Message.success('操作成功');
            this.getList();
          }).catch((err) => {
            this.$Message.error(err.msg || '操作失败');
          });
        },
      });
    },
    repay(row) {
      if (this.$refs.debtRepayFlow) {
        this.$refs.debtRepayFlow.open(row);
      }
    },
  },
};
</script>

<style scoped>
.debt-block-head {
  background: #f5f7fa;
  padding: 10px 16px;
  font-size: 13px;
  color: #606266;
}
.head-actions a {
  color: #6c5ce7;
}
.user-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  margin-right: 8px;
  object-fit: cover;
}
.user-link {
  cursor: pointer;
}
.user-name-link,
.user-phone-link {
  color: #2d8cf0;
  cursor: pointer;
}
.user-name-link:hover,
.user-phone-link:hover {
  text-decoration: underline;
}
.sub {
  color: #909399;
  font-size: 12px;
}
.pending {
  color: #ff4d4f;
}
.vip-tag {
  margin-left: 6px;
  transform: scale(0.9);
}
.product-cell {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}
.type-tag {
  margin-right: 6px;
}
.text-muted {
  color: #909399;
}
.ml-6 {
  margin-left: 6px;
}
.ml-10 {
  margin-left: 10px;
}
.ml-14 {
  margin-left: 14px;
}
.ml-16 {
  margin-left: 16px;
}
.ml-20 {
  margin-left: 20px;
}
.mr-10 {
  margin-right: 10px;
}
.mr-16 {
  margin-right: 16px;
}
</style>
