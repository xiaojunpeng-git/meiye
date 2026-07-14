<template>
  <div class="pt-4 pr-8 pl-8 pb-4">
    <div class="acea-row row-middle">
      <div class="w-3 h-16 mr-10 bg-w111-1890FF"></div>
      <div class="fw-500 fs-16 text-wlll-303133">基本信息</div>
    </div>
    <div class="pt-20 pb-20 mb-10 fs-13 text--w111-666">
      <div>卡项名称：{{ cardHolderData.card_name }}</div>
      <div class="acea-row">
        <div class="flex-33 mt-20">
          使用次数/总次数：{{
            cardHolderData.write_times - cardHolderData.write_surplus_times
          }}/{{ cardHolderData.write_times }}
        </div>
        <div class="flex-33 mt-20">
          开卡金额：{{ cardHolderData.pay_price }}
        </div>
        <div class="flex-33 mt-20">
          卡内剩余余额：{{ cardHolderData.remaining_price }}
        </div>
        <div class="flex-33 mt-20 acea-row">
          开卡门店：
          <div class="flex-1">{{ cardHolderData.store_name }}</div>
        </div>
        <div class="flex-33 mt-20">开卡时间：{{ cardHolderData.add_time }}</div>
        <div class="flex-33 mt-20" v-if="cardHolderData.write_valid == 1">
          有效期：永久有效
        </div>
        <div class="flex-33 mt-20" v-else-if="cardHolderData.write_valid == 2">
          有效期：购买后{{ cardHolderData.write_days }}天有效
        </div>
        <div class="flex-33 mt-20" v-else-if="cardHolderData.write_valid == 3">
          有效期：{{ cardHolderData.write_start }} -
          {{ cardHolderData.write_end }}
        </div>
      </div>
    </div>
    <Tabs>
      <TabPane label="卡项权益">
        <Table max-height="360" :columns="columns1" :data="cartInfo"></Table>
      </TabPane>
      <TabPane label="使用记录">
        <Table max-height="360" :columns="columns2" :data="records"></Table>
      </TabPane>
    </Tabs>
  </div>
</template>

<script>
import { cardBenefits, writeoffRecords } from '@/api/order';
import { userCardHolder } from '@/api/user';

export default {
  props: {
    cardHolder: {
      type: Object,
      default() {
        return {};
      },
    },
  },
  data() {
    return {
      columns1: [
        {
          title: '权益名称',
          minWidth: 110,
          render: (h, params) => {
            return h(
              'div',
              `${params.row.cart_info.productInfo.store_name}${params.row.cart_info.productInfo.attrInfo.suk}`
            );
          },
        },
        {
          title: '总次数',
          key: 'write_times',
        },
        {
          title: '使用次数',
          render: (h, params) => {
            return h(
              'div',
              params.row.write_times - params.row.write_surplus_times
            );
          },
        },
        {
          title: '剩余次数',
          key: 'write_surplus_times',
        },
        {
          title: '购买金额',
          key: 'pay_price',
        },
      ],
      columns2: [
        {
          title: '权益名称',
          key: 'store_name',
          minWidth: 110,
        },
        {
          title: '本次使用次数',
          key: 'writeoff_num',
        },
        {
          title: '剩余次数',
          key: 'write_surplus_times',
        },
        {
          title: '本次消耗金额',
          key: 'writeoff_price',
        },
        {
          title: '服务门店',
          key: 'name',
        },
        {
          title: '服务店员',
          key: 'staff_name',
        },
        {
          title: '使用时间',
          key: 'add_time',
        },
      ],
      cardHolderData: {},
      cartInfo: [],
      records: [],
    };
  },
  watch: {
    'cardHolder.id'(val) {
      if (!val) {
        return;
      }
      this.getCardHolder();
      this.getOrderBenefits();
      this.getWriteoffRecords();
    },
  },
  methods: {
    getCardHolder() {
      userCardHolder(this.cardHolder.id).then((res) => {
        this.cardHolderData = res.data;
      });
    },
    getOrderBenefits() {
      cardBenefits(this.cardHolder.oid).then((res) => {
        this.cartInfo = res.data;
      });
    },
    getWriteoffRecords() {
      writeoffRecords(this.cardHolder.oid).then((res) => {
        this.records = res.data;
      });
    },
  },
};
</script>

<style lang="less" scoped>
/deep/.ivu-tabs-bar {
  padding-top: 0 !important;
  border-bottom: 0;
  margin-bottom: 14px;
}
/deep/.ivu-tabs-ink-bar {
  display: none;
  top: 0;
  bottom: auto;
}
/deep/.ivu-tabs .ivu-tabs-nav .ivu-tabs-tab {
  height: 36px;
  padding-top: 0;
  padding-bottom: 0;
  padding-right: 18px;
  padding-left: 18px;
  border-radius: 18px;
  margin-right: 0;
  line-height: 36px;
  font-size: 14px;
  color: #666666;
}
/deep/.ivu-tabs .ivu-tabs-nav .ivu-tabs-tab-active {
  background: #1890ff;
  color: #ffffff;
}
/deep/.ivu-tabs-nav-wrap {
  margin-bottom: 0;
}
/deep/.ivu-table-wrapper {
  border: 1px solid #d8d8d8;
  border-radius: 10px;
}
</style>
