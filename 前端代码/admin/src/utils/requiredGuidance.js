/** 首个必填错误的共享定位引导；不补值、不提交，交互或3.2秒后清除。 */
let cleanup = null;
export function clearRequiredGuidance() { if (cleanup) cleanup(); }
export function guideRequired(root, key, message) {
  clearRequiredGuidance();
  const area = root && root.querySelector(`[data-required-key="${key}"]`);
  if (!area) return false;
  const control = area.querySelector('input,textarea,button,[tabindex]') || area;
  if (!control.hasAttribute('tabindex') && !/^(INPUT|TEXTAREA|BUTTON)$/.test(control.tagName)) control.setAttribute('tabindex', '-1');
  const oldOutline = area.style.outline; const oldPosition = area.style.position;
  area.style.outline = '2px solid #d97706'; area.style.position = 'relative';
  const tip = document.createElement('span'); tip.textContent = '↑ ' + message;
  tip.setAttribute('role', 'status'); tip.setAttribute('aria-live', 'polite');
  Object.assign(tip.style, { position: 'absolute', left: '4px', top: '100%', zIndex: 9999, padding: '3px 8px', background: '#fff7e6', color: '#8b5100', border: '1px solid #f0c36d', borderRadius: '4px', fontSize: '12px' });
  area.appendChild(tip); area.scrollIntoView({ behavior: 'smooth', block: 'center' }); control.focus({ preventScroll: true });
  let timer;
  cleanup = () => { clearTimeout(timer); area.style.outline = oldOutline; area.style.position = oldPosition; tip.remove(); ['pointerdown', 'input', 'change', 'keydown'].forEach(event => area.removeEventListener(event, clearRequiredGuidance, true)); cleanup = null; };
  ['pointerdown', 'input', 'change', 'keydown'].forEach(event => area.addEventListener(event, clearRequiredGuidance, true));
  timer = setTimeout(clearRequiredGuidance, 3200); return true;
}
