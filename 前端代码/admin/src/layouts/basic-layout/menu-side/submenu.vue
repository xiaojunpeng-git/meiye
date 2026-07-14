<template>
    <Submenu :name="menuData.path">
        <template slot="title" class="menu-side-title">
            <i-menu-side-title :menu="menuData" />
        </template>
        <template v-for="(item, index) in menuData.children.filter(j => !j.auth)">
            <i-menu-side-item :menu="item" :key="index" v-if="item.children === undefined || !item.children.length" />
			<!-- <i-menu-side-item :menu="item.children[0]" :key="index" v-else /> -->
            <i-menu-side-submenu v-else :menu="item" :key="index" />
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
      const menu = JSON.parse(JSON.stringify(this.menu));
      if (menu.children && menu.children.length) {
        menu.children.forEach((item, index) => {
          if (item.children && item.children.length) {
            item.path = item.children[0].path;
            delete item.children;
          }
        });
      }
      return menu;
    }
  }
};
</script>
