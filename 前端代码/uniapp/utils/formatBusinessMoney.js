/** 仅格式化接口返回的元金额；不得将显示后的整数用于支付、业绩计算或导出。 */
export default function formatBusinessMoney(value) {
  if (value === undefined || value === null || value === '') return '—';
  const amount = Number(value);
  return Number.isFinite(amount) ? Math.round(amount).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '—';
}
