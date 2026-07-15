<template>
  <Modal
    v-model="visible"
    title="选择商品框"
    footer-hide
    class="paymentFooter"
    scrollable
    width="900"
    @on-cancel="close"
  >
    <goods-attr
      v-if="visible"
      :chooseType="chooseType"
      :ischeckbox="ischeckbox"
      @getProductId="onSelect"
    ></goods-attr>
  </Modal>
</template>

<script>
import goodsAttr from '@/components/goodsAttr';

/**
 * 统一库存业务「选择商品框」
 * 仅展示 is_inventory=1 的商品（由 chooseType=94 后端过滤）
 */
export default {
  name: 'selectGoodsBox',
  components: { goodsAttr },
  props: {
    value: {
      type: Boolean,
      default: false,
    },
    ischeckbox: {
      type: Boolean,
      default: true,
    },
    // 选择场景：94=库存商品(默认)、95=院装耗材、96=院装项目
    chooseType: {
      type: Number,
      default: 94,
    },
  },
  computed: {
    visible: {
      get() {
        return this.value;
      },
      set(val) {
        this.$emit('input', val);
      },
    },
  },
  methods: {
    onSelect(list) {
      this.$emit('getProductId', list);
      this.visible = false;
    },
    close() {
      this.visible = false;
      this.$emit('on-cancel');
    },
  },
};
</script>
