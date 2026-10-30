<script>
import { visibleMemberServices } from '../../../../../../../shared/memberMenuMedia';
import MemberMenuMedia from "@/components/memberMenuMedia";
export default {
  components: { MemberMenuMedia },
  props: {
    MyMenus: {
      type: Array,
      default() {
        return [];
      },
    },
  },
  // 只过滤展示副本，保留完整配置及原有客服与跳转分支。
  computed: { visibleServices() { return visibleMemberServices(this.MyMenus); } },
  methods: {
    intoPage(url) {
      this.$emit("intoPage", url);
    },
    goMenuPage(url, name) {
      this.$emit("goMenuPage", url, name);
    },
    tapQrCode() {
      this.$emit("tapQrCode");
    },
  },
};
</script>
<template>
  <div>
    <div class="acea-row service">
      <div
        class="acea-row row-column row-middle row-center item"
        v-for="item in visibleServices"
        :key="item.id"
      >
        <MemberMenuMedia :item="item" :size="32" class="image" />
        <div>{{ item.name }}</div>
      </div>
    </div>
  </div>
</template>

<style lang="stylus" scoped>
.border_top {
  border-top: 1px solid rgba(0, 0, 0, 0.08);
}

.SemiBold {
  font-family: SemiBold;
}

.service {
  padding-left: 10px;
  margin: 0px -12px 0px 0;

  .item {
    flex: 0 0 110px;
    height: 110px;
    border-radius: 12px;
    background-color: #FFFFFF;
    margin: 0 10px 10px 0;
    font-size: 13px;
    line-height: 18px;
    color: #333333;
  }

  .image {
    width: 32px;
    height: 32px;
    margin-bottom: 16px;
  }
}
</style>