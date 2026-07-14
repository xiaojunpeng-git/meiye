<script>
export default {
  props: {
    MyMenus: {
      type: Array,
      default() {
        return [];
      },
    },
  },
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
    <div class="service">
      <div
        class="acea-row row-middle item"
        v-for="item in MyMenus"
        :key="item.id"
        @click="goMenuPage(item.url, item.name)"
      >
        <img :src="item.pic" class="image"></img>
        <div class="name">{{ item.name }}</div>
        <span class="mobiconfont icon-ic_rightarrow"></span>
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
  padding: 10px 0;
  border-radius: 8px;
  margin: 10px;
  background-color: #FFFFFF;

  .item {
    padding: 14px 10px 14px 16px;
  }

  .image {
    width: 20px;
    height: 20px;
    margin-right: 12px;
  }

  .name {
    flex: 1;
    font-size: 14px;
    color: #333333;
  }

  .mobiconfont {
    font-size: 14px;
    color: #999999;
  }
}
</style>