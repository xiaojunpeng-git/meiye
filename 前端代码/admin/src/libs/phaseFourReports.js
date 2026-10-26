// 第四阶段报表的稳定注册表。菜单权限、事实口径和列定义均由后端统一服务控制，
// 此处只供平台路由与 iframe 标题使用。
const PHASE_FOUR_OPERATION_REPORTS = Object.freeze([
  { code: 'operations_pre_sale_bdegh', title: '售前报表（BDEGH）' },
  { code: 'operations_customer_status_bdegh', title: '顾客状态表（BDEGH）' },
  { code: 'operations_referral_beautician_pre_sale', title: '售前报表（老带新+美容师卖卡）' },
  { code: 'operations_performance_comparison', title: '业绩对比报表' },
  { code: 'operations_health_data', title: '运营健康数据报表' },
  { code: 'operations_beauty_item', title: '运营生美品项表' },
  { code: 'operations_annual_member_consumption', title: '年会员消费统计（福建）' },
  { code: 'marketing_acquisition_pre_sale', title: '品牌营销-售前报表（营销拓客）' },
  { code: 'marketing_referral_pre_sale', title: '品牌营销-售前报表（老带新）' },
  { code: 'marketing_post_sale_performance', title: '品牌营销-售后业绩报表' },
  { code: 'marketing_health_data', title: '品牌营销-健康数据报表' },
  { code: 'marketing_beauty_new_item', title: '品牌营销-生美新品/主推品项报表' },
  { code: 'marketing_customer_status', title: '品牌营销-顾客状态表' },
  { code: 'product_monetization_performance_total', title: '产品变现-业绩总表' },
  { code: 'product_monetization_beauty_performance_total', title: '产品变现-生美业绩总表' },
  { code: 'product_monetization_beauty_item', title: '产品变现-生美品项表' },
  { code: 'product_monetization_beauty_market_distribution', title: '产品变现-生美业绩市场分布表' },
  { code: 'product_monetization_six_dimension_performance', title: '产品变现-六维业绩' },
  { code: 'product_monetization_six_dimension_item_performance', title: '产品变现-六维品项业绩' },
  { code: 'product_monetization_six_dimension_efficiency', title: '产品变现-六维业绩成交效率' },
  { code: 'product_monetization_six_dimension_market_distribution', title: '产品变现-六维业绩市场分布' },
  { code: 'product_monetization_haomei_performance', title: '产品变现-昊美业绩' },
  { code: 'product_monetization_private_performance', title: '产品变现-私密业绩' },
  { code: 'product_monetization_private_item_performance', title: '产品变现-私密品项业绩' },
  { code: 'product_monetization_private_efficiency', title: '产品变现-私密业绩成交效率' },
  { code: 'product_monetization_private_market_distribution', title: '产品变现-私密业绩市场分布' }
]);

const PHASE_FOUR_CROSS_END_REPORTS = Object.freeze([
  { code: 'six_dimension_analysis', title: '六维数据分析表' }
]);

const PHASE_FOUR_REPORTS = Object.freeze([
  ...PHASE_FOUR_OPERATION_REPORTS,
  ...PHASE_FOUR_CROSS_END_REPORTS
]);
const PHASE_FOUR_REPORT_CODES = Object.freeze(PHASE_FOUR_REPORTS.map(report => report.code));
const PHASE_FOUR_OPERATION_REPORT_CODES = Object.freeze(PHASE_FOUR_OPERATION_REPORTS.map(report => report.code));

export {
  PHASE_FOUR_REPORTS,
  PHASE_FOUR_REPORT_CODES,
  PHASE_FOUR_OPERATION_REPORTS,
  PHASE_FOUR_OPERATION_REPORT_CODES,
  PHASE_FOUR_CROSS_END_REPORTS
};
