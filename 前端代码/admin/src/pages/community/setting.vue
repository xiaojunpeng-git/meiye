<template>
  <!-- 社区设置 -->
  <from-submit
    :validate="ruleValidate"
    :url="url"
    :title="title"
    :rules="rules"
  />
</template>

<script>
import fromSubmit from '@/components/fromSubmit/fromSubmit.vue';
import buildData from '@/pages/setting/shop/buildData';

export default {
  name: 'community',
  components: { fromSubmit },
  mixins: [buildData],
  data() {
    return {
      ruleValidate: {},
      rules: [],
      url: '',
      title: '社区设置',
      type: 'community',
    };
  },
};
</script>

<style scoped>
</style>