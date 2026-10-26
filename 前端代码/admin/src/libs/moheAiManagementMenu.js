export function isMoheAiMaintainer(user) {
  return !!user && String(user.account || user.username || '') === 'admin' && Number(user.admin_type || user.adminType || 0) !== 3;
}

function isMoheAiManagementEntry(item) {
  return item && (item.unique_auth === 'setting-mohe-ai' || /\/setting\/mohe-ai(?:\/|$)/.test(String(item.path || item.menu_path || '')));
}

// Login and /menus are authenticated server responses. This fresh-session
// marker cannot be inferred from the menu cached in localStorage.
export function hasMoheAiManagementMenu(items) {
  return (Array.isArray(items) ? items : []).some(item => item && (
    isMoheAiManagementEntry(item) || hasMoheAiManagementMenu(item.children)
  ));
}

export function filterMoheAiManagementMenu(items, user, serverVerified = false) {
  const allowed = isMoheAiMaintainer(user) || serverVerified;
  return (Array.isArray(items) ? items : []).filter(item => item && (allowed || !isMoheAiManagementEntry(item)))
    .map(item => ({ ...item, ...(Array.isArray(item.children) ? { children: filterMoheAiManagementMenu(item.children, user, serverVerified) } : {}) }));
}
