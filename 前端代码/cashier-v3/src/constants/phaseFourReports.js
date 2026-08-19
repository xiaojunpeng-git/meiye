// 第四阶段前端运行时目录。仅 six_dimension_analysis 可出现在门店端；
// 其余代码只在平台 iframe 路由中由权限菜单进入。
export const PHASE_FOUR_OPERATION_REPORT_TABS = Object.freeze([
  { code: 'operations_pre_sale_bdegh', name: '售前报表（BDEGH）' },
  { code: 'operations_customer_status_bdegh', name: '顾客状态表（BDEGH）' },
  { code: 'operations_referral_beautician_pre_sale', name: '售前报表（老带新+美容师卖卡）' },
  { code: 'operations_performance_comparison', name: '业绩对比报表' },
  { code: 'operations_health_data', name: '运营健康数据报表' },
  { code: 'operations_beauty_item', name: '运营生美品项表' },
  { code: 'operations_annual_member_consumption', name: '年会员消费统计（福建）' },
  { code: 'marketing_acquisition_pre_sale', name: '品牌营销-售前报表（营销拓客）' },
  { code: 'marketing_referral_pre_sale', name: '品牌营销-售前报表（老带新）' },
  { code: 'marketing_post_sale_performance', name: '品牌营销-售后业绩报表' },
  { code: 'marketing_health_data', name: '品牌营销-健康数据报表' },
  { code: 'marketing_beauty_new_item', name: '品牌营销-生美新品/主推品项报表' },
  { code: 'marketing_customer_status', name: '品牌营销-顾客状态表' },
  { code: 'product_monetization_performance_total', name: '产品变现-业绩总表' },
  { code: 'product_monetization_beauty_performance_total', name: '产品变现-生美业绩总表' },
  { code: 'product_monetization_beauty_item', name: '产品变现-生美品项表' },
  { code: 'product_monetization_beauty_market_distribution', name: '产品变现-生美业绩市场分布表' },
  { code: 'product_monetization_six_dimension_performance', name: '产品变现-六维业绩' },
  { code: 'product_monetization_six_dimension_item_performance', name: '产品变现-六维品项业绩' },
  { code: 'product_monetization_six_dimension_efficiency', name: '产品变现-六维业绩成交效率' },
  { code: 'product_monetization_six_dimension_market_distribution', name: '产品变现-六维业绩市场分布' },
  { code: 'product_monetization_haomei_performance', name: '产品变现-昊美业绩' },
  { code: 'product_monetization_private_performance', name: '产品变现-私密业绩' },
  { code: 'product_monetization_private_item_performance', name: '产品变现-私密品项业绩' },
  { code: 'product_monetization_private_efficiency', name: '产品变现-私密业绩成交效率' },
  { code: 'product_monetization_private_market_distribution', name: '产品变现-私密业绩市场分布' }
]);

export const PHASE_FOUR_CROSS_END_REPORT_TABS = Object.freeze([
  { code: 'six_dimension_analysis', name: '六维数据分析表' }
]);

export const PHASE_FOUR_REPORT_TABS = Object.freeze([
  ...PHASE_FOUR_OPERATION_REPORT_TABS,
  ...PHASE_FOUR_CROSS_END_REPORT_TABS
]);

// 这些报表按自然年度返回每月一行。选择任何年份均固定加载 1 至 12 月，
// 从而使该表声明的月度手动目标可以一次性维护。
export const PHASE_FOUR_ANNUAL_REPORT_CODES = Object.freeze([
  'operations_pre_sale_bdegh',
  'operations_customer_status_bdegh',
  'operations_referral_beautician_pre_sale',
  'operations_performance_comparison',
  'operations_health_data',
  'operations_annual_member_consumption',
  'marketing_acquisition_pre_sale',
  'marketing_referral_pre_sale',
  'marketing_post_sale_performance',
  'marketing_health_data',
  'marketing_beauty_new_item',
  'marketing_customer_status',
  'product_monetization_performance_total',
  'product_monetization_beauty_performance_total',
  'product_monetization_beauty_market_distribution',
  'product_monetization_six_dimension_performance',
  'product_monetization_six_dimension_efficiency',
  'product_monetization_six_dimension_market_distribution',
  'product_monetization_haomei_performance',
  'product_monetization_private_performance',
  'product_monetization_private_efficiency',
  'product_monetization_private_market_distribution'
]);
