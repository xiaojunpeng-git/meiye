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
            <div><strong>{{ item.title }}</strong><p>{{ item.description }}</p></div>
            <Icon type="ios-arrow-forward" class="report-arrow" />
          </div>
        </Col>
    </Row>
    </Card>
  </div>
</template>

<script>
import { STORE_OPERATION_REPORTS } from '@/libs/storeOperationReports'

export default {
  name: 'BusinessReportHub',
  data () {
    return {
      reports: STORE_OPERATION_REPORTS
    }
  },
  methods: {
    openReport (item) {
      this.$router.push({ name: 'report_store_operations_report', params: { report: item.code } })
    }
  }
}
</script>

<style lang="less" scoped>
.title{margin:0;font-size:20px}.desc{margin:8px 0 0;color:#999}.group-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;font-size:16px}.report-card{position:relative;display:flex;align-items:center;min-height:92px;padding:16px 38px 16px 16px;border:1px solid #edf0f5;border-radius:4px;cursor:pointer;transition:.2s}.report-card:hover{border-color:#2d8cf0;box-shadow:0 3px 10px rgba(45,140,240,.12)}.report-icon{margin-right:12px;color:#2d8cf0;font-size:26px}.report-card strong{font-size:15px;color:#333}.report-card p{margin:6px 0 0;color:#999;font-size:12px;line-height:18px}.report-arrow{position:absolute;right:14px;color:#b8c1cc;font-size:18px}
</style>
