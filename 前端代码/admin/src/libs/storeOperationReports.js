const STORE_OPERATION_REPORTS = Object.freeze([
  { code: 'partner_item_summary', title: '合作方品项汇总', description: '合作方品项的体验、成交、消耗与手工汇总' },
  { code: 'partner_item_detail', title: '合作方品项明细', description: '合作方成交、消耗与人工补充明细' },
  { code: 'member_consumption_detail', title: '会员消费明细', description: '会员消费、收款、退款与人员归属明细' },
  { code: 'store_item_analysis', title: '门店品项分析', description: '按商品分类、商品类型与品项分析经营数据' },
  { code: 'store_craftsman_consumption', title: '门店手艺人消耗', description: '按手艺人和日期分析消耗与手工费' },
  { code: 'store_salesperson_performance', title: '门店销售人业绩', description: '按销售人分析订单、顾客、现金与实际业绩' },
  { code: 'market_performance', title: '市场业绩表', description: '按动态来源渠道与记账收费方式汇总市场业绩' },
  { code: 'market_detail', title: '市场明细表', description: '查看市场维度的进店、人次、有效人员及金额明细' },
  { code: 'member_visit_analysis', title: '会员进店分析表', description: '分析会员进店次数、年度来源快照与现金业绩' },
  { code: 'member_visit_annual_summary', title: '会员进店年度汇总表', description: '按年度、月份和权限范围汇总会员进店数据' },
  { code: 'field_acquisition_detail', title: '地推拓客明细表', description: '查看地推来源会员的卖卡、服务和业绩明细' },
  { code: 'field_acquisition_summary', title: '地推拓客汇总表', description: '汇总地推会员首年及各月现金业绩' },
  { code: 'cross_industry_customer_detail', title: '异业收客明细表', description: '查看异业来源的护理、收款和奖励明细' },
  { code: 'cross_industry_customer_summary', title: '异业收客汇总表', description: '汇总异业客户成交、权益及月度业绩' },
  { code: 'new_customer_analysis', title: '新客明细表', description: '查看新客服务、人员分配及收款归类明细' },
  { code: 'new_customer_analysis_summary', title: '新客汇总表', description: '按当前查询年度动态汇总新客月度数据' },
  { code: 'salesperson_large_order_statistics', title: '销售人生美大单统计表', description: '统计销售人生美大单及分成阶段结果' },
  { code: 'store_refund_ledger', title: '院店退款台账', description: '查看院店退款事实、原销售快照及退款业绩' }
]);

const STORE_OPERATION_REPORT_CODES = Object.freeze(STORE_OPERATION_REPORTS.map(report => report.code));

export { STORE_OPERATION_REPORTS, STORE_OPERATION_REPORT_CODES };
