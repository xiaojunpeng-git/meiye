/** 只控制展示和操作入口；价格、库存和真正交易资格始终由后端核验。 */
export function combinationPhase(activity, now) {
  if (activity.activity_status === 'unavailable') return 'unavailable';
  const start = Number(activity.start_time) || 0;
  const stop = Number(activity.stop_time) || 0;
  // 旧接口没有窗口字段时保留原展示；后端交易校验不因前端兼容而放宽。
  if (!stop) return activity.activity_status || 'running';
  if (now > stop) return 'ended';
  return now < start ? 'upcoming' : 'running';
}

/** 以接口服务器时间递进后的剩余秒显示，跨天不把小时截成 24 小时内。 */
export function combinationCountdown(start, now) {
  const remaining = Math.max(0, Math.ceil(Number(start) - Number(now)) || 0);
  const days = Math.floor(remaining / 86400);
  const pad = value => String(value).padStart(2, '0');
  return (days ? days + '天 ' : '') + pad(Math.floor(remaining / 3600) % 24) + ':'
    + pad(Math.floor(remaining / 60) % 60) + ':' + pad(remaining % 60);
}
