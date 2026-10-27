<template>
  <footer class="growth-pager">
    <span
      >共 {{ pageData ? pageData.total : 0 }} 条 · 每页 20 条 · 第 {{ pageData ? pageData.page : 1 }} /
      {{ pageData ? pageData.total_pages : 1 }} 页</span
    ><button :disabled="busy || !pageData || pageData.page <= 1" @click="$emit('change', pageData.page - 1)">
      上一页</button
    ><button
      :disabled="busy || !pageData || pageData.page >= pageData.total_pages"
      @click="$emit('change', pageData.page + 1)"
    >
      下一页
    </button>
  </footer>
</template>
<script>
/** 普通列表复用后端分页元数据；翻页事件不修改查询条件。 */
export default {
  name: 'GrowthPager',
  props: { pageData: { type: Object, default: null }, busy: { type: Boolean, default: false } }
};
</script>
