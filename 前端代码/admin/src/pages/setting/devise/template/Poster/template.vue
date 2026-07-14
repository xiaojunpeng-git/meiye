<script>
export default {
  props: {},
  computed: {
    posterData(val) {
      return this.$store.state.admin.userTemplateConfig.poster;
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
  <div class="main">
    <div class="shortcut">
      <img :src="posterData.list[0].pic" class="image" v-if="posterData.list.length"></img>
    </div>
    <div class="mask" v-if="!parseInt(posterData.is_show)">已隐藏</div>
  </div>
</template>

<style lang="stylus" scoped>
.shortcut {
  border-radius: 10px;
  margin: 10px;
  background-color: #FFFFFF;
  min-height: 94px;

  .image {
    width: 100%;
    height: 94px;
  }
}
</style>