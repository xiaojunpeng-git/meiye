<script setup>
defineProps({
  customerMode: {
    type: String,
    default: 'unselected'
  },
  member: {
    type: Object,
    default: null
  },
  emptyTitle: {
    type: String,
    default: '尚未选择会员'
  },
  emptyDescription: {
    type: String,
    default: '请先选择会员后继续操作。'
  },
  selectLabel: {
    type: String,
    default: '更换会员'
  },
  showActions: {
    type: Boolean,
    default: true
  },
  showEmptyActions: {
    type: Boolean,
    default: true
  },
  showWriteoff: {
    type: Boolean,
    default: false
  },
  showInlineSelect: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['select', 'open-detail', 'open-debt', 'go-writeoff'])

function maskedPhone(phone) {
  const value = String(phone || '')
  if (value.length < 7) return value
  return `${value.slice(0, 3)}****${value.slice(-4)}`
}

function cardBenefitAmount(member) {
  return Number(member?.cardBenefitAmount ?? member?.remainingProjectAmount ?? 0)
}

function totalBalanceAmount(member) {
  return member?.totalBalanceAmount ?? member?.totalAvailableAmount ?? member?.accountBalance ?? 0
}

function outstandingDebtAmount(member) {
  return member?.outstandingDebtAmount ?? member?.totalDebtAmount ?? member?.debtAmount ?? 0
}

function hasOutstandingDebt(member) {
  return Number(outstandingDebtAmount(member)) > 0
}

function formatPlainAmount(value) {
  return String(Number(value || 0))
}

function openMemberDebt(member) {
  emit('open-debt', member?.id || member?.memberId || member?.uid || null)
}
</script>

<template>
  <section v-if="customerMode === 'guest'" class="member-card member-card--empty member-card--guest" :class="{ 'member-card--no-actions': !showActions }">
    <div>
      <strong>游客</strong>
      <span>本次按游客结账，不关联会员权益。</span>
    </div>
  </section>

  <section v-else-if="member" class="member-card" :class="{ 'member-card--no-actions': !showActions }">
    <div class="member-card__identity">
      <button type="button" class="member-card__avatar" :aria-label="`查看${member.name}的会员详情`" @click="$emit('open-detail', member.id)">
        <img v-if="member.avatarUrl || member.avatar" :src="member.avatarUrl || member.avatar" alt="">
        <span v-else>{{ String(member.name || '会').slice(0, 1) }}</span>
      </button>
      <div class="member-card__details">
        <button type="button" class="member-card__member" @click="$emit('open-detail', member.id)">
          <span class="member-card__member-line">
            <strong>{{ member.name }}</strong>
            <i>·</i><span>手机 {{ maskedPhone(member.phone) }}</span>
            <i>·</i><span>{{ member.storeName || member.belongStoreName || '所属门店未设置' }}</span>
            <i>·</i><span>{{ member.serviceAdvisorName || member.exclusiveStaffName || '未设置' }}</span>
          </span>
        </button>
        <div class="member-card__metrics">
          <div><span>余额：</span><strong>{{ formatPlainAmount(member.accountBalance) }}</strong></div>
          <div><span>次卡：</span><strong>{{ formatPlainAmount(cardBenefitAmount(member)) }}</strong></div>
          <div class="member-card__metric-total"><span>总余额：</span><strong>{{ formatPlainAmount(totalBalanceAmount(member)) }}</strong></div>
          <div class="member-card__metric-debt">
            <span>总欠款：</span>
            <button
              v-if="hasOutstandingDebt(member)"
              type="button"
              class="member-card__debt-link"
              :aria-label="`查看${member.name}的欠款明细，总欠款${formatPlainAmount(outstandingDebtAmount(member))}`"
              @click.stop="openMemberDebt(member)"
            >
              {{ formatPlainAmount(outstandingDebtAmount(member)) }}
            </button>
            <strong v-else class="member-card__debt-zero">{{ formatPlainAmount(outstandingDebtAmount(member)) }}</strong>
          </div>
          <button v-if="showInlineSelect" type="button" class="member-card__inline-change" @click="$emit('select')">{{ selectLabel }}</button>
        </div>
      </div>
    </div>

    <div v-if="showActions" class="member-card__actions">
      <button type="button" class="member-card__change" @click="$emit('select')">{{ selectLabel }}</button>
      <slot name="available-action">
        <button v-if="showWriteoff" type="button" class="member-card__writeoff" @click="$emit('go-writeoff', member.id)">核销项目</button>
      </slot>
    </div>

  </section>

  <section v-else class="member-card member-card--empty" :class="{ 'member-card--no-actions': !showActions }">
    <div>
      <strong>{{ emptyTitle }}</strong>
      <span>{{ emptyDescription }}</span>
    </div>
    <div v-if="showEmptyActions" class="member-card--empty__actions">
      <slot name="empty-actions">
        <button type="button" class="button button--secondary" @click="$emit('select')">选择会员</button>
      </slot>
    </div>
  </section>
</template>
