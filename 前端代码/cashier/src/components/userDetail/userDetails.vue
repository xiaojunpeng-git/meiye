<template>
  <Drawer
    :closable="false"
    width="1300"
    class-name="order_box"
    v-model="modals"
    :styles="{ padding: 0 }"
  >
    <div class="acea-row user-row">
      <div class="avatar mr15">
        <img :src="psInfo.avatar" />
      </div>
      <div class="user-row-text">
        <div>
          <span class="nickname">{{ psInfo.nickname || "-" }}</span>
          <i
            :class="{
              iconxiaochengxu: psInfo.user_type === 'routine',
              icongongzhonghao: psInfo.user_type === 'wechat',
              iconPC: psInfo.user_type === 'pc',
              iconh5: psInfo.user_type === 'h5',
              iconapp: psInfo.user_type === 'app',
            }"
            class="iconfont"
          ></i>
        </div>
        <div class="level">
          <img
            v-if="psInfo.is_money_level"
            src="@/assets/images/svip-user.png"
          />
          <span v-if="psInfo.level" class="vip">V{{ psInfo.level }}</span>
        </div>
      </div>
      <div class="user-row-action">
         <Button type="primary" @click="changeMoney">修改余额</Button>
        <Button @click="toPay">消耗</Button>
        <Button type="success" @click="changeMenu(1)">充值</Button>
        <Button @click="changeMenu(2)">切换用户</Button>
      </div>
    </div>
    <div class="acea-row info-row">
      <div
        v-for="(item, index) in detailsData"
        :key="index"
        class="info-row-item"
      >
        <div class="info-row-item-title">{{ item.title }}</div>
        <div>{{ item.value }}{{ item.key }}</div>
      </div>
    </div>
    <Tabs v-model="activeName" @on-click="onTabClick">
      <TabPane
        v-for="(item, index) in list"
        :key="index"
        :label="item.label"
        :name="item.val"
      >
        <template v-if="item.val === 'info'">
          <user-info v-show="!isEdit" :ps-info="psInfo"></user-info>
        </template>
        <template v-else-if="item.val === 'debt_record'">
          <user-debt-record
            ref="debtRecordList"
            :uid="userId"
            mode="debt"
            :store-name="debtStoreName"
            @repay="$emit('debtRepay', $event)"
          />
        </template>
        <template v-else-if="item.val === 'debt_repay'">
          <user-debt-record
            ref="debtRepayList"
            :uid="userId"
            mode="repay"
            :store-name="debtStoreName"
          />
        </template>
        <template v-else>
          <Table
            :columns="columns"
            :data="userLists"
            ref="table"
            :loading="loading"
            no-userFrom-text="暂无数据"
            no-filtered-userFrom-text="暂无筛选结果"
          >
            <template slot-scope="{ row }" slot="product_names">
              <div v-for="(item,index) in row.product_names">
                {{ item }}
              </div>
            </template>
            <template slot-scope="{ row }" slot="product">
              <div class="product">
                <div class="image" v-viewer>
                  <img v-lazy="row.image" />
                </div>
                <div class="title">{{ row.store_name }}</div>
              </div>
            </template>
            <template slot-scope="{ row }" slot="user">
              {{ row.user.real_name }}
              <span
                  style="color: #ed4014"
                  v-if="!row.user || row.user.delete_time != null"
              >
            (已注销)</span
              >
            </template>
          </Table>
          <div class="acea-row row-right page">
            <Page
              :total="total"
              :current.sync="userFrom.page"
              show-elevator
              show-total
              @on-change="pageChange"
              :page-size="userFrom.limit"
            />
          </div>
        </template>
      </TabPane>
    </Tabs>
    <editMoney
        ref="editMoney"
        @changeSuccess="changeSuccess"
        @close="close"
        :visible="editMoneyVisible"
        :userInfo="psInfo"
    ></editMoney>
  </Drawer>
</template>

<script>
import { detailsApi, infoApi, spreadList } from "@/api/user";
import userInfo from "./userInfo";
import userDebtRecord from "./userDebtRecord";
import Setting from '@/setting';
import editMoney from '@/components/editMoney';
export default {
  name: "userDetails",
  components: {
    userInfo,
    userDebtRecord,
    editMoney
  },
  props: {
    uid: [Number, String],
    debtStoreName: { type: String, default: '' },
  },
  data() {
    return {
      editMoneyVisible:false,
      theme2: "light",
      list: [
        { val: 'card_holder', label: '卡项权益' },
        { val: 'balance_change', label: '余额变动' },
        { val: 'coupon', label: '持有优惠券' },
        { val: 'order', label: '消费记录' },
        { val: 'hexiao', label: '核销记录' },
        { val: 'debt_record', label: '欠款记录' },
        { val: 'debt_repay', label: '还款记录' },
        { val: 'youzan', label: '有赞数据' },
        { val: 'info', label: '用户信息' },
        { val: 'integral', label: '积分明细' },
        { val: 'sign', label: '签到记录' },
        { val: 'spread', label: '好友关系' },
        { val: 'reservation_record', label: '预约记录' },
        { val: 'visit', label: '浏览足迹' },
        { val: 'spread_change', label: '推荐人变更记录' },
      ],
      modals: false,
      spinShow: false,
      detailsData: [],
      userId: 0,
      loading: false,
      userFrom: {
        type: "info",
        page: 1, // 当前页
        limit: 12, // 每页显示条数
      },
      total: 0,
      columns: [],
      userLists: [],
      psInfo: {},
      activeName: "info",
      isEdit: false,
      groupOptions: [],
      labelOptions: [],
    };
  },
  watch: {
    activeName(value) {
      this.userFrom.page = 1;
      if (value == "info" || value === "debt_record" || value === "debt_repay") {
        this.loading = false;
        return;
      } else {
        this.isEdit = false;
        this.changeType(value);
      }
    },
    modals(value) {
      if (value) {
        this.isEdit = false;
        this.$nextTick(() => {
          this.refreshActiveDebtTab();
        });
      }
    },
  },
  created() {},
  methods: {
    getDebtTabRef(refName) {
      const ref = this.$refs[refName];
      if (Array.isArray(ref)) {
        return ref[0];
      }
      return ref;
    },
    reloadDebtList(force) {
      const ref = this.getDebtTabRef('debtRecordList');
      if (ref && ref.reload) {
        ref.reload(force);
      }
    },
    reloadRepayList(force) {
      const ref = this.getDebtTabRef('debtRepayList');
      if (ref && ref.reload) {
        ref.reload(force);
      }
    },
    refreshActiveDebtTab(force) {
      if (this.activeName === 'debt_record') {
        this.reloadDebtList(force);
      } else if (this.activeName === 'debt_repay') {
        this.reloadRepayList(force);
      }
    },
    reloadDebtRecord(force) {
      this.reloadDebtList(force);
      this.reloadRepayList(force);
    },
    onTabClick(name) {
      if (name === 'debt_record') {
        this.$nextTick(() => {
          this.reloadDebtList(true);
        });
      } else if (name === 'debt_repay') {
        this.$nextTick(() => {
          this.reloadRepayList(true);
        });
      }
    },
    close(){
       this.editMoneyVisible=false;
    },
    changeSuccess(id){
          this.getDetails(id);
          this.$emit("changeSuccess");
    },
    toPay(){
      let that=this;
      this.$router.push({
        path: `${Setting.roterPre}/verify/index`,
        query: {
          keyword: that.psInfo.phone,
        },
      });
    },
    changeMenu(value) {
      this.$emit("operation", value);
    },
    changeMoney(){
        this.editMoneyVisible=true;
    },
    // 完成
    finish() {},
    // 会员详情
    getDetails(id) {
      this.userId = id;
      this.spinShow = true;
      detailsApi(id)
        .then(async (res) => {
          if (res.status === 200) {
            let data = res.data;
            this.detailsData = data.headerList;
            // if (this.fromType !== "order") {
            //   let groupItem = this.groupList.find(
            //     (item) => item.id == data.ps_info.group_id
            //   );
            //   if (groupItem) {
            //     data.ps_info.group_name = groupItem.group_name;
            //   }
            // }
            this.psInfo = data.ps_info;
            this.spinShow = false;
            this.$nextTick(() => {
              this.refreshActiveDebtTab(true);
            });
          } else {
            this.spinShow = false;
            this.$Message.error(res.msg);
          }
        })
        .catch((res) => {
          this.spinShow = false;
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.userFrom.page = index;
      this.changeType(this.userFrom.type);
    },
    delCoupon(row){
      this.$modalSure({
        title: '确认删除',
        info: '确认删除后不可撤销，是否确认？',
        url: `/order/delCoupon/${row.id}`,
        method: 'delete',
        ids:''
      }).then((res) => {
        this.$Message.success(res.msg);
        this.changeType('coupon');
      }).catch((res) => {
        this.$Message.error(res.msg);
      });
    },
    changeType(name) {
      this.loading = true;
      this.userFrom.type = name;
      this.activeName = name;
      if (name === 'info' || name === 'debt_record' || name === 'debt_repay') {
        this.loading = false;
        return;
      }
      let data = {
        id: this.userId,
        datas: this.userFrom,
      };
      infoApi(data)
            .then(async (res) => {
              if (res.status === 200) {
                let data = res.data;
                this.userLists = data.list;
                this.total = data.count;
                switch (this.userFrom.type) {
                  case 'hexiao':
                    this.columns=[
                      {
                        title: 'ID',
                        key: 'id',
                        minWidth: 80,
                      },
                      {
                        title: '订单号',
                        key: 'order_id',
                        minWidth: 150,
                      },
                      {
                        title: '客户名称',
                        slot: 'user',
                        minWidth: 100,
                      },
                      {
                        title: '手机号',
                        key: 'phone',
                        minWidth: 100,
                      },
                      {
                        title: '商品名称',
                        key: 'product_name',
                        minWidth: 150,
                      },
                      {
                        title: '手艺人',
                        key: 'yeji_staff',
                        minWidth: 150,
                      },
                      {
                        title: '核销金额',
                        key: 'writeoff_price',
                        minWidth: 150,
                      },
                      {
                        title: '下单门店',
                        key: 'ordering_store',
                        minWidth: 150,
                      },
                      {
                        title: '核销门店',
                        key: 'write_off_store',
                        minWidth: 150,
                      },
                      {
                        title: '核销人员',
                        key: 'staff_name',
                        minWidth: 150,
                      },
                      {
                        title: '核销时间',
                        key: 'add_time',
                        minWidth: 150,
                      },
                    ];
                    break;
                  case 'youzan':
                    this.columns=[
                      {
                        title: '订单编号',
                        key: 'ddjl',
                        minWidth: 80,
                      },
                      {
                        title: '商品分类',
                        key: 'spfl',
                        minWidth: 80,
                      },
                      {
                        title: '商品名称',
                        key: 'spmc',
                        minWidth: 80,
                      },
                      {
                        title: '商品数量',
                        key: 'sl',
                        minWidth: 60,
                      },
                      {
                        title: '商品单价',
                        key: 'dj',
                        minWidth: 60,
                      },
                      {
                        title: '订单状态',
                        key: 'ddzt',
                        minWidth: 80,
                      },
                      {
                        title: '完成时间',
                        key: 'wcsj',
                        minWidth: 100,
                      },
                      {
                        title: '手艺人',
                        key: 'syr',
                        minWidth: 80,
                      },
                      {
                        title: '销售',
                        key: 'ss',
                        minWidth: 80,
                      },
                      {
                        title: '点客',
                        key: 'dk',
                        minWidth: 80,
                      },
                      {
                        title: '下单门店',
                        key: 'xdmd',
                        minWidth: 80,
                      },
                      {
                        title: '收款合计',
                        key: 'skhj',
                        minWidth: 80,
                      },
                      {
                        title: '付款方式',
                        key: 'fkfs',
                        minWidth: 80,
                      },
                      {
                        title: '客户姓名',
                        key: 'order_id',
                        minWidth: 80,
                      },
                      {
                        title: '客户手机号',
                        key: 'phone',
                        minWidth: 100,
                      },
                    ];
                    break;
                  case 'order':
                    this.columns = [
                      {
                        title: '订单ID',
                        key: 'order_id',
                        minWidth: 160,
                      },
                      {
                        title: '所属门店',
                        key: 'store_name',
                        minWidth: 120,
                      },
                      {
                        title: '商品名称',
                        slot: 'product_names',
                        minWidth: 120,
                      },
                      {
                        title: '手艺/销售人',
                        key: 'yeji_staff',
                        minWidth: 100,
                      },
                      {
                        title: '商品数量',
                        key: 'total_num',
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
                    ];
                    break;
                  case 'integral':
                    this.columns = [
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
                        title: '变化前积分',
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
                    ];
                    break;
                  case 'sign':
                    this.columns = [
                      // {
                      //     title: '动作',
                      //     key: 'title',
                      //     minWidth: 120
                      // },
                      {
                        title: '获得积分',
                        key: 'number',
                        minWidth: 120,
                      },
                      {
                        title: '签到时间',
                        key: 'add_time',
                        minWidth: 120,
                      },
                      {
                        title: '备注',
                        key: 'mark',
                        minWidth: 120,
                      },
                    ];
                    break;
                  case 'coupon':
                    this.columns = [
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
                        title: '门槛',
                        key: 'use_min_price',
                        minWidth: 120,
                      },
                      {
                        title: '有效期(天)',
                        key: 'coupon_time',
                        minWidth: 120,
                      },
                      {
                        title: '优惠券来源',
                        minWidth: 120,
                        render: (h, params) => {
                          return h(
                              'div',
                              params.row.coupon_issue_type == 1 ? params.row.author : '平台'
                          );
                        },
                      },
                      {
                        title: '领取时间',
                        key: '_add_time',
                        minWidth: 120,
                      },
                      {
                        title: '使用时间',
                        align: 'center',
                        minWidth: 120,
                        render: (h, params) => {
                          const row = params.row;
                          let text = '—';
                          if (row.status == '已使用') {
                            text = row._use_time;
                          } else if (row.status == '已过期') {
                            text = row.status;
                          }
                          return h('div', text);
                        },
                      },
                      {
                        title: '操作',
                        minWidth: 120,
                        render: (h, params) => {
                          if(params.row.use_time == 0) {
                            return h(
                                'a',
                                {
                                  on: {
                                    click: () => {
                                      this.delCoupon(params.row);
                                    },
                                  },
                                },
                                '删除'
                            );
                          }else{
                            return '';
                          }
                        },
                      }
                    ];
                    break;
                  case 'balance_change':
                    this.columns = [
                      {
                        title: '动作',
                        key: 'title',
                        minWidth: 120,
                      },
                      {
                        title: '所属门店',
                        key: 'store_name',
                        minWidth: 120,
                      },
                      {
                        title: '销售',
                        key: 'link_label',
                        minWidth: 120,
                      },
                      {
                        title: '变动金额',
                        key: 'number',
                        minWidth: 120,
                      },
                      {
                        title: '本金',
                        key: 'ben_money',
                        minWidth: 120
                      },
                      {
                        title: '赠金',
                        key: 'give_money',
                        minWidth: 120
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
                    ];
                    break;
                  case 'visit':
                    this.columns = [
                      {
                        title: '商品信息',
                        slot: 'product',
                        minWidth: 400,
                      },
                      {
                        title: '价格',
                        key: 'product_price',
                        minWidth: 120,
                        render: (h, params) => {
                          return h('div', `¥${params.row.product_price}`);
                        },
                      },
                      {
                        title: '浏览时间',
                        key: 'add_time',
                        minWidth: 120,
                      },
                    ];
                    break;
                  case 'spread_change':
                    this.columns = [
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
                    ];
                    break;
                  case 'card_holder':
                    this.columns = [
                      {
                        title: '卡项名称',
                        key: 'card_name',
                        minWidth: 120,
                      },
                      {
                        title: '所属门店',
                        key: 'store_name',
                        minWidth: 120,
                      },
                      {
                        title: '剩余次数',
                        minWidth: 120,
                        render: (h, params) => {
                          return h(
                              'div',
                              `${
                                  params.row.write_surplus_times
                              }`
                          );
                        },
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
                            return h('div', '永久有效');
                          } else if (params.row.write_valid == 2) {
                            return h('div', `购买后${params.row.write_days}天有效`);
                          } else if (params.row.write_valid == 3) {
                            return h(
                                'div',
                                `${params.row.write_start} - ${params.row.write_end}`
                            );
                          }
                        },
                      },
                    ];
                    if (this.fromType !== 'order') {
                      this.columns.push({
                        title: '操作',
                        minWidth: 120,
                        render: (h, params) => {
                          return h(
                              'a',
                              {
                                on: {
                                  click: () => {
                                    this.$emit('cardHolderOpen', params.row);
                                  },
                                },
                              },
                              '查看'
                          );
                        },
                      });
                    }
                    break;
                  case 'reservation_record':
                    this.columns = [
                      {
                        title: '预约服务',
                        key: 'store_name',
                        minWidth: 120,
                        render: (h, params) => {
                          return h(
                              'div',
                              params.row.cart_info.productInfo.store_name
                          );
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
                          return h(
                              'div',
                              `${params.row.reservation_start}-${params.row.reservation_end}`
                          );
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
                    ];
                    break;
                  default:
                    this.columns = [
                      {
                        title: 'ID',
                        key: 'uid',
                        minWidth: 120,
                      },
                      {
                        title: '昵称',
                        key: 'nickname',
                        minWidth: 120,
                      },
                      {
                        title: '等级',
                        key: 'type',
                        minWidth: 120,
                      },
                      {
                        title: '加入时间',
                        key: 'add_time',
                        minWidth: 120,
                      },
                    ];
                }
                this.loading = false;
              } else {
                this.loading = false;
                this.$Message.error(res.msg);
              }
            })
            .catch((res) => {
              this.loading = false;
              this.$Message.error(res.msg);
            });
    },
  },
};
</script>

<style lang="less" scoped>
/deep/ .ivu-modal-body {
  padding: 0;
}

.user-row {
  padding: 30px 35px 0;

  &-text {
    flex: 1;
    align-self: center;
  }

  &-action {
    .ivu-btn {
      margin-left: 12px;
      font-size: 13px !important;
      color: rgba(0, 0, 0, 0.85);

      &:first-child {
        margin-left: 0;
      }

      &.ivu-btn-primary {
        border-color: #1890ff;
        background-color: #1890ff;
        color: #ffffff;
      }

      &.ivu-btn-success {
        border-color: #00c050;
        background-color: #00c050;
        color: #ffffff;
      }
    }
  }

  .nickname {
    font-weight: 500;
    font-size: 16px;
    line-height: 16px;
    color: rgba(0, 0, 0, 0.85);
  }

  .iconfont {
    margin-left: 7px;
    font-size: 18px;

    &:nth-child(2) {
      margin-left: 9px;
    }

    &.iconxiaochengxu {
      color: #007dff;
    }

    &.icongongzhonghao {
      color: #00bf00;
    }

    &.iconPC {
      color: #f69b00;
    }

    &.iconh5 {
      color: #9f5ce3;
    }

    &.iconapp {
      color: #e36734;
    }
  }

  .level {
    margin-top: 5px;

    img {
      width: 42px;
      height: 20px;
      vertical-align: middle;

      + span {
        margin-left: 7px;
      }
    }

    .vip {
      display: inline-block;
      width: 56px;
      height: 26px;
      padding-left: 30px;
      background: url("../../assets/images/vip-bg.png") left top/100% 100%
        no-repeat;
      font-weight: bold;
      font-size: 9px;
      line-height: 26px;
      color: #5f7db5;
      transform-origin: left;
      transform: scale(0.75, 0.75);
      vertical-align: middle;
    }
  }
}

.info-row {
  flex-wrap: nowrap;
  padding: 20px 35px 24px;

  &-item {
    flex: none;
    width: 155px;
    font-size: 14px;
    line-height: 14px;
    color: rgba(0, 0, 0, 0.85);

    &-title {
      margin-bottom: 12px;
      font-size: 13px;
      line-height: 13px;
      color: #666666;
    }
  }
}

.ivu-tabs {
  color: rgba(0, 0, 0, 0.85);

  /deep/ .ivu-tabs-bar {
    border-bottom: 0;
    margin-bottom: 0;
    background-color: #f5f7fa;

    .ivu-tabs-nav-container {
      font-size: 13px;
    }

    .ivu-tabs-ink-bar {
      display: none;
    }

    .ivu-tabs-tab {
      padding: 7px 19px;
      margin-right: 0;
      line-height: 26px;
    }

    .ivu-tabs-tab-active {
      background-color: #ffffff;
      color: rgba(0, 0, 0, 0.85);

      &:before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 2px;
        background-color: #1890ff;
      }
    }
  }

  /deep/ .ivu-tabs-content {
    .ivu-tabs-tabpane {
      padding: 25px 35px;

      &:first-child {
        padding: 0 35px;
      }
    }
  }

  .product {
    display: flex;

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
}

.avatar {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  overflow: hidden;

  img {
    width: 100%;
    height: 100%;
  }
}

.dashboard-workplace {
  &-header {
    &-avatar {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      margin-right: 16px;
      font-weight: 600;
    }

    &-tip {
      width: 82%;
      display: inline-block;
      vertical-align: middle;

      &-title {
        font-size: 13px;
        color: #000000;
        margin-bottom: 12px;
      }

      &-desc {
        &-sp {
          width: 33.33%;
          color: #17233d;
          font-size: 13px;
          display: inline-block;
        }
      }
    }

    &-extra {
      .ivu-col {
        p {
          text-align: right;
        }

        p:first-child {
          span:first-child {
            margin-right: 4px;
          }

          span:last-child {
            color: #808695;
          }
        }

        p:last-child {
          font-size: 22px;
        }
      }
    }
  }
}
</style>
<style scoped lang="stylus">
.user_menu >>> .ivu-menu {
  width: 100% !important;
}
</style>
