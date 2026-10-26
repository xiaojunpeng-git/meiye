<template>
  <div class="main">
    <template1 v-if="orderType == 1" :orderData="orderData"></template1>
    <template2 v-else-if="orderType == 2" :orderData="orderData"></template2>
    <template3 v-else-if="orderType == 3" :orderData="orderData"></template3>
  </div>
</template>

<script>
import template1 from "./template1.vue";
import template2 from "./template2.vue";
import template3 from "./template3.vue";
export default {
  name: "",
  components: { template1, template2, template3 },
  props: {
    orderType: {
      type: Number | String,
      default: 1,
    },
    orderData: {
      type: Object,
      default: () => ({}),
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
