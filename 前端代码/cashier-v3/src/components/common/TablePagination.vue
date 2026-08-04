<script setup>
import { computed } from 'vue'

const props = defineProps({
  total: {
    type: Number,
    default: 0
  },
  page: {
    type: Number,
    default: 1
  },
  pageSize: {
    type: Number,
    default: 20
  },
  pageSizeOptions: {
    type: Array,
    default: () => [20, 50, 100]
  },
  sequential: {
    type: Boolean,
    default: false
  },
  hasMore: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['change'])

const normalizedTotal = computed(() => Math.max(0, Number(props.total) || 0))
const normalizedPageSize = computed(() => Math.max(1, Number(props.pageSize) || 20))
const totalPages = computed(() => Math.max(1, Math.ceil(normalizedTotal.value / normalizedPageSize.value)))
const currentPage = computed(() => {
  const page = Math.max(1, Number(props.page) || 1)
  return props.sequential ? page : Math.min(page, totalPages.value)
})
const pageItems = computed(() => {
  if (props.sequential) return [currentPage.value]
  const pages = new Set([1, totalPages.value, currentPage.value - 1, currentPage.value, currentPage.value + 1])
  const ordered = Array.from(pages).filter((page) => page >= 1 && page <= totalPages.value).sort((left, right) => left - right)
  const items = []
  ordered.forEach((page, index) => {
    if (index && page - ordered[index - 1] > 1) items.push(`gap-${page}`)
    items.push(page)
  })
  return items
})
const rangeStart = computed(() => normalizedTotal.value
  ? Math.min(((currentPage.value - 1) * normalizedPageSize.value) + 1, normalizedTotal.value)
  : 0)
const rangeEnd = computed(() => Math.min(currentPage.value * normalizedPageSize.value, normalizedTotal.value))

function changePage(page) {
  const requested = Math.max(1, Number(page) || 1)
  const nextPage = props.sequential
    ? Math.min(requested, currentPage.value + 1)
    : Math.min(requested, totalPages.value)
  if (props.sequential && nextPage > currentPage.value && !props.hasMore) return
  if (nextPage === currentPage.value) return
  emit('change', { page: nextPage, pageSize: normalizedPageSize.value })
}

function changePageSize(event) {
  const pageSize = Math.max(1, Number(event.target.value) || normalizedPageSize.value)
  emit('change', { page: 1, pageSize })
}
</script>

<template>
  <footer class="table-pagination" aria-label="列表分页">
    <span class="table-pagination__summary">
      共 <strong>{{ normalizedTotal }}</strong> 条，当前 {{ rangeStart }}-{{ rangeEnd }} 条
    </span>
    <div class="table-pagination__controls">
      <label>
        <span class="sr-only">每页数量</span>
        <select :value="normalizedPageSize" aria-label="每页数量" @change="changePageSize">
          <option v-for="size in pageSizeOptions" :key="size" :value="size">{{ size }} 条／页</option>
        </select>
      </label>
      <button type="button" aria-label="上一页" :disabled="currentPage <= 1" @click="changePage(currentPage - 1)">‹</button>
      <template v-for="item in pageItems" :key="item">
        <span v-if="typeof item === 'string'" class="table-pagination__gap">…</span>
        <button
          v-else
          type="button"
          :class="{ 'table-pagination__page--active': item === currentPage }"
          :aria-current="item === currentPage ? 'page' : undefined"
          @click="changePage(item)"
        >{{ item }}</button>
      </template>
      <button
        type="button"
        aria-label="下一页"
        :disabled="sequential ? !hasMore : currentPage >= totalPages"
        @click="changePage(currentPage + 1)"
      >›</button>
    </div>
  </footer>
</template>
