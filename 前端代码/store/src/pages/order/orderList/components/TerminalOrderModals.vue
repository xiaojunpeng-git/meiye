<template>
  <div>
    <!-- 整单退款 -->
    <Modal
      v-model="refundVisible"
      title="整单退款"
      width="720"
      class-name="terminal-refund-modal"
      :mask-closable="false"
      @on-visible-change="onRefundVisible"
    >
      <Alert type="warning" show-icon class="mb12">
        只能整笔退款一次。退款会计入退款统计；若只是开错单请使用「作废」。
      </Alert>
      <Form :label-width="120">
        <FormItem label="订单编号：">
          <span>{{ row.order_id || '-' }}</span>
        </FormItem>
        <FormItem label="实付金额：">
          <span>￥{{ payPriceText }}</span>
        </FormItem>
        <FormItem label="退款日期：" required>
          <DatePicker
            v-model="refundBusinessDate"
            type="date"
            transfer
            placeholder="选择退款日期"
            style="width: 240px"
            :options="dateOptions"
          />
          <div class="tips">默认今天；可补录原支付日至今天之间的日期，用于退款统计归属。</div>
        </FormItem>
        <FormItem label="退款金额：">
          <InputNumber v-model="refundMoney" :min="0" :precision="2" class="w-240" />
          <div class="tips">本单可退金额上限以页面提示与提交校验为准；不能只退部分商品。</div>
        </FormItem>
        <template v-if="showBalance">
          <FormItem label="退本金：">
            <Input v-model="refundBen" class="w-240" placeholder="退还本金" />
          </FormItem>
          <FormItem label="退赠金：">
            <Input v-model="refundGive" class="w-240" placeholder="退还赠金" />
          </FormItem>
          <FormItem label="总余额：">
            <span>￥{{ balanceSumText }}</span>
            <div class="tips">总余额由本金+赠金自动合计，不能单独填写。</div>
          </FormItem>
        </template>
        <FormItem label="退款说明：">
          <Input v-model="refundExplain" type="textarea" :rows="2" placeholder="选填" />
        </FormItem>
        <template v-if="showBookkeepingConfirm">
          <FormItem label="记账确认：" required>
            <Checkbox v-model="bookkeepingConfirmed">我已确认线下退回记账款项</Checkbox>
            <div class="tips">本单含记账收款，须由操作员亲自勾选确认后才能提交。</div>
          </FormItem>
          <FormItem label="记账退款备注：" required>
            <Input
              v-model="bookkeepingRemark"
              type="textarea"
              :rows="2"
              placeholder="请填写线下退回说明（必填）"
            />
          </FormItem>
        </template>
        <FormItem v-if="showReturnCoupon" label="优惠券：">
          <RadioGroup v-model="returnCoupon">
            <Radio :label="1">退回优惠券给用户</Radio>
            <Radio :label="0">不退回</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem label="售后入库：">
          <RadioGroup v-model="stockInType">
            <Radio :label="0">暂不入库</Radio>
            <Radio :label="1">入良品库</Radio>
            <Radio :label="2">入残次品库</Radio>
          </RadioGroup>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="refundVisible = false">取消</Button>
        <Button type="primary" :loading="submitting" @click="submitRefund">确认退款</Button>
      </div>
    </Modal>

    <!-- 整单作废 -->
    <Modal
      v-model="voidVisible"
      title="作废订单"
      width="640"
      :mask-closable="false"
      @on-visible-change="onVoidVisible"
    >
      <Alert type="error" show-icon class="mb12">
        作废用于开错单纠错，不计入退款统计。将冲销本单关联的权益、核销、业绩和库存影响。
      </Alert>
      <Form :label-width="120">
        <FormItem label="订单编号：">
          <span>{{ row.order_id || '-' }}</span>
        </FormItem>
        <FormItem label="作废原因：" required>
          <Input v-model="voidReason" type="textarea" :rows="3" placeholder="请说明作废原因" />
        </FormItem>
        <template v-if="showBookkeepingConfirm">
          <FormItem label="记账确认：" required>
            <Checkbox v-model="bookkeepingConfirmed">我已确认线下退回记账款项</Checkbox>
            <div class="tips">本单含记账收款，须由操作员亲自勾选确认后才能提交。</div>
          </FormItem>
          <FormItem label="记账退款备注：" required>
            <Input
              v-model="bookkeepingRemark"
              type="textarea"
              :rows="2"
              placeholder="请填写线下退回说明（必填）"
            />
          </FormItem>
        </template>
        <FormItem v-if="showReturnCoupon" label="优惠券：">
          <RadioGroup v-model="returnCoupon">
            <Radio :label="1">退回优惠券给用户</Radio>
            <Radio :label="0">不退回</Radio>
          </RadioGroup>
        </FormItem>
        <FormItem label="售后入库：">
          <RadioGroup v-model="stockInType">
            <Radio :label="0">暂不入库</Radio>
            <Radio :label="1">入良品库</Radio>
            <Radio :label="2">入残次品库</Radio>
          </RadioGroup>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="voidVisible = false">取消</Button>
        <Button type="error" :loading="submitting" @click="submitVoid">确认作废</Button>
      </div>
    </Modal>

    <!-- 充值退款 -->
    <Modal
      v-model="rechargeVisible"
      title="充值退款"
      width="640"
      :mask-closable="false"
      @on-visible-change="onRechargeVisible"
    >
      <Alert type="warning" show-icon class="mb12">
        一张充值订单只能成功退款一次；请核对本金、赠金与退款日期后再提交。
      </Alert>
      <Form :label-width="120">
        <FormItem label="订单编号：">
          <span>{{ row.order_id || '-' }}</span>
        </FormItem>
        <FormItem label="退款日期：" required>
          <DatePicker
            v-model="refundBusinessDate"
            type="date"
            transfer
            placeholder="选择退款日期"
            style="width: 240px"
            :options="dateOptions"
          />
          <div class="tips">默认今天；允许选择支付日至今天。</div>
        </FormItem>
        <FormItem label="本金：" required>
          <Input v-model="refundBen" class="w-240" placeholder="本次充值本金" />
        </FormItem>
        <FormItem label="赠金：" required>
          <Input v-model="refundGive" class="w-240" placeholder="本次充值赠金" />
        </FormItem>
        <FormItem label="总余额：">
          <span>￥{{ balanceSumText }}</span>
          <div class="tips">总余额由本金+赠金自动合计，不能单独填写。</div>
        </FormItem>
      </Form>
      <div slot="footer">
        <Button @click="rechargeVisible = false">取消</Button>
        <Button type="primary" :loading="submitting" @click="submitRechargeRefund">确认退款</Button>
      </div>
    </Modal>
  </div>
</template>

<script>
import {
  postOrderTerminalRefund,
  postOrderTerminalVoid,
  makeTerminalRequestToken,
  putRechargeRefund,
} from '@/api/order';

function formatYmd(d) {
  if (!d) return '';
  const date = d instanceof Date ? d : new Date(d);
  if (Number.isNaN(date.getTime())) return '';
  const y = date.getFullYear();
  const m = `${date.getMonth() + 1}`.padStart(2, '0');
  const day = `${date.getDate()}`.padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function parsePayDay(row) {
  const raw = row._pay_time || row.pay_time || row.add_time;
  if (!raw) return null;
  if (typeof raw === 'number') {
    const ms = raw < 1e12 ? raw * 1000 : raw;
    return new Date(ms);
  }
  const s = String(raw).replace(/-/g, '/');
  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** 与后端 resolveOriginPaymentParts 对齐：是否含记账收款 */
export function orderHasBookkeeping(row) {
  if (!row || typeof row !== 'object') return false;
  const payType = String(row.pay_type || '');
  const payPrice = Number(row.pay_price || 0);
  const cashPay = Number(row.cash_pay_price || 0);
  if (payType === 'cash' && payPrice > 0) return true;
  if (cashPay > 0) return true;
  if (payType === 'combination') {
    const lines = row.combination_pay_lines || row.combination_order || row.combination || [];
    if (Array.isArray(lines)) {
      for (let i = 0; i < lines.length; i += 1) {
        const line = lines[i] || {};
        const active = Number(line.active_pay != null ? line.active_pay : line.activePay);
        const price = Number(line.price || 0);
        if (active === 2 && price > 0) return true;
      }
    }
  }
  // cash_choose=7 为本地常见「记账收款」现金类型；仅在确有现金/记账额时作为辅助信号
  if (Number(row.cash_choose) === 7 && (payType === 'cash' || cashPay > 0 || payPrice > 0)) {
    return true;
  }
  return false;
}

export default {
  name: 'TerminalOrderModals',
  data() {
    return {
      refundVisible: false,
      voidVisible: false,
      rechargeVisible: false,
      submitting: false,
      row: {},
      rechargeId: 0,
      refundMoney: 0,
      refundBen: '0',
      refundGive: '0',
      refundExplain: '',
      refundBusinessDate: new Date(),
      returnCoupon: 1,
      stockInType: 0,
      voidReason: '',
      showReturnCoupon: false,
      bookkeepingConfirmed: false,
      bookkeepingRemark: '',
      requestToken: '',
      dateOptions: {
        disabledDate: (date) => {
          const pay = parsePayDay(this.row);
          const today = new Date();
          today.setHours(23, 59, 59, 999);
          if (date.getTime() > today.getTime()) return true;
          if (pay) {
            const min = new Date(pay);
            min.setHours(0, 0, 0, 0);
            if (date.getTime() < min.getTime()) return true;
          }
          return false;
        },
      },
    };
  },
  computed: {
    payPriceText() {
      return Number(this.row.pay_price || 0).toFixed(2);
    },
    showBalance() {
      const t = this.row.pay_type;
      return t === 'yue' || t === 'combination' || Number(this.row.yue_pay_price || 0) > 0;
    },
    showBookkeepingConfirm() {
      return orderHasBookkeeping(this.row);
    },
    balanceSumText() {
      const ben = parseFloat(this.refundBen) || 0;
      const give = parseFloat(this.refundGive) || 0;
      return (ben + give).toFixed(2);
    },
  },
  methods: {
    resetBookkeeping() {
      this.bookkeepingConfirmed = false;
      this.bookkeepingRemark = '';
    },
    buildBookkeepingPayload() {
      if (!this.showBookkeepingConfirm) {
        return { bookkeeping_confirmed: 0, bookkeeping_remark: '' };
      }
      if (!this.bookkeepingConfirmed) {
        this.$Message.required('请勾选：我已确认线下退回记账款项');
        return null;
      }
      const remark = String(this.bookkeepingRemark || '').trim();
      if (!remark) {
        this.$Message.required('记账退款备注未填写');
        return null;
      }
      return { bookkeeping_confirmed: 1, bookkeeping_remark: remark };
    },
    validateRefundDate() {
      const dateStr = formatYmd(this.refundBusinessDate);
      if (!dateStr) {
        this.$Message.required('退款日期未选择');
        return '';
      }
      const pay = parsePayDay(this.row);
      const today = formatYmd(new Date());
      if (dateStr > today) {
        this.$Message.error('退款日期不能晚于今天');
        return '';
      }
      if (pay && dateStr < formatYmd(pay)) {
        this.$Message.error('退款日期不能早于原订单支付日期');
        return '';
      }
      return dateStr;
    },
    openRefund(row, opts = {}) {
      this.row = row || {};
      this.refundMoney = Number(row.pay_price || 0);
      this.refundBen = String(opts.refundBen != null ? opts.refundBen : '0');
      this.refundGive = String(opts.refundGive != null ? opts.refundGive : '0');
      this.refundExplain = '';
      this.refundBusinessDate = new Date();
      this.returnCoupon = 1;
      this.stockInType = 0;
      this.showReturnCoupon = !!opts.showReturnCoupon;
      this.resetBookkeeping();
      this.requestToken = makeTerminalRequestToken('refund');
      this.refundVisible = true;
    },
    openVoid(row, opts = {}) {
      this.row = row || {};
      this.voidReason = '';
      this.returnCoupon = 1;
      this.stockInType = 0;
      this.showReturnCoupon = !!opts.showReturnCoupon;
      this.resetBookkeeping();
      this.requestToken = makeTerminalRequestToken('void');
      this.voidVisible = true;
    },
    openRechargeRefund(row, opts = {}) {
      this.row = row || {};
      this.rechargeId = Number(opts.rechargeId != null ? opts.rechargeId : row.link_id || 0);
      const ben = opts.refundBen != null
        ? opts.refundBen
        : (row.recharge_price != null ? row.recharge_price : (row.paid_ben_amount != null ? row.paid_ben_amount : row.pay_price));
      const give = opts.refundGive != null
        ? opts.refundGive
        : (row.recharge_give_price != null ? row.recharge_give_price : (row.paid_give_amount != null ? row.paid_give_amount : 0));
      this.refundBen = String(ben != null ? ben : '0');
      this.refundGive = String(give != null ? give : '0');
      this.refundBusinessDate = new Date();
      this.requestToken = makeTerminalRequestToken('recharge');
      this.rechargeVisible = true;
    },
    onRefundVisible(v) {
      if (!v) this.submitting = false;
    },
    onVoidVisible(v) {
      if (!v) this.submitting = false;
    },
    onRechargeVisible(v) {
      if (!v) this.submitting = false;
    },
    submitRefund() {
      const dateStr = this.validateRefundDate();
      if (!dateStr) return;
      if (this.showBalance) {
        if (this.refundBen === '' || this.refundBen === null || this.refundGive === '' || this.refundGive === null) {
          return this.$Message.required('退本金未填写');
        }
      }
      const bk = this.buildBookkeepingPayload();
      if (!bk) return;
      const doSubmit = () => {
        if (!this.requestToken) {
          this.requestToken = makeTerminalRequestToken('refund');
        }
        this.submitting = true;
        const data = {
          refund_price: this.refundMoney,
          refund_amount: this.refundMoney,
          refund_ben: this.showBalance ? this.refundBen : '0',
          refund_give: this.showBalance ? this.refundGive : '0',
          refund_business_date: dateStr,
          refund_explain: this.refundExplain,
          refund_reason: this.refundExplain,
          request_token: this.requestToken,
          return_coupon: this.showReturnCoupon ? this.returnCoupon : 1,
          stock_in_type: this.stockInType,
          bookkeeping_confirmed: bk.bookkeeping_confirmed,
          bookkeeping_remark: bk.bookkeeping_remark,
        };
        postOrderTerminalRefund(this.row.id, data)
          .then((res) => {
            this.$Message.success(res.msg || '退款成功');
            this.refundVisible = false;
            this.$emit('success');
          })
          .catch((err) => {
            this.$Message.error((err && err.msg) || '退款未成功，请核对后重试');
          })
          .finally(() => {
            this.submitting = false;
          });
      };
      if (this.showBalance) {
        this.$Modal.confirm({
          title: '确认退款',
          content: `本次退回本金【${this.refundBen}】元、赠金【${this.refundGive}】元（合计￥${this.balanceSumText}），是否确认？`,
          okText: '确认退款',
          cancelText: '取消',
          onOk: doSubmit,
        });
      } else {
        doSubmit();
      }
    },
    submitVoid() {
      const reason = String(this.voidReason || '').trim();
      if (!reason) {
        return this.$Message.required('作废原因未填写');
      }
      const bk = this.buildBookkeepingPayload();
      if (!bk) return;
      if (!this.requestToken) {
        this.requestToken = makeTerminalRequestToken('void');
      }
      this.submitting = true;
      postOrderTerminalVoid(this.row.id, {
        void_reason: reason,
        request_token: this.requestToken,
        return_coupon: this.showReturnCoupon ? this.returnCoupon : 1,
        stock_in_type: this.stockInType,
        bookkeeping_confirmed: bk.bookkeeping_confirmed,
        bookkeeping_remark: bk.bookkeeping_remark,
      })
        .then((res) => {
          this.$Message.success(res.msg || '作废成功');
          this.voidVisible = false;
          const data = (res && res.data) || {};
          this.$emit('success', { action: 'void', can_reopen: !!data.can_reopen, row: this.row });
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '作废未成功，请核对后重试');
        })
        .finally(() => {
          this.submitting = false;
        });
    },
    submitRechargeRefund() {
      if (!this.rechargeId) {
        return this.$Message.error('充值单不存在，请刷新后重试');
      }
      const dateStr = this.validateRefundDate();
      if (!dateStr) return;
      if (this.refundBen === '' || this.refundBen === null || this.refundGive === '' || this.refundGive === null) {
        return this.$Message.required('本金或赠金未填写');
      }
      if (!this.requestToken) {
        this.requestToken = makeTerminalRequestToken('recharge');
      }
      this.submitting = true;
      putRechargeRefund(this.rechargeId, {
        price: this.refundBen,
        give_price: this.refundGive,
        refund_business_date: dateStr,
        request_token: this.requestToken,
      })
        .then((res) => {
          this.$Message.success(res.msg || '退款成功');
          this.rechargeVisible = false;
          this.$emit('success', { action: 'recharge_refund', row: this.row });
        })
        .catch((err) => {
          this.$Message.error((err && err.msg) || '充值退款未成功，请核对后重试');
        })
        .finally(() => {
          this.submitting = false;
        });
    },
  },
};
</script>

<style scoped>
.mb12 { margin-bottom: 12px; }
.tips { color: #999; font-size: 12px; line-height: 1.5; margin-top: 4px; }
.w-240 { width: 240px; }
</style>
