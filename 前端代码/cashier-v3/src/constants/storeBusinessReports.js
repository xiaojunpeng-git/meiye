import { PHASE_FOUR_CROSS_END_REPORT_TABS } from './phaseFourReports'
import { PHASE_SIX_CROSS_END_REPORT_TABS } from './phaseSixReports'

/** 门店端已确认报表导航唯一清单；页面页签和顶部菜单共用，隐藏下钻页不成为导航。 */
export const REPORT_TABS = Object.freeze([
  { code: 'partner_item_summary', name: '合作方品项汇总' },
  { code: 'partner_item_detail', name: '合作方品项明细' },
  { code: 'member_consumption_detail', name: '会员消费明细' },
  { code: 'store_item_analysis', name: '门店品项分析' },
  { code: 'store_craftsman_consumption', name: '门店手艺人消耗' },
  // 仅由汇总单元格的服务端下钻声明进入，不能作为独立导航页签。
  { code: 'store_craftsman_consumption_detail', name: '手艺人消耗明细', hidden: true },
  { code: 'store_salesperson_performance', name: '门店销售人业绩' },
  // 汇总单元格的后端下钻目标；沿用同一报表壳，不增加独立导航入口。
  { code: 'store_salesperson_performance_detail', name: '销售人业绩明细', hidden: true },
  { code: 'market_performance', name: '市场业绩表' },
  { code: 'market_detail', name: '市场明细表' },
  { code: 'member_visit_analysis', name: '会员进店分析表' },
  { code: 'member_visit_annual_summary', name: '会员进店年度汇总表' },
  { code: 'field_acquisition_detail', name: '地推拓客明细表' },
  { code: 'field_acquisition_summary', name: '地推拓客汇总表' },
  // 年度会员汇总与逐笔收款明细按真实内容命名；保留原路由、顺序和下钻目标。
  { code: 'cross_industry_customer_summary', name: '异业收客汇总表' },
  { code: 'cross_industry_customer_detail', name: '异业收客明细表' },
  { code: 'new_customer_analysis_summary', name: '新客汇总表' },
  { code: 'new_customer_analysis', name: '新客明细表' },
  { code: 'salesperson_large_order_statistics', name: '销售人生美大单统计表' },
  { code: 'store_refund_ledger', name: '院店退款台账' },
  { code: 'phase_six_other_multi_payment', name: '其他多收款业绩表' },
  { code: 'phase_six_salary_summary', name: '员工薪资汇总月报表' },
  { code: 'phase_six_salary_detail', name: '员工薪资明细月报表' },
  // The confirmed cross-end report is the only fourth-stage report exposed
  // through the store Data menu; the remaining 26 stay platform-only.
  { code: 'six_dimension_analysis', name: '六维数据分析表' }
])

export const STORE_REPORT_TABS = Object.freeze([
  ...REPORT_TABS,
  ...PHASE_FOUR_CROSS_END_REPORT_TABS,
  ...PHASE_SIX_CROSS_END_REPORT_TABS
].filter((tab, index, all) => all.findIndex((item) => item.code === tab.code) === index))
