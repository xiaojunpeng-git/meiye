<template>
  <div>
    <debt-repay
      v-model="repayVisible"
      :row="repayRow"
      :store-name="currentStoreName"
      :modal-z-index="repayModalZIndex"
      @pay="onRepayPay"
    />
    <settle-drawer
      ref="settlePay"
      v-model="settleVisible"
      :list="payList"
      :type="payType"
      :hide-yue-pay-option="hideYuePayOption"
      :force-combination-pay="forceCombinationPay"
      :init-combination-info="initCombinationInfo"
      :lock-yue-edit="lockSettleYueEdit"
      :lock-debt-repay-source="lockDebtRepaySource"
      :initial-source="debtRepayInitialSource"
      :money="settleMoney"
      :collection="collection"
      :price-info="priceInfo"
      :has-remark="createOrder.remarkInfo"
      :now-money="userInfo.now_money"
      :submit-data="submitData"
      :verify="yueVerify"
      :is-recharge="0"
      :z-index="zIndex"
      @payPrice="payPrice"
      @changeSource="changeSource"
      @numTap="numTap"
      @delNum="delNum"
      @cashBnt="cashBnt"
      @setBudan="setBudan"
      @saveRemark="saveRemark"
      @saveCombinationinfo="saveCombinationinfo"
    />
  </div>
</template>

<script>
import debtRepay from '@/components/debtRepay';
import settleDrawer from '@/components/settleDrawer';
import { debtRepayPayApi } from '@/api/debt';
import { cashierUser } from '@/api/order';
import util from '@/libs/util';

export default {
  name: 'debtRepayFlow',
  components: { debtRepay, settleDrawer },
  props: {
    storeName: { type: String, default: '' },
    repayModalZIndex: { type: Number, default: 1100 },
  },
  data() {
    return {
      repayVisible: false,
      repayRow: {},
      settleVisible: false,
      isDebtRepay: 0,
      debtRepayData: {},
      debtRepayInitialSource: 0,
      lockDebtRepaySource: false,
      hideYuePayOption: false,
      forceCombinationPay: false,
      lockSettleYueEdit: false,
      initCombinationInfo: [],
      settleMoney: 0,
      collection: 0,
      collectionArray: [],
      payType: '',
      payNum: '',
      zIndex: 9999,
      yueVerify: false,
      userInfo: { uid: 0, now_money: 0 },
      priceInfo: { is_cashier_yue_pay_verify: 0 },
      submitData: {},
      createOrder: {
        source: 0,
        pay_type: '',
        cash_choose: 0,
        remarkInfo: {},
        combination_info: [],
        is_budan: 0,
        budan_time: '',
        userCode: '',
        auth_code: '',
      },
      payList: [
        { id: 1, label: '微信/支付宝', value: '', status: true, icon: '', num: 3 },
        { id: 2, label: '现金收款', value: 'cash', status: true, icon: 'iconicon_cash', num: 3 },
        { id: 3, label: '余额收款', value: 'yue', status: true, icon: 'icona-icon_yue', num: 3 },
        { id: 4, label: '组合收款', value: 'combination', status: true, icon: 'iconicon_cash', num: 4 },
      ],
    };
  },
  computed: {
    currentStoreName() {
      return this.repayRow.store_name || this.storeName || util.cookies.get('pageTitle') || '';
    },
  },
  methods: {
    buildRepayRow(row) {
      const pendingItems = (row.items || []).filter((item) => Number(item.pending_debt || 0) > 0);
      const debtItemId = pendingItems.length === 1
        ? Number(pendingItems[0].id || 0)
        : Number(row.debt_item_id || 0);
      const targetItem = pendingItems.length === 1 ? pendingItems[0] : null;
      const pendingDebt = targetItem
        ? Number(targetItem.pending_debt || 0)
        : Number(row.pending_debt || 0);
      return {
        debt_id: Number(row.debt_id || row.id || 0),
        debt_item_id: debtItemId,
        pending_debt: pendingDebt,
        order_sn: row.order_sn || '',
        original_source: Number(row.original_source || row.source || 0),
        staff_name: row.staff_name || '',
        product_id: Number((targetItem && targetItem.product_id) || row.product_id || 0),
        cart_id: Number((targetItem && targetItem.cart_id) || 0),
        uid: Number(row.uid || 0),
        store_id: Number(row.store_id || 0),
        store_name: row.store_name || '',
      };
    },
    open(row) {
      if (!row || Number(row.status) !== 0 || Number(row.pending_debt || 0) <= 0) {
        this.$Message.warning('当前欠款不可还款');
        return;
      }
      this.repayRow = this.buildRepayRow(row);
      this.repayVisible = true;
      const uid = Number(row.uid || 0);
      if (uid) {
        cashierUser({ uid }).then((res) => {
          this.userInfo = res.data || { uid, now_money: 0 };
          this.refreshPayList();
        }).catch(() => {
          this.userInfo = { uid, now_money: 0 };
          this.refreshPayList();
        });
      } else {
        this.userInfo = { uid: 0, now_money: 0 };
        this.refreshPayList();
      }
    },
    refreshPayList() {
      this.payList.forEach((item) => {
        item.status = true;
        item.num = 3;
        if (!this.userInfo.uid && item.value === 'yue') {
          item.status = false;
        }
      });
    },
    onRepayPay(data) {
      this.openSettle({
        ...data,
        original_source: data.original_source || this.repayRow.original_source || 0,
      });
    },
    openSettle(data) {
      const originalSource = Number(data.original_source || this.repayRow.original_source || this.repayRow.source || 0);
      this.isDebtRepay = 1;
      this.debtRepayData = { ...data, original_source: originalSource };
      this.lockDebtRepaySource = true;
      this.debtRepayInitialSource = originalSource;
      this.createOrder.source = originalSource;
      this.hideYuePayOption = false;
      this.forceCombinationPay = false;
      this.lockSettleYueEdit = false;
      this.initCombinationInfo = [];
      this.settleMoney = data.repay_amount;
      this.collection = data.repay_amount;
      this.refreshPayList();
      this.payType = '';
      this.createOrder.pay_type = '';
      this.createOrder.cash_choose = 0;
      this.yueVerify = !!this.priceInfo.is_cashier_yue_pay_verify;
      this.zIndex = this.repayModalZIndex + 100;
      this.settleVisible = true;
      this.$nextTick(() => {
        const settle = this.$refs.settlePay;
        if (settle) {
          settle.combinationPay = 0;
          settle.combination_info = [];
          settle.activePay = 0;
          settle.payLabel = '请选择支付方式';
          if (typeof settle.syncDebtRepaySource === 'function') {
            settle.syncDebtRepaySource();
          }
        }
      });
    },
    changeSource(data) {
      this.createOrder.source = data.source;
    },
    payPrice(data) {
      const cashChoose = Number(data.cashChoose || 0);
      const type = data.type || (cashChoose > 0 ? 'cash' : '');
      this.payType = type;
      this.createOrder.auth_code = '';
      this.createOrder.userCode = '';
      this.createOrder.cash_choose = cashChoose || data.cashChoose;
      this.createOrder.pay_type = type;
      this.collection = (type === 'cash' || cashChoose > 0) ? this.settleMoney : 0;
    },
    numTap(item) {
      const x = String(this.collection).indexOf('.') + 1;
      const y = String(this.collection).length - x;
      if (x === 0 || y < 2 || !this.collectionArray.length) {
        if (this.collectionArray.join('') <= 9999999) {
          this.collectionArray.push(item);
        }
      }
      this.collection = this.collectionArray.join('') > 99999999
        ? 99999999
        : this.collectionArray.join('');
    },
    delNum(type) {
      if (type === -1) {
        this.collectionArray = [];
      } else {
        this.collectionArray.pop();
      }
      this.collection = this.collectionArray.length ? this.collectionArray.join('') : 0;
    },
    saveRemark(remarkInfo) {
      this.createOrder.remarkInfo = remarkInfo;
    },
    saveCombinationinfo(info) {
      this.createOrder.combination_info = info;
    },
    setBudan(data) {
      this.createOrder.is_budan = data.is_budan;
      this.createOrder.budan_time = data.budan_time;
    },
    cashBnt(payNum) {
      this.payNum = payNum;
      if (!this.isDebtRepay && !this.lockDebtRepaySource) return;
      this.syncPayStateFromSettle();
      const payType = this.resolveRepayPayType();
      if (payType === 'yue') {
        this.createOrder.userCode = payNum;
        if (!payNum && this.yueVerify) {
          return this.showRepayError('请扫描个人中心二维码');
        }
      } else if (payType === '') {
        this.createOrder.auth_code = payNum;
        if (!payNum) {
          return this.showRepayError('请扫描您的付款码');
        }
      }
      this.debtRepaySubmit(payNum, payType);
    },
    syncPayStateFromSettle() {
      const settle = this.$refs.settlePay;
      if (!settle) return;
      const activePay = Number(settle.activePay);
      const cashChoose = Number(settle.cashChoose || 0);
      if (activePay === 2 || cashChoose > 0) {
        this.payType = 'cash';
        this.createOrder.pay_type = 'cash';
        this.createOrder.cash_choose = settle.cashChoose;
        this.collection = this.settleMoney;
      } else if (activePay === 3) {
        this.payType = 'yue';
        this.createOrder.pay_type = 'yue';
      }
    },
    showRepayError(content) {
      this.$Message.error(content);
      this.$nextTick(() => {
        const messageEl = document.querySelector('.ivu-message');
        if (messageEl) {
          messageEl.style.zIndex = String(Number(this.zIndex || 9999) + 100);
        }
      });
    },
    resolveRepayPayType() {
      const combo = this.createOrder.combination_info || [];
      if (combo.length) return 'combination';
      const settle = this.$refs.settlePay;
      const settleCashChoose = Number((settle && settle.cashChoose) || 0);
      const settleActivePay = settle ? Number(settle.activePay) : 0;
      if (
        this.payType === 'cash'
        || this.createOrder.pay_type === 'cash'
        || Number(this.createOrder.cash_choose || 0) > 0
        || settleActivePay === 2
        || settleCashChoose > 0
      ) {
        return 'cash';
      }
      if (this.payType === 'yue' || this.createOrder.pay_type === 'yue' || settleActivePay === 3) {
        return 'yue';
      }
      return this.payType || this.createOrder.pay_type || '';
    },
    debtRepaySubmit(payNum, payTypeOverride) {
      const payType = payTypeOverride || this.resolveRepayPayType();
      if (payType === 'cash') {
        const collection = parseFloat(this.collection) || parseFloat(this.settleMoney) || 0;
        if (parseFloat(this.settleMoney) > collection) {
          return this.showRepayError('您付款金额不足');
        }
      }
      const combo = this.createOrder.combination_info || [];
      const submitPayType = combo.length ? 'combination' : payType;
      const settle = this.$refs.settlePay;
      const cashChoose = Number(this.createOrder.cash_choose || (settle && settle.cashChoose) || 0);
      const payload = {
        debt_id: this.debtRepayData.debt_id,
        debt_item_id: this.debtRepayData.debt_item_id,
        repay_amount: this.debtRepayData.repay_amount,
        pay_type: submitPayType,
        source: this.createOrder.source != null
          ? Number(this.createOrder.source)
          : Number(this.debtRepayInitialSource || 0),
        cash_choose: cashChoose,
        remark_info: this.createOrder.remarkInfo || {},
        budan_time: this.createOrder.budan_time || '',
        combination_info: combo,
        user_code: submitPayType === 'yue' ? payNum : (this.createOrder.userCode || ''),
        auth_code: submitPayType === '' ? payNum : (this.createOrder.auth_code || payNum || ''),
        is_budan: this.createOrder.is_budan || 0,
        setYejiAll: this.debtRepayData.setYejiAll || [],
      };
      debtRepayPayApi(payload).then((res) => {
        this.payNum = '';
        if (res.data.status === 'SUCCESS') {
          this.resetRepayState();
          this.$Message.success(res.data.message || '还款成功');
          this.$emit('success');
        } else if (res.data.status === 'PAY_ING') {
          this.$Message.warning(res.data.message || '等待支付');
        } else {
          this.$Message.error(res.data.message || '还款失败');
        }
      }).catch((err) => {
        this.$Message.error(err.msg || '还款失败');
      });
    },
    resetRepayState() {
      this.isDebtRepay = 0;
      this.lockDebtRepaySource = false;
      this.debtRepayInitialSource = 0;
      this.debtRepayData = {};
      this.settleVisible = false;
      this.collectionArray = [];
    },
  },
};
</script>
