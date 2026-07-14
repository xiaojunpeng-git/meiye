<template>
  <div>
    <div class="table-wrapper">
      <Table :columns="$data[type].columns" :data="$data[type].data">
        <template slot-scope="{ row }" slot="coupon_price">
          <span v-if="row.coupon_type == 1">{{ row.coupon_price }}元</span>
          <span v-if="row.coupon_type == 2"
            >{{ parseFloat(row.coupon_price) / 10 }}折（{{
              row.coupon_price.toString().split('.')[0]
            }}%）</span
          >
        </template>
        <template slot-scope="{ row }" slot="product">
          <div class="product">
            <div class="image" v-viewer>
              <img v-lazy="row.image" />
            </div>
            <div class="title">{{ row.store_name }}</div>
          </div>
        </template>
        <template slot-scope="{ row }" slot="productInfo">
            <div  v-for="(item,index) in row.product_names">{{ item }}</div>
        </template>
      </Table>
    </div>
    <div class="acea-row row-right page">
      <Page
        :total="$data[type].total"
        :current="$data[type].page"
        :page-size="limit"
        show-total
        @on-change="pageChange"
      />
    </div>
  </div>
</template>

<script>
import { getUserOneInfo } from '@/api/user';

export default {
  props: {
    userInfo: {
      type: Object,
      default() {
        return {};
      },
    },
    type: {
      type: String,
      default: '',
    },
  },
  data() {
    return {
      order: {
        columns: [
          {
            title: '订单ID',
            key: 'order_id',
            minWidth: 160,
          },
          {
            title: '收货人',
            key: 'real_name',
            minWidth: 100,
          },
          {
            title: '商品数量',
            key: 'total_num',
            minWidth: 90,
          },
          {
            title: '商品信息',
            slot: 'productInfo',
            minWidth: 90,
          },
          {
            title: '商品总价',
            key: 'total_price',
            minWidth: 110,
          },
          {
            title: '实付金额',
            key: 'pay_price',
            minWidth: 120,
          },
          {
            title: '交易完成时间',
            key: 'pay_time',
            minWidth: 120,
          },
        ],
        data: [],
        total: 0,
        page: 1,
      },
      integral: {
        columns: [
          {
            title: '来源/用途',
            key: 'title',
            minWidth: 120,
          },
          {
            title: '积分变化',
            key: 'number',
            minWidth: 120,
          },
          {
            title: '变化后积分',
            key: 'balance',
            minWidth: 120,
          },
          {
            title: '日期',
            key: 'add_time',
            minWidth: 120,
          },
          {
            title: '备注',
            key: 'mark',
            minWidth: 120,
          },
        ],
        data: [],
        total: 0,
        page: 1,
      },
      coupon: {
        columns: [
          {
            title: '优惠券名称',
            key: 'coupon_title',
            minWidth: 120,
          },
          {
            title: '面值',
            slot: 'coupon_price',
            minWidth: 120,
          },
          {
            title: '有效期(天)',
            key: 'coupon_time',
            minWidth: 120,
          },
          {
            title: '兑换时间',
            key: '_add_time',
            minWidth: 120,
          },
        ],
        data: [],
        total: 0,
        page: 1,
      },
      balance_change: {
        columns: [
          {
            title: '动作',
            key: 'title',
            minWidth: 120,
          },
          {
            title: '变动金额',
            key: 'number',
            minWidth: 120,
          },
          {
            title: '变动后',
            key: 'balance',
            minWidth: 120,
          },
          {
            title: '创建时间',
            key: 'add_time',
            minWidth: 120,
          },
          {
            title: '备注',
            key: 'mark',
            minWidth: 120,
          },
        ],
        data: [],
        total: 0,
        page: 1,
      },
      visit: {
        columns: [
          {
            title: '商品信息',
            slot: 'product',
            width: 500,
          },
          {
            title: '价格',
            key: 'product_price',
            render: (h, params) => {
              return h('div', `¥${params.row.product_price}`);
            },
          },
          {
            title: '浏览时间',
            key: 'add_time',
          },
        ],
        data: [],
        total: 0,
        page: 1,
      },
      spread_change: {
        columns: [
          {
            title: '推荐人ID',
            key: 'spread_uid',
            minWidth: 120,
          },
          {
            title: '推荐人',
            key: 'nickname',
            minWidth: 120,
            render: (h, params) => {
              return h('div', [
                h('img', {
                  style: {
                    borderRadius: '50%',
                    marginRight: '10px',
                    verticalAlign: 'middle',
                  },
                  attrs: {
                    with: 38,
                    height: 38,
                  },
                  directives: [
                    {
                      name: 'lazy',
                      value: params.row.avatar,
                    },
                    {
                      name: 'viewer',
                    },
                  ],
                }),
                h(
                  'span',
                  {
                    style: {
                      verticalAlign: 'middle',
                    },
                  },
                  params.row.nickname
                ),
              ]);
            },
          },
          {
            title: '变更方式',
            key: 'type',
            minWidth: 120,
          },
          {
            title: '变更时间',
            key: 'spread_time',
            minWidth: 120,
          },
        ],
        data: [],
        total: 0,
        page: 1,
      },
      card_holder: {
        columns: [
          {
            title: '卡项名称',
            key: 'card_name',
            minWidth: 120,
          },
          {
            title: '剩余次数',
            key:'write_surplus_times',
            minWidth: 120,
          },
          {
            title: '总次数',
            key: 'write_times',
            minWidth: 120,
          },
          {
            title: '购买时间',
            key: 'add_time',
            minWidth: 120,
          },
          {
            title: '到期时间',
            key: 'add_time',
            minWidth: 120,
            render: (h, params) => {
              if (params.row.write_valid == 1) {
                return h('div', `永久有效`);
              } else if (params.row.write_valid == 2) {
                return h('div', `购买后${params.row.write_days}天有效`);
              } else if (params.row.write_valid == 3) {
             //   return h('div', `${params.row.write_start - params.row.write_end}`);
                return h('div', `${params.row.write_end}`);
              }
            },
          },
          {
            title: '操作',
            minWidth: 120,
            render: (h, params) => {
              return h('a', {
                on: {
                  click: () => {
                    this.$emit('cardHolderOpen', params.row);
                  },
                },
              }, '查看');
            },
          },
        ],
        data: [],
        total: 0,
        page: 1,
      },
      reservation_record: {
        columns: [
          {
            title: '预约服务',
            key: 'store_name',
            minWidth: 120,
            render: (h, params) => {
              return h('div', params.row.cart_info.productInfo.store_name);
            },
          },
          {
            title: '预约日期',
            key: 'reservation_time',
            minWidth: 120,
          },
          {
            title: '预约时间',
            key: 'reservation_start',
            minWidth: 120,
            render: (h, params) => {
              return h('div', `${params.row.reservation_start}-${params.row.reservation_end}`);
            },
          },
          {
            title: '预约门店',
            key: 'store_name',
            minWidth: 120,
          },
          {
            title: '服务人员',
            key: 'service_staff_name',
            minWidth: 120,
          },
          {
            title: '预约状态',
            key: 'status_name',
            minWidth: 120,
          },
        ],
        data: [],
        total: 0,
        page: 1,
      },
      limit: 10,
    };
  },
  watch: {
    userInfo: {
      handler(value) {
        if (!value.uid) {
          return;
        }
        Object.keys(this.$data).forEach((key) => {
          if (this[key].page) {
            this[key].page = 1;
          }
        });
        this.getUserOneInfo();
      },
      immediate: true,
    },
  },
  methods: {
    getUserOneInfo() {
      getUserOneInfo(this.userInfo.uid, {
        type: this.type,
        page: this[this.type].page,
        limit: this.limit,
      }).then((res) => {
        let { count, list } = res.data;
        this[this.type].data = list;
        this[this.type].total = count;
      });
    },
    pageChange(page) {
      this[this.type].page = page;
      this.getUserOneInfo();
    },
  },
};
</script>

<style lang="stylus" scoped>
.product {
  display: flex;
  margin-right: 150px;

  .image {
    width: 50px;
    height: 50px;
  }

  img {
    width: 100%;
    height: 100%;
    border-radius: 4px;
  }

  .title {
    flex: 1;
    padding-left: 13px;
    text-align: left;
  }
}
.table-wrapper {
  border: 1px solid #DDDDDD;
  border-radius: 10px;
  overflow: hidden;
}
</style>
