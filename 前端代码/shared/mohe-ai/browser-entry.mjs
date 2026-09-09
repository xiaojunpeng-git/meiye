import { DeviceSessions, newId, isTerminal, acceptRun } from './device-session.mjs';
import { GUIDANCE_SCHEMA, validateClarification, clarificationKey, clarificationSubmission } from './clarification-state.mjs';

// Framework-neutral, shadow-scoped adapter. No business arithmetic or HTML injection.
export function mountMoheAi({ request, storage = window.localStorage, documentRef = document }) {
  const host = documentRef.createElement('div'); host.dataset.moheAi = 'entry';
  documentRef.body.appendChild(host); const root = host.attachShadow({ mode: 'open' });
  const style = documentRef.createElement('style'); style.textContent = `
    :host{font:14px/1.5 system-ui,sans-serif;color:#243447}button,input,textarea,select{font:inherit}button{cursor:pointer;border:1px solid #dbe2ec;border-radius:8px;background:white;padding:7px 12px;color:inherit}button:disabled{opacity:.5;cursor:default}.entry{position:fixed;left:0;top:50%;transform:translateY(-50%);z-index:2147483000;background:#5142b5;color:white;border-radius:0 14px 14px 0;padding:12px 10px}.panel{position:fixed;left:12px;top:50%;transform:translateY(-50%);width:min(440px,calc(100vw - 24px));height:min(700px,calc(100vh - 32px));z-index:2147483001;background:white;border:1px solid #dce2ed;border-radius:16px;box-shadow:0 16px 60px #17233d33;display:flex;flex-direction:column;overflow:hidden}.head,.footer{padding:14px;border-bottom:1px solid #edf0f4}.head{display:flex;gap:8px;align-items:center}.head strong{flex:1}.body{overflow:auto;padding:14px;flex:1}.message{white-space:pre-wrap;margin:8px 0;padding:10px;border-radius:10px;background:#f5f6fa}.question{background:#eeebff}.card{border:1px solid #e2e8f0;padding:12px;margin:8px 0;border-radius:10px}.value{font-size:26px;color:#1f5f8b}.muted{color:#65758b;font-size:12px}.footer{border-top:1px solid #edf0f4;border-bottom:0}textarea{box-sizing:border-box;width:100%;resize:vertical;min-height:65px;border:1px solid #ccd5e3;border-radius:8px;padding:8px}.actions{display:flex;gap:8px;align-items:center;margin-top:8px}.primary{background:#5142b5;color:white}.error{color:#9a3412}select,input{max-width:100%;padding:6px;margin:5px 0}table{border-collapse:collapse;display:block;overflow:auto}td,th{border:1px solid #e2e8f0;padding:6px;white-space:nowrap}`;
  style.textContent += '[hidden]{display:none!important}';
  root.appendChild(style);
  const el = (tag, text, cls) => { const n = documentRef.createElement(tag); if (text != null) n.textContent = String(text); if (cls) n.className = cls; return n; };
  let boot, sessions, conversation, run = null, question = '', panel = null, body, progress, input, send, pollTimer, expiryTimer, disposed = false, cancelling = false, closeRequested = false;
  const clientSession = newId(); const entry = el('button', '魔核 AI', 'entry'); entry.hidden = true; root.appendChild(entry);
  let clarificationArea = null, clarificationId = null, activeGuidanceSchema = null;
  function clearClarification() { if (clarificationArea) clarificationArea.remove(); clarificationArea = null; clarificationId = null; }
  function message(text, cls) { const n = el('div', text, 'message ' + (cls || '')); body.appendChild(n); body.scrollTop = body.scrollHeight; }
  function renderAnswer(answer, live = true) {
    if (!answer || typeof answer !== 'object') return;
    if (answer.summary) message(answer.summary);
    (answer.cards || []).forEach(card => { const n = el('div', null, 'card'); n.appendChild(el('div', card.metric_name)); n.appendChild(el('div', String(card.display_value) + (card.unit || ''), 'value')); if (card.tooltip) { const details = el('details'); details.appendChild(el('summary', '统计口径')); if (typeof card.tooltip === 'string') details.appendChild(el('div', card.tooltip)); else [['summary',''],['include','包含：'],['exclude','不包含：'],['timing','统计时间：'],['note','说明：']].forEach(([key,label]) => { if (typeof card.tooltip[key] === 'string' && card.tooltip[key]) details.appendChild(el('div', label + card.tooltip[key])); }); n.appendChild(details); } if (card.period_label) n.appendChild(el('div', card.period_label, 'muted')); body.appendChild(n); });
    if (answer.table && Array.isArray(answer.table.columns) && Array.isArray(answer.table.rows)) { const table = el('table'); const tr = el('tr'); answer.table.columns.forEach(c => tr.appendChild(el('th',c.label))); table.appendChild(tr); answer.table.rows.forEach(row => { const r = el('tr'); answer.table.columns.forEach(c => r.appendChild(el('td',row[c.key] == null ? '-' : row[c.key]))); table.appendChild(r); }); body.appendChild(table); }
    if (live && answer.export && answer.export.file_ref && run && isTerminal(run.status)) { const source = { ...run }; const download = el('button', '下载 Excel'); download.onclick = async () => { download.disabled = true; try { const blob = await request('GET', '/runs/' + encodeURIComponent(source.run_id) + '/export', { client_session_id: clientSession, run_delivery_token: source.run_delivery_token, generation: source.generation }, {binary:true}); const url = URL.createObjectURL(blob); const link = el('a'); link.href = url; link.download = answer.export.filename || '经营数据.xlsx'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); } catch (_) { message('文件暂不可下载，请重新查询。','error'); } finally { download.disabled = false; } }; body.appendChild(download); }
  }
  function binding() { return { client_session_id: clientSession, run_delivery_token: run.run_delivery_token, generation: run.generation }; }
  async function update(next) {
    const previous = run; run = acceptRun(run, next); if (!run || run === previous) return;
    clearTimeout(pollTimer);
    progress.textContent = typeof run.progress === 'string' ? run.progress : (run.progress && run.progress.message) || '正在处理';
    if (isTerminal(run.status)) {
      clearClarification();
      clearTimeout(pollTimer); cancelling = false; send.disabled = false;
      if (['COMPLETED', 'PARTIAL_SUCCEEDED'].includes(run.status) && run.answer) {
        renderAnswer(run.answer);
        const text = run.answer.summary || (run.answer.cards || []).map(c => `${c.metric_name}：${c.display_value}${c.unit || ''}`).join('\n');
        try { sessions.append(conversation, question, text, run.answer); } catch (_) { message('本机历史保存失败，本次结果仍可查看。', 'error'); }
      } else message(run.status === 'CANCELLED' ? '已取消' : run.message || '本次未能完成，请重新提问。');
      return;
    }
    if (run.status === 'WAITING_CLARIFICATION' && run.clarification && !cancelling) renderClarification(run.clarification);
    else { clearClarification(); pollTimer = setTimeout(poll, 1000); }
  }
  async function poll() {
    if (disposed || !run || isTerminal(run.status)) return;
    try { const previous = run; await update(await request('GET', '/runs/' + encodeURIComponent(run.run_id), binding())); if (run === previous && run && !isTerminal(run.status) && (run.status !== 'WAITING_CLARIFICATION' || cancelling)) pollTimer = setTimeout(poll, 1000); }
    catch (_) { if (progress) progress.textContent = cancelling ? '暂未确认取消结果，请检查网络。' : '连接暂时中断，正在重新确认任务状态。'; pollTimer = setTimeout(poll, 3000); }
  }
  function renderClarification(c) {
    try { validateClarification(c, activeGuidanceSchema); } catch (error) { clearClarification(); progress.textContent = error.message; return; }
    if (c.schema_version === GUIDANCE_SCHEMA) activeGuidanceSchema = GUIDANCE_SCHEMA;
    const key = clarificationKey(c);
    if (clarificationArea && clarificationArea.isConnected && clarificationId === key) return;
    clearClarification();
    const area = el('div', null, 'card'); area.dataset.guidance = 'step';
    clarificationArea = area; clarificationId = key;
    if (c.schema_version === GUIDANCE_SCHEMA) {
      area.appendChild(el('div', `第 ${c.round_no} 步 · 最多 ${c.max_clarification_rounds} 步，明确后立即查询`, 'muted'));
      if (c.confirmed_summary.length) {
        const summary = el('div', null, 'muted'); summary.dataset.guidance = 'summary'; summary.appendChild(el('div', '已确认条件'));
        c.confirmed_summary.forEach(item => summary.appendChild(el('div', item.label + '：' + item.value))); area.appendChild(summary);
      }
    }
    const form = el('div'); area.appendChild(form);
    let pendingSubmission = null, submitting = false, controls = [], reviseId = null;
    const editing = [];
    function current() { return clarificationArea === area && clarificationId === key && run && run.status === 'WAITING_CLARIFICATION' && !cancelling; }
    function setDisabled(value) { controls.forEach(item => item.elements.forEach(control => { control.disabled = value; })); editing.forEach(button => { button.disabled = value; }); }
    function renderFields(fields, initial, title) {
      if (submitting || pendingSubmission) return;
      form.textContent = ''; controls = []; form.appendChild(el('div', title || '请选择本次要查询的内容'));
      const hint = el('div', '', 'error'); hint.setAttribute('role', 'alert');
      fields.forEach(field => {
        const label = el('div', field.label); form.appendChild(label);
        if (field.type === 'date') {
          const control = el('input'); control.type = 'date'; control.required = true; control.setAttribute('aria-label', field.label);
          if (typeof field.min === 'string') control.min = field.min;
          if (typeof field.max === 'string') control.max = field.max;
          control.value = typeof initial[field.key] === 'string' ? initial[field.key] : '';
          form.appendChild(control); controls.push({ field, elements: [control], read: () => control.value });
        } else if (field.options.length <= 3) {
          const group = el('div'); group.setAttribute('role', 'radiogroup'); group.setAttribute('aria-label', field.label);
          const name = 'guidance-' + newId(); const inputs = [];
          field.options.forEach(option => { const optionLabel = el('label'); const control = el('input'); control.type = 'radio'; control.name = name; control.value = option.value; control.checked = initial[field.key] === option.value; optionLabel.append(control, el('span', option.label)); group.appendChild(optionLabel); inputs.push(control); });
          form.appendChild(group); controls.push({ field, elements: inputs, read: () => { const selected = inputs.find(control => control.checked); return selected ? selected.value : ''; } });
        } else {
          const control = el('select'); control.setAttribute('aria-label', field.label); const empty = el('option', '请选择'); empty.value = ''; control.appendChild(empty);
          field.options.forEach(option => { const o = el('option', option.label); o.value = option.value; control.appendChild(o); });
          control.value = typeof initial[field.key] === 'string' ? initial[field.key] : ''; form.appendChild(control); controls.push({ field, elements: [control], read: () => control.value });
        }
      });
      form.appendChild(hint);
      const confirm = el('button', c.schema_version === GUIDANCE_SCHEMA ? '确认并继续' : '确认查询', 'primary');
      confirm.onclick = async () => {
        if (!current() || submitting) return;
        if (!pendingSubmission) {
          const choices = {}; controls.forEach(item => { choices[item.field.key] = item.read(); });
          if (controls.some(item => !choices[item.field.key] || item.elements.some(control => control.type === 'date' && typeof control.checkValidity === 'function' && !control.checkValidity()))) { hint.textContent = '请完成当前选择或填写有效日期。'; return; }
          pendingSubmission = { ...binding(), ...clarificationSubmission(c, choices, newId(), reviseId) };
        }
        const submitted = pendingSubmission; submitting = true; confirm.disabled = true; setDisabled(true); hint.textContent = '';
        try {
          await update(await request('POST', '/runs/' + encodeURIComponent(run.run_id) + '/clarify', submitted));
          if (current()) { pendingSubmission = null; setDisabled(false); confirm.disabled = false; }
        } catch (error) {
          if (current()) {
            // An uncertain send is retried byte-for-byte with the same ID;
            // changing choices before confirmation could create two answers.
            if (error.responseKnown) { pendingSubmission = null; setDisabled(false); }
            confirm.disabled = false; confirm.textContent = error.responseKnown ? '确认并继续' : '重试确认';
            hint.textContent = error.responseKnown ? error.message : '提交结果暂未确认，请重试确认同一次选择。';
          }
        } finally { submitting = false; }
      };
      form.appendChild(confirm);
    }
    renderFields(c.fields, {}, c.question);
    if (c.schema_version === GUIDANCE_SCHEMA && (c.revisable_steps || []).length) {
      const details = el('details'); details.appendChild(el('summary', '修改已确认条件'));
      c.revisable_steps.forEach(step => { const button = el('button', step.question); button.onclick = () => { if (!current() || submitting || pendingSubmission) return; reviseId = step.id; renderFields(step.fields, step.choices, '修改：' + step.question); }; details.appendChild(button); editing.push(button); });
      const back = el('button', '返回当前问题'); back.onclick = () => { if (!current() || submitting || pendingSubmission) return; reviseId = null; renderFields(c.fields, {}, c.question); }; details.appendChild(back); editing.push(back); area.appendChild(details);
    }
    body.appendChild(area); body.scrollTop = body.scrollHeight;
  }
  async function stop() {
    closeRequested = true;
    if (!run || isTerminal(run.status)) return;
    cancelling = true; progress.textContent = '正在取消';
    if (clarificationArea) clarificationArea.querySelectorAll('button,input,select').forEach(control => { control.disabled = true; });
    try { await update(await request('POST', '/runs/' + encodeURIComponent(run.run_id) + '/cancel', binding(), { keepalive: true })); } catch (_) { progress.textContent = '暂未确认取消结果，请检查网络。'; }
    if (run && !isTerminal(run.status)) { clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000); }
  }
  async function open() {
    if (panel) { panel.hidden = false; return; }
    try { boot = await request('GET', '/bootstrap', {client_session_id:clientSession}); if (!boot.enabled && !boot.can_configure) return; sessions = new DeviceSessions(storage, boot.identity_key); conversation = sessions.create().id; } catch (_) { return; }
    panel = el('section', null, 'panel'); panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', '魔核 AI');
    const head = el('div', null, 'head'); head.appendChild(el('strong', '魔核 AI')); const history = el('button', '历史'); const fresh = el('button', '新对话'); const close = el('button', '关闭'); head.append(history, fresh, close); panel.appendChild(head);
    body = el('div', null, 'body'); panel.appendChild(body); message('能确定就直接查；有歧义逐步选清，明确后立即查询。聊天只保留在本设备 24 小时。', 'muted');
    const footer = el('div', null, 'footer'); progress = el('div', '', 'muted'); progress.setAttribute('role', 'status'); footer.appendChild(progress); input = el('textarea'); input.placeholder = '例如：今天本店现金业绩多少？'; input.maxLength = 4000; footer.appendChild(input); const actions = el('div', null, 'actions'); const format = el('select'); [['screen','仅查看数据'],['screen_and_xlsx','数据和 Excel']].forEach(([value,label]) => { const o = el('option', label); o.value = value; format.appendChild(o); }); actions.appendChild(format); send = el('button', '发送', 'primary'); const cancel = el('button', '停止'); actions.append(send,cancel); footer.appendChild(actions); panel.appendChild(footer); root.appendChild(panel);
    const excelOption = format.querySelector('option[value="screen_and_xlsx"]');
    function refreshCapabilities() {
      const capabilities = boot.capabilities || {};
      const ready = (capabilities.output_formats || []).includes('screen_and_xlsx');
      excelOption.disabled = !ready; excelOption.textContent = ready ? '数据和 Excel' : 'Excel 暂未开放';
      if (!ready) format.value = 'screen';
      input.placeholder = (capabilities.metric_codes || []).includes('consume_amount') ? '例如：今天本店消耗业绩多少？' : '请输入要查询的指标和日期';
    }
    refreshCapabilities();
    // R6: all configuration is maintained in platform Settings by the trusted admin account.
    let pendingCreate = null;
    send.onclick = async () => {
      if (!boot.enabled) { progress.textContent = boot.disabled_reason || '请先完成配置并启用可用能力。'; return; }
      if (!input.value.trim() || (run && !isTerminal(run.status))) return;
      question = pendingCreate ? pendingCreate.question : input.value.trim(); send.disabled = true; closeRequested = false;
      if (!pendingCreate) { message(question, 'question'); activeGuidanceSchema = boot.guidance_schema_version || null; pendingCreate = { client_request_id: newId(), conversation_id: conversation, client_session_id: clientSession, window_token: boot.window_token, question, history: sessions.history(conversation), output_format: format.value }; if (activeGuidanceSchema === GUIDANCE_SCHEMA) pendingCreate.guidance_schema_version = GUIDANCE_SCHEMA; const contextRef = sessions.contextRef(conversation); if (contextRef) pendingCreate.context_ref = contextRef; }
      input.value = ''; progress.textContent = '正在接纳请求'; run = null;
      try {
        const submitted = pendingCreate;
        const accepted = await request('POST', '/runs', submitted);
        if (accepted && accepted.accepted === false) { pendingCreate = null; send.disabled = false; send.textContent = '发送'; progress.textContent = accepted.message || '当前使用人数较多，请稍后再问。'; return; }
        await update(accepted);
        pendingCreate = null;
        if (closeRequested || disposed) { await stop(); return; }
        if (run && !isTerminal(run.status)) await update(await request('POST', '/runs/' + encodeURIComponent(run.run_id) + '/execute', { ...binding(), question: submitted.question, history: submitted.history, output_format: submitted.output_format, ...(submitted.context_ref ? { context_ref: submitted.context_ref } : {}) }));
      } catch (error) { progress.textContent = error.responseKnown ? error.message : '请求结果暂未确认。'; if (run) { clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000); } else { if (error.responseKnown) pendingCreate = null; send.disabled = false; send.textContent = error.responseKnown ? '发送' : '重试确认'; input.value = question; } }
    };
    cancel.onclick = stop; close.onclick = () => { stop(); panel.hidden = true; };
    fresh.onclick = () => { if (pendingCreate || (run && !isTerminal(run.status))) { message('请先确认当前任务状态。'); return; } conversation = sessions.create().id; body.textContent = ''; progress.textContent = ''; run = null; };
    history.onclick = () => { if (pendingCreate || (run && !isTerminal(run.status))) return; body.textContent = ''; progress.textContent = ''; sessions.load().slice().reverse().forEach(s => { const b = el('button', s.rounds[0] ? s.rounds[0].question.slice(0,30) : '新对话'); b.onclick = () => { conversation = s.id; body.textContent = ''; progress.textContent = ''; s.rounds.forEach(r => { message(r.question,'question'); renderAnswer(r.presentation || {summary:r.answer},false); }); }; body.appendChild(b); }); };
  }
  entry.onclick = open;
  request('GET', '/bootstrap',{client_session_id:clientSession}).then(value => { if (!disposed) { boot = value; entry.hidden = !value.enabled && !value.can_configure; } }).catch(() => {});
  expiryTimer = setInterval(() => { if (sessions) { try { if (!sessions.load().some(s => s.id === conversation) && panel) { body.textContent = ''; progress.textContent = '会话已到期，请新建对话。'; } } catch (_) {} } }, 30000);
  const unload = () => { stop(); }; window.addEventListener('pagehide', unload);
  return () => { stop(); disposed = true; clearTimeout(pollTimer); clearInterval(expiryTimer); window.removeEventListener('pagehide', unload); host.remove(); };
}
