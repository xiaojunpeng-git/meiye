// Persisted rule IDs are authoritative. A saved directory never grants newly
// added descendants merely because the permission editor is opened or searched.
export function applyPermissionIds(nodes, ids) {
  (nodes || []).forEach((node) => {
    node.ruleChecked = ids.has(Number(node.id));
    node.selected = false;
    applyPermissionIds(node.children, ids);
  });
  refreshPermissionChecks(nodes);
}

function refreshPermissionChecks(nodes) {
  (nodes || []).forEach((node) => {
    const children = node.children || [];
    refreshPermissionChecks(children);
    // 五类展示分组不保存授权编号，但复选状态要反映其子功能是否全选。
    if (node.grantable === false) node.ruleChecked = children.length > 0 && children.every(child => child.checked);
    node.checked = !!node.ruleChecked && children.every(child => child.checked);
    node.indeterminate = !node.checked && (!!node.ruleChecked ||
      children.some(child => child.checked || child.indeterminate));
  });
}

// Cascading is allowed only for an explicit user action. Work on the complete
// source tree so a search cannot silently drop permissions outside its results.
export function togglePermissionNode(nodes, id, checked) {
  let changed = false;
  (nodes || []).forEach((node) => {
    if (Number(node.id) === Number(id)) {
      setPermissionChecks([node], checked);
      changed = true;
    } else if (togglePermissionNode(node.children, id, checked)) {
      node.ruleChecked = node.children.every(child => child.ruleChecked);
      changed = true;
    }
  });
  refreshPermissionChecks(nodes);
  return changed;
}

export function setPermissionChecks(nodes, checked) {
  (nodes || []).forEach((node) => {
    node.ruleChecked = !!checked;
    setPermissionChecks(node.children, checked);
  });
  refreshPermissionChecks(nodes);
}

// UI indeterminate state is presentation only; save the exact selected rules.
export function collectPermissionIds(nodes, result = []) {
  (nodes || []).forEach((node) => {
    if (node.ruleChecked && node.grantable !== false) result.push(Number(node.id));
    collectPermissionIds(node.children, result);
  });
  return result;
}
