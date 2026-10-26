<template>
    <span class="i-layout-menu-side-title" @click="menuClick">
        <span class="i-layout-menu-side-title-icon" :class="{ 'i-layout-menu-side-title-icon-single': hideTitle }" v-if="!hideIcon && (menu.icon || menu.custom)">
            <Icon :type="menu.icon" v-if="menu.icon" />
            <Icon :custom="menu.custom" v-else-if="menu.custom" />
        </span>
       <!-- <span class="i-layout-menu-side-title-icon-null" :class="{ 'i-layout-menu-side-title-icon-single': hideTitle }" v-else >
            <Icon :custom="null" />
        </span> -->
        <span class="i-layout-menu-side-title-text" :class="{ 'i-layout-menu-side-title-text-selected': selected }" v-if="!hideTitle">{{ tTitle(menu.title) }}</span>
    </span>
</template>
<script>
import tTitle from '../mixins/translate-title';

export default {
  name: 'iMenuSideTitle',
  mixins: [tTitle],
  props: {
    menu: {
      type: Object,
      default() {
        return {};
      }
    },
    hideTitle: {
      type: Boolean,
      default: false
    },
    // 三级菜单只保留文字，图标字段仍保留在权限菜单模型中，避免影响
    // 顶栏、二级菜单以及其他依赖同一菜单数据的入口。
    hideIcon: {
      type: Boolean,
      default: false
    },
    // 用于侧边栏收起 Dropdown 当前高亮
    selected: {
      type: Boolean,
      default: false
    }
  },
  methods: {
    menuClick() {
      if (this.hideTitle) {
        if (this.menu.children === undefined || !this.menu.children.length) {
          this.$router.push(this.menu.path);
        } else {
          this.$router.push(this.menu.children[0].path);
        }
      }
    }
  }
};
</script>
