export const PHASE_SIX_REPORTS = Object.freeze([
  { code: 'phase_six_garden_item_analysis', name: '花园品项分析表' },
  { code: 'phase_six_monthly_featured_item', name: '月主推数据统计表' },
  { code: 'phase_six_headquarters_acquisition', name: '总部拓客数据统计表' },
  { code: 'phase_six_other_multi_payment', name: '其他多收款业绩表' },
  { code: 'phase_six_salary_summary', name: '员工薪资汇总月报表' },
  { code: 'phase_six_salary_detail', name: '员工薪资明细月报表' },
  { code: 'phase_six_training_employee', name: '教培员工需求统计表' },
  { code: 'phase_six_acquisition_source', name: '拓客部门客户来源数据分析表' },
  { code: 'phase_six_human_store_health', name: '人力-院店健康报表' },
]);
export const PHASE_SIX_REPORT_CODES = Object.freeze(PHASE_SIX_REPORTS.map(report => report.code));
export const PHASE_SIX_CROSS_END_REPORT_TABS = Object.freeze(PHASE_SIX_REPORTS.filter(report => [
  'phase_six_other_multi_payment', 'phase_six_salary_summary', 'phase_six_salary_detail'
].includes(report.code)));
