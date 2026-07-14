<template>
  <div class="main">
    <member1 v-if="merType == 1"></member1>
    <member2 v-else-if="merType == 2"></member2>
    <member3 v-else-if="merType == 3"></member3>
    <member4 v-else-if="merType == 4"></member4>
    <member5 v-else></member5>
  </div>
</template>

<script>
import member1 from "./template1.vue";
import member2 from "./template2.vue";
import member3 from "./template3.vue";
import member4 from "./template4.vue";
import member5 from "./template5.vue";
export default {
  name: "",
  components: { member1, member2, member3, member4, member5 },
  props: {
    merType: {
      type: Number | String,
      default: 1,
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