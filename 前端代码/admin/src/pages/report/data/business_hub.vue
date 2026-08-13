<template>
  <div class="store-operation-hub">
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <h2 class="title">门店运营</h2>
      <p class="desc">选择报表进入查询；选择组织时结果始终按门店维度展示，数据范围由平台权限服务端裁剪。</p>
    </Card>
    <Card :bordered="false" dis-hover class="ivu-mt mt15">
      <div class="group-title"><strong>门店运营报表</strong><Tag color="green">平台 + 门店</Tag></div>
      <Row :gutter="16">
        <Col v-for="item in reports" :key="item.code" :xs="24" :sm="12" :lg="8" class="ivu-mb">
          <div class="report-card" @click="openReport(item)">
            <Icon type="ios-stats" class="report-icon" />
            <div><strong>{{ item.name }}</strong><p>{{ item.description }}</p></div>
            <Icon type="ios-arrow-forward" class="report-arrow" />
          </div>
        </Col>
    </Row>
    </Card>
  </div>
</template>

<script>
export default {
  name: 'BusinessReportHub',
  data () {
    return {
      reports: [
        { code: 'partner_item_summary', name: '合作方品项汇总', description: '合作方品项的体验、成交、消耗与手工汇总' },
        { code: 'partner_item_detail', name: '合作方品项明细', description: '合作方成交、消耗与人工补充明细' },
        { code: 'member_consumption_detail', name: '会员消费明细', description: '会员消费、收款、退款与人员归属明细' },
        { code: 'store_item_analysis', name: '门店品项分析', description: '按商品分类、商品类型与品项分析经营数据' },
        { code: 'store_craftsman_consumption', name: '门店手艺人消耗', description: '按手艺人和日期分析消耗与手工费' },
        { code: 'store_salesperson_performance', name: '门店销售人业绩', description: '按销售人分析订单、顾客、现金与实际业绩' },
        { code: 'market_performance', name: '市场业绩', description: '按来源和门店展示市场到店、成交与业绩' }
      ]
    }
  },
  methods: {
    openReport (item) {
      this.$router.push({ name: 'report_store_operations', query: { report: item.code } })
    }
  }
}
</script>

<style lang="less" scoped>
.title{margin:0;font-size:20px}.desc{margin:8px 0 0;color:#999}.group-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;font-size:16px}.report-card{position:relative;display:flex;align-items:center;min-height:92px;padding:16px 38px 16px 16px;border:1px solid #edf0f5;border-radius:4px;cursor:pointer;transition:.2s}.report-card:hover{border-color:#2d8cf0;box-shadow:0 3px 10px rgba(45,140,240,.12)}.report-icon{margin-right:12px;color:#2d8cf0;font-size:26px}.report-card strong{font-size:15px;color:#333}.report-card p{margin:6px 0 0;color:#999;font-size:12px;line-height:18px}.report-arrow{position:absolute;right:14px;color:#b8c1cc;font-size:18px}
</style>
