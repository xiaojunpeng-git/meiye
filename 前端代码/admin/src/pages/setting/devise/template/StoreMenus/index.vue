<template>
  <div class="main">
    <template1 v-if="storeAdminType == 1" :storeMenuData="storeMenuData"></template1>
    <template2
      v-else-if="storeAdminType == 2"
      :storeMenus="storeMenuData.list"
    ></template2>
    <template3
      v-else-if="storeAdminType == 3"
      :storeMenus="storeMenuData.list"
    ></template3>
    <div class="mask" v-if="!parseInt(storeMenuData.is_show)">已隐藏</div>
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
    storeAdminType: {
      type: Number | String,
      default: 1,
    },
  },
  computed: {
    // 获取用户信息配置
    storeMenuData() {
      return this.$store.state.admin.userTemplateConfig.storeMenu;
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