<script setup>
import { formatMoney } from '@/services/cashierV3Bridge'

defineProps({
  member: {
    type: Object,
    default: () => ({})
  },
  amount: {
    type: [String, Number],
    default: 0
  }
})

defineEmits(['cancel', 'repay'])
</script>

<template>
  <div class="member-debt-reminder" role="presentation">
    <section class="member-debt-reminder__dialog" role="alertdialog" aria-modal="true" aria-labelledby="member-debt-reminder-title">
      <button type="button" class="member-debt-reminder__close" aria-label="关闭欠款提醒" @click="$emit('cancel')">×</button>
      <span class="member-debt-reminder__mark" aria-hidden="true">!</span>
      <div>
        <h2 id="member-debt-reminder-title">该会员有待还欠款</h2>
        <p>已选择 {{ member.name || '该会员' }}，当前总欠款 <strong>{{ formatMoney(amount) }}</strong>。</p>
        <span>取消只关闭提醒，会员仍保持选中；也可以先进入欠款明细处理还款。</span>
      </div>
      <footer>
        <button type="button" class="button button--secondary" @click="$emit('cancel')">取消</button>
        <button type="button" class="button button--primary" @click="$emit('repay')">去还款</button>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.member-debt-reminder {
  position: fixed;
  z-index: 1510;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(22, 29, 40, .38);
}

.member-debt-reminder__dialog {
  position: relative;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 16px;
  width: min(520px, 100%);
  padding: 26px;
  border: 1px solid #e1d5d6;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 24px 70px rgba(28, 38, 52, .24);
}

.member-debt-reminder__mark {
  display: grid;
  width: 42px;
  height: 42px;
  place-items: center;
  border-radius: 50%;
  background: #fff1f2;
  color: #9f1239;
  font-size: 23px;
  font-weight: 800;
}

.member-debt-reminder__dialog > div {
  display: grid;
  gap: 8px;
  padding-right: 20px;
}

.member-debt-reminder h2 {
  margin: 0;
  color: #252a31;
  font-size: 19px;
  letter-spacing: 0;
}

.member-debt-reminder p {
  margin: 0;
  color: #4f5b68;
  font-size: 14px;
  line-height: 22px;
}

.member-debt-reminder p strong {
  color: #9f1239;
  font-size: 17px;
  font-variant-numeric: tabular-nums;
}

.member-debt-reminder__dialog > div > span {
  color: #7d8897;
  font-size: 12px;
  line-height: 19px;
}

.member-debt-reminder footer {
  grid-column: 1 / -1;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding-top: 6px;
}

.member-debt-reminder__close {
  position: absolute;
  top: 14px;
  right: 14px;
  display: grid;
  width: 30px;
  height: 30px;
  place-items: center;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: #f1f3f5;
  color: #5d6876;
  font-size: 20px;
}
</style>
