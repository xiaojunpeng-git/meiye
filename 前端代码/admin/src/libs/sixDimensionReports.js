const SIX_DIMENSION_REPORTS = Object.freeze([
  { code: 'six_dimension_item_deal_analysis', title: '品项成交分析表' },
  { code: 'six_dimension_cash_consumption_analysis', title: '现金消费分析表' },
  { code: 'six_dimension_consumption_refund_detail', title: '消耗及退款明细' },
  { code: 'six_dimension_performance_deal', title: '业绩成交表' },
  { code: 'six_dimension_performance_distribution', title: '业绩分布表' },
  { code: 'six_dimension_performance_market_distribution', title: '业绩市场分布表' }
]);

const SIX_DIMENSION_REPORT_CODES = Object.freeze(SIX_DIMENSION_REPORTS.map(report => report.code));

export { SIX_DIMENSION_REPORTS, SIX_DIMENSION_REPORT_CODES };
