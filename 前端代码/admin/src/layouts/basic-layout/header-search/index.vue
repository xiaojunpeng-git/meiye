<template>
  <!--<span-->
  <!--v-if="isDesktop"-->
  <!--class="i-layout-header-trigger i-layout-header-trigger-min i-layout-header-trigger-in i-layout-header-trigger-nohover"-->
  <!--&gt;-->
  <!--&lt;!&ndash;<input class="i-layout-header-search" type="text" :placeholder="$t('basicLayout.search.placeholder')">&ndash;&gt;-->
  <!--<Select-->
  <!--v-model="currentVal"-->
  <!--placeholder="搜索..."-->
  <!--filterable-->
  <!--remote-->
  <!--style="width:150px"-->
  <!--:remote-method="remoteMethod"-->
  <!--:loading="loading"-->
  <!--&gt;-->
  <!--<Option-->
  <!--v-for="(option, index) in menusList"-->
  <!--:value="option.menu_path"-->
  <!--:key="index"-->
  <!--:disabled="option.type === 1"-->
  <!--&gt;{{ option.menu_name }}</Option-->
  <!--&gt;-->
  <!--</Select>-->
  <!--</span>-->
  <span class="i-layout-header-trigger i-layout-header-trigger-min">
    <Dropdown
      trigger="custom"
      class="i-layout-header-search-drop"
      ref="dropdown"
      :visible="visible"
    >
      <Icon type="ios-search" @click="visible = true" />
      <DropdownMenu slot="list">
        <div class="i-layout-header-search-drop-main">
          <!--<Input size="large" prefix="ios-search" type="text" :placeholder="$t('basicLayout.search.placeholder')" />-->
          <Select
            v-model="currentVal"
            placeholder="搜索..."
            filterable
            size="large"
            remote
            :remote-method="remoteMethod"
            :loading="loading"
          >
            <Option
              v-for="(option, index) in menusList"
              :value="option.menu_path"
              :key="index"
              :disabled="option.type === 1"
              >{{ option.menu_name }}</Option
            >
          </Select>
          <span
            class="i-layout-header-search-drop-main-cancel"
            @click="handleCloseSearch"
            >{{ $t("basicLayout.search.cancel") }}</span
          >
        </div>
      </DropdownMenu>
    </Dropdown>
  </span>
</template>
<style lang="less" scoped>
/deep/.ivu-select-dropdown-list {
  max-height: 200px !important;
  overflow-y: auto !important;
}
/deep/.i-layout-header-search-drop .ivu-select-dropdown {
  left: unset !important;
  right: 247px;
  width: 300px;
}
/deep/.ivu-select .ivu-select-dropdown {
  right: 0;
}
</style>
<style>
.i-layout-header-trigger .ivu-select-selection {
  border: 0 !important;
}
.i-layout-header-trigger .ivu-select-visible .ivu-select-selection {
  box-shadow: unset !important;
}
.ivu-select-input {
  font-size: 13px !important;
}
.i-layout-header-trigger li.ivu-select-item {
  text-align: left;
}
</style>
<script>
import { mapState } from 'vuex';
import { menusListApi } from '@/api/account';
import Setting from '@/setting';
export default {
  name: 'iHeaderSearch',
  data() {
    return {
      roterPre: Setting.roterPre,
      currentVal: '',
      loading: false,
      menusList: [],
      visible: false
    };
  },
  computed: {
    ...mapState('admin/layout', ['isDesktop', 'headerMenu'])
  },
  watch: {
    '$route'() {
      this.visible = false;
      this.currentVal = '';
    }
  },
  created() {
    this.getMenusList();
  },
  methods: {
    handleCloseSearch() {
      this.visible = false;
      // this.$refs.dropdown.handleClick();
    },
    getMenusList() {
      this.loading = true;
      menusListApi().then((res) => {
        // for (let i = 0; i < res.data.length; i++) {
        //   res.data[i].menu_path = res.data[i].menu_path.replace('/admin', '')
        // }
        this.loading = false;
        this.menusList = res.data;
      });
    },
    remoteMethod() {
      this.roterPre = this.__isAgentPath()
        ? Setting.routePreAgent
        : Setting.roterPre;
      this.$router.push({ path: this.roterPre + this.currentVal });
    }
  }
};
</script>
