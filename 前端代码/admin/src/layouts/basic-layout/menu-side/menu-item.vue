<template>
    <div class="menu-item">
        <MenuItem :to="menu.path" :replace="menu.replace" :target="menu.target" :name="menu.path">
            <i-menu-side-title :menu="menu" :hide-title="hideTitle" :hide-icon="hideIcon"/>
        </MenuItem>
    </div>
</template>
<script>
import iMenuSideTitle from './menu-title';

export default {
  name: 'iMenuSideItem',
  components: { iMenuSideTitle },
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
    hideIcon: {
      type: Boolean,
      default: false
    }
  }
};
</script>
