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
    if (node.ruleChecked) result.push(Number(node.id));
    collectPermissionIds(node.children, result);
  });
  return result;
}
