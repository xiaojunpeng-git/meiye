<template>
    <Submenu :name="menuData.path">
        <template slot="title" class="menu-side-title">
            <i-menu-side-title :menu="menuData" />
        </template>
        <template v-for="(item, index) in menuData.children.filter(j => !j.auth)">
            <!-- 侧栏父分组属于二级菜单，其直接入口统一是三级菜单。 -->
            <i-menu-side-item :menu="toSidebarEntry(item)" :key="index" hide-icon />
        </template>
    </Submenu>
</template>
<script>
import iMenuSideItem from './menu-item';
import iMenuSideTitle from './menu-title';

export default {
  name: 'iMenuSideSubmenu',
  components: { iMenuSideItem, iMenuSideTitle },
  props: {
    menu: {
      type: Object,
      default() {
        return {};
      }
    }
  },
  computed: {
    menuData() {
      // 保留接口配置的原始菜单树；侧栏只展示当前分组及其直接子项。
      return JSON.parse(JSON.stringify(this.menu));
    }
  },
  methods: {
    toSidebarEntry(item) {
      if (!item.children || !item.children.length) return item;

      // 仍展示配置分组名称，但导航到第一个可访问子项，避免为了显示
      // 入口而在侧栏继续渲染第三级菜单。
      const firstChild = item.children.find(child => !child.auth && child.path);
      return firstChild ? {
        ...item,
        path: firstChild.path,
        replace: firstChild.replace,
        target: firstChild.target
      } : item;
    }
  }
};
</script>
