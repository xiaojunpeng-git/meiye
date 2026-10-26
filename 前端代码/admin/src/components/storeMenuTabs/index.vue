<template>
  <Tabs class="menuTabs" :animated="false" :value="activePath" @on-click="tabClick">
    <TabPane
      v-for="item in tabs"
      :key="item.path"
      :label="item.title"
      :name="item.path"
    />
  </Tabs>
</template>

<script>
import Setting from '@/setting';

const STORE_SETTING_TABS = [
  { title: '门店设置', path: `${Setting.roterPre}/store/system/base` },
  { title: '门店菜单', path: `${Setting.roterPre}/store/store_menus/index` },
  { title: '收银台菜单', path: `${Setting.roterPre}/store/cashier_menus/index` }
];

export default {
  name: 'StoreMenuTabs',
  data() {
    return {
      tabs: STORE_SETTING_TABS
    };
  },
  computed: {
    activePath() {
      const path = (this.$route.path || '').replace(/\/$/, '');
      const matched = this.tabs.find(item => item.path.replace(/\/$/, '') === path);
      return matched ? matched.path : this.tabs[0].path;
    }
  },
  methods: {
    tabClick(path) {
      const current = (this.$route.path || '').replace(/\/$/, '');
      const target = (path || '').replace(/\/$/, '');
      if (current !== target) {
        this.$router.push(path);
      }
    }
  }
};
</script>

<style scoped lang="stylus">
.menuTabs
  margin 0
.menuTabs /deep/.ivu-tabs-bar
  margin-bottom 1px
  border-bottom 0
.menuTabs /deep/.ivu-tabs-nav .ivu-tabs-tab
  padding 12px 0
  margin-right 32px
.menuTabs /deep/.ivu-tabs-ink-bar
  height 0
.menuTabs /deep/.ivu-tabs-nav .ivu-tabs-tab-active:before
  content ''
  position absolute
  width 100%
  height 2px
  background-color #2d8cf0
  bottom 1px
</style>
