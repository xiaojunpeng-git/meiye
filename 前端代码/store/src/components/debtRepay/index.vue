<template>
  <div>
    <Modal
      v-model="visible"
      title="还款"
      width="480"
      :mask-closable="false"
      :z-index="modalZIndex"
      class-name="debt-repay-modal"
      @on-cancel="handleCancel"
    >
      <div class="repay-row">
        <span class="label">待还款</span>
        <span class="value amount">¥ {{ pendingAmount }}</span>
      </div>
      <div class="repay-row">
        <span class="label">还款门店</span>
        <span class="value">{{ storeName }}</span>
      </div>
      <div class="repay-row input-row">
        <span class="label">还款金额</span>
        <div class="input-wrap">
          <span class="prefix">¥</span>
          <Input
            v-model="repayAmount"
            placeholder="输入还款金额"
            :maxlength="11"
            inputmode="decimal"
            @on-change="onAmountChange"
            @on-input="onAmountChange"
            @input.native="onAmountChange"
            @on-blur="onAmountBlur"
          />
          <a class="link-all" @click="fillAll">还全款</a>
        </div>
      </div>
      <div class="repay-row">
        <span class="label">销售人员</span>
        <div class="staff-picker" @click="openYeji">
          <span v-if="!setYeji.staffChoose.length" class="staff-placeholder">请选择销售人员</span>
          <span v-else class="staff-selected">
            <span v-for="(item, index) in setYeji.staffChoose" :key="item.staff_id">
              <span v-if="index === 0">{{ item.staff_name }}</span>
              <span v-else>,{{ item.staff_name }}</span>
            </span>
          </span>
        </div>
      </div>
      <div class="divider"></div>
      <div class="repay-row">
        <span class="label">本单剩余欠款</span>
        <span class="value remain">¥ {{ remainAmount }}</span>
      </div>
      <div slot="footer">
        <Button type="primary" long :disabled="!canSubmit" @click="submit">去支付</Button>
      </div>
    </Modal>
    <yeji
      ref="yeji"
      :can-sy="false"
      :is-sale="true"
      :show-apply-all="false"
      :modal-z-index="yejiZIndex"
      :store-id="Number(row.store_id || 0)"
      :yeji="setYeji"
      :sync-product="[]"
      :staff-ids="staffIds"
      :staff-ids-service="[]"
      :visible="yejiVisible"
      :yeji-service="setYejiService"
      @doChoose="onYejiChoose"
      @closeYeji="closeYeji"
    />
  </div>
</template>

<script>
import yeji from '@/components/yeji';

export default {
  name: 'debtRepay',
  components: { yeji },
  props: {
    value: { type: Boolean, default: false },
    row: { type: Object, default: () => ({}) },
    storeName: { type: String, default: '' },
    modalZIndex: { type: Number, default: 1100 },
  },
  data() {
    return {
      repayAmount: '',
      yejiVisible: false,
      staffIds: [],
      setYeji: {
        link_id: 0,
        cart_id: 0,
        price: 0,
        balance_price: 0,
        goods_id: 0,
        type: 2,
        staffChoose: [],
      },
      setYejiService: {
        link_id: 0,
        cart_id: 0,
        price: 0,
        goods_id: 0,
        type: 2,
        staffChoose: [],
      },
    };
  },
  computed: {
    visible: {
      get() { return this.value; },
      set(v) { this.$emit('input', v); },
    },
    maxRepayAmount() {
      return Number(this.row.pending_debt || 0);
    },
    pendingAmount() {
      return this.maxRepayAmount.toFixed(2);
    },
    remainAmount() {
      const repay = Number(this.repayAmount || 0);
      const remain = Math.max(this.maxRepayAmount - (isNaN(repay) ? 0 : repay), 0);
      return remain.toFixed(2);
    },
    canSubmit() {
      const repay = Number(this.repayAmount || 0);
      return repay > 0 && repay <= this.maxRepayAmount;
    },
    yejiZIndex() {
      return this.modalZIndex + 100;
    },
  },
  watch: {
    value(val) {
      if (val) {
        this.repayAmount = '';
        this.resetYeji();
      }
    },
    repayAmount() {
      this.syncYejiPrice();
    },
  },
  methods: {
    resetYeji() {
      this.staffIds = [];
      this.setYeji = {
        link_id: 0,
        cart_id: Number(this.row.cart_id || 0),
        price: 0,
        balance_price: 0,
        goods_id: Number(this.row.product_id || 0),
        type: 2,
        staffChoose: [],
      };
    },
    syncYejiPrice() {
      const price = Number(this.repayAmount || 0);
      this.setYeji.price = price;
      this.setYeji.balance_price = 0;
    },
    openYeji() {
      this.syncYejiPrice();
      this.setYeji.goods_id = Number(this.row.product_id || 0);
      this.setYeji.cart_id = Number(this.row.cart_id || 0);
      if (this.$refs.yeji) {
        const storeId = Number(this.row.store_id || 0);
        this.$refs.yeji.staffForm.store_id = storeId;
        this.$refs.yeji.getStaff();
        this.$refs.yeji.activeName = 'yeji';
      }
      this.yejiVisible = true;
    },
    onYejiChoose(yeji) {
      this.setYeji = { ...this.setYeji, ...yeji };
      this.staffIds = (yeji.staffChoose || []).map((item) => item.staff_id);
      this.closeYeji();
    },
    closeYeji() {
      this.yejiVisible = false;
    },
    fillAll() {
      this.repayAmount = this.pendingAmount;
    },
    formatMoney(num) {
      return Number(num || 0).toFixed(2);
    },
    normalizeMoneyInput(val) {
      if (val === null || typeof val === 'undefined') return '';
      let s = String(val);
      s = s.replace(/[^\d.]/g, '');
      s = s.replace(/\.{2,}/g, '.');
      s = s.replace(/^\./g, '0.');
      const parts = s.split('.');
      if (parts.length > 2) s = parts[0] + '.' + parts.slice(1).join('');
      if (s.includes('.')) {
        const [a, b] = s.split('.');
        s = a.slice(0, 8) + '.' + (b || '');
      } else {
        s = s.slice(0, 8);
      }
      if (s.includes('.')) {
        const [a, b] = s.split('.');
        s = a + '.' + (b || '').slice(0, 2);
      }
      return s;
    },
    clampRepayAmount(incomingVal) {
      const raw = this.normalizeMoneyInput(
        typeof incomingVal === 'undefined' ? this.repayAmount : incomingVal
      );
      if (raw === '') return '';
      let next = raw;
      const cap = this.maxRepayAmount;
      if (Number(next) > cap) {
        next = cap > 0 ? String(cap.toFixed(2)) : '';
      }
      return next;
    },
    onAmountChange(e) {
      const incoming = e && e.target ? e.target.value : (typeof e === 'string' ? e : this.repayAmount);
      const next = this.clampRepayAmount(incoming);
      if (next !== this.repayAmount) {
        this.repayAmount = next;
      }
      if (e && e.target && typeof e.target.value !== 'undefined' && e.target.value !== next) {
        e.target.value = next;
      }
    },
    onAmountBlur() {
      const raw = this.normalizeMoneyInput(this.repayAmount);
      if (raw === '' || raw === '.') {
        this.repayAmount = '';
        return;
      }
      let num = Number(raw);
      if (isNaN(num) || num <= 0) {
        this.repayAmount = '';
        return;
      }
      const cap = this.maxRepayAmount;
      if (num > cap) num = cap;
      this.repayAmount = this.formatMoney(num);
    },
    submit() {
      if (!this.canSubmit) {
        return;
      }
      const setYejiAll = this.setYeji.staffChoose.length
        ? [{
          ...this.setYeji,
          price: Number(this.repayAmount),
          balance_price: 0,
        }]
        : [];
      this.$emit('pay', {
        debt_id: this.row.debt_id,
        debt_item_id: this.row.debt_item_id || this.row.id,
        repay_amount: Number(this.repayAmount),
        order_sn: this.row.order_sn,
        original_source: Number(this.row.original_source || 0),
        setYejiAll,
      });
      this.visible = false;
    },
    handleCancel() {
      this.visible = false;
    },
  },
};
</script>

<style scoped>
.repay-row {
  display: flex;
  align-items: center;
  margin-bottom: 18px;
  font-size: 14px;
}
.repay-row .label {
  width: 100px;
  color: #606266;
  flex-shrink: 0;
}
.repay-row .value.amount {
  color: #303133;
  font-size: 18px;
  font-weight: 600;
}
.repay-row .value.remain {
  color: #ff4d4f;
  font-weight: 600;
}
.input-row .input-wrap {
  flex: 1;
  display: flex;
  align-items: center;
}
.input-wrap .prefix {
  margin-right: 6px;
}
.input-wrap .link-all {
  margin-left: 10px;
  white-space: nowrap;
  color: #6c5ce7;
  cursor: pointer;
}
.staff-picker {
  flex: 1;
  min-height: 32px;
  line-height: 32px;
  padding: 0 12px;
  border: 1px solid #dcdee2;
  border-radius: 4px;
  cursor: pointer;
  color: #736a6a;
}
.staff-placeholder {
  color: #c5c8ce;
}
.staff-selected {
  color: #736a6a;
}
.divider {
  border-top: 1px dashed #e8eaec;
  margin: 8px 0 18px;
}
</style>
