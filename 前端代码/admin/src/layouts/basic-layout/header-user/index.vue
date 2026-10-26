<template>
  <span class="i-layout-header-trigger i-layout-header-trigger-min">
    <Dropdown
      :trigger="isMobile ? 'click' : 'hover'"
      class="i-layout-header-user"
      :class="{ 'i-layout-header-user-mobile': isMobile }"
      @on-click="handleClick"
    >
      <Avatar size="small" :src="infor.head_pic" v-if="infor.head_pic" />
      <Avatar size="small" :src="headPic" v-else />
      <span class="i-layout-header-user-name" v-if="!isMobile">{{
        infor.account
      }}</span>
      <DropdownMenu slot="list">
        <span @click="handleClickCenter">
          <DropdownItem>
            <Icon type="ios-contact-outline" />
            <span>{{ $t("basicLayout.user.center") }}</span>
          </DropdownItem>
        </span>
        <!--<i-link to="/setting/account">-->
        <!--<DropdownItem>-->
        <!--<Icon type="ios-settings-outline" />-->
        <!--<span>{{ $t('basicLayout.user.setting') }}</span>-->
        <!--</DropdownItem>-->
        <!--</i-link>-->
        <DropdownItem divided name="logout">
          <Icon type="ios-log-out" />
          <span>{{ $t("basicLayout.user.logOut") }}</span>
        </DropdownItem>
      </DropdownMenu>
    </Dropdown>
  </span>
</template>
<script>
import { mapState, mapActions } from 'vuex';
import Setting from '@/setting';
export default {
  name: 'iHeaderUser',
  data() {
    return {
      roterPre: '',
      headPic: require('@/assets/images/f.png'),
      infor: ''
    };
  },
  computed: {
    ...mapState('admin/user', ['info']),
    ...mapState('admin/layout', ['isMobile', 'logoutConfirm'])
  },
  methods: {
    ...mapActions('admin/account', ['logout']),
    handleClick(name) {
      if (name === 'logout') {
        this.logout({
          confirm: this.logoutConfirm,
          vm: this
        });
      }
    },
    handleClickCenter() {
      this.$router.push({
        path: `${this.roterPre}/system/user`
      });
    }
  },
  async mounted() {
    const db = await this.$store.dispatch('admin/db/database', {
      user: true,
	  isAgent: this.__isAgentPath()
    });
    this.infor = this.__isAgentPath()
      ? db.get('agent_user_info').value()
      : db.get('user_info').value();
    this.roterPre = this.__isAgentPath()
      ? Setting.routePreAgent
      : Setting.roterPre;
  }
};
</script>
