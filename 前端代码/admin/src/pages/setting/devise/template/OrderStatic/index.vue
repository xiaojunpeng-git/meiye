<template>
  <div class="main">
    <template1 v-if="orderStaticType == 1"></template1>
    <template2 v-else-if="orderStaticType == 2"></template2>
    <div class="mask" v-if="!parseInt(orderStaticData.is_show)">已隐藏</div>
  </div>
</template>

<script>
import template1 from "./template1.vue";
import template2 from "./template2.vue";
export default {
  name: "",
  components: { template1, template2 },
  props: {
    orderStaticType: {
      type: Number | String,
      default: 1,
    },
  },
  computed: {
    orderStaticData(val) {
      return this.$store.state.admin.userTemplateConfig.orderStatic;
    },
  },
  data() {
    return {};
  },
  created() {},
  mounted() {},
  methods: {},
};
</script>
<style lang="stylus" scoped></style>