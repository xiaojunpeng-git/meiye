<template>
  <span class="i-layout-header-trigger i-layout-header-trigger-min sidebar-user-trigger">
    <Dropdown
      trigger="click"
      placement="right-start"
      class="i-layout-header-user"
      :class="{ 'i-layout-header-user-mobile': isMobile }"
      @on-click="handleClick"
    >
      <div class="sidebar-user-entry" :title="accountFull || accountLabel">
        <img
          v-if="showRemoteAvatar"
          class="sidebar-user-avatar-img"
          :src="remoteAvatar"
          alt=""
          @error="onAvatarError"
        />
        <Avatar v-else class="sidebar-user-avatar" :src="headPic" />
        <span class="i-layout-header-user-name">{{ accountLabel }}</span>
      </div>
      <DropdownMenu slot="list">
        <DropdownItem disabled v-if="accountFull && accountFull !== '登录'">
          <Icon type="ios-person-outline" />
          <span>{{ accountFull }}</span>
        </DropdownItem>
        <i-link :to="`${roterPre}/system/user`">
          <DropdownItem>
            <Icon type="ios-contact-outline" />
            <span>{{ $t('basicLayout.user.center') }}</span>
          </DropdownItem>
        </i-link>
        <DropdownItem divided name="logout">
          <Icon type="ios-log-out" />
          <span>{{ $t('basicLayout.user.logOut') }}</span>
        </DropdownItem>
      </DropdownMenu>
    </Dropdown>
  </span>
</template>
<script>
import Utils from '@/utils/index';
import { mapState, mapActions } from 'vuex';
import '@/assets/js/core.js';
import Setting from '@/setting';

export default {
  name: 'iHeaderUser',
  data() {
    return {
      roterPre: Setting.roterPre,
      headPic: require('@/assets/images/yonghu.png'),
      infor: null,
      avatarBroken: false,
    };
  },
  computed: {
    ...mapState('store/user', ['info']),
    ...mapState('store/layout', ['isMobile', 'logoutConfirm']),
    accountFull() {
      return (this.infor && this.infor.account) || '';
    },
    accountLabel() {
      return this.accountFull || '登录';
    },
    remoteAvatar() {
      const src = this.infor && this.infor.avatar;
      if (!src || src === 'null' || src === 'undefined') return '';
      return String(src);
    },
    showRemoteAvatar() {
      return !this.avatarBroken && !!this.remoteAvatar;
    },
  },
  methods: {
    ...mapActions('store/account', ['logout']),
    onAvatarError() {
      this.avatarBroken = true;
    },
    handleClick(name) {
      if (name === 'logout') {
        try {
          window.Jsbridge.invoke('collectLogout', JSON.stringify({ 'p1-key': 'p1-value' }));
        } catch (e) {}
        this.logout({
          confirm: this.logoutConfirm,
          vm: this,
        });
      }
    },
    storeTap() {
      Utils.$emit('demo', 'msg');
    },
  },
  async mounted() {
    let storage = window.localStorage;
    let value = storage.getItem('cashier_user_info');
    try {
      this.infor = value ? JSON.parse(value) : null;
    } catch (e) {
      this.infor = null;
    }
    this.avatarBroken = false;
  },
};
</script>
<style scoped lang="stylus">
.sidebar-user-trigger {
  display: block;
  width: 100%;
  padding: 0 !important;
  line-height: normal !important;
}

.sidebar-user-entry {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 100%;
  max-width: 120px;
  margin: 0 auto;
  padding: 4px 6px;
  cursor: pointer;
}

.sidebar-user-avatar {
  flex-shrink: 0;
  background: rgba(255, 255, 255, 0.18) !important;
}

.sidebar-user-avatar-img {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  object-fit: cover;
  background: rgba(255, 255, 255, 0.18);
  flex-shrink: 0;
}

.i-layout-header-user {
  display: block;
  width: 100%;
}

.i-layout-header-user-name {
  display: block;
  width: 100%;
  max-width: 112px;
  margin: 6px 0 0;
  padding: 0 2px;
  color: rgba(255, 255, 255, 0.95);
  font-size: 12px;
  line-height: 1.2;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  vertical-align: middle;
}
</style>
