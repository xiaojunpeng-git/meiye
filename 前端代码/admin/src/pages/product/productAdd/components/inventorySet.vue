<template>
  <div>
    <FormItem label="参与库存管理：">
      <i-switch
        v-model="baseInfo.is_inventory"
        :true-value="1"
        :false-value="0"
        size="large"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
      <div class="tips">
        开启后，该商品会进入入库、出库、盘点、调拨、院装及销售库存判断；关闭仅影响后续业务，不会清除已有库存。
      </div>
    </FormItem>
    <FormItem label="允许负库存：" v-if="baseInfo.is_inventory == 1">
      <i-switch
        v-model="baseInfo.allow_negative_stock"
        :true-value="1"
        :false-value="0"
        size="large"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
      <div class="tips">
        开启后库存不足仍允许完成出库，库存可能显示为负数；关闭后库存不足将阻止支付或出库。普通下单不改变库存；已通过支付前检查的订单，支付成功时仍直接扣减，极少数并发情况下可能形成负库存。残次品库存永远不允许为负数。
      </div>
    </FormItem>
    <FormItem label="可作为院装耗材：" v-if="baseInfo.is_inventory == 1">
      <i-switch
        v-model="baseInfo.salon_stock_enabled"
        :true-value="1"
        :false-value="0"
        size="large"
      >
        <span slot="open">开启</span>
        <span slot="close">关闭</span>
      </i-switch>
      <div class="tips">
        开启后，该商品（如面膜、精油）可被项目配方选用，核销项目时按配方自动扣减、撤销核销时按原量退回；关闭后不能被新配方选择，历史耗材流水仍保留。仅参与库存管理的商品可开启。开启后可按片、ml、g 等用量管理，数量保留 2 位小数。
      </div>
    </FormItem>
  </div>
</template>

<script>
export default {
  name: 'inventorySet',
  props: {
    baseInfo: {
      type: Object,
      default: () => ({}),
    },
  },
};
</script>

<style scoped>
.tips {
  margin-top: 6px;
  font-size: 12px;
  line-height: 18px;
  color: #999;
}
</style>
