function isInventoryDateInput(target) {
  return String(target?.tagName || '').toUpperCase() === 'INPUT'
    && String(target?.type || '').toLowerCase() === 'date'
}

/**
 * 库存日期必须来自浏览器日历选择器，避免分段键盘输入产生“看得见但提交值为空”的非法日期。
 * Tab、Esc、Enter 和空格保留给键盘导航及打开/关闭原生日历，其余按键不能直接改写日期。
 */
export function preventInventoryDateKeyboardInput(event) {
  if (!isInventoryDateInput(event?.target)) return false
  if (['Tab', 'Escape', 'Enter', ' '].includes(String(event?.key || ''))) return false
  event.preventDefault?.()
  return true
}

/** 阻止软键盘、粘贴或拖放文本绕过日历选择器写入库存日期。 */
export function preventInventoryDateTextTransfer(event) {
  if (!isInventoryDateInput(event?.target)) return false
  event.preventDefault?.()
  return true
}
