<template>
  <div class="main">
    <template1 v-if="menuType == 1" :menuData="menuData"></template1>
    <template2 v-else-if="menuType == 2" :MyMenus="menuData.list"></template2>
    <template3 v-else-if="menuType == 3" :MyMenus="menuData.list"></template3>
    <div class="mask" v-if="!parseInt(menuData.is_show)">已隐藏</div>
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
    menuType: {
      type: Number | String,
      default: 1,
    },
  },
  computed: {
    // 获取用户信息配置
    menuData() {
      return this.$store.state.admin.userTemplateConfig.menu;
    },
  },
  data() {
    return {
      MyMenus: [],
    };
  },
  created() {},
  mounted() {},
  methods: {},
};
</script>
<style lang="stylus" scoped></style>