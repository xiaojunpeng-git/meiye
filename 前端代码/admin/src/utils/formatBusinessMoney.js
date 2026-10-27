// Display only: saved money and exported source precision must never be rounded by this helper.
const wholeYuan = new Intl.NumberFormat('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

export default function formatBusinessMoney(value) {
  const amount = Number(value);
  return Number.isFinite(amount) ? wholeYuan.format(amount) : '—';
}
