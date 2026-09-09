export function isMoheAiMaintainer(user) {
  return !!user && String(user.account || user.username || '') === 'admin' && Number(user.admin_type || user.adminType || 0) !== 3;
}

export function filterMoheAiManagementMenu(items, user, serverVerified = false) {
  const allowed = isMoheAiMaintainer(user) || serverVerified;
  return (Array.isArray(items) ? items : []).filter(item => item && (allowed || !(
    item.unique_auth === 'setting-mohe-ai' || /\/setting\/mohe-ai\/?$/.test(String(item.path || item.menu_path || ''))
  ))).map(item => ({ ...item, ...(Array.isArray(item.children) ? { children: filterMoheAiManagementMenu(item.children, user, serverVerified) } : {}) }));
}
