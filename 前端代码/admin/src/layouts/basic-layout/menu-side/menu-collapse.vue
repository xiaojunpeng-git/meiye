<template>
    <Dropdown placement="right-start" transfer :class="dropdownClasses">
        <li class="menuItem" :class="menuItemClasses" v-if="topLevel">
			<!-- 二级分类 -->
			<i-menu-side-item :menu="menuData" hide-title v-if="menuData.children === undefined || !menuData.children.length" />
            <i-menu-side-title :menu="menuData" hide-title v-else/>
        </li>
        <DropdownItem v-else>
            <i-menu-side-title :menu="menuData" :selected="openNames.indexOf(menuData.path) >= 0" hide-icon />
            <!-- <Icon type="ios-arrow-forward" class="i-layout-menu-side-arrow" /> -->
        </DropdownItem>
        <DropdownMenu slot="list">
			<!-- 折起时显示上级大标题 -->
            <!-- <div class="i-layout-menu-side-collapse-title" v-if="showCollapseMenuTitle">
                <i-menu-side-title :menu="menuData" />
            </div> -->
			<div class="menuTitle">{{menuData.title}}</div>
            <template v-for="(item, index) in menuData.children">
                <i-link :to="item.path" :target="item.target" v-if="item.children === undefined || !item.children.length" :key="index">
                    <DropdownItem :divided="item.divided" :class="{ 'i-layout-menu-side-collapse-item-selected': item.path === activePath }">
                        <!-- 折叠侧栏的下拉入口与展开状态保持同一三级菜单口径。 -->
                        <i-menu-side-title :menu="item" hide-icon />
                    </DropdownItem>
                </i-link>
                <i-menu-side-collapse v-else :menu="item" :key="index" />
            </template>
        </DropdownMenu>
    </Dropdown>
</template>
<script>
import iMenuSideTitle from './menu-title';
import iMenuSideItem from './menu-item';

import { mapState } from 'vuex';

export default {
  name: 'iMenuSideCollapse',
  components: { iMenuSideTitle, iMenuSideItem },
  props: {
    menu: {
      type: Object,
      default() {
        return {};
      }
    },
    // 是否是第一级，区分在于左侧和展开侧
    topLevel: {
      type: Boolean,
      default: false
    }
  },
  computed: {
    ...mapState('admin/layout', [
      'siderTheme',
      'showCollapseMenuTitle',
      'menuCollapse'
    ]),
    ...mapState('admin/menu', [
      'activePath',
      'openNames'
    ]),
    dropdownClasses() {
      return {
        'i-layout-menu-side-collapse-top': this.topLevel,
        'i-layout-menu-side-collapse-dark': this.siderTheme === 'dark'
      };
    },
    menuItemClasses() {
      return [
        'ivu-menu-item i-layout-menu-side-collapse-top-item',
        {
          'ivu-menu-item-selected ivu-menu-item-active': (this.openNames.indexOf(this.menu.path) >= 0 && !this.menuCollapse) || (this.menuCollapse && this.menuData.children && this.menuData.children.length && this.openNames.indexOf(this.menu.path) >= 0)// -active 在高亮时，有背景
        }
      ];
    },
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
<style scoped lang="stylus">
	/deep/.ivu-dropdown-item{
		color: #606266;
	}
	/deep/.i-layout-menu-side-collapse-item-selected,
	/deep/.i-layout-menu-side-collapse-item-selected:hover{
		background-color: #fff;
		color: #2d8cf0;
	}
	/deep/.ivu-dropdown-item:hover{
		background:#fff;
		color: #2d8cf0;
	}
	.menuTitle{
		padding: 7px 16px;
		color: #303133;
	}
	/deep/.ivu-menu-item .i-layout-menu-side-title{
		width: 40px;
		height: 40px;
		border-radius: 4px;
		text-align: center;
		line-height: 40px;
	}
	/deep/.i-layout-menu-side-title-icon{
		margin-right: 0;
	}
	/deep/.menuItem{
		padding: 0 10px !important;
		&:hover{
			.i-layout-menu-side-title{
				background-color: #F1F9FF;
			}
		}
	}
	/deep/.menuItem.ivu-menu-item-active:not(.ivu-menu-submenu) .i-layout-menu-side-title{
		background-color: #F1F9FF;
	}

	/deep/.menuItem .ivu-menu-item{
		// width: 40px;
		// height: 40px !important;
		display: block;
		padding: 0 !important;
		// line-height: 40px;
		// text-align: center;
		margin: 0 !important;
	}
	/deep/.menuItem .ivu-menu-item-active:not(.ivu-menu-submenu) {
		background: #F1F9FF !important;
	}
</style>
