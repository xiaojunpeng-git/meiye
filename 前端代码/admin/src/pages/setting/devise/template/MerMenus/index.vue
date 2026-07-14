<template>
  <div class="main">
    <template1 v-if="merAdminType == 1" :merMenuData="merMenuData"></template1>
    <template2
      v-else-if="merAdminType == 2"
      :merMenus="merMenuData.list"
    ></template2>
    <template3
      v-else-if="merAdminType == 3"
      :merMenus="merMenuData.list"
    ></template3>
    <div class="mask" v-if="!parseInt(merMenuData.is_show)">已隐藏</div>
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
    merAdminType: {
      type: Number | String,
      default: 1,
    },
  },
  computed: {
    // 获取用户信息配置
    merMenuData() {
      return this.$store.state.admin.userTemplateConfig.merMenu;
    },
  },
  data() {
    return {
      merMenus: [],
    };
  },
  created() {},
  mounted() {},
  methods: {},
};
</script>
<style lang="stylus" scoped></style>