<template>
  <div class="order">
    <div class="left">
      <div class="left-top">
        <div class="title">用户列表</div>
      </div>
      <div class="order-box">
        <div class="search">
          <Input
            search
            enter-button="搜索"
            v-model="userFrom.keyword"
            placeholder="搜索手机号/ID"
            size="large"
            @on-search="search"
          />
        </div>
        <userList
          v-if="userList.length"
          :userList="userList"
          :total="total"
          @selectUser="selectUser"
          @addPage="addPage"
        ></userList>
        <div v-else class="no-order">
          <img src="../../assets/images/no-order.png" alt="" />
          <span class="trip">噢～目前暂无用户</span>
        </div>
      </div>
    </div>
    <div v-if="total" class="order-data">
      <div class="header">
        <div
          class="item"
          :class="{
            sel: sle == index,
            'neighbor-left': sle - index == 1,
            'neighbor-right': index - sle == 1,
          }"
          v-for="(tab, index) in tabs"
          v-show="!index || !selectUserData.delete_time"
          :key="index"
          @click="selectTab(index)"
        >
          <div class="item-wrap">
            {{ tab }}
          </div>
        </div>
        <div
          class="box"
          :class="{ 'neighbor-right': selectUserData.delete_time || sle == 2 }"
        ></div>
      </div>
      <div class="content" :class="{ 'border-radius': sle }">
        <div v-if="sle === 0" class="detail">
          <div class="user">
            <div><img class="img" :src="selectUserInfo.avatar" alt="" /></div>
            <div class="user-content">
              <div class="name-wrap">
                <div class="name">{{ userDisplayName(selectUserInfo) }}</div>
                <div v-if="selectUserInfo.vip_name" class="tag">
                  {{ selectUserInfo.vip_name }}
                </div>
                <div v-if="selectUserData.delete_time">（已注销）</div>
              </div>
              <div class="phone-wrap">
                <div v-if="selectUserInfo.phone" class="phone">
                  {{ selectUserInfo.phone }}
                </div>
                <div>
                  余额<span class="num">{{ selectUserInfo.now_money }}</span>
                </div>
                <div>
                  本金<span class="num">{{ selectUserInfo.ben_money }}</span>
                </div>
                <div>
                  赠金<span class="num">{{ selectUserInfo.give_money }}</span>
                </div>
                <div>
                  积分<span class="num">{{ selectUserInfo.integral }}</span>
                </div>
                <div v-if="selectUserInfo.is_money_level">
                  付费会员到期：
                  <span class="time">{{
                    selectUserInfo.is_ever_level
                      ? '永久会员'
                      : selectUserInfo.overdue_time || '已过期'
                  }}</span>
                </div>
              </div>
            </div>
          </div>
          <Tabs v-model="tabActive" :animated="false">
            <TabPane
              v-for="item in menus"
              :key="item.value"
              :label="item.name"
              :name="item.value"
              :data-name="item.component"
            >
              <component
                :is="item.component"
                :userInfo="selectUserInfo"
                :type="item.value"
                @cardHolderOpen="cardHolderOpen"
              ></component>
            </TabPane>
          </Tabs>
        </div>
        <div v-if="sle === 1" class="member">
          <Form :model="rechargeData" :label-width="82">
            <FormItem label="会员时长：">
              <template v-if="memberCheck.overdue_time">
                <span class="num">{{ memberCheck.vip_day }}</span
                >天（{{ memberCheck.overdue_time }} 到期）
              </template>
              <span v-else class="num">永久</span>
            </FormItem>
            <FormItem :label-width="0" class="merber-radio-group">
              <RadioGroup v-model="rechargeData.merber_id">
                <Radio
                  v-for="item in memberCard"
                  :key="item.id"
                  :label="item.id"
                  border
                >
                  <div>{{ item.title }}</div>
                  <div>
                    ¥<span class="num">{{ item.pre_price }}</span>
                  </div>
                  <div class="marking">¥{{ item.price }}</div>
                </Radio>
              </RadioGroup>
            </FormItem>
          </Form>
        </div>
        <div v-if="sle === 2" class="balance">
          <Form :model="rechargeData" :label-width="82">
            <FormItem label="充值方式：">
              <RadioGroup v-model="rechargeType" type="button" button-style="solid">
                <Radio :label="1">充值套餐</Radio>
                <Radio :label="2">自定义充值</Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-show="rechargeType == 1" :label-width="0" class="merber-radio-group">
              <RadioGroup v-model="rechargeData.rechar_id">
                <Radio
                  v-for="item in rechargeList"
                  :key="item.id"
                  :label="item.id"
                  border
                >
                  <div class="money">
                    ¥<span class="num">{{ item.price }}</span>
                  </div>
                  <div class="marking">额外赠送：¥ {{ item.give_money }}</div>
                </Radio>
              </RadioGroup>
            </FormItem>
            <FormItem v-show="rechargeType == 2">
              <InputNumber
                v-model="payPrice"
                :min="1"
                :max="9999999"
                placeholder="0.00"
              ></InputNumber>
            </FormItem>
          </Form>
        </div>
      </div>
      <div v-if="sle" class="footer">
        <Button @click="recharge">点击支付</Button>
      </div>
    </div>
    <Modal
      v-model="modalPay"
      footer-hide
      width="450px"
      class="modalPay"
      @on-cancel="yuePayClear"
    >
      <div class="payPage">
        <div class="header acea-row row-center-wrapper">
          <div class="pictrue"><img src="../../assets/images/gold.png" /></div>
          <div class="text">应收金额(元)</div>
        </div>
        <div class="money">
          ¥<span class="num">{{ rechargeData.price }}</span>
        </div>
        <Input
          v-model="rechargeData.auth_code"
          ref="rechargeNum"
          size="large"
          type="url"
          placeholder="请点击输入框聚焦扫码或输入编码号"
          style="margin-top: 16px"
        />
        <div class="process">
          <div class="pictrue">
            <img src="../../assets/images/process2.png" />
          </div>
          <div class="list acea-row row-between-wrapper">
            <div class="item one">
              <div class="name">出示付款码</div>
              <div>支付宝/微信</div>
            </div>
            <div class="item two">
              <div class="name">扫描付款码</div>
              <div>扫码枪</div>
            </div>
            <div class="item three">
              <div class="name">确认收款</div>
              <div>收银台点击确认</div>
            </div>
          </div>
        </div>
        <Button type="primary" class="bnt" @click="confirm">确认</Button>
      </div>
    </Modal>
    <Modal
      v-model="modalCash"
      class="cash"
      footer-hide
      width="770px"
      @on-cancel="cancel"
    >
      <div class="cashPage acea-row">
        <div class="left">
          <div class="picture">
            <img src="../../assets/images/gold.png" />
          </div>
          <div class="text">应收金额(元)</div>
          <div class="money">
            ¥<span class="num">{{ rechargeData.price || 0 }}</span>
          </div>
        </div>
        <div class="right">
          <div class="rightCon">
            <div class="top acea-row row-between-wrapper">
              <div>实际收款(元)</div>
              <div class="num">{{ collection }}</div>
            </div>
            <div class="center acea-row row-between-wrapper">
              <div>需找零(元)</div>
              <div
                v-if="$computes.Sub(collection, rechargeData.price || 0) > 0"
                class="num"
              >
                {{ $computes.Sub(collection, rechargeData.price || 0) }}
              </div>
              <div v-else class="num">0</div>
            </div>
            <div class="bottom acea-row">
              <div
                v-for="(item, index) in numList"
                :key="index"
                :class="item == '.' ? 'spot' : ''"
                class="item acea-row row-center-wrapper"
                @click="numTap(item)"
              >
                {{ item }}
              </div>
              <div class="item acea-row row-center-wrapper" @click="delNum">
                <Icon type="ios-backspace" />
              </div>
            </div>
          </div>
          <Button type="primary" @click="cashBnt">确认</Button>
        </div>
      </div>
    </Modal>
    <settleDrawer
      v-model="settleVisible"
      :list="payList"
      :type="payType"
      :money="rechargeData.price"
      :collection="collection"
      @payPrice="onPayPrice"
      @numTap="numTap"
      @delNum="delNum"
      @cashBnt="cashBnt"
    ></settleDrawer>
    <Modal
        v-model="cardHolderShow"
        scrollable
        title="卡项详情"
        closable
        footer-hide
        width="900"
    >
      <cardHolder :cardHolder="cardHolderData"></cardHolder>
    </Modal>
  </div>
</template>

<script>
import userInfo from './components/userInfo.vue';
import userTable from './components/userTable.vue';
import userList from './components/userList';
import goodsList from '@/pages/hang/components/goodsList';
import userOrder from '@/pages/order/components/userOrder';
import orderDetails from '@/pages/order/components/orderDetails';
import orderRecord from '@/components/orderRecord';
import settleDrawer from "@/components/settleDrawer";
import cardHolder from "@/components/cardHolder";
import { getRechargeData } from '@/api/recharge';
import '../../assets/js/core.js';
import {
  userListApi,
  userSaveApi,
  checkOrderApi,
  readUserInfo,
  getMemberCard,
  memberRecharge,
} from '@api/user';

export default {
  components: {
    userList,
    goodsList,
    userOrder,
    orderDetails,
    orderRecord,
    userInfo,
    userTable,
    settleDrawer,
    cardHolder,
  },
  data() {
    return {
      userList: [],
      tabs: ['会员详情', '付费会员', '余额充值'],
      sle: 0,
      rechargeList: [],
      searchData: {
        type: 0,
        status: 0,
        time: 0,
        people: 0,
      },
      rechargeData: {
        uid: 0,
        price: 0,
        rechar_id: 0,
        merber_id: 0,
        pay_type: 3,
        auth_code: '',
      },
      // selTypeIndex: 0,
      payPrice: 1,
      payNum: 0,
      total: 0,
      modalPay: false,
      userFrom: {
        keyword: '',
        page: 1,
        limit: 15,
        field_key: 'all',
      },
      selectUserData: {},
      selectUserInfo: {},
      menus: [
        {
          name: '用户信息',
          value: 'info',
          component: 'userInfo',
        },
        {
          name: '卡项权益',
          value: 'card_holder',
          component: 'userTable',
        },
        {
          name: '消费记录',
          value: 'order',
          component: 'userTable',
        },
        {
          name: '积分明细',
          value: 'integral',
          component: 'userTable',
        },
        {
          name: '持有优惠券',
          value: 'coupon',
          component: 'userTable',
        },
        {
          name: '预约记录',
          value: 'reservation_record',
          component: 'userTable',
        },
        {
          name: '余额变动',
          value: 'balance_change',
          component: 'userTable',
        },
        {
          name: '浏览足迹',
          value: 'visit',
          component: 'userTable',
        },
        {
          name: '推广人变更记录',
          value: 'spread_change',
          component: 'userTable',
        },
      ],
      tabActive: 'info',
      memberCard: [],
      rechargeType: 1,
      modalCash: false,
      collectionArray: [],
      collection: 0,
      numList: ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '.'],
      isOrderCreate: 0,
      settleVisible: false,
      payType: '',
      payList: [
        {
          label: '微信/支付宝',
          value: '',
          status: true,
        },
        {
          label: '现金收款',
          value: 'cash',
          status: true,
        },
      ],
      cardHolderShow: false,
      cardHolderData: {},
    };
  },
  computed: {
    memberCheck() {
      let member = this.memberCard.find(
        (item) => item.id == this.rechargeData.merber_id
      );
      return member || {};
    },
    balanceCheck() {
      let balance = this.rechargeList.find(
        (item) => item.id == this.rechargeData.rechar_id
      );
      return balance || {};
    },
  },
  watch: {
    'selectUserData.uid'(value) {
      if (value) {
        this.readUserInfo();
        this.getMemberCard();
      }
    },
    total(value) {
      if (value) {
        this.getRechargeData();
      } else {
        this.selectUserInfo = this.selectUserData = {};
        this.sle = 0;
        this.rechargeType = 1;
        this.payPrice = 1;
      }
    },
    payPrice(value) {
      this.$nextTick(() => {
        if (typeof value != 'number') {
          return;
        }
        if (parseFloat(value) == parseInt(value)) {
          return;
        }
        if (value.toString().length - (value.toString().indexOf('.') + 1) > 2) {
          this.payPrice = Number(value.toFixed(2));
        }
      });
    },
  },
  created() {
    this.userListApi();
  },
  beforeDestroy() {
    clearInterval(this.timer);
  },
  methods: {
    /** 与左侧用户列表一致：姓名 → 昵称 → 手机号 → 游客 */
    userDisplayName(u) {
      if (!u || typeof u !== 'object') return '游客';
      const real = String(u.real_name || '').trim();
      if (real) return real;
      const nick = String(u.nickname || '').trim();
      if (nick) return nick;
      const phone = String(u.phone || '').trim();
      if (phone) return phone;
      return '游客';
    },
    jsToJava() {
      try {
        window.Jsbridge.invoke(
          'openCacheBox',
          JSON.stringify({ 'p1-key': 'p1-value' }),
          this.myFunction()
        );
      } catch (e) {}
    },
    // 监听键盘函数
    keyboard() {
      let that = this;

      function delNums(item) {
        that.collectionArray.pop();
        that.collection = that.collectionArray.length
          ? that.collectionArray.join('')
          : 0;
      }

      function numTaps(item) {
        if (that.defaultcalc === false) {
          that.collection = '';
          that.defaultcalc = true;
        }
        let x = String(that.collection).indexOf('.') + 1;
        let y = String(that.collection).length - x;
        if (x === 0 || y < 2) {
          if (that.collectionArray.join('') <= 9999999) {
            that.collectionArray.push(item);
          }
          that.collection =
            that.collectionArray.join('') > 99999999
              ? 99999999
              : that.collectionArray.join('');
        }
      }

      document.onkeydown = function (event) {
        let e = event || window.event;
        let key = e.keyCode;
        if (that.modalCash) {
          event.stopPropagation(); // 阻止事件冒泡传递
          event.preventDefault(); //阻止默认事件原有功能
        }
        switch (key) {
          case 96:
          case 48:
            numTaps(0);
            break;
          case 97:
          case 49:
            numTaps(1);
            break;
          case 98:
          case 50:
            numTaps(2);
            break;
          case 99:
          case 51:
            numTaps(3);
            break;
          case 100:
          case 52:
            numTaps(4);
            break;
          case 101:
          case 53:
            numTaps(5);
            break;
          case 102:
          case 54:
            numTaps(6);
            break;
          case 103:
          case 55:
            numTaps(7);
            break;
          case 104:
          case 56:
            numTaps(8);
            break;
          case 105:
          case 57:
            numTaps(9);
            break;
          case 110:
            numTaps('.');
            break;
          case 190:
            numTaps('.');
            break;
          case 8:
            delNums();
            break;
        }
      };
    },
    //现金收款创建订单并支付
    cashBnt(payNum) {
      if (this.cashBntLoading) return;
      this.cashBntLoading = true;
      if (this.payType) {
        this.rechargeData.pay_type = 4;
      } else {
        this.rechargeData.pay_type = 3;
        this.rechargeData.auth_code = payNum;
      }
      this.confirm();
      setTimeout(() => {
        this.cashBntLoading = false;
      }, 1000);
      return
    },
    //清除计算机输入的数字
    delNum() {
      this.collectionArray.pop();
      this.collection = this.collectionArray.length
        ? this.collectionArray.join('')
        : 0;
    },
    //输入实际收款金额
    numTap(item) {
      if (this.defaultcalc === false) {
        this.collection = '';
        this.defaultcalc = true;
      }
      let x = String(this.collection).indexOf('.') + 1;
      let y = String(this.collection).length - x;
      if (x === 0 || y < 2) {
        if (this.collectionArray.join('') <= 9999999) {
          this.collectionArray.push(item);
        }
        this.collection =
          this.collectionArray.join('') > 99999999
            ? 99999999
            : this.collectionArray.join('');
      }
    },
    cancel() {
      this.collection = 0;
      this.collectionArray = [];
    },
    // 付费会员充值
    memberRecharge() {
      let rechargeData = { ...this.rechargeData };
      delete rechargeData.rechar_id;
      if (
        this.rechargeData.pay_type == 4 &&
        this.rechargeData.price > Number(this.collection)
      ) {
        return this.$Message.error('您付款金额不足');
      }
      memberRecharge(rechargeData)
        .then((res) => {
          const { status, data } = res.data;
          switch (status) {
            case 'SUCCESS':
              this.$Message.success('支付成功');
              this.settleVisible = false;
              this.search();
              this.payPrice = 1;
              if (this.rechargeData.pay_type == 4) {
                this.jsToJava();
              } else {
                this.rechargeData.auth_code = '';
              }
              break;
            case 'PAY_ING':
              let msg = this.$Message.loading({
                content: '等待支付中...',
                duration: 0,
              });
              this.checkOrderTime(data.order_id, msg);
              break;
            default:
              this.$Message.warning('支付失败');
              break;
          }
        })
        .catch((err) => {
          this.$Message.warning(err.msg);
        });
    },
    // 获取付费会员类型
    getMemberCard() {
      let { is_money_level, overdue_time } = this.selectUserData;
      getMemberCard({
        is_money_level,
        overdue_time,
      }).then((res) => {
        this.memberCard = res.data;
      });
    },
    readUserInfo() {
      readUserInfo(this.selectUserData.uid).then((res) => {
        this.selectUserInfo = res.data.ps_info;
      });
    },
    addPage() {
      if (this.userList.length < this.total) this.userFrom.page++;
      this.userListApi();
    },
    search() {
      this.userList = [];
      this.userFrom.page = 1;
      this.userListApi();
    },
    // 用户列表
    userListApi() {
      this.loading = true;
      userListApi(this.userFrom)
        .then((res) => {
          this.loading = false;
          this.total = res.data.count;
          if (this.userFrom.page === 1) {
            this.selectUserData = res.data.list.length ? res.data.list[0] : {};
            this.rechargeData.uid = res.data.list.length
              ? res.data.list[0].uid
              : '';
          }
          this.userList = this.userList.concat(res.data.list);
          let cashierUser = window.localStorage.getItem('cashierUser');
          if (cashierUser) {
            cashierUser = JSON.parse(cashierUser);
            if (
              Object.prototype.toString.call(cashierUser) === '[object Object]'
            ) {
              for (const userItem of this.userList) {
                if (cashierUser.uid === userItem.uid) {
                  cashierUser.now_money = userItem.now_money;
                  window.localStorage.setItem(
                    'cashierUser',
                    JSON.stringify(cashierUser)
                  );
                  break;
                }
              }
            }
          }
        })
        .catch((err) => {
          this.loading = false;
          this.$Message.error(err.msg);
        });
    },
    keyupEvent(val) {
      if (val) {
        var re = /^\D*([0-9]\d*\.?\d{0,2})?.*$/;
        let price = val.toString().replace(re, '$1');
        this.payPrice = Number(price);
      } else {
        this.payPrice = '';
      }
    },
    selectTab(index) {
      this.sle = index;
      if (index == 1) {
        if (this.memberCard.length) {
          this.rechargeData.merber_id = this.memberCard[0].id;
        }
      } else if (index == 2) {
        this.rechargeType = 1;
        if (this.rechargeList.length) {
          this.rechargeData.rechar_id = this.rechargeList[0].id;
        }
      }
    },
    clear() {
      this.payPrice = 0;
      this.sel = 0;
    },
    yuePayClear() {
      this.$Message.destroy();
      if (this.timer) {
        clearInterval(this.timer);
        this.timer = null;
      }
    },
    getRechargeData() {
      getRechargeData().then((res) => {
        this.rechargeList = res.data.recharge_quota;
      });
    },
    // 确认充值
    confirm() {
      if (this.sle == 1) {
        return this.memberRecharge();
      }
      let rechargeData = { ...this.rechargeData };
      if (this.rechargeType == 2) {
        delete rechargeData.rechar_id;
      }
      userSaveApi(rechargeData)
        .then((res) => {
          this.payNum = '';
          let status = res.data.status;
          let orderId = res.data.data.order_id;
          switch (status) {
            case 'SUCCESS':
              this.$Message.success('支付成功');
              this.settleVisible = false;
              this.search();
              this.payPrice = 1;
              this.$emit('getSuccess', this.totalPrice);
              if (this.rechargeData.pay_type == 4) {
                this.jsToJava();
              } else {
                this.rechargeData.auth_code = '';
              }
              break;
            case 'PAY_ING':
              let msg = this.$Message.loading({
                content: '等待支付中...',
                duration: 0,
              });
              this.checkOrderTime(orderId, msg);
              break;
            default:
              this.$Message.warning('支付失败');
              break;
          }
        })
        .catch((err) => {
          this.payNum = '';
          this.rechargeData.auth_code = '';
          this.$Message.error(err.msg);
        });
    },
    checkOrderTime(orderId, msg) {
      let that = this;
      let num = 1;
      let timer = (this.timer = setInterval(function () {
        that.checkOrder(orderId, timer, msg);
        num++;
        if (num >= 60) {
          clearInterval(timer);
          msg();
          that.$Message.success('支付失败');
        }
      }, 1000));
    },
    checkOrder(orderId, timer, msg) {
      checkOrderApi(this.sle == 2 ? 1 : 2, { order_id: orderId })
        .then((res) => {
          if (res.data.status == true) {
            msg();
            this.$Message.success('支付成功');
            this.$emit('getSuccess', this.totalPrice);
            this.settleVisible = false;
            this.modalPay = false;
            this.modal = false;
            clearInterval(timer);
            this.readUserInfo();
          }
        })
        .catch((err) => {
          msg();
          this.$Message.error(err.msg);
        });
    },
    selectUser(data) {
      this.selectUserData = data;
      this.rechargeData.uid = data.uid;
      this.sle = 0;
      this.rechargeData.rechar_id = 0;
      this.payPrice = 1;
    },
    // 点击充值按钮
    recharge(type) {
      if (!this.selectUserData.uid) {
        return this.$Message.error('请先选择会员');
      }
      if (this.sle == 1) {
        this.rechargeData.price = Number(this.memberCheck.pre_price);
      } else {
        if (this.rechargeType == 1) {
          this.rechargeData.price = this.balanceCheck.price;
        } else {
          if (!this.payPrice) {
            return this.$Message.error('请先输入金额');
          }
          this.rechargeData.price = this.payPrice;
        }
      }
      this.collection = this.rechargeData.price;
      this.settleVisible = true;
    },
    onPayPrice(type) {
      this.payType = type;
      this.rechargeData.auth_code = '';
    },
    cardHolderOpen(row) {
      this.cardHolderData = {};
      this.$nextTick(() => {
        this.cardHolderData = row;
        this.cardHolderShow = true;
      });
    },
  },
};
</script>
<style scoped lang="stylus">
::-webkit-scrollbar {
  display: none;
}

.order {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  display: flex;
  padding: 20px;
  background-color: #F5F5F5;

  .left {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    width: 460px;
    border-radius: 20px;
    background: #FFFFFF;

    .left-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 24px;

      .title {
        color: rgba(0, 0, 0, 0.85);
        font-weight: 600;
        font-size: 20px;
      }

      .sx {
        color: #666666;

        .ios-funnel-outline {
          font-weight: bold;
          font-size: 12px;
        }
      }
    }

    .order-box {
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 0;
      font-size: 18px;

      .search {
        padding: 0 24px 24px;

        /deep/.ivu-input {
          padding-left: 14px;
          border-color: #DDDDDD;
          border-radius: 20px 0 0 20px;
        }

        /deep/.ivu-input-search {
          border-radius: 0px 20px 20px 0px;
          background: #1890FF !important;
          font-size: 14px;
          line-height: normal;
        }
      }

      .pending-user {
        flex: 1;
      }
    }
  }

  .order-data {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    border-radius: 20px;
    margin-left: 20px;

    .content {
      flex: 1;
      padding: 24px;
      border-radius: 0 20px 20px;
      background: #FFFFFF;
      overflow-x: hidden;
    }

    .border-radius {
      border-radius: 20px 20px 0 0;
    }

    .header {
      display: flex;
      background: #FFFFFF;
      font-size: 18px;

      .box {
        flex: 1;
        background: #F5F5F5;
      }

      .item {
        cursor: pointer;
        background-color: #F5F5F5;
        transition: all 0.1s;
      }

      .item-wrap {
        padding: 16px 29px;
        border-radius: 20px 20px 0px 0px;
      }

      .sel {
        color: rgba(0, 0, 0, 0.85);
        font-weight: 500;

        .item-wrap {
          background: #FFFFFF;
        }
      }

      .neighbor-left {
        border-bottom-right-radius: 20px;
      }

      .neighbor-right {
        border-bottom-left-radius: 20px;
      }
    }

    .recharge {
      display: flex;
      flex-wrap: wrap;
      padding: 10px;

      .item {
        display: flex;
        flex-direction: column;
        align-items: center;
        border-radius: 10px;
        border: 1px solid #1890FF;
        padding: 24px 34px;
        width: 30%;
        height: 102px;
        margin: 1%;
        cursor: pointer;

        .price {
          font-size: 24px;
          color: #1890FF;
        }

        .give-price {
          color: #999999;
          font-size: 14px;
          white-space: nowrap;
        }
      }

      .item.sel {
        background-color: #1890FF;
        color: #fff;

        .price {
          font-size: 24px;
          color: #fff;
        }

        .give-price {
          color: #fff;
        }
      }
    }

    .diy-recharge {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      margin-top: 200px;

      .text {
        font-size: 20px;
      }

      .inputNum {
        width: 45%;
        margin: 28px 0 14px 0;
        height: 66px;
      }

      /deep/ .ivu-input-number-large {
        height: 66px;
        font-size: 30px;
      }

      /deep/ .ivu-input-number-input-wrap {
        height: 66px;
      }

      /deep/ .ivu-input-number-handler-wrap {
        display: none;
      }

      /deep/ .ivu-input-number-large input {
        height: 66px;
        line-height: 66px;
      }

      .tip {
        font-size: 13px;
        color: rgba(153, 153, 153, 0.85);
        margin-top: 15px;
      }
    }

    .footer {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      height: 88px;
      padding: 0 88px;
      border-radius: 0 0 20px 20px;
      background: #FFFFFF;
      box-shadow: 0 -1px 11px 0 rgba(0, 0, 0, 0.06);

      .ivu-btn {
        width: 100%;
        height: 50px;
        border-color: #1890FF;
        border-radius: 25px;
        background: #1890FF;
        font-size: 18px !important;
        color: #FFFFFF;

        &:focus {
          box-shadow: none;
        }
      }
    }
  }
}

.payPage {
  text-align: center;
  padding: 16px;

  /deep/ .ivu-input {
    width: 394px !important;
    text-align: center;
  }

  .header {
    margin: 35px 0 3px 0;
  }

  .process {
    width: 394px;
    height: 158px;
    border: 1px dashed #D8D8D8;
    border-top: 1px dashed #fff;
    margin: -1px auto 0 auto;

    &.on {
      border-top: 1px dashed #D8D8D8;
      margin-top: 20px;

      .list {
        padding-left: 14px !important;
      }
    }

    .list {
      padding: 6px 10px 0 18px;

      .item {
        font-size: 12px;
        color: #666;

        .name {
          color: #333;
          font-size: 13px;
          font-weight: bold;
        }
      }
    }

    .pictrue {
      width: 362px;
      height: 68px;
      margin: 24px auto 0 auto;

      img {
        width: 100%;
        height: 100%;
      }
    }
  }

  .pictrue {
    width: 18px;
    height: 18px;

    img {
      width: 100%;
      height: 100%;
    }

    margin-right: 7px;
  }

  .text {
    color: rgba(0, 0, 0, 0.45);
    font-size: 14px;
  }

  .money {
    font-size: 18px;
    color: rgba(0, 0, 0, 0.85);

    .num {
      font-size: 32px;
      margin-left: 5px;
    }
  }

  .tip {
    width: 310px;
    height: 26px;
    background: rgba(255, 126, 0, 0.1);
    border-radius: 13px;
    font-size: 13px;
    color: #FF7E00;
    margin: 10px auto 0 auto;

    .icon {
      font-size: 16px;
      margin-right: 5px;
    }
  }

  .bnt {
    width: 394px;
    height: 38px;
    margin: 28px 0 15px 0;
  }
}

.detail {
  .user {
    display: flex;
    align-items: center;
    padding: 18px;
    border-radius: 10px;
    background: rgba(24, 144, 255, 0.05);

    .img {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      margin-right: 20px;
      object-fit: cover;
    }

    .user-content {
      flex: 1;
      min-width: 0;
    }

    .name-wrap {
      display: flex;
      align-items: center;
    }

    .name {
      font-weight: 600;
      font-size: 18px;
      color: rgba(0, 0, 0, 0.85);
    }

    .tag {
      padding: 0 5px;
      border: 1px solid #1890FF;
      border-radius: 3px;
      margin-left: 6px;
      font-size: 12px;
      line-height: 16px;
      color: #1890FF;
    }

    .phone-wrap {
      display: flex;
      align-items: center;
      margin-top: 12px;
      font-size: 12px;
      color: rgba(51, 51, 51, 0.85);
    }

    .phone {
      margin-right: 20px;
    }

    .num {
      margin: 0 20px 0 4px;
      font-weight: 600;
      font-size: 17px;
      color: #F5222D;
    }

    .time {
      font-size: 14px;
      color: #1890FF;
    }
  }

  .tabbar {
    display: flex;
    padding: 17px 0;

    .item {
      height: 36px;
      padding: 0 19px;
      border-radius: 18px;
      font-size: 14px;
      line-height: 36px;
      color: #999999;
      cursor: pointer;

      &.active {
        background-color: #1890FF;
        font-weight: 500;
        color: #FFFFFF;
      }
    }
  }
}

.member {
  >>>.ivu-form-item-content {
    font-size: 14px !important;
  }

  .num {
    color: #1890FF;
  }

  >>>.ivu-radio-border {
    display: inline-flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    width: 180px;
    height: 110px;
    border: 1px solid #1890FF;
    border-radius: 10px;
    margin: 0 15px 15px 0;
    box-shadow: 0 0 14px 0 rgba(0, 84, 161, 0.18);
    font-weight: 500;
    font-size: 16px !important;
    color: #1890FF;

    &.ivu-radio-wrapper-checked {
      background-color: #1890FF;
      color: #FFFFFF;

      .num {
        color: #FFFFFF;
      }

      .marking {
        color: #FFFFFF;
      }
    }

    .ivu-radio {
      display: none;
    }

    .num {
      font-size: 24px;
    }
  }

  .marking {
    text-decoration: line-through;
    font-weight: normal;
    font-size: 14px;
    color: #999999;
  }
}

.balance {
  >>>.ivu-radio-border {
    display: inline-flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    width: 180px;
    height: 110px;
    border: 1px solid #1890FF;
    border-radius: 10px;
    margin: 0 15px 15px 0;
    box-shadow: 0 0 14px 0 rgba(0,84,161,0.18);

    &.ivu-radio-wrapper-checked {
      background-color: #1890FF;
      color: #FFFFFF;

      .num {
        color: #FFFFFF;
      }

      .marking {
        color: #FFFFFF;
      }
    }

    .ivu-radio {
      display: none;
    }
  }

  .money {
    font-weight: 500;
    font-size: 16px;
    color: #1890FF;
  }

  .num {
    font-size: 24px;
  }

  .marking {
    font-size: 14px;
    color: #999999;
  }

  .ivu-input-number {
    width: 460px;
    height: 36px;
  }

  >>>.ivu-input-number-input-wrap {
    height: 36px;
  }

  >>>.ivu-input-number-input {
    height: 36px;
    line-height: 36px;
  }
}

>>>.ivu-tabs {
  .ivu-tabs-bar {
    padding: 20px 0 17px;
    border-bottom-color: #F4F4F4;
    margin-bottom: 0;
  }

  .ivu-tabs-ink-bar {
    display: none;
  }

  .ivu-tabs-tab {
    padding: 10px 19px;
    margin-right: 5px;
    line-height: 16px;
    color: #999999;

    &.ivu-tabs-tab-active {
      border-radius: 18px;
      background-color: #1890FF;
      color: #FFFFFF;
    }
  }
}

.cash {
  /deep/ .ivu-modal-body {
    padding: 0 !important;
  }
}

.cashPage {
  text-align: center;

  .right {
    width: 488px;
    background: #F5F5F5;
    padding: 16px 16px 16px 0;
    border-radius: 0 6px 6px 0;

    /deep/ .ivu-btn-primary {
      width: 100px;
    }

    .rightCon {
      width: 388px;
      height: 506px;
      margin: 35px auto 20px auto;
      background-color: #fff;
      border-radius: 14px;

      .top {
        height: 80px;
        color: rgba(0, 0, 0, 0.65);
        font-size: 13px;
        padding: 0 20px;

        .num {
          font-size: 42px;
          color: rgba(0, 0, 0, 0.85);
        }
      }

      .center {
        width: 100%;
        height: 46px;
        background-color: #1890FF;
        font-size: 13px;
        color: #fff;
        padding: 0 20px;

        .num {
          font-size: 27px;
        }
      }

      .bottom {
        padding: 10px 0 0 8px;

        .item {
          width: 108px;
          height: 62px;
          background: #FAFAFA;
          border-radius: 9px;
          border: 1px solid rgba(0, 0, 0, 0.15);
          color: #1890FF;
          font-size: 32px;
          margin-left: 12px;
          margin-top: 12px;
          cursor: pointer;

          &.on {
            background: #1890FF;
            color: #FFFFFF;
            font-size: 20px;
          }

          &.spot {
            padding-bottom: 15px;
          }
        }
      }
    }
  }

  .left {
    width: 282px;
    padding: 16px 0 16px 16px;

    .picture {
      width: 110px;
      height: 110px;
      margin: 180px auto 0 auto;

      img {
        width: 100%;
        height: 100%;
      }
    }

    .text {
      color: rgba(0, 0, 0, 0.45);
      font-size: 14px;
      margin-top: 14px;
    }

    .money {
      color: rgba(0, 0, 0, 0.85);
      font-size: 18px;

      .num {
        font-size: 32px;
        margin-left: 5px;
      }
    }
  }
}
.merber-radio-group {
  margin: 0 -15px -15px 0;
}
</style>
