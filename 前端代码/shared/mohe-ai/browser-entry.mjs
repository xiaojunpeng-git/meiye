import { DeviceSessions, newId, isTerminal, acceptRun } from './device-session.mjs';
import { GUIDANCE_SCHEMA, validateClarification, clarificationKey, clarificationSubmission } from './clarification-state.mjs';

// Framework-neutral, shadow-scoped adapter. No business arithmetic or HTML injection.
export function mountMoheAi({ request, storage = window.localStorage, documentRef = document, entryLeft = '0px', panelLeft = '12px', presentation = 'drawer', entryIconUrl = '', draggableEntry = false }) {
  // The platform shell can briefly retain the previous route component while
  // mounting its replacement.  Two entries would share one device runtime:
  // a late terminal projection from the retired entry could otherwise clear
  // the replacement entry's newer Run.  There is intentionally one browser
  // entry per document; dispose the previous owner first so it persists any
  // active Run and stops its polling before the replacement starts.
  const previousHost = documentRef.querySelector('[data-mohe-ai]');
  if (previousHost) {
    if (typeof previousHost.__moheAiDispose === 'function') previousHost.__moheAiDispose();
    else previousHost.remove();
  }
  const host = documentRef.createElement('div'); host.dataset.moheAi = 'entry'; host.dataset.moheAiPresentation = presentation;
  // A terminal with a persistent sidebar can reserve that width while the
  // entry remains fixed at the left-middle of the actual work area.
  host.style.setProperty('--mohe-ai-entry-left', entryLeft);
  host.style.setProperty('--mohe-ai-panel-left', panelLeft);
  documentRef.body.appendChild(host); const root = host.attachShadow({ mode: 'open' });
  const style = documentRef.createElement('style'); style.textContent = `
    :host{--mohe-ai-brand:#1677cc;--mohe-ai-brand-soft:#eaf4ff;font:14px/1.5 system-ui,sans-serif;color:#243447}
    button,input,textarea,select{font:inherit}button{cursor:pointer;border:1px solid #dbe2ec;border-radius:8px;background:white;padding:7px 12px;color:inherit}button:disabled{opacity:.5;cursor:default}
    .entry{position:fixed;left:var(--mohe-ai-entry-left);top:50%;transform:translateY(-50%);z-index:2147483000;background:var(--mohe-ai-brand);color:white;border-radius:0 14px 14px 0;padding:12px 10px}
    .entry.entry--icon{width:58px;height:58px;min-width:58px;min-height:58px;box-sizing:border-box;padding:0;border:0;border-radius:50%;background:transparent;overflow:visible;box-shadow:0 10px 26px #1669ca55}.entry.entry--icon:hover{transform:translateY(-50%) scale(1.04);box-shadow:0 13px 32px #1669ca77}.entry.entry--draggable{touch-action:none;user-select:none;cursor:grab}.entry.entry--dragging{cursor:grabbing}.entry-icon{display:block;width:100%;height:100%;border-radius:50%;object-fit:contain;pointer-events:none}
    .panel{position:fixed;left:var(--mohe-ai-panel-left);top:50%;transform:translateY(-50%);width:min(440px,calc(100vw - var(--mohe-ai-panel-left) - 12px));height:min(700px,calc(100vh - 32px));z-index:2147483001;box-sizing:border-box;background:white;border:1px solid #dce2ed;border-radius:16px;box-shadow:0 16px 60px #17233d33;display:flex;flex-direction:column;overflow:hidden}
    .head,.footer{padding:14px;border-bottom:1px solid #edf0f4}.head{display:flex;gap:8px;align-items:center;min-width:0}.head strong{flex:1;min-width:0}.head button{flex:0 0 auto;white-space:nowrap}
    .body{min-height:0;overflow:auto;padding:14px;flex:1}.message{white-space:pre-wrap;overflow-wrap:anywhere;margin:8px 0;padding:10px;border-radius:10px;background:#f5f6fa}.question{background:var(--mohe-ai-brand-soft);color:#174f7d}
    .card{border:1px solid #e2e8f0;padding:12px;margin:8px 0;border-radius:10px;overflow-wrap:anywhere}.value{font-size:26px;color:#1f5f8b}.muted{color:#65758b;font-size:12px}
    .answer-presentation{margin:8px 0 18px}.answer-headline{font-size:18px;font-weight:650;line-height:1.6;color:#151b27}.answer-section{margin-top:16px}.answer-section-title{display:block;margin-bottom:8px;color:#53647d;font-size:14px;font-weight:600}.answer-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.answer-fact{padding:12px 14px;border-left:3px solid var(--mohe-ai-brand);border-radius:8px;background:#f6f9fd}.answer-fact-label,.answer-period,.answer-note{display:block;color:#65758b;font-size:13px;line-height:1.5}.answer-fact-value{display:block;margin-top:3px;color:#111827;font-size:22px;font-weight:650}.answer-period{margin-top:12px}.answer-notes{display:grid;gap:4px;margin-top:8px}
    .footer{flex:0 0 auto;border-top:1px solid #edf0f4;border-bottom:0}textarea{box-sizing:border-box;width:100%;resize:vertical;min-height:65px;max-height:160px;border:1px solid #ccd5e3;border-radius:8px;padding:8px}.actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:8px}.actions select{flex:1 1 120px}.primary{background:var(--mohe-ai-brand);color:white}.error{color:#9a3412}select,input{max-width:100%;padding:6px;margin:5px 0}table{border-collapse:collapse;display:block;max-width:100%;overflow:auto}td,th{border:1px solid #e2e8f0;padding:6px;white-space:nowrap}
    @media (max-width:560px){.panel{left:12px;width:calc(100vw - 24px);height:calc(100vh - 24px);top:12px;transform:none;border-radius:14px}.entry{top:auto;bottom:24px;transform:none}.head{flex-wrap:wrap}.head strong{flex-basis:100%}.head button{padding:6px 10px}.footer{padding:12px}.actions button{flex:1 1 auto}}
    /* The platform uses the supplied desktop reference as a dedicated AI
       workspace. Keep this opt-in so the separately approved cashier drawer
       remains unchanged. */
    .panel.workspace{inset:0;width:100vw;height:100vh;transform:none;border:0;border-radius:0;box-shadow:none;display:flex;flex-direction:row;background:#fff;color:#111827}
    .conversation-turn{scroll-margin-top:28px}.workspace .conversation-turn{margin:0 0 12px}.workspace-reading-runway{min-height:0;pointer-events:none}
    .workspace-aside{width:284px;flex:0 0 284px;box-sizing:border-box;display:flex;flex-direction:column;padding:26px 14px 24px;background:#fbfbfc;border-right:1px solid #e7e9ee}
    .workspace-brand{display:flex;flex-direction:column;align-items:flex-start;min-height:36px;padding:0 14px;font-size:23px;font-weight:700;letter-spacing:.02em;color:#111827}.workspace-brand-tagline{margin-top:4px;font-size:12px;font-weight:400;line-height:1.5;letter-spacing:0;color:#667085}
    .workspace-new{margin:24px 0 28px;border:1px solid #e2e8f0;border-radius:15px;padding:16px 20px;text-align:left;font-size:17px;font-weight:650;color:#111827;background:#f6f9fd}.workspace-new:hover{background:#edf2f7}
    .workspace-recent-label{padding:0 16px 12px;color:#7b8390;font-size:14px}.workspace-history{display:grid;gap:4px;min-width:0;overflow-x:hidden;overflow-y:auto}.workspace-history-row{display:flex;min-width:0;align-items:center;gap:4px;padding:0 6px;border-radius:10px}.workspace-history-open{min-width:0;flex:1;border:0;background:transparent;text-align:left;padding:12px 10px;border-radius:10px;font-size:16px;color:#273143;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.workspace-history-open:hover,.workspace-history-open[aria-current="true"]{background:#edf2f7;color:#111827}.workspace-history-delete{flex:0 0 auto;width:26px;height:26px;padding:0;border:0;border-radius:50%;background:#f0f2f5;color:#6d7788;font-size:18px;line-height:1}.workspace-history-delete:hover{background:#fee2e2;color:#b42318}.workspace-history-delete:focus-visible{outline:2px solid var(--mohe-ai-brand);outline-offset:2px}
    .workspace-retention{margin-top:auto;padding:20px 16px;color:#7b8390;font-size:13px;border-bottom:1px solid #e7e9ee}.workspace-main{min-width:0;flex:1;display:flex;flex-direction:column;background:#fff}.workspace-top{display:flex;align-items:center;min-height:78px;padding:0 28px;border-bottom:1px solid #e7e9ee}.workspace-title{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:23px;font-weight:650}.workspace-close{border:0;padding:8px 12px;font-size:24px;line-height:1;color:#465163}.workspace .body{width:min(1350px,calc(100% - 56px));box-sizing:border-box;margin:0 auto;padding:40px 0 32px}.workspace .message{max-width:100%;margin:0 0 24px;padding:0;border-radius:0;background:transparent;color:#151b27;font-size:21px;line-height:1.7}.workspace .message.muted{font-size:14px;line-height:1.5;color:#778092}.workspace .message.question{width:max-content;max-width:78%;margin:0 0 24px auto;padding:15px 24px;border-radius:22px;background:#17191e;color:#fff;font-size:18px;line-height:1.45}.workspace .conversation-turn--active .message.question{margin-bottom:18px}.workspace-run-status{max-width:100%;margin:0 0 34px;color:#98a0aa}.workspace-run-elapsed{font-size:15px;line-height:1.5}.workspace-run-thinking{margin-top:18px;padding-top:18px;border-top:1px solid #edf0f3;font-size:18px;line-height:1.5}.workspace .answer-presentation{max-width:100%;margin:0 0 36px}.workspace .answer-headline{font-size:22px;line-height:1.55}.workspace .answer-facts{max-width:760px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));margin-top:18px}.workspace .answer-fact{padding:16px;border:1px solid #e2e8f0;border-radius:14px;background:#f6f9fd}.workspace .answer-fact-value{font-size:27px;color:#1f5f8b;overflow-wrap:anywhere}.workspace .answer-period,.workspace .answer-note{font-size:15px}.workspace .card{margin:14px 0;border:1px solid #e7e9ee;border-radius:14px;padding:18px 20px;background:#f6f9fd}.workspace .value{color:#1f5f8b}.workspace table{width:100%;margin:18px 0;border-collapse:collapse;display:table}.workspace td,.workspace th{border-width:0 0 1px;padding:14px;text-align:left;color:#1f2937}.workspace th{color:#6e7786;font-weight:500}
    .workspace .footer{width:100%;box-sizing:border-box;margin:0;padding:0 28px 20px;border:0;background:#fff}.workspace-progress{min-height:20px;margin:0 0 8px;color:#667085}.workspace-composer-card{display:grid;grid-template-columns:minmax(0,1fr) 76px;grid-template-rows:minmax(64px,auto) auto;overflow:hidden;border:1px solid #d9dee7;border-radius:22px;background:#fff;box-shadow:0 8px 22px #17233d0d}.workspace .workspace-composer-card textarea{grid-column:1;grid-row:1;min-height:64px;resize:none;padding:18px 22px 10px;border:0;border-radius:0;font-size:17px;line-height:1.55;background:transparent;outline:0}.workspace-composer-actions{grid-column:1;grid-row:2;display:flex;align-items:center;padding:0 22px 12px}.workspace-excel{position:relative;display:inline-flex;align-items:center;cursor:pointer}.workspace-excel input{position:absolute;width:1px;height:1px;margin:-1px;opacity:0;pointer-events:none}.workspace-excel span{display:inline-flex;align-items:center;min-height:30px;padding:0 14px;border:1px solid #111;border-radius:999px;background:#fff;color:#111;font-size:15px;font-weight:600;line-height:1;transition:background-color .16s ease,color .16s ease}.workspace-excel:hover span{background:#f4f4f5}.workspace-excel input:checked+span{background:#111;color:#fff}.workspace-excel input:checked+span:hover{background:#2d2f34}.workspace-excel input:focus-visible+span{outline:2px solid var(--mohe-ai-brand);outline-offset:3px}.workspace-excel input:disabled+span{border-color:#d3d7de;background:#f7f8fa;color:#9aa3b0;cursor:not-allowed}.workspace .workspace-send{grid-column:2;grid-row:1 / span 2;place-self:center;width:56px;height:56px;margin:0;padding:0;border:0;border-radius:50%;font-size:0;background:#111;color:#fff}.workspace .workspace-send::after{content:'↑';font-size:30px;line-height:1}.workspace .workspace-send:disabled{background:#98a1ad}.workspace .workspace-cancel{grid-column:2;grid-row:1 / span 2;place-self:end center;margin:0 10px 11px 0;padding:5px 0;border:0;background:transparent;color:#667085;font-size:12px}.workspace-keyboard-hint{padding:12px 4px 0;text-align:right;color:#8a93a1;font-size:14px}.workspace .entry{display:none}
    .workspace-run-complete{margin:18px 0;color:#7a8493;font-size:15px;line-height:1.5}
    :host([data-mohe-ai-presentation="workspace"]) .entry{left:auto;right:28px;top:auto;bottom:30px;transform:none}
    :host([data-mohe-ai-presentation="workspace"]) .entry.entry--icon:hover{transform:scale(1.04)}
    @media (max-width:760px){.panel.workspace{display:block;overflow:auto}.workspace-aside{display:none}.workspace-main{min-height:100vh}.workspace-top{min-height:62px;padding:0 18px}.workspace-title{font-size:19px}.workspace .body,.workspace .footer{width:calc(100% - 32px)}.workspace .body{padding-top:28px}.workspace .message{font-size:17px}.workspace .message.question{font-size:16px;max-width:88%;margin-bottom:30px}.workspace .footer{position:sticky;bottom:0;padding:12px 0 16px}.workspace-composer-card{grid-template-columns:minmax(0,1fr) 64px;grid-template-rows:minmax(64px,auto) auto;border-radius:18px}.workspace .workspace-composer-card textarea{min-height:64px;padding:14px 18px 8px;font-size:16px}.workspace-composer-actions{padding:0 18px}.workspace-excel span{min-height:28px;padding:0 12px;font-size:14px}.workspace .workspace-send{width:48px;height:48px}.workspace-keyboard-hint{display:none}.workspace-new{margin-top:16px}:host([data-mohe-ai-presentation="workspace"]) .entry{right:16px;bottom:18px}}
  `;
  // 两端确认卡共用黑白层级；视觉序号不参与业务选项与权限判断。
  style.textContent += `.card.guidance-card{padding:22px;border:1px solid #e5e5e7;border-radius:22px;background:#fff;color:#202124}.guidance-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;color:#777;font-size:14px}.guidance-cancel{border:0;background:transparent;color:#777;font-size:13px}.guidance-question{margin:10px 0 18px;font-size:18px;font-weight:600;line-height:1.65}.guidance-label{margin:12px 0 8px;color:#777;font-size:13px}.guidance-option{position:relative;display:flex;align-items:center;gap:12px;margin:6px 0;padding:12px;border:1px solid transparent;border-radius:16px;cursor:pointer}.guidance-option:has(input:checked){background:#f3f3f4;border-color:#d9d9dc}.guidance-option:focus-within{outline:2px solid #737377;outline-offset:2px}.guidance-option input{position:absolute;width:1px;height:1px;opacity:0}.guidance-number{display:flex;align-items:center;justify-content:center;flex:0 0 30px;height:30px;border:1px solid #dedee1;border-radius:50%;background:#f3f3f4;color:#737377;font-size:14px}.guidance-option-label{min-width:0;font-size:16px;line-height:1.5;overflow-wrap:anywhere}.guidance-supplement{box-sizing:border-box;width:100%;min-height:88px;padding:12px;border:1px solid #d9d9dc;border-radius:12px;font:inherit;resize:vertical}.guidance-confirm.primary{display:block;margin:18px 0 0 auto;padding:10px 18px;min-height:44px;border:0;border-radius:24px;background:#17191e;color:#fff}.guidance-confirm:disabled{background:#aaa}.guidance-card input[type=date],.guidance-card select{box-sizing:border-box;max-width:100%;min-height:44px}[hidden]{display:none!important}`;
  // A transport failure is one small action, not another business question card.
  style.textContent += `.workspace .failure-retry{display:inline-flex;align-items:center;min-height:38px;margin:0 0 20px;padding:7px 16px;border:1px solid #d9dee7;border-radius:999px;background:#fff;color:#273143;font-size:14px}.workspace .failure-retry:hover{background:#f4f6f9}.workspace .failure-retry:focus-visible{outline:2px solid var(--mohe-ai-brand);outline-offset:2px}`;
  style.textContent += `.answer-feedback{display:flex;align-items:center;gap:8px;margin:14px 0;color:#778399;font-size:13px}.answer-feedback button{border:1px solid #d8dee7;background:#fff;border-radius:16px;padding:5px 12px;color:#43536c;cursor:pointer}.answer-feedback button[aria-pressed="true"]{background:#eaf3ff;border-color:#2e86d0;color:#1565a8}.answer-feedback button:disabled{opacity:.6}.answer-feedback-note{font-size:12px}`;
  root.appendChild(style);
  const el = (tag, text, cls) => { const n = documentRef.createElement(tag); if (text != null) n.textContent = String(text); if (cls) n.className = cls; return n; };
  // Gateway codes remain machine-readable for diagnostics, but customer-facing
  // status must explain the actionable boundary without exposing raw codes.
  function customerErrorMessage(value, fallback = '魔核 AI 暂时无法处理这次请求，请联系管理员检查服务状态。') {
    const reason = typeof value === 'string' ? value.trim() : '';
    if (reason === 'AI_NOT_CONFIGURED' || reason === 'AI_EXTERNAL_AUTHORIZATION_REQUIRED') return '魔核 AI 暂不可用，请管理员检查基础配置中的启用状态和外部处理授权。';
    if (reason === 'AI_AUTH_REQUIRED') return '登录状态已失效，请重新登录后再试。';
    if (reason === 'AI_PERMISSION_DENIED') return '当前账号没有使用魔核 AI 的权限，请联系管理员。';
    if (reason === 'AI_CLIENT_UPGRADE_REQUIRED') return '魔核 AI 页面版本较旧，请刷新页面后再试。';
    return /[\u3400-\u9fff]/.test(reason) ? reason : fallback;
  }
  let boot, sessions, conversation, run = null, question = '', panel = null, body, progress, input, send, format, pollTimer, expiryTimer, elapsedTimer, admissionRetryTimer = null, admissionInFlight = false, admissionRetryCount = 0, disposed = false, cancelling = false, closeRequested = false, pendingCreate = null, compatibilityExecuting = false, clientDeliveryStartedAt = 0, workspaceTitle = null, workspaceHistory = null, workspaceCancel = null, readingRunway = null, activeRunStatus = null, exportRequested = false, recoveryDraft = null, freshButton = null;
  let clientSession = newId(); const entry = el('button', entryIconUrl ? null : '魔核 AI', entryIconUrl ? 'entry entry--icon' : 'entry');
  entry.type = 'button'; entry.setAttribute('aria-label', '打开魔核 AI 工作台');
  if (entryIconUrl) { const icon = documentRef.createElement('img'); icon.className = 'entry-icon'; icon.src = entryIconUrl; icon.alt = ''; icon.draggable = false; entry.appendChild(icon); }
  entry.hidden = true; root.appendChild(entry);
  // Only the platform launcher opts in. A short pointer gesture remains a
  // normal click; a drag stores viewport pixels locally and is clamped after
  // resize so the launcher cannot become unreachable.
  let disposeEntryDrag = () => {};
  if (draggableEntry && entryIconUrl) {
    const view = documentRef.defaultView || window;
    const positionKey = 'mohe-ai:platform-entry-position:v1';
    let drag = null, suppressClick = false, clickResetTimer = null;
    const clamp = (value, maximum) => Math.min(Math.max(value, 8), Math.max(8, maximum - 8));
    const place = (x, y) => {
      const rect = entry.getBoundingClientRect();
      const width = rect.width || 58, height = rect.height || 58;
      const left = clamp(x, view.innerWidth - width), top = clamp(y, view.innerHeight - height);
      entry.style.left = `${left}px`; entry.style.right = 'auto';
      entry.style.top = `${top}px`; entry.style.bottom = 'auto';
      return { x: left, y: top };
    };
    const save = (position) => { try { storage.setItem(positionKey, JSON.stringify(position)); } catch (_) { /* Storage may be unavailable; dragging still works. */ } };
    const fit = () => {
      if (!entry.style.left || !entry.style.top) return;
      save(place(parseFloat(entry.style.left), parseFloat(entry.style.top)));
    };
    try {
      const saved = JSON.parse(storage.getItem(positionKey));
      if (saved && Number.isFinite(saved.x) && Number.isFinite(saved.y)) place(saved.x, saved.y);
    } catch (_) { /* Ignore stale or unavailable local position. */ }
    const end = (event) => {
      if (!drag || event.pointerId !== drag.id) return;
      view.removeEventListener('pointermove', move);
      view.removeEventListener('pointerup', end);
      view.removeEventListener('pointercancel', end);
      entry.classList.remove('entry--dragging');
      if (drag.moved) {
        if (event.type === 'pointerup') save(place(drag.x, drag.y));
        suppressClick = true;
        clickResetTimer = view.setTimeout(() => { suppressClick = false; clickResetTimer = null; }, 0);
      }
      drag = null;
    };
    const move = (event) => {
      if (!drag || event.pointerId !== drag.id) return;
      const dx = event.clientX - drag.startX, dy = event.clientY - drag.startY;
      if (!drag.moved && Math.hypot(dx, dy) < 5) return;
      drag.moved = true;
      const placed = place(drag.left + dx, drag.top + dy);
      drag.x = placed.x; drag.y = placed.y;
      entry.classList.add('entry--dragging');
      event.preventDefault();
    };
    const start = (event) => {
      if (event.button !== 0 || event.isPrimary === false) return;
      if (clickResetTimer != null) view.clearTimeout(clickResetTimer);
      suppressClick = false;
      const rect = entry.getBoundingClientRect();
      drag = { id: event.pointerId, startX: event.clientX, startY: event.clientY, left: rect.left, top: rect.top, x: rect.left, y: rect.top, moved: false };
      view.addEventListener('pointermove', move);
      view.addEventListener('pointerup', end);
      view.addEventListener('pointercancel', end);
    };
    const stopDraggedClick = (event) => {
      if (!suppressClick) return;
      event.preventDefault(); event.stopImmediatePropagation(); suppressClick = false;
    };
    entry.classList.add('entry--draggable');
    entry.addEventListener('pointerdown', start);
    entry.addEventListener('click', stopDraggedClick, true);
    view.addEventListener('resize', fit);
    disposeEntryDrag = () => {
      view.removeEventListener('pointermove', move);
      view.removeEventListener('pointerup', end);
      view.removeEventListener('pointercancel', end);
      view.removeEventListener('resize', fit);
      entry.removeEventListener('pointerdown', start);
      entry.removeEventListener('click', stopDraggedClick, true);
      if (clickResetTimer != null) view.clearTimeout(clickResetTimer);
    };
  }
  // A clarification POST is durably accepted before the worker can change the
  // public Run status.  Keep polling that same step instead of rendering its
  // old controls again or treating WAITING_CLARIFICATION as an idle state.
  let clarificationArea = null, clarificationId = null, clarificationSubmittedId = null, activeGuidanceSchema = null, guidanceSubmission = null, guidanceDraft = null, clarificationTimer = null;
  function clearClarification() { clearTimeout(clarificationTimer); clarificationTimer = null; if (clarificationArea) clarificationArea.remove(); clarificationArea = null; clarificationId = null; }
  // Rendering a message must never decide where the customer is reading. The
  // reading anchor is set only for a newly submitted turn, so history browsing
  // and asynchronous results cannot repeatedly pull the viewport to the end.
  function message(text, cls, target = body) { const n = el('div', text, 'message ' + (cls || '')); target.appendChild(n); return n; }
  function activeConversationTurn() { return body && body.querySelector('[data-mohe-ai-active-turn]'); }
  function appendTranscriptTurn(questionText, answer, elapsedSeconds = 0, delivery = null) {
    const turn = el('section', null, 'conversation-turn');
    message(questionText, 'question', turn);
    appendCompletedElapsed(turn, elapsedSeconds);
    // Local history may predate the platform failure contract. Reopening it
    // must not resurrect a retired recovery menu or ask for a nonexistent
    // choice. The saved transcript remains unchanged for server context review.
    const oldFailureMenu = presentation === 'workspace' && answer && answer.terminal_status === 'FAILED'
      && Array.isArray(answer.failure_guidance && answer.failure_guidance.options);
    const displayedAnswer = oldFailureMenu ? { ...answer, summary: '这次暂时未能完成查询，请稍后重试。', failure_guidance: null } : answer;
    renderAnswer(displayedAnswer, false, turn);
    renderFeedback(turn, questionText, displayedAnswer && displayedAnswer.summary || '', delivery);
    if (displayedAnswer && displayedAnswer.failure_guidance) renderFailureCard(questionText, displayedAnswer, turn);
    // Keep the reading runway at the physical transcript end. Reopened
    // history remains chronological instead of being appended after its gap.
    body.insertBefore(turn, readingRunway && readingRunway.parentNode === body ? readingRunway : null);
    // History is local display data. Recover the immutable file task only
    // through this turn's original server-bound delivery proof, never through
    // the mutable current Run or the stored answer text. Poll after attach so
    // a detached history card cannot silently skip its first status request.
    if (displayedAnswer && displayedAnswer.auto_export_required === true && delivery && delivery.status === 'COMPLETED') {
      if (delivery.run_id && delivery.run_delivery_token) startIndependentExport(delivery, turn, false);
      else turn.appendChild(el('div', '此历史回答的 Excel 凭证已失效，请重新提问。', 'card muted'));
    }
    return turn;
  }
  // A failed turn is immutable history. Choosing its card starts a new Run
  // with that turn's captured predecessor, never the conversation's latest
  // result; the gateway rechecks the signed reference and current permission.
  function renderFailureCard(questionText, presentation, turn) {
    const guidance = presentation.failure_guidance;
    // Expiry is a recoverable clarification boundary. Reuse the same fields
    // and private source proof, with one explicit fresh-query action.
    if (guidance && guidance.kind === 'clarification_expired' && presentation.clarification_restart) {
      renderClarification(presentation.expired_clarification, {
        ...presentation.clarification_restart, question: questionText
      }, presentation.clarification_choices || {}, turn);
      return;
    }
    // A provider timeout has no accepted meaning, but the failed question is
    // safe to retry as a new Run with its original predecessor context. Show
    // one explicit retry in the workspace. Editing and starting over already
    // live in its composer/sidebar; extra choices imply that more wording can
    // repair a provider timeout. Semantic uncertainty uses live fields below.
    if (host.dataset.moheAiPresentation === 'workspace') {
      if (!guidance || guidance.kind !== 'technical_error'
        || guidance.retry_original !== true && !Array.isArray(guidance.options)) return;
      const origin = presentation.recovery_origin;
      if (!origin || !Array.isArray(origin.history) || typeof origin.created_at !== 'number') return;
      const currentDay = sameBusinessDay(origin.created_at, Date.now());
      const retry = el('button', currentDay ? '重试原问题' : '核对日期后重试', 'failure-retry'); retry.type = 'button';
      retry.dataset.failureGuidance = 'true';
      retry.onclick = () => {
        if (disposed || pendingCreate || (run && !isTerminal(run.status)) || conversation !== origin.conversation_id) return;
        recoveryDraft = origin; input.value = questionText;
        if (currentDay) void submitQuestion();
        else input.focus();
      };
      turn.appendChild(retry);
      return;
    }
    const options = guidance && guidance.options;
    if (!Array.isArray(options) || options.length !== 3 || options.some(item => !item || typeof item.label !== 'string'
      || !['retry_original', 'edit_original', 'new_conversation'].includes(item.value))) return;
    const origin = presentation.recovery_origin;
    if (!origin || !Array.isArray(origin.history) || typeof origin.created_at !== 'number') return;
    const card = el('section', null, 'card guidance-card'); card.dataset.failureGuidance = 'true';
    card.appendChild(el('div', '您可以这样继续', 'guidance-heading'));
    const supplement = el('textarea', null, 'guidance-supplement'); supplement.maxLength = 4000;
    supplement.setAttribute('aria-label', '补充或改写完整问题'); supplement.placeholder = '请补充或改写完整问题'; supplement.hidden = true;
    const sendSupplement = el('button', '发送补充问题', 'primary guidance-confirm'); sendSupplement.hidden = true;
    const start = text => {
      if (disposed || pendingCreate || (run && !isTerminal(run.status)) || conversation !== origin.conversation_id) return;
      recoveryDraft = origin; input.value = text; void submitQuestion();
    };
    options.forEach((option, index) => {
      const button = el('button', null, 'guidance-option'); button.type = 'button';
      button.append(el('span', String(index + 1), 'guidance-number'), el('span', option.label, 'guidance-option-label'));
      button.onclick = () => {
        if (option.value === 'new_conversation') { recoveryDraft = null; if (freshButton) freshButton.click(); return; }
        if (option.value === 'edit_original' || !sameBusinessDay(origin.created_at, Date.now())) {
          recoveryDraft = origin; input.value = questionText; input.focus(); return;
        }
        start(questionText);
      };
      card.appendChild(button);
    });
    const custom = el('button', null, 'guidance-option'); custom.type = 'button';
    custom.append(el('span', '4', 'guidance-number'), el('span', guidance.supplement_label || '我来补充描述', 'guidance-option-label'));
    custom.onclick = () => { supplement.hidden = false; sendSupplement.hidden = false; supplement.value = questionText; supplement.focus(); };
    sendSupplement.onclick = () => { const text = supplement.value.trim(); if (text) start(text); };
    card.append(custom, supplement, sendSupplement); turn.appendChild(card);
  }
  // The server owns failure classification. Platform failures explain that
  // boundary without inventing missing conditions or replacing an admission
  // refusal with a generic retry card. Drawer compatibility stays below.
  function safeFailureGuidance(value, reason = '', detail = {}) {
    if (presentation === 'workspace') {
      if (reason === 'CLARIFICATION_EXPIRED') return {
        kind: 'clarification_expired', question: '补充等待已超时，尚未执行查询。已确认条件已保留，可继续原查询。'
      };
      const typed = value && ['technical_error', 'capability_help'].includes(value.kind)
        && typeof value.question === 'string' && value.question.trim();
      const fallback = reason === 'AI_MODEL_RESULT_UNKNOWN' ? 'AI 服务暂时未返回结果，请稍后重试。'
        : reason === 'AI_CAPABILITY_CHANGED' ? '查询能力已更新，请刷新页面后重新查询。'
          : '这次暂时未能完成查询，请稍后重试。';
      // Untyped legacy options describe a recovery menu, not a classified
      // failure. An explicit admission message remains valid even if an old
      // server also sends that menu; terminal legacy menu copy is discarded.
      const explanation = typed ? value.question.trim()
        : detail.accepted === false || !Array.isArray(value && value.options)
          ? customerErrorMessage(detail.message || reason, fallback) : fallback;
      const retryAfterMs = typed && Number.isSafeInteger(value.retry_after_ms) ? value.retry_after_ms : detail.retry_after_ms;
      const retryHint = Number.isSafeInteger(retryAfterMs) && retryAfterMs > 0
        ? '\n预计 ' + Math.ceil(retryAfterMs / 1000) + ' 秒后可重试。' : '';
      // The model did not return a reliable meaning, so retry the exact
      // question with its verified predecessor instead of asking the customer
      // for unrelated wording changes or inventing metric choices.
      const recovery = reason === 'AI_MODEL_RESULT_UNKNOWN' ? { retry_original: true } : {};
      return { kind: typed ? value.kind : 'technical_error', question: explanation + retryHint, ...recovery };
    }
    // A server-confirmed absence of business-query meaning needs a short
    // capability explanation, not three ways to repeat an unrelated question.
    // Capability drift fails before understanding; do not pretend it classified
    // the wording, but explain the product boundary without a choice card.
    if (value && value.kind === 'capability_help' && typeof value.question === 'string' && value.question.trim()) return value;
    if (reason === 'AI_INTENT_UNRESOLVED') return { kind: 'capability_help',
      question: '我主要帮您查询门店经营数据，比如今天的现金业绩、消耗业绩。您可以直接用平常的话提问。' };
    if (reason === 'AI_CAPABILITY_CHANGED') return { kind: 'capability_help',
      question: '这次暂时没能完成查询。我主要帮您查询门店经营数据，比如今天的现金业绩、消耗业绩。您可以直接再问一次。' };
    const options = value && value.options;
    if (Array.isArray(options) && options.length === 3
      && ['retry_original', 'edit_original', 'new_conversation'].every((key, index) =>
        options[index] && options[index].value === key && typeof options[index].label === 'string' && options[index].label)) return value;
    return { question: '这次没能给出可靠结果。请选择一种方式继续。',
      options: [{ value: 'retry_original', label: '按原问题再查一次' },
        { value: 'edit_original', label: '修改原问题后查询' },
        { value: 'new_conversation', label: '开启新对话' }], supplement_label: '我来补充描述' };
  }
  function sameBusinessDay(first, second) {
    const key = value => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit' }).format(value);
    return key(first) === key(second);
  }
  // A known admission refusal has no Run ID. Keep its server explanation as
  // a non-contextual turn; an unknown transport outcome remains recoverable
  // with the same admission key instead of being called a confirmed failure.
  function recordAdmissionRefusal(submitted, explanation, guidance, reason = '', detail = {}) {
    const safeGuidance = safeFailureGuidance(guidance, reason, { ...detail, accepted: false, message: explanation });
    const customerText = ['capability_help', 'technical_error'].includes(safeGuidance.kind) ? safeGuidance.question : explanation;
    const deliveredQuestion = ensureQuestionVisible();
    const elapsedSeconds = completedElapsedSeconds();
    finishActiveRunStatus(); appendCompletedElapsed(activeConversationTurn(), elapsedSeconds); progress.textContent = '';
    const turn = activeConversationTurn() || body;
    message(customerText, '', turn);
    const presentation = { summary: customerText, terminal_status: 'FAILED', failure_guidance: safeGuidance };
    if (safeGuidance.retry_original === true || Array.isArray(safeGuidance.options)) presentation.recovery_origin = { conversation_id: conversation,
      history: submitted.history, context_ref: submitted.context_ref || null, created_at: Date.now() };
    renderFailureCard(deliveredQuestion, presentation, turn);
    try { sessions.append(conversation, deliveredQuestion, customerText, presentation, null,
      { contextEligible: false, elapsedSeconds }); } catch (_) { message('本机历史保存失败，本次问题仍可查看。', 'error', turn); }
    completeActiveQuestion(); clearActive(); pendingCreate = null; clientDeliveryStartedAt = 0;
    if (sessions) sessions.clearPendingQuestion(conversation);
    send.disabled = false; send.textContent = '发送'; syncWorkspaceActions();
  }
  function ensureReadingRunway(turn = activeConversationTurn()) {
    if (!body) return;
    if (!readingRunway) { readingRunway = el('div', null, 'workspace-reading-runway'); readingRunway.setAttribute('aria-hidden', 'true'); }
    // A newest turn has no content after it, so the browser clamps scrolling
    // before it can reach the reading top. Reserve one viewport below it;
    // this is layout space only, never a second answer or transcript record.
    readingRunway.style.minHeight = Math.max(0, body.clientHeight - 112) + 'px';
    if (turn && turn.parentNode === body) body.insertBefore(readingRunway, turn.nextSibling);
    else body.appendChild(readingRunway);
  }
  function refreshElapsed() {
    if (!activeRunStatus) return;
    // An admission refusal can clear the start timestamp before a timer tick;
    // never turn that sentinel into an epoch-length customer-facing wait.
    const seconds = completedElapsedSeconds();
    activeRunStatus.elapsed.textContent = '已处理 ' + seconds + ' 秒';
  }
  function completedElapsedSeconds() {
    return Number.isFinite(clientDeliveryStartedAt) && clientDeliveryStartedAt > 0
      ? Math.max(0, Math.floor((Date.now() - clientDeliveryStartedAt) / 1000)) : 0;
  }
  // This is the time a customer experiences before the first answer is
  // visible. It deliberately never claims a server-side execution duration.
  function completedElapsedLabel(seconds) {
    const value = Number.isSafeInteger(seconds) && seconds > 0 ? seconds : 0;
    if (!value) return '';
    const minutes = Math.floor(value / 60), remainder = value % 60;
    return minutes > 0 ? '用时 ' + minutes + ' 分钟' + (remainder ? ' ' + remainder + ' 秒' : '') : '用时 ' + value + ' 秒';
  }
  function appendCompletedElapsed(turn, seconds) {
    const label = completedElapsedLabel(seconds);
    if (label && turn) turn.appendChild(el('div', label, 'workspace-run-complete'));
  }
  function startActiveRunStatus(turn = activeConversationTurn()) {
    if (!turn || activeRunStatus) return;
    const area = el('div', null, 'workspace-run-status');
    const elapsed = el('div', '', 'workspace-run-elapsed');
    const thinking = el('div', '正在思考', 'workspace-run-thinking');
    area.append(elapsed, thinking); turn.appendChild(area);
    activeRunStatus = { area, elapsed, thinking }; refreshElapsed();
    clearInterval(elapsedTimer); elapsedTimer = setInterval(refreshElapsed, 250);
  }
  function setActiveRunState(text) {
    if (!activeRunStatus) return false;
    activeRunStatus.area.hidden = false;
    if (!elapsedTimer) { refreshElapsed(); elapsedTimer = setInterval(refreshElapsed, 250); }
    activeRunStatus.thinking.textContent = text || '正在思考';
    if (progress) progress.textContent = '';
    return true;
  }
  function finishActiveRunStatus() {
    clearInterval(elapsedTimer); elapsedTimer = null;
    if (activeRunStatus) activeRunStatus.area.remove();
    activeRunStatus = null;
  }
  function anchorTurnAtReadingTop(turn) {
    if (!turn || !body) return;
    // A question starts a new reading task. Position that task near the top
    // after layout settles, but do not auto-follow later answer updates: the
    // customer may have intentionally scrolled to compare an earlier result.
    const align = () => {
      if (!turn.isConnected || !body.isConnected) return;
      ensureReadingRunway(turn);
      // Let the browser choose the nearest real scroll container first. The
      // workbench may be mounted below another scrollable shell, where a
      // body-only scrollTop calculation leaves the new turn near the bottom.
      if (typeof turn.scrollIntoView === 'function') turn.scrollIntoView({ block: 'start', inline: 'nearest', behavior: 'auto' });
      const bodyTop = body.getBoundingClientRect().top;
      const turnTop = turn.getBoundingClientRect().top;
      const requestedTop = body.scrollTop + turnTop - bodyTop - 28;
      const maximumTop = Math.max(0, body.scrollHeight - body.clientHeight);
      body.scrollTop = Math.min(maximumTop, Math.max(0, requestedTop));
    };
    const schedule = typeof window.requestAnimationFrame === 'function' ? window.requestAnimationFrame.bind(window) : callback => setTimeout(callback, 0);
    schedule(() => schedule(align));
  }
  // An active Run can outlive a page component.  Keep its submitted wording in
  // the restored transcript before a late terminal projection renders the
  // answer; otherwise a refresh misleadingly shows an answer with no question.
  function questionFromRecord(record) {
    if (!record || typeof record !== 'object') return '';
    // Older runtime snapshots and a late create acknowledgement can carry the
    // submitted wording only inside the immutable create envelope.  It is the
    // same customer wording, not a reconstructed or model-generated question.
    if (typeof record.question === 'string' && record.question) return record.question;
    return record.pending_create && typeof record.pending_create.question === 'string' ? record.pending_create.question : '';
  }
  function persistedQuestion(conversationId = conversation) {
    return sessions && typeof conversationId === 'string' ? sessions.pendingQuestion(conversationId) : '';
  }
  function showActiveQuestion() {
    if (!question || !body) return;
    // Do not rely on an in-memory flag alone: after a route remount, that flag
    // can describe a previous body while the new panel has no visible question.
    const previous = body.querySelector('[data-mohe-ai-active-question]');
    if (previous && previous.textContent === question) {
      const turn = previous.closest('[data-mohe-ai-active-turn]');
      startActiveRunStatus(turn); anchorTurnAtReadingTop(turn); return;
    }
    if (previous) (previous.closest('[data-mohe-ai-active-turn]') || previous).remove();
    const turn = el('section', null, 'conversation-turn conversation-turn--active'); turn.dataset.moheAiActiveTurn = 'true';
    const n = message(question, 'question', turn); n.dataset.moheAiActiveQuestion = 'true';
    body.appendChild(turn); ensureReadingRunway(turn); startActiveRunStatus(turn); anchorTurnAtReadingTop(turn);
  }
  function conversationTitle(value) {
    const first = value && Array.isArray(value.rounds) && value.rounds[0];
    return first && typeof first.question === 'string' && first.question.trim() ? first.question.trim() : '新对话';
  }
  function updateWorkspaceTitle(value) {
    if (workspaceTitle) workspaceTitle.textContent = conversationTitle(value);
  }
  function renderWorkspaceHistory() {
    if (!workspaceHistory || !sessions) return;
    workspaceHistory.textContent = '';
    // A customer can open and close the workbench without asking anything.
    // Keep the current draft visible, but do not fill the recent-conversation
    // list with those empty local shells.
    sessions.load().filter(item => item.id === conversation || (Array.isArray(item.rounds) && item.rounds.length > 0)).slice().reverse().forEach(item => {
      const row = el('div', null, 'workspace-history-row');
      const title = conversationTitle(item); const button = el('button', title, 'workspace-history-open');
      if (item.id === conversation) button.setAttribute('aria-current', 'true');
      button.onclick = () => {
        if (pendingCreate || (run && !isTerminal(run.status))) return;
        recoveryDraft = null; conversation = item.id; body.textContent = ''; progress.textContent = '';
        item.rounds.forEach(row => appendTranscriptTurn(row.question, row.presentation || { summary: row.answer }, row.elapsed_seconds, row));
        body.scrollTop = 0;
        updateWorkspaceTitle(item); renderWorkspaceHistory();
      };
      const remove = el('button', '×', 'workspace-history-delete'); remove.type = 'button'; remove.setAttribute('aria-label', '删除对话：' + title);
      remove.onclick = () => {
        if (pendingCreate || (run && !isTerminal(run.status))) return;
        const deletingCurrent = item.id === conversation;
        if (!sessions.remove(item.id)) return;
        if (deletingCurrent) {
          conversation = sessions.create().id; body.textContent = ''; progress.textContent = ''; run = null; clearActive(); updateWorkspaceTitle(null);
        }
        renderWorkspaceHistory(); syncWorkspaceActions();
      };
      row.append(button, remove); workspaceHistory.appendChild(row);
    });
  }
  function syncWorkspaceActions() {
    if (!panel || !panel.classList.contains('workspace')) return;
    const active = Boolean(pendingCreate || (run && !isTerminal(run.status)));
    if (workspaceCancel) workspaceCancel.hidden = !active;
    if (send) send.setAttribute('aria-label', active ? '正在执行，等待结果' : '发送问题');
  }
  // Once the current Run reaches a terminal projection, its question becomes
  // an immutable transcript turn. Re-anchor this one completed turn because
  // a short question may not make the body scrollable until its answer has
  // expanded it; do not keep following any later transcript updates. Remove
  // the active marker so a later identical question creates a new pair rather
  // than appending several outcomes below an older question.
  function completeActiveQuestion() {
    if (!body) return;
    const current = body.querySelector('[data-mohe-ai-active-question]');
    if (current) current.removeAttribute('data-mohe-ai-active-question');
    const turn = activeConversationTurn();
    if (turn) {
      finishActiveRunStatus();
      anchorTurnAtReadingTop(turn);
      turn.removeAttribute('data-mohe-ai-active-turn'); turn.classList.remove('conversation-turn--active');
    }
  }
  function validGuidanceSubmission(value) {
    return !!(value && typeof value === 'object' && typeof value.clarification_id === 'string' && value.clarification_id
      && typeof value.client_submission_id === 'string' && value.client_submission_id && value.choices
      && typeof value.choices === 'object' && !Array.isArray(value.choices)
      && (!value.schema_version || value.schema_version === GUIDANCE_SCHEMA));
  }
  function activeRecord() {
    const value = sessions && sessions.loadRuntime();
    if (!value || (value.run == null && value.pending_create == null)
      || (value.queued_clarification_id != null && typeof value.queued_clarification_id !== 'string')
      || (value.guidance_submission != null && !validGuidanceSubmission(value.guidance_submission))) {
      if (sessions) sessions.clearRuntime(); return null;
    }
    return value;
  }
  function persistActive() {
    if (!sessions) return;
    if ((!run && !pendingCreate) || isTerminal(run && run.status)) { sessions.clearRuntime(); return; }
    sessions.saveRuntime({ created_at: Date.now(), session_id: clientSession, conversation_id: conversation, question,
      close_requested: closeRequested, queued_clarification_id: clarificationSubmittedId,
      guidance_submission: guidanceSubmission, guidance_draft: guidanceDraft, client_delivery_started_at: clientDeliveryStartedAt,
      run, pending_create: pendingCreate });
  }
  function activeRuntimeBelongsToThisEntry() {
    if (!sessions) return false;
    const current = sessions.loadRuntime();
    if (!current || current.session_id !== clientSession || current.conversation_id !== conversation) return false;
    if (run && current.run) return current.run.run_id === run.run_id && current.run.generation === run.generation;
    return !!(pendingCreate && current.pending_create
      && current.pending_create.client_request_id === pendingCreate.client_request_id);
  }
  // A retired entry is allowed to finish rendering its own terminal Run, but
  // must never erase another entry's newer recovery record.  The runtime is
  // shared only as a handoff mechanism, so removal is ownership-checked.
  function clearActive() { if (activeRuntimeBelongsToThisEntry()) sessions.clearRuntime(); }
  function deliveryBinding(source) { return { client_session_id: clientSession, run_delivery_token: source.run_delivery_token, generation: source.generation }; }
  function reportVisibleDelivery(source) {
    const startedAt=clientDeliveryStartedAt;
    if (!source || !Number.isSafeInteger(startedAt) || startedAt<=0) return;
    // The value is client-observed telemetry only. It is bounded before it
    // leaves the device and the server accepts it as a one-time, non-business
    // diagnostic; no customer text, result, identity, or clock timestamp is
    // included in the request.
    const elapsed=Math.min(300000,Math.max(0,Date.now()-startedAt));
    clientDeliveryStartedAt=0;
    try {
      Promise.resolve(request('POST', '/runs/' + encodeURIComponent(source.run_id) + '/delivery', {
        ...deliveryBinding(source), client_elapsed_ms: elapsed
      })).catch(() => {});
    } catch (_) {
      // A telemetry failure must never turn a rendered answer into a UI error.
    }
  }
  function reportVisibleDeliveryAfterPaint(source) {
    const deliver = () => {
      // A result that is no longer mounted was not visibly delivered by this
      // page. A replacement entry can report its own visible delivery instead.
      if (!disposed && run && isTerminal(run.status) && run.run_id === source.run_id && run.generation === source.generation) reportVisibleDelivery(source);
    };
    if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(deliver);
    else setTimeout(deliver, 0);
  }
  function ensureQuestionVisible() {
    if (!question) {
      const stored = sessions && sessions.loadRuntime();
      question = questionFromRecord(stored) || (pendingCreate && typeof pendingCreate.question === 'string' ? pendingCreate.question : '') || persistedQuestion();
    }
    showActiveQuestion();
    return question;
  }
  function sameRequest(value, submitted) {
    return !!(value && value.pending_create && submitted
      && value.pending_create.client_request_id === submitted.client_request_id);
  }
  // A create acknowledgement may arrive after this entry has been replaced by
  // another page.  It may only enrich the same durable request; it must never
  // replace a newer run or render/write a result from the retired entry.
  function retainLateAdmission(accepted, submitted) {
    if (!sessions || !accepted || typeof accepted.run_id !== 'string') return;
    const stored = sessions.loadRuntime();
    if (!sameRequest(stored, submitted)) return null;
    const next = acceptRun(stored.run || null, accepted);
    if (!next) return null;
    const retained = { ...stored, created_at: Date.now(), run: next, pending_create: submitted };
    sessions.saveRuntime(retained);
    return retained;
  }
  async function cancelLateAdmission(accepted, submitted, retained) {
    if (!retained || retained.close_requested !== true) return;
    try {
      const current = await request('POST', '/runs/' + encodeURIComponent(accepted.run_id) + '/cancel', {
        client_session_id: submitted.client_session_id,
        run_delivery_token: accepted.run_delivery_token,
        generation: accepted.generation
      }, { keepalive: true });
      // Do not erase a replacement entry's newer task. The cancelled admission
      // is removed only while this exact pending request is still current.
      const stored = sessions && sessions.loadRuntime();
      if (isTerminal(current && current.status) && sameRequest(stored, submitted)
        && stored.run && stored.run.run_id === accepted.run_id && stored.run.generation === accepted.generation) clearActive();
    } catch (_) {
      // The persisted close request remains recoverable; the next entry will
      // retry its signed cancellation instead of turning it into a new query.
    }
  }
  function runIsUnavailable(error) {
    return !!(error && error.responseKnown && ['AI_RUN_EXPIRED','AI_RUN_NOT_FOUND'].includes(error.reason));
  }
  function abandonUnavailableRun() {
    clearTimeout(pollTimer); clearActive(); run = null; pendingCreate = null; compatibilityExecuting = false;
    finishActiveRunStatus();
    clientDeliveryStartedAt=0;
    clarificationSubmittedId = null; guidanceSubmission = null; clearClarification(); if (sessions) sessions.clearPendingQuestion(conversation);
    cancelling = false; closeRequested = false;
    if (send) { send.disabled = false; send.textContent = '发送'; }
    syncWorkspaceActions();
    if (progress) progress.textContent = '上一次任务已失效，请重新提问。';
  }
  function presentationOf(answer) {
    const value = answer && answer.presentation;
    if (!value || value.version !== 1 || typeof value.headline !== 'string' || typeof value.period_label !== 'string'
      || !Array.isArray(value.facts) || !Array.isArray(value.notes)) return null;
    if (!value.facts.every(item => item && typeof item.label === 'string' && typeof item.value === 'string' && typeof item.unit === 'string')
      || !value.notes.every(note => typeof note === 'string')) return null;
    return value;
  }
  function sectionsOf(answer) {
    const sections = answer && answer.sections;
    if (!Array.isArray(sections) || sections.length < 2 || sections.length > 4) return null;
    const seen = new Set();
    for (const section of sections) {
      if (!section || typeof section.id !== 'string' || !/^q[1-4]$/.test(section.id) || seen.has(section.id)
        || typeof section.title !== 'string' || !section.title || section.title.length > 64
        || !section.answer || typeof section.answer !== 'object' || Array.isArray(section.answer)
        || Array.isArray(section.answer.sections)) return null;
      seen.add(section.id);
    }
    return sections;
  }
  function renderAnswer(answer, live = true, target = body) {
    if (!answer || typeof answer !== 'object') return;
    const sections = sectionsOf(answer);
    if (sections) {
      const collection = el('section', null, 'answer-presentation');
      sections.forEach(section => {
        const part = el('section', null, 'answer-section');
        part.appendChild(el('div', section.title, 'answer-section-title'));
        renderAnswer(section.answer, false, part);
        collection.appendChild(part);
      });
      target.appendChild(collection);
      return;
    }
    const presentation = presentationOf(answer);
    if (presentation) {
      const result = el('section', null, 'answer-presentation');
      if (presentation.headline) result.appendChild(el('div', presentation.headline, 'answer-headline'));
      if (presentation.facts.length) {
        const groups = []; const bySection = new Map();
        presentation.facts.forEach(fact => { const section = fact.section || ''; if (!bySection.has(section)) { const group = { section, facts: [] }; bySection.set(section, group); groups.push(group); } bySection.get(section).facts.push(fact); });
        groups.forEach(group => { const section = el('div', null, 'answer-section'); if (group.section) section.appendChild(el('div', group.section, 'answer-section-title')); const facts = el('div', null, 'answer-facts'); group.facts.forEach(fact => { const row = el('div', null, 'answer-fact'); row.append(el('span', fact.label, 'answer-fact-label'), el('strong', fact.value + fact.unit, 'answer-fact-value')); facts.appendChild(row); }); section.appendChild(facts); result.appendChild(section); });
      }
      if (presentation.period_label) result.appendChild(el('div', presentation.period_label, 'answer-period'));
      if (presentation.notes.length) { const notes = el('div', null, 'answer-notes'); presentation.notes.forEach(note => notes.appendChild(el('div', note, 'answer-note'))); result.appendChild(notes); }
      target.appendChild(result);
    } else if (answer.summary) target.appendChild(el('div', answer.summary, 'message'));
    (answer.cards || []).forEach(card => { const n = el('div', null, 'card'); n.appendChild(el('div', card.metric_name)); n.appendChild(el('div', String(card.display_value) + (card.unit || ''), 'value')); if (card.tooltip) { const details = el('details'); details.appendChild(el('summary', '统计口径')); if (typeof card.tooltip === 'string') details.appendChild(el('div', card.tooltip)); else [['summary',''],['include','包含：'],['exclude','不包含：'],['timing','统计时间：'],['note','说明：']].forEach(([key,label]) => { if (typeof card.tooltip[key] === 'string' && card.tooltip[key]) details.appendChild(el('div', label + card.tooltip[key])); }); n.appendChild(details); } if (card.period_label) n.appendChild(el('div', card.period_label, 'muted')); target.appendChild(n); });
    if (answer.table && Array.isArray(answer.table.columns) && Array.isArray(answer.table.rows)) { const table = el('table'); const tr = el('tr'); answer.table.columns.forEach(c => tr.appendChild(el('th',c.label))); table.appendChild(tr); answer.table.rows.forEach(row => { const r = el('tr'); answer.table.columns.forEach(c => r.appendChild(el('td',row[c.key] == null ? '-' : row[c.key]))); table.appendChild(r); }); target.appendChild(table); }
    if (live && answer.export && answer.export.file_ref && run && isTerminal(run.status)) { const source = { ...run }; const download = el('button', '下载 Excel'); download.onclick = async () => { download.disabled = true; try { const blob = await request('GET', '/runs/' + encodeURIComponent(source.run_id) + '/export', { client_session_id: clientSession, run_delivery_token: source.run_delivery_token, generation: source.generation }, {binary:true}); const url = URL.createObjectURL(blob); const link = el('a'); link.href = url; link.download = answer.export.filename || '经营数据.xlsx'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); } catch (_) { message('文件暂不可下载，请重新查询。','error'); } finally { download.disabled = false; } }; target.appendChild(download); }
  }
  // One button pair is attached to the completed Run, never to a mutable
  // conversation or its individual cards. The server snapshots its answer.
  function renderFeedback(target, questionText, displayedAnswer, delivery) {
    if (!delivery || delivery.feedback_available !== true || !delivery.run_id || !delivery.run_delivery_token
      || !['COMPLETED','PARTIAL_SUCCEEDED','FAILED'].includes(delivery.status || delivery.terminal_status)) return;
    const source = { ...delivery, client_session_id: delivery.client_session_id || clientSession };
    const conversationAtRender = conversation;
    const wrap = el('div', null, 'answer-feedback');
    const useful = el('button', '有用'); const notUseful = el('button', '没用');
    useful.type = notUseful.type = 'button';
    const note = el('span', '', 'answer-feedback-note');
    const buttons = { useful, not_useful: notUseful };
    let selected = delivery.feedback_rating || '';
    const paint = () => Object.entries(buttons).forEach(([rating, button]) => button.setAttribute('aria-pressed', String(selected === rating)));
    paint();
    Object.entries(buttons).forEach(([rating, button]) => { button.onclick = async () => {
      useful.disabled = notUseful.disabled = true;
      try {
        await request('POST', '/runs/' + encodeURIComponent(source.run_id) + '/feedback', {
          client_session_id: source.client_session_id, run_delivery_token: source.run_delivery_token,
          generation: source.generation, rating, question: questionText, displayed_answer: displayedAnswer
        });
        selected = rating; paint(); sessions.setFeedback(conversationAtRender, source.run_id, source.generation, rating);
        note.textContent = '已记录';
      } catch (error) { note.textContent = customerErrorMessage(error.message, '评价未保存，请稍后重试。'); }
      finally { useful.disabled = notUseful.disabled = false; }
    }; });
    wrap.append(useful, notUseful, note); target.appendChild(wrap);
  }
  function binding() { return { client_session_id: clientSession, run_delivery_token: run.run_delivery_token, generation: run.generation }; }
  // An Excel receipt belongs to one completed answer, not to the mutable
  // current Run. Polling it must never hold the composer or delay the answer.
  function startIndependentExport(source, target, createTask = true) {
    // The admin production compiler does not parse optional chaining in this
    // shared module; keep the same null guard usable by both web and mobile.
    if (!target || !source || !source.run_delivery_token) return;
    const proof = { client_session_id: source.client_session_id || clientSession,
      run_delivery_token: source.run_delivery_token, generation: source.generation };
    const card = el('div', 'Excel 正在排队…', 'card muted'); target.appendChild(card);
    const path = '/runs/' + encodeURIComponent(source.run_id);
    let statusFailures = 0;
    // A transient status-request failure does not make a completed workbook
    // fail. Keep this receipt independent from the next question and retry a
    // bounded number of times without submitting another export task.
    const pollStatus = async () => { if (disposed || !card.isConnected) return;
      try { const receipt = await request('GET', path + '/export-status', proof); statusFailures = 0; updateFile(receipt); }
      catch (_) {
        card.textContent = 'Excel 状态暂不可用，正在重试…';
        if (++statusFailures <= 10) setTimeout(pollStatus, 3000);
        else card.textContent = 'Excel 状态暂不可用，请稍后刷新查看。';
      }
    };
    const updateFile = receipt => {
      if (disposed || !card.isConnected) return;
      if (receipt.status === 'succeeded') {
        card.textContent = 'Excel 已生成。';
        const download = el('button', '下载 Excel');
        download.onclick = async () => { download.disabled = true; try {
          const blob = await request('GET', path + '/export', proof, { binary:true });
          const url = URL.createObjectURL(blob), link = el('a'); link.href = url;
          link.download = receipt.filename || '经营数据.xlsx'; link.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (_) { message('文件暂不可下载，请重试。', 'error', card); }
        finally { download.disabled = false; } };
        card.appendChild(download); return;
      }
      if (['failed','cancelled','expired'].includes(receipt.status)) { card.textContent = 'Excel 生成失败，问答结果不受影响。'; return; }
      if (receipt.status === 'not_requested') {
        card.textContent = '完整明细尚未生成 Excel。';
        const generate = el('button', '生成 Excel');
        generate.onclick = async () => { generate.disabled = true; card.textContent = 'Excel 正在排队…';
          try { updateFile(await request('POST', path + '/export', proof)); }
          catch (_) { card.textContent = 'Excel 暂无法生成，请稍后重试。'; }
        };
        card.appendChild(generate); return;
      }
      card.textContent = receipt.status === 'running' ? 'Excel 正在生成…' : 'Excel 正在排队…';
      setTimeout(pollStatus, 1500);
    };
    if (createTask) request('POST', path + '/export', proof).then(updateFile).catch(() => { if (card.isConnected) card.textContent = 'Excel 暂无法生成，问答结果不受影响。'; });
    else pollStatus();
  }
  // The create response is authoritative for that specific Run. Bootstrap is
  // only a capability snapshot and can change while a request is in flight.
  function needsCompatibilityExecution(value) { return value && (value.execution_mode === 'compatibility' || (value.execution_mode == null && boot.async_execution !== true)); }
  async function update(next) {
    if (disposed) return;
    const previous = run; run = acceptRun(run, next); if (!run || run === previous) return;
    clearTimeout(pollTimer);
    const serverProgress = typeof run.progress === 'string' ? run.progress : (run.progress && run.progress.message) || '正在处理';
    if (!setActiveRunState('正在思考')) progress.textContent = serverProgress;
    if (isTerminal(run.status)) {
      // Capture a late submitted value before the terminal projection removes
      // its controls. Drafts stay in this device's existing 24-hour transcript.
      const expiredChoices = guidanceSubmission && guidanceSubmission.choices || guidanceDraft && guidanceDraft.choices || {};
      clarificationSubmittedId = null; guidanceSubmission = null; clearClarification();
      clearTimeout(pollTimer); cancelling = false; send.disabled = false;
      if (['COMPLETED', 'PARTIAL_SUCCEEDED'].includes(run.status) && run.answer) {
        // A recovered terminal projection must render the original customer
        // wording before its answer. Never leave the customer with an orphaned
        // answer merely because the page changed while the task was running.
        const deliveredQuestion = ensureQuestionVisible();
        const elapsedSeconds = completedElapsedSeconds();
        finishActiveRunStatus(); appendCompletedElapsed(activeConversationTurn(), elapsedSeconds);
        const answerTurn = activeConversationTurn() || body;
        renderAnswer(run.answer, true, answerTurn);
        renderFeedback(answerTurn, deliveredQuestion, run.answer.summary || '', { ...run, client_session_id: clientSession });
        // The server decides from the verified business table whether a full
        // file is required. The existing manual Excel choice shares one
        // idempotent task and never delays answer delivery.
        if ((exportRequested || run.answer.auto_export_required === true) && run.status === 'COMPLETED') startIndependentExport({ ...run }, answerTurn);
        exportRequested = false;
        reportVisibleDeliveryAfterPaint(run);
        const text = run.answer.summary || (run.answer.cards || []).map(c => `${c.metric_name}：${c.display_value}${c.unit || ''}`).join('\n');
        try { sessions.append(conversation, deliveredQuestion, text, run.answer, { ...run, client_session_id: clientSession },
          { elapsedSeconds, topicBoundary: run.topic_boundary }); } catch (_) { message('本机历史保存失败，本次结果仍可查看。', 'error', activeConversationTurn() || body); }
        completeActiveQuestion();
      } else {
        // The terminal message is already appended to the conversation.  Do
        // not leave the same failure in the footer status as a second visible
        // answer; it makes a controlled refusal look like two responses. A
        // refusal is still a complete customer turn, so restore the original
        // wording before showing it as well.
        const deliveredQuestion = ensureQuestionVisible();
        const safeGuidance = run.status === 'FAILED' ? safeFailureGuidance(run.failure_guidance, run.reason, run) : null;
        const terminalMessage = run.status === 'CANCELLED' ? '已取消' : safeGuidance
          ? (safeGuidance.question || '这次没能给出可靠结果。请选择一种方式继续。')
          : customerErrorMessage(run.message, '本次未能完成，请重新提问。');
        const elapsedSeconds = completedElapsedSeconds();
        finishActiveRunStatus(); appendCompletedElapsed(activeConversationTurn(), elapsedSeconds); progress.textContent = '';
        const answerTurn = activeConversationTurn() || body;
        message(terminalMessage, '', answerTurn);
        renderFeedback(answerTurn, deliveredQuestion, terminalMessage, { ...run, client_session_id: clientSession });
        const failurePresentation = { summary: terminalMessage, terminal_status: run.status };
        if (safeGuidance) {
          failurePresentation.failure_guidance = safeGuidance;
        }
        if (safeGuidance && safeGuidance.kind === 'clarification_expired' && run.clarification) {
          failurePresentation.expired_clarification = run.clarification;
          failurePresentation.clarification_restart = { conversation_id: conversation,
            source: { run_id: run.run_id, ...deliveryBinding(run), clarification_id: run.clarification.id } };
          failurePresentation.clarification_choices = expiredChoices;
          renderFailureCard(deliveredQuestion, failurePresentation, answerTurn);
        } else if (safeGuidance && (safeGuidance.retry_original === true || Array.isArray(safeGuidance.options))) {
          // A remounted task can retain its Run and visible original question
          // after the admission envelope was retired. No newer turn can enter
          // this active conversation, so its stored transcript is the same
          // predecessor; the server still validates any opaque result ref.
          const predecessor = pendingCreate || { history: sessions.history(conversation), context_ref: sessions.contextRef(conversation) };
          failurePresentation.recovery_origin = { conversation_id: conversation, history: predecessor.history,
            context_ref: predecessor.context_ref || null, created_at: Date.now() };
          renderFailureCard(deliveredQuestion, failurePresentation, answerTurn);
        }
        // Keep a complete customer-visible turn after refresh.  It is marked
        // non-contextual so a previous failure never becomes an instruction
        // or a claimed business fact for the next model request.
        try { sessions.append(conversation, deliveredQuestion, terminalMessage,
          failurePresentation, { ...run, client_session_id: clientSession },
          { contextEligible: false, elapsedSeconds, topicBoundary: run.topic_boundary });
        } catch (_) { message('本机历史保存失败，本次结果仍可查看。', 'error', activeConversationTurn() || body); }
        completeActiveQuestion();
        clientDeliveryStartedAt=0;
        if (sessions) sessions.clearPendingQuestion(conversation);
      }
      pendingCreate = null; clearActive(); syncWorkspaceActions(); return;
    }
    if (run.status === 'WAITING_CLARIFICATION' && run.clarification && run.clarification_rejected === true && clarificationSubmittedId === run.clarification.id) {
      clarificationSubmittedId = null;
      progress.textContent = '所选条件格式不完整，请检查后重新确认。';
    }
    if (run.status === 'WAITING_CLARIFICATION' && run.clarification && !cancelling && clarificationSubmittedId !== run.clarification.id) renderClarification(run.clarification);
    else { clearClarification(); pollTimer = setTimeout(poll, 1000); }
    syncWorkspaceActions();
    persistActive();
  }
  async function poll() {
    if (disposed || !run || isTerminal(run.status)) return;
    const source = run;
    try {
      const current = await request('GET', '/runs/' + encodeURIComponent(source.run_id), binding(source));
      if (disposed || run !== source) return;
      await update(current);
      if (!disposed && run === source && run && !isTerminal(run.status) && (run.status !== 'WAITING_CLARIFICATION' || cancelling || clarificationSubmittedId === (run.clarification && run.clarification.id))) pollTimer = setTimeout(poll, 1000);
    }
    catch (error) {
      if (disposed || run !== source) return;
      if (runIsUnavailable(error)) { abandonUnavailableRun(); return; }
      const retryMessage = cancelling ? '暂未确认取消结果，请检查网络。' : '正在重新确认任务状态。';
      if (!setActiveRunState(retryMessage) && progress) progress.textContent = retryMessage;
      persistActive(); pollTimer = setTimeout(poll, 3000);
    }
  }
  async function executeCompatibility(source, submitted) {
    if (compatibilityExecuting || !submitted || disposed) return;
    compatibilityExecuting = true;
    try {
      const current = await request('POST', '/runs/' + encodeURIComponent(source.run_id) + '/execute', {
        client_session_id: clientSession, run_delivery_token: source.run_delivery_token, generation: source.generation,
        question: submitted.question, history: submitted.history, output_format: submitted.output_format,
        ...(submitted.context_ref ? { context_ref: submitted.context_ref } : {})
      });
      if (disposed || !run || run.run_id !== source.run_id || run.generation !== source.generation) return;
      await update(current);
    } catch (error) {
      if (disposed || !run || run.run_id !== source.run_id || run.generation !== source.generation || isTerminal(run.status)) return;
      if (runIsUnavailable(error)) { abandonUnavailableRun(); return; }
      progress.textContent = error.responseKnown ? customerErrorMessage(error.message) : '请求结果暂未确认。';
      clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000);
    } finally {
      compatibilityExecuting = false;
    }
  }
  // Schedule one status read at the server's cumulative deadline. Browser
  // clocks do not decide expiry, and there is no polling while a user types.
  function scheduleClarificationExpiry(source) {
    clearTimeout(clarificationTimer); clarificationTimer = null;
    if (presentation !== 'workspace' || !source || source.status !== 'WAITING_CLARIFICATION'
      || !Number.isSafeInteger(source.clarification_deadline_at) || !Number.isSafeInteger(source.server_time)) return;
    clarificationTimer = setTimeout(() => {
      if (disposed || !run || run.run_id !== source.run_id || run.status !== 'WAITING_CLARIFICATION') return;
      if (clarificationArea) clarificationArea.querySelectorAll('button,input,select,textarea').forEach(control => { control.disabled = true; });
      void poll();
    }, Math.max(0, source.clarification_deadline_at - source.server_time) + 100);
  }
  function renderClarification(c, restart = null, restoredChoices = {}, target = null) {
    try { validateClarification(c, activeGuidanceSchema); } catch (error) { clearClarification(); progress.textContent = error.message; return; }
    if (c.schema_version === GUIDANCE_SCHEMA) activeGuidanceSchema = GUIDANCE_SCHEMA;
    const key = clarificationKey(c);
    // 人工确认不属于处理中；暂停计时并隐藏思考提示，重复投影也保持等待状态。
    clearInterval(elapsedTimer); elapsedTimer = null;
    if (activeRunStatus) activeRunStatus.area.hidden = true;
    if (clarificationArea && clarificationArea.isConnected && clarificationId === key) return;
    clearClarification();
    const area = el('div', null, 'card guidance-card'); area.dataset.guidance = 'step';
    clarificationArea = area; clarificationId = key;
    const heading = el('div', null, 'guidance-heading'); heading.appendChild(el('span', restart ? '继续原查询' : '请确认'));
    const cancel = el('button', '取消查询', 'guidance-cancel'); cancel.onclick = () => { if (!cancel.disabled) void stop(); }; if (!restart) heading.appendChild(cancel); area.appendChild(heading);
    if (c.schema_version === GUIDANCE_SCHEMA) {
      area.appendChild(el('div', `第 ${c.round_no} 步`, 'muted'));
      if (c.confirmed_summary.length) {
        const summary = el('div', null, 'muted'); summary.dataset.guidance = 'summary'; summary.appendChild(el('div', '已确认条件'));
        c.confirmed_summary.forEach(item => summary.appendChild(el('div', item.label + '：' + item.value))); area.appendChild(summary);
      }
    }
    const form = el('div'); area.appendChild(form);
    let pendingSubmission = !restart && validGuidanceSubmission(guidanceSubmission) && guidanceSubmission.clarification_id === c.id
      && (c.schema_version !== GUIDANCE_SCHEMA || (guidanceSubmission.schema_version === GUIDANCE_SCHEMA
        && guidanceSubmission.intent_revision === c.intent_revision && guidanceSubmission.step_revision === c.step_revision))
      ? guidanceSubmission : null;
    let submitting = false, controls = [], reviseId = pendingSubmission && pendingSubmission.revise_clarification_id || null;
    const editing = [];
    function current() {
      return !disposed && clarificationArea === area && clarificationId === key && !cancelling
        && (restart ? conversation === restart.conversation_id && !pendingCreate && (!run || isTerminal(run.status))
          : run && run.status === 'WAITING_CLARIFICATION');
    }
    function setDisabled(value) { cancel.disabled = value; controls.forEach(item => item.elements.forEach(control => { control.disabled = value; })); editing.forEach(button => { button.disabled = value; }); }
    function renderFields(fields, initial, title) {
      if (submitting) return;
      form.textContent = ''; controls = []; form.appendChild(el('div', title || '请选择本次要查询的内容', 'guidance-question'));
      const hint = el('div', '', 'error'); hint.setAttribute('role', 'alert');
      fields.forEach(field => {
        const label = el('div', field.label, 'guidance-label'); form.appendChild(label);
        if (field.type === 'date') {
          const control = el('input'); control.type = 'date'; control.required = true; control.setAttribute('aria-label', field.label);
          if (typeof field.min === 'string') control.min = field.min;
          if (typeof field.max === 'string') control.max = field.max;
          control.value = typeof initial[field.key] === 'string' ? initial[field.key] : '';
          form.appendChild(control); controls.push({ field, elements: [control], read: () => control.value });
        } else if (c.schema_version === GUIDANCE_SCHEMA && fields.length === 1 && field.key === 'semantic_reply') {
          const group = el('div'); group.setAttribute('role', 'radiogroup'); group.setAttribute('aria-label', field.label);
          const name = 'guidance-' + newId(); const inputs = [];
          // Only semantic_reply offers a free-text correction. Server-owned
          // period and object cards must expose exactly their signed options;
          // adding a synthetic "other" choice would submit an invalid value.
          const executable = field.options.filter(option => option.action !== 'stop' && option.value !== '__supplement__');
          const recommended = (executable.length <= 3 ? executable : executable.filter(option => option.recommended === true)).slice(0, 3);
          const supplementOption = field.options.find(option => option.value === '__supplement__');
          if (recommended.length === 0 && executable.length > 3) {
            // No model-ranked suggestion is evidence for highlighting the
            // first catalogue entries. Show the registered choices neutrally.
            const control = el('select'); control.setAttribute('aria-label', field.label);
            const empty = el('option', '请选择已登记指标或补充问题'); empty.value = ''; control.appendChild(empty);
            executable.forEach(option => { const item = el('option', option.label); item.value = option.value; control.appendChild(item); });
            const other = el('option', supplementOption ? supplementOption.label : '我来补充描述'); other.value = '__supplement__'; control.appendChild(other);
            control.value = typeof initial[field.key] === 'string' ? initial[field.key] : '';
            const supplementText = el('textarea', null, 'guidance-supplement'); supplementText.maxLength = 1000;
            supplementText.setAttribute('aria-label', '补充描述'); supplementText.placeholder = '请补充或改写您的问题';
            supplementText.hidden = control.value !== '__supplement__';
            supplementText.value = typeof initial.supplement_text === 'string' ? initial.supplement_text : '';
            control.addEventListener('change', () => { supplementText.hidden = control.value !== '__supplement__'; });
            form.append(control, supplementText);
            controls.push({ field, elements: [control, supplementText], supplementText, read: () => control.value });
            return;
          }
          recommended.forEach((option, index) => { const optionLabel = el('label', null, 'guidance-option'); const control = el('input'); control.type = 'radio'; control.name = name; control.value = option.value; control.checked = initial[field.key] === option.value; optionLabel.append(control, el('span', String(index + 1), 'guidance-number'), el('span', option.label, 'guidance-option-label')); group.appendChild(optionLabel); inputs.push(control); });
          const supplementLabel = el('label', null, 'guidance-option');
          const supplementChoice = el('input'); supplementChoice.type = 'radio'; supplementChoice.name = name; supplementChoice.value = '__supplement__';
          supplementLabel.append(supplementChoice, el('span', String(inputs.length + 1), 'guidance-number'), el('span', supplementOption ? supplementOption.label : '我来补充描述', 'guidance-option-label'));
          group.appendChild(supplementLabel); inputs.push(supplementChoice);
          const supplementText = el('textarea', null, 'guidance-supplement'); supplementText.maxLength = 1000; supplementText.setAttribute('aria-label', '补充描述'); supplementText.placeholder = '请补充或改写您的问题'; supplementChoice.checked = initial[field.key] === '__supplement__';
          supplementText.hidden = !supplementChoice.checked;
          supplementText.value = typeof initial.supplement_text === 'string' ? initial.supplement_text : '';
          supplementChoice.addEventListener('change', () => { supplementText.hidden = false; });
          inputs.slice(0, -1).forEach(control => control.addEventListener('change', () => { supplementText.hidden = true; }));
          form.append(group, supplementText);
          controls.push({ field, elements: [...inputs, supplementText], supplementText, read: () => { const selected = inputs.find(control => control.checked); return selected ? selected.value : ''; } });
        } else if (field.options.length <= 3) {
          const group = el('div'); group.setAttribute('role', 'radiogroup'); group.setAttribute('aria-label', field.label);
          const name = 'guidance-' + newId(); const inputs = [];
          field.options.forEach((option, index) => { const optionLabel = el('label', null, 'guidance-option'); const control = el('input'); control.type = 'radio'; control.name = name; control.value = option.value; control.checked = initial[field.key] === option.value; optionLabel.append(control, el('span', String(index + 1), 'guidance-number'), el('span', option.label, 'guidance-option-label')); group.appendChild(optionLabel); inputs.push(control); });
          form.appendChild(group); controls.push({ field, elements: inputs, read: () => { const selected = inputs.find(control => control.checked); return selected ? selected.value : ''; } });
        } else {
          const control = el('select'); control.setAttribute('aria-label', field.label); const empty = el('option', '请选择'); empty.value = ''; control.appendChild(empty);
          field.options.forEach(option => { const o = el('option', option.label); o.value = option.value; control.appendChild(o); });
          control.value = typeof initial[field.key] === 'string' ? initial[field.key] : ''; form.appendChild(control); controls.push({ field, elements: [control], read: () => control.value });
        }
      });
      const readChoices = () => {
        const choices = {}; controls.forEach(item => { choices[item.field.key] = item.read();
          if (item.supplementText && choices[item.field.key] === '__supplement__') choices.supplement_text = item.supplementText.value.trim(); });
        return choices;
      };
      // Preserve what was typed across refresh or an expiry response, without
      // treating an unfinished draft as an accepted semantic condition.
      form.oninput = form.onchange = () => {
        if (submitting) return;
        guidanceDraft = { id: c.id, choices: readChoices() };
        if (restart && sessions) {
          // An expired card is immutable history, but its local input draft
          // can still be edited. Keep it in the same retained conversation.
          const saved = sessions.load(); const session = saved.find(item => item.id === conversation);
          const row = session && session.rounds.find(item => item.run_id === restart.source.run_id);
          if (row && row.presentation) { row.presentation.clarification_choices = guidanceDraft.choices; sessions.save(saved); }
        } else persistActive();
      };
      form.appendChild(hint);
      const confirm = el('button', restart ? '继续原查询' : c.schema_version === GUIDANCE_SCHEMA ? '确认并继续' : '确认查询', 'primary guidance-confirm');
      confirm.onclick = async () => {
        if (!current() || submitting) return;
        if (!pendingSubmission) {
          const choices = readChoices();
          if (controls.some(item => !choices[item.field.key] || item.elements.some(control => control.type === 'date' && typeof control.checkValidity === 'function' && !control.checkValidity()))) { hint.textContent = '请完成当前选择或填写有效日期。'; return; }
          const supplement = controls.find(item => choices[item.field.key] === '__supplement__');
          if (supplement) {
            const detail = supplement.supplementText && supplement.supplementText.value.trim();
            if (!detail) { hint.textContent = '请补充您想查询的内容。'; return; }
            // The same revision-protected confirmation carries only the new
            // wording. The server owns original conditions and merges changes;
            // the browser must not cancel, concatenate or recreate the query.
            choices.supplement_text = detail;
          }
          if (restart) {
            // A fresh admission carries only opaque source proof and choices.
            // The server restores private meaning and rechecks current rights;
            // no client text concatenation or executable-plan replay occurs.
            recoveryDraft = { conversation_id: conversation, history: [], context_ref: null,
              clarification_restart: { ...restart.source, choices } };
            input.value = restart.question; clearClarification(); void submitQuestion(); return;
          }
          // The platform build chain does not transpile this shared module;
          // read the selected recovery action without optional-chain syntax.
          const selected = controls.length === 1 && controls[0].field.options.find(option =>
            option.value === choices[controls[0].field.key]);
          const action = selected && selected.action;
          if (['edit_original', 'retry_original', 'new_conversation'].includes(action)) {
            // Recovery actions are client commands, not metric choices. End
            // the paused Run before editing or resubmitting the same question.
            const original = question;
            const origin = { conversation_id: conversation, history: sessions.history(conversation),
              context_ref: sessions.contextRef(conversation) };
            await stop();
            if (!run || run.status !== 'CANCELLED') return;
            if (action === 'new_conversation') { if (freshButton) freshButton.click(); return; }
            recoveryDraft = origin; input.value = original; input.focus();
            if (action === 'retry_original') void submitQuestion();
            return;
          }
          pendingSubmission = { ...binding(), ...clarificationSubmission(c, choices, newId(), reviseId) };
          guidanceSubmission = pendingSubmission; guidanceDraft = { id: c.id, choices }; persistActive();
        }
        const submitted = pendingSubmission; submitting = true; confirm.disabled = true; setDisabled(true); hint.textContent = '';
        try {
          // This click begins a new execution segment after the customer has
          // made a choice.  Do not include time spent reading the form in the
          // answer latency for that continuation.
          clientDeliveryStartedAt=Date.now(); setActiveRunState('正在继续查询'); persistActive();
          const accepted = await request('POST', '/runs/' + encodeURIComponent(run.run_id) + '/clarify', submitted);
          if (disposed || !current()) return;
          // The durable acknowledgement legitimately still projects the old
          // clarification.  Mark it locally before update so polling resumes
          // until the queued worker publishes a new step or final answer.
          clarificationSubmittedId = c.id;
          pendingSubmission = null; guidanceSubmission = null;
          await update(accepted);
          // A late selection may return an expired run, or execution may have
          // finished already. Preserve that final message instead of restarting
          // polling and incorrectly telling the customer to keep waiting.
          if (!run || isTerminal(run.status)) return;
          // A worker can already have published the next clarification in the
          // acknowledgement.  Do not clear that newer step after update()
          // rendered it; only hide the old controls while the same step waits.
          if (run && run.status === 'WAITING_CLARIFICATION' && run.clarification && run.clarification.id !== c.id) {
            progress.textContent = '已接纳选择，请继续确认下一项。';
            persistActive();
            return;
          }
          clearClarification(); progress.textContent = '已接纳选择，正在继续查询。';
          persistActive();
          clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000);
        } catch (error) {
          if (current()) {
            clearInterval(elapsedTimer); elapsedTimer = null;
            if (activeRunStatus) activeRunStatus.area.hidden = true;
            // An uncertain send is retried byte-for-byte with the same ID;
            // changing choices before confirmation could create two answers.
            if (error.responseKnown) { pendingSubmission = null; guidanceSubmission = null; setDisabled(false); }
            confirm.disabled = false; confirm.textContent = error.responseKnown ? '确认并继续' : '重试确认';
            hint.textContent = error.responseKnown ? customerErrorMessage(error.message) : '提交结果暂未确认，请重试确认同一次选择。';
            persistActive();
          }
        } finally { submitting = false; }
      };
      form.appendChild(confirm);
    }
    const draft = guidanceDraft && guidanceDraft.id === c.id ? guidanceDraft.choices : restoredChoices;
    guidanceDraft = { id: c.id, choices: draft };
    renderFields(c.fields, pendingSubmission ? pendingSubmission.choices : draft, c.question);
    if (!restart && c.schema_version === GUIDANCE_SCHEMA && (c.revisable_steps || []).length) {
      const details = el('details'); details.appendChild(el('summary', '修改已确认条件'));
      c.revisable_steps.forEach(step => { const button = el('button', step.question); button.onclick = () => { if (!current() || submitting || pendingSubmission) return; reviseId = step.id; renderFields(step.fields, step.choices, '修改：' + step.question); }; details.appendChild(button); editing.push(button); });
      const back = el('button', '返回当前问题'); back.onclick = () => { if (!current() || submitting || pendingSubmission) return; reviseId = null; renderFields(c.fields, {}, c.question); }; details.appendChild(back); editing.push(back); area.appendChild(details);
    }
    // Clarification belongs to the customer turn that caused it. Keeping it
    // there avoids an orphaned form at the end of the transcript and preserves
    // the reader's manual scroll position while the server is deciding.
    (target || activeConversationTurn() || body).appendChild(area);
    if (!restart) scheduleClarificationExpiry(run);
    // 仅新确认步骤滚到卡片，不在状态轮询中反复抢走用户的滚动位置。
    if (typeof area.scrollIntoView === 'function') area.scrollIntoView({ block: 'nearest', behavior: 'auto' });
    // Restore an interrupted submission only when it still targets the exact
    // server-projected step.  The server replays the stored result for the
    // same id and never lets it overwrite a newer choice.
    if (pendingSubmission && clarificationSubmittedId !== c.id && run.clarification_rejected !== true) void confirm.onclick();
  }
  async function submitQuestion() {
    if (!boot.enabled) { progress.textContent = customerErrorMessage(boot.disabled_reason, '当前账号暂不能使用魔核 AI，请联系管理员检查配置或权限。'); return; }
    if (!pendingCreate && !input.value.trim()) return;
    if (admissionInFlight || (run && !isTerminal(run.status))) return;
    clearTimeout(admissionRetryTimer); admissionRetryTimer = null;
    const restoringClosedRequest = pendingCreate !== null && closeRequested;
    question = pendingCreate ? pendingCreate.question : input.value.trim(); send.disabled = true; closeRequested = restoringClosedRequest;
    if (!pendingCreate) {
      // The elapsed label starts at the customer action, not queue admission,
      // so it honestly covers every visible wait before a first answer.
      if (!clientDeliveryStartedAt) clientDeliveryStartedAt = Date.now();
      showActiveQuestion(); activeGuidanceSchema = boot.guidance_schema_version || null;
      // Keep the question on the screen-only path; the selected Excel file is
      // requested only after the answer has been published and shown.
      exportRequested = format.value === 'screen_and_xlsx';
      const source = recoveryDraft && recoveryDraft.conversation_id === conversation ? recoveryDraft : null;
      pendingCreate = { client_request_id: newId(), conversation_id: conversation, client_session_id: clientSession,
        window_token: boot.window_token, question, history: source ? source.history : sessions.history(conversation), output_format: 'screen' };
      if (activeGuidanceSchema === GUIDANCE_SCHEMA) pendingCreate.guidance_schema_version = GUIDANCE_SCHEMA;
      const contextRef = source ? source.context_ref : sessions.contextRef(conversation); if (contextRef) pendingCreate.context_ref = contextRef;
      if (source && source.clarification_restart) pendingCreate.clarification_restart = source.clarification_restart;
      recoveryDraft = null; guidanceDraft = null; guidanceSubmission = null;
      // The workspace header is customer-facing context, so show the actual
      // submitted wording instead of a generic title as soon as it is durable.
      updateWorkspaceTitle({ rounds: [{ question }] }); renderWorkspaceHistory();
    }
    syncWorkspaceActions();
    if (!clientDeliveryStartedAt) clientDeliveryStartedAt=Date.now();
    if (sessions) sessions.setPendingQuestion(conversation, question);
    input.value = ''; if (!setActiveRunState(admissionRetryCount ? '正在重新确认请求' : '正在思考')) progress.textContent = '正在接纳请求'; run = null; persistActive();
    admissionInFlight = true;
    try {
      const submitted = pendingCreate;
      const accepted = await request('POST', '/runs', submitted);
      if (disposed) {
        const retained = retainLateAdmission(accepted, submitted);
        void cancelLateAdmission(accepted, submitted, retained);
        return;
      }
      if (accepted && accepted.accepted === false) {
        admissionRetryCount = 0;
        recordAdmissionRefusal(submitted, customerErrorMessage(accepted.message, '当前使用人数较多，请稍后再问。'), accepted.failure_guidance, accepted.reason, accepted); return;
      }
      admissionRetryCount = 0;
      await update(accepted); persistActive();
      // A page replacement leaves the customer task alive.  An explicit close
      // is different: once the delayed admission is known, cancel that Run.
      if (closeRequested) { await stop(); return; }
      if (disposed) { persistActive(); return; }
      // New servers acknowledge only after durable queue acceptance. Older
      // staged servers keep the compatible /execute path until their worker
      // barrier is explicitly enabled.
      if (needsCompatibilityExecution(run) && !isTerminal(run.status)) void executeCompatibility({ ...run }, submitted);
    } catch (error) {
      if (disposed) return;
      if (runIsUnavailable(error)) { abandonUnavailableRun(); return; }
      progress.textContent = error.responseKnown ? customerErrorMessage(error.message) : '请求结果暂未确认。';
      if (run) { persistActive(); clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000); }
      else {
        if (error.responseKnown) { admissionRetryCount = 0; recordAdmissionRefusal(pendingCreate, customerErrorMessage(error.message), null, error.message); return; }
        persistActive();
        // A lost acknowledgement is not a failed query. Replay the immutable
        // admission key at most twice; the server deduplicates it. If transport
        // remains unknown, stop the visible thinking clock and offer manual
        // confirmation of that same request instead of waiting indefinitely.
        if (!closeRequested && admissionRetryCount < 2) {
          const submitted = pendingCreate;
          admissionRetryCount += 1;
          if (!setActiveRunState('正在重新确认请求')) progress.textContent = '正在重新确认请求';
          admissionRetryTimer = setTimeout(() => {
            admissionRetryTimer = null;
            if (!disposed && !closeRequested && pendingCreate === submitted && !run) void submitQuestion();
          }, admissionRetryCount * 1000);
        } else {
          finishActiveRunStatus();
          progress.textContent = '请求是否已接收暂未确认，请点发送重新确认同一问题。';
          send.disabled = false; send.textContent = '重试确认'; input.value = question;
        }
      }
      syncWorkspaceActions();
    } finally {
      admissionInFlight = false;
    }
  }
  async function resumeActive(record) {
    conversation = record.conversation_id; question = questionFromRecord(record) || persistedQuestion(record.conversation_id);
    run = record.run || null; pendingCreate = record.pending_create || null;
    clientDeliveryStartedAt=Number.isSafeInteger(record.client_delivery_started_at) && record.client_delivery_started_at>0 ? record.client_delivery_started_at : 0;
    showActiveQuestion();
    clarificationSubmittedId = typeof record.queued_clarification_id === 'string' ? record.queued_clarification_id : null;
    guidanceSubmission = validGuidanceSubmission(record.guidance_submission) ? record.guidance_submission : null;
    guidanceDraft = record.guidance_draft && typeof record.guidance_draft.id === 'string'
      && record.guidance_draft.choices && typeof record.guidance_draft.choices === 'object' ? record.guidance_draft : null;
    closeRequested = record.close_requested === true;
    if (!run) {
      if (!pendingCreate) { clearActive(); return; }
      // The durable receipt is bound to the original device session.  `open`
      // has already restored that session before bootstrap; only its short
      // lived window proof is refreshed.  Reusing the immutable create body
      // lets the server replay the original Run instead of admitting a second
      // task after a page refresh.
      pendingCreate.window_token = boot.window_token;
      input.value = question; persistActive(); await submitQuestion(); return;
    }
    try {
      const before = run; const current = await request('GET', '/runs/' + encodeURIComponent(run.run_id), binding());
      if (disposed) return;
      await update(current);
      // A reload commonly receives the same server projection saved before
      // navigation. Keep the version guard, but still resume its polling or
      // its current clarification instead of treating it as an idle task.
      if (run === before && current && typeof current.progress === 'string') progress.textContent = current.progress;
      if (run && !isTerminal(run.status) && !closeRequested) {
        if (run.status === 'WAITING_CLARIFICATION' && run.clarification && !cancelling && clarificationSubmittedId !== run.clarification.id) { renderClarification(run.clarification); scheduleClarificationExpiry(current); }
        else { clearClarification(); clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000); }
      }
      if (closeRequested) { await stop(); return; }
      // In staged synchronous mode the browser owns the execution trigger.
      // Keep the original immutable create input until terminal state so a
      // refresh between admission and /execute can safely continue the Run.
      if (needsCompatibilityExecution(run) && !isTerminal(run.status)) {
        if (pendingCreate) void executeCompatibility({ ...run }, pendingCreate);
        else if (['READY','RECEIVED'].includes(run.status)) { abandonUnavailableRun(); return; }
      }
    } catch (error) {
      if (disposed) return;
      if (runIsUnavailable(error)) { abandonUnavailableRun(); return; }
      progress.textContent = '连接暂时中断，正在重新确认任务状态。'; persistActive(); clearTimeout(pollTimer); pollTimer = setTimeout(poll, 3000);
    }
  }
  async function stop() {
    closeRequested = true;
    persistActive();
    if (!run || isTerminal(run.status)) return;
    cancelling = true; if (!setActiveRunState('正在取消')) progress.textContent = '正在取消';
    syncWorkspaceActions();
    if (clarificationArea) clarificationArea.querySelectorAll('button,input,select').forEach(control => { control.disabled = true; });
    try { const current = await request('POST', '/runs/' + encodeURIComponent(run.run_id) + '/cancel', binding(), { keepalive: true }); if (disposed) return; await update(current); } catch (_) { if (disposed) return; if (!setActiveRunState('暂未确认取消结果，请检查网络。')) progress.textContent = '暂未确认取消结果，请检查网络。'; }
    if (!disposed && run && !isTerminal(run.status)) { clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000); }
  }
  async function open() {
    if (panel) { panel.hidden = false; return; }
    let record;
    try {
      boot = await request('GET', '/bootstrap', {client_session_id:clientSession});
      if (!boot.enabled && !boot.can_configure) return;
      sessions = new DeviceSessions(storage, boot.identity_key); record = activeRecord();
      // The Run token is bound to its device session. Reuse that local session
      // only after finding an active record for this same signed-in identity.
      if (record && record.session_id !== clientSession) {
        clientSession = record.session_id;
        boot = await request('GET', '/bootstrap', {client_session_id:clientSession});
        sessions = new DeviceSessions(storage, boot.identity_key); record = activeRecord();
      }
      conversation = record ? record.conversation_id : sessions.create().id;
    } catch (_) { return; }
    const workspace = presentation === 'workspace';
    panel = el('section', null, 'panel' + (workspace ? ' workspace' : '')); panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', '魔核 AI');
    const history = el('button', '历史'); const fresh = el('button', '新对话'); const close = el('button', workspace ? '×' : '关闭');
    freshButton = fresh;
    let main = panel;
    if (workspace) {
      const aside = el('aside', null, 'workspace-aside');
      // Brand copy is display-only; it does not enter conversation context or query input.
      const brand = el('div', '魔核AI 1.0', 'workspace-brand');
      brand.appendChild(el('small', '够智能、够准确、够便捷', 'workspace-brand-tagline'));
      aside.appendChild(brand);
      fresh.className = 'workspace-new'; aside.appendChild(fresh);
      aside.appendChild(el('div', '近期对话', 'workspace-recent-label'));
      workspaceHistory = el('div', null, 'workspace-history'); aside.appendChild(workspaceHistory);
      aside.appendChild(el('div', '会话仅在本机保留 24 小时', 'workspace-retention'));
      main = el('div', null, 'workspace-main');
      const top = el('div', null, 'workspace-top'); workspaceTitle = el('div', conversationTitle(record ? sessions.load().find(item => item.id === conversation) : null), 'workspace-title'); close.className = 'workspace-close'; close.setAttribute('aria-label', '关闭魔核 AI'); top.append(workspaceTitle, close); main.appendChild(top); panel.append(aside, main);
    } else {
      const head = el('div', null, 'head'); head.appendChild(el('strong', '魔核 AI')); head.append(history, fresh, close); panel.appendChild(head);
    }
    body = el('div', null, 'body'); main.appendChild(body);
    if (!workspace) message('能确定就直接查；有歧义逐步选清，明确后立即查询。聊天只保留在本设备 24 小时。', 'muted');
    const footer = el('div', null, 'footer' + (workspace ? ' workspace-composer' : ''));
    progress = el('div', '', 'muted' + (workspace ? ' workspace-progress' : '')); progress.setAttribute('role', 'status'); footer.appendChild(progress);
    // Both terminals start without suggestion text; the accessible name is not a visible prompt.
    input = el('textarea'); input.setAttribute('aria-label', '输入问题'); input.maxLength = 4000;
    const actions = el('div', null, 'actions');
    format = el('select'); [['screen','仅查看数据'],['screen_and_xlsx','数据和 Excel']].forEach(([value,label]) => { const o = el('option', label); o.value = value; format.appendChild(o); });
    send = el('button', '发送', 'primary' + (workspace ? ' workspace-send' : ''));
    const cancel = el('button', '停止', workspace ? 'workspace-cancel' : '');
    if (workspace) {
      const composer = el('div', null, 'workspace-composer-card'); composer.appendChild(input);
      // 电脑工作台直接显示导出选项，与输入文字左对齐；不影响原生手机端的加号入口。
      const composerActions = el('div', null, 'workspace-composer-actions');
      input.oninput = () => { input.style.height = 'auto'; input.style.height = Math.min(160, input.scrollHeight) + 'px'; };
      const excelLabel = el('label', null, 'workspace-excel');
      const excelToggle = documentRef.createElement('input'); excelToggle.type = 'checkbox'; excelToggle.setAttribute('aria-label', 'Execl');
      const excelText = el('span', 'Execl'); excelLabel.append(excelToggle, excelText); composerActions.appendChild(excelLabel);
      composer.append(composerActions, send, cancel); footer.appendChild(composer);
      footer.appendChild(el('div', 'Enter 发送 · Shift + Enter 换行', 'workspace-keyboard-hint'));
      workspaceCancel = cancel;
      excelToggle.onchange = () => { format.value = excelToggle.checked ? 'screen_and_xlsx' : 'screen'; };
      format._excelToggle = excelToggle; format._excelText = excelText;
    } else {
      footer.appendChild(input); actions.appendChild(format); actions.append(send, cancel); footer.appendChild(actions);
    }
    main.appendChild(footer); root.appendChild(panel);
    const excelOption = format.querySelector('option[value="screen_and_xlsx"]');
    function refreshCapabilities() {
      const capabilities = boot.capabilities || {};
      const ready = (capabilities.output_formats || []).includes('screen_and_xlsx');
      excelOption.disabled = !ready; excelOption.textContent = ready ? '数据和 Excel' : 'Excel 暂未开放';
      if (!ready) format.value = 'screen';
      if (format._excelToggle) {
        format._excelToggle.disabled = !ready; format._excelToggle.checked = ready && format.value === 'screen_and_xlsx';
        format._excelText.textContent = ready ? 'Execl' : 'Execl 暂未开放';
      }
    }
    refreshCapabilities();
    // R6: all configuration is maintained in platform Settings by the trusted admin account.
    send.onclick = () => { void submitQuestion(); };
    cancel.onclick = stop; close.onclick = () => { stop(); panel.hidden = true; };
    input.onkeydown = event => { if (event.isComposing || event.key !== 'Enter' || event.shiftKey) return; event.preventDefault(); void submitQuestion(); };
    fresh.onclick = () => { if (pendingCreate || (run && !isTerminal(run.status))) { message('请先确认当前任务状态。'); return; } recoveryDraft = null; finishActiveRunStatus(); conversation = sessions.create().id; body.textContent = ''; progress.textContent = ''; run = null; clearActive(); updateWorkspaceTitle(null); renderWorkspaceHistory(); syncWorkspaceActions(); };
    history.onclick = () => { if (pendingCreate || (run && !isTerminal(run.status))) return; recoveryDraft = null; body.textContent = ''; progress.textContent = ''; sessions.load().slice().reverse().forEach(s => { const b = el('button', conversationTitle(s)); b.onclick = () => { recoveryDraft = null; conversation = s.id; body.textContent = ''; progress.textContent = ''; s.rounds.forEach(r => appendTranscriptTurn(r.question, r.presentation || {summary:r.answer}, r.elapsed_seconds, r)); body.scrollTop = 0; }; body.appendChild(b); }); };
    if (workspace) { renderWorkspaceHistory(); syncWorkspaceActions(); }
    if (record) void resumeActive(record);
  }
  entry.onclick = open;
  request('GET', '/bootstrap',{client_session_id:clientSession}).then(value => { if (!disposed) { boot = value; entry.hidden = !value.enabled && !value.can_configure; } }).catch(() => {});
  expiryTimer = setInterval(() => { if (sessions) { try { if (!sessions.load().some(s => s.id === conversation) && panel) { body.textContent = ''; progress.textContent = '会话已到期，请新建对话。'; } } catch (_) {} } }, 30000);
  // Page navigation, refresh and adapter replacement are not customer intent
  // to stop work. Keep the active Run locally so the next mount can restore
  // its signed delivery session and continue polling. The visible buttons are
  // the only cancellation path.
  const unload = () => { persistActive(); }; window.addEventListener('pagehide', unload); window.addEventListener('beforeunload', unload);
  const dispose = () => { persistActive(); disposed = true; clearTimeout(pollTimer); clearTimeout(admissionRetryTimer); clearTimeout(clarificationTimer); clearInterval(expiryTimer); finishActiveRunStatus(); window.removeEventListener('pagehide', unload); window.removeEventListener('beforeunload', unload); disposeEntryDrag(); host.remove(); };
  host.__moheAiDispose = dispose;
  return dispose;
}
