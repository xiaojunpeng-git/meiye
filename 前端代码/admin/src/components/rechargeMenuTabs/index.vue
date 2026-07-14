<template>
  <div class="recharge-tabs">
    <span
      v-for="item in tabs"
      :key="item.path"
      :class="['recharge-tabs__item', { 'recharge-tabs__item--active': isActive(item.path) }]"
      @click="tabClick(item.path)"
    >{{ item.title }}</span>
  </div>
</template>

<script>
import Setting from '@/setting';

const RECHARGE_TABS = [
  { title: '储值设置', path: `${Setting.roterPre}/marketing/setup_recharge` },
  { title: '储值金额', path: `${Setting.roterPre}/marketing/balance_recharge` }
];

export default {
  name: 'RechargeMenuTabs',
  data() {
    return {
      tabs: RECHARGE_TABS
    };
  },
  methods: {
    normalizePath(path) {
      return (path || '').replace(/\/$/, '');
    },
    isActive(path) {
      return this.normalizePath(path) === this.normalizePath(this.$route.path);
    },
    tabClick(path) {
      if (!this.isActive(path)) {
        this.$router.push(path);
      }
    }
  }
};
</script>

<style scoped lang="stylus">
.recharge-tabs
  display inline-flex
  align-items center
  gap 32px
  line-height 1
.recharge-tabs__item
  position relative
  font-size 16px
  font-weight 500
  color #515a6e
  cursor pointer
  padding-bottom 2px
  user-select none
  &:hover
    color #2d8cf0
.recharge-tabs__item--active
  color #2d8cf0
  &:after
    content ''
    position absolute
    left 0
    right 0
    bottom -12px
    height 2px
    background-color #2d8cf0
</style>
