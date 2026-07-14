<template>
    <span class="i-layout-header-trigger" :class="{ 'i-layout-header-trigger-min': showReload,'iconOpen': !menuCollapse && !isMobile}" @click="handleToggleMenuSide">
       <Icon class="iconfont iconshouqi-01" size='20' v-show="menuCollapse || isMobile" />
       <Icon class="iconfont iconzhankai-01 text-wlll-C0C4CC" v-show="!menuCollapse && !isMobile" />
    </span>
</template>
<script>
import { mapState, mapMutations } from 'vuex';

export default {
  name: 'iHeaderCollapse',
  computed: {
    ...mapState('admin/layout', [
      'isMobile',
      'isTablet',
      'isDesktop',
      'menuCollapse',
      'showReload'
    ])
  },
  methods: {
    ...mapMutations('admin/layout', [
      'updateMenuCollapse'
    ]),
    // 展开/收起侧边栏
    handleToggleMenuSide(state) {
      if (this.isMobile) {
        this.updateMenuCollapse(false);
        this.$emit('on-toggle-drawer', state);
      } else {
        this.updateMenuCollapse(!this.menuCollapse);
      }
    }
  },
  watch: {
    // 切换页面时，在移动端自动收起侧边栏
    // 强行传参 false 是因为有的路由不是在菜单栏发生的，toggle 会使其显示
    '$route'() {
      if (this.isMobile) this.handleToggleMenuSide(false);
    },
    // 在平板时自动收起菜单
    isTablet(state) {
      if (!this.isMobile && state) this.updateMenuCollapse(true);
    },
    // 在桌面时自动展开菜单
    isDesktop(state) {
      if (!this.isMobile && state) this.updateMenuCollapse(false);
    }
  }
};
</script>
<style lang="stylus" scoped>
	.i-layout-header-trigger{
		width: 40px;
		height: 40px !important;
		line-height: 40px !important;
		padding: 0;
		text-align: center;
	}
	.iconOpen{
		width: 30px;
		height: 30px !important;
		line-height: 30px !important;
	}
</style>
