<script>
export default {
  props: {
    merMenuData: {
      type: Object,
      default() {
        return () => {
          return {};
        };
      },
    },
  },

  methods: {},
};
</script>
<template>
  <div>
    <div class="pt-17 pr-15 pb-18 pl-16 bg--w111-fff rd-16px  service">
      <div class="fs-15 fw-500 lh-21px text--w111-333 pb-14">{{merMenuData.title}}</div>
      <div class="grid-column-4 grid-gap-x-19px grid-gap-y-27px mt-19">
        <div
          class="w-65 flex-col flex-center"
          v-for="(item, index) in merMenuData.list"
          :key="index"
        >
          <img :src="item.pic" class="image"></img>
          <span class="fs-13 lh-18px text--w111-282828 pt-11">{{
            item.name
          }}</span>
        </div>
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
  margin: 10px;

  .image {
    width: 24px;
    height: 24px;
  }
}

.order-wrapper {
  .image {
    width: 24px;
    height: 24px;
  }
}
</style>