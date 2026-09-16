import { DeviceSessions, newId, isTerminal, acceptRun } from './device-session.mjs';
import { GUIDANCE_SCHEMA, validateClarification, clarificationKey, clarificationSubmission } from './clarification-state.mjs';

// Framework-neutral, shadow-scoped adapter. No business arithmetic or HTML injection.
export function mountMoheAi({ request, storage = window.localStorage, documentRef = document, entryLeft = '0px', panelLeft = '12px' }) {
  const host = documentRef.createElement('div'); host.dataset.moheAi = 'entry';
  // A terminal with a persistent sidebar can reserve that width while the
  // entry remains fixed at the left-middle of the actual work area.
  host.style.setProperty('--mohe-ai-entry-left', entryLeft);
  host.style.setProperty('--mohe-ai-panel-left', panelLeft);
  documentRef.body.appendChild(host); const root = host.attachShadow({ mode: 'open' });
  const style = documentRef.createElement('style'); style.textContent = `
    :host{font:14px/1.5 system-ui,sans-serif;color:#243447}button,input,textarea,select{font:inherit}button{cursor:pointer;border:1px solid #dbe2ec;border-radius:8px;background:white;padding:7px 12px;color:inherit}button:disabled{opacity:.5;cursor:default}.entry{position:fixed;left:var(--mohe-ai-entry-left);top:50%;transform:translateY(-50%);z-index:2147483000;background:#5142b5;color:white;border-radius:0 14px 14px 0;padding:12px 10px}.panel{position:fixed;left:var(--mohe-ai-panel-left);top:50%;transform:translateY(-50%);width:min(440px,calc(100vw - var(--mohe-ai-panel-left) - 12px));height:min(700px,calc(100vh - 32px));z-index:2147483001;background:white;border:1px solid #dce2ed;border-radius:16px;box-shadow:0 16px 60px #17233d33;display:flex;flex-direction:column;overflow:hidden}.head,.footer{padding:14px;border-bottom:1px solid #edf0f4}.head{display:flex;gap:8px;align-items:center}.head strong{flex:1}.body{overflow:auto;padding:14px;flex:1}.message{white-space:pre-wrap;margin:8px 0;padding:10px;border-radius:10px;background:#f5f6fa}.question{background:#eeebff}.card{border:1px solid #e2e8f0;padding:12px;margin:8px 0;border-radius:10px}.value{font-size:26px;color:#1f5f8b}.muted{color:#65758b;font-size:12px}.footer{border-top:1px solid #edf0f4;border-bottom:0}textarea{box-sizing:border-box;width:100%;resize:vertical;min-height:65px;border:1px solid #ccd5e3;border-radius:8px;padding:8px}.actions{display:flex;gap:8px;align-items:center;margin-top:8px}.primary{background:#5142b5;color:white}.error{color:#9a3412}select,input{max-width:100%;padding:6px;margin:5px 0}table{border-collapse:collapse;display:block;overflow:auto}td,th{border:1px solid #e2e8f0;padding:6px;white-space:nowrap}`;
  style.textContent += '[hidden]{display:none!important}';
  root.appendChild(style);
  const el = (tag, text, cls) => { const n = documentRef.createElement(tag); if (text != null) n.textContent = String(text); if (cls) n.className = cls; return n; };
  let boot, sessions, conversation, run = null, question = '', panel = null, body, progress, input, send, format, pollTimer, expiryTimer, disposed = false, cancelling = false, closeRequested = false, pendingCreate = null, compatibilityExecuting = false, clientDeliveryStartedAt = 0, activeQuestionRendered = false;
  let clientSession = newId(); const entry = el('button', '魔核 AI', 'entry'); entry.hidden = true; root.appendChild(entry);
  // A clarification POST is durably accepted before the worker can change the
  // public Run status.  Keep polling that same step instead of rendering its
  // old controls again or treating WAITING_CLARIFICATION as an idle state.
  let clarificationArea = null, clarificationId = null, clarificationSubmittedId = null, activeGuidanceSchema = null, guidanceSubmission = null;
  function clearClarification() { if (clarificationArea) clarificationArea.remove(); clarificationArea = null; clarificationId = null; }
  function message(text, cls) { const n = el('div', text, 'message ' + (cls || '')); body.appendChild(n); body.scrollTop = body.scrollHeight; }
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
    if (previous && previous.textContent === question) { activeQuestionRendered = true; return; }
    if (previous) previous.remove();
    const n = el('div', question, 'message question'); n.dataset.moheAiActiveQuestion = 'true';
    body.appendChild(n); body.scrollTop = body.scrollHeight; activeQuestionRendered = true;
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
      guidance_submission: guidanceSubmission, client_delivery_started_at: clientDeliveryStartedAt,
      run, pending_create: pendingCreate });
  }
  function clearActive() { if (sessions) sessions.clearRuntime(); }
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
    clearTimeout(pollTimer); run = null; pendingCreate = null; compatibilityExecuting = false;
    clientDeliveryStartedAt=0;
    clarificationSubmittedId = null; guidanceSubmission = null; clearClarification(); if (sessions) sessions.clearPendingQuestion(conversation);
    cancelling = false; closeRequested = false; clearActive();
    if (send) { send.disabled = false; send.textContent = '发送'; }
    if (progress) progress.textContent = '上一次任务已失效，请重新提问。';
  }
  function renderAnswer(answer, live = true) {
    if (!answer || typeof answer !== 'object') return;
    if (answer.summary) message(answer.summary);
    (answer.cards || []).forEach(card => { const n = el('div', null, 'card'); n.appendChild(el('div', card.metric_name)); n.appendChild(el('div', String(card.display_value) + (card.unit || ''), 'value')); if (card.tooltip) { const details = el('details'); details.appendChild(el('summary', '统计口径')); if (typeof card.tooltip === 'string') details.appendChild(el('div', card.tooltip)); else [['summary',''],['include','包含：'],['exclude','不包含：'],['timing','统计时间：'],['note','说明：']].forEach(([key,label]) => { if (typeof card.tooltip[key] === 'string' && card.tooltip[key]) details.appendChild(el('div', label + card.tooltip[key])); }); n.appendChild(details); } if (card.period_label) n.appendChild(el('div', card.period_label, 'muted')); body.appendChild(n); });
    if (answer.table && Array.isArray(answer.table.columns) && Array.isArray(answer.table.rows)) { const table = el('table'); const tr = el('tr'); answer.table.columns.forEach(c => tr.appendChild(el('th',c.label))); table.appendChild(tr); answer.table.rows.forEach(row => { const r = el('tr'); answer.table.columns.forEach(c => r.appendChild(el('td',row[c.key] == null ? '-' : row[c.key]))); table.appendChild(r); }); body.appendChild(table); }
    if (live && answer.export && answer.export.file_ref && run && isTerminal(run.status)) { const source = { ...run }; const download = el('button', '下载 Excel'); download.onclick = async () => { download.disabled = true; try { const blob = await request('GET', '/runs/' + encodeURIComponent(source.run_id) + '/export', { client_session_id: clientSession, run_delivery_token: source.run_delivery_token, generation: source.generation }, {binary:true}); const url = URL.createObjectURL(blob); const link = el('a'); link.href = url; link.download = answer.export.filename || '经营数据.xlsx'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); } catch (_) { message('文件暂不可下载，请重新查询。','error'); } finally { download.disabled = false; } }; body.appendChild(download); }
  }
  function binding() { return { client_session_id: clientSession, run_delivery_token: run.run_delivery_token, generation: run.generation }; }
  // The create response is authoritative for that specific Run. Bootstrap is
  // only a capability snapshot and can change while a request is in flight.
  function needsCompatibilityExecution(value) { return value && (value.execution_mode === 'compatibility' || (value.execution_mode == null && boot.async_execution !== true)); }
  async function update(next) {
    if (disposed) return;
    const previous = run; run = acceptRun(run, next); if (!run || run === previous) return;
    clearTimeout(pollTimer);
    progress.textContent = typeof run.progress === 'string' ? run.progress : (run.progress && run.progress.message) || '正在处理';
    if (isTerminal(run.status)) {
      clarificationSubmittedId = null; guidanceSubmission = null; clearClarification();
      clearTimeout(pollTimer); cancelling = false; send.disabled = false;
      if (['COMPLETED', 'PARTIAL_SUCCEEDED'].includes(run.status) && run.answer) {
        // A recovered terminal projection must render the original customer
        // wording before its answer. Never leave the customer with an orphaned
        // answer merely because the page changed while the task was running.
        const deliveredQuestion = ensureQuestionVisible();
        renderAnswer(run.answer);
        reportVisibleDeliveryAfterPaint(run);
        const text = run.answer.summary || (run.answer.cards || []).map(c => `${c.metric_name}：${c.display_value}${c.unit || ''}`).join('\n');
        try { sessions.append(conversation, deliveredQuestion, text, run.answer, run); } catch (_) { message('本机历史保存失败，本次结果仍可查看。', 'error'); }
      } else {
        // The terminal message is already appended to the conversation.  Do
        // not leave the same failure in the footer status as a second visible
        // answer; it makes a controlled refusal look like two responses. A
        // refusal is still a complete customer turn, so restore the original
        // wording before showing it as well.
        const deliveredQuestion = ensureQuestionVisible();
        const terminalMessage = run.status === 'CANCELLED' ? '已取消' : run.message || '本次未能完成，请重新提问。';
        progress.textContent = '';
        message(terminalMessage);
        // Keep a complete customer-visible turn after refresh.  It is marked
        // non-contextual so a previous failure never becomes an instruction
        // or a claimed business fact for the next model request.
        try { sessions.append(conversation, deliveredQuestion, terminalMessage,
          { summary: terminalMessage, terminal_status: run.status }, run, { contextEligible: false });
        } catch (_) { message('本机历史保存失败，本次结果仍可查看。', 'error'); }
        clientDeliveryStartedAt=0;
        if (sessions) sessions.clearPendingQuestion(conversation);
      }
      pendingCreate = null; clearActive(); return;
    }
    if (run.status === 'WAITING_CLARIFICATION' && run.clarification && run.clarification_rejected === true && clarificationSubmittedId === run.clarification.id) {
      clarificationSubmittedId = null;
      progress.textContent = '所选条件格式不完整，请检查后重新确认。';
    }
    if (run.status === 'WAITING_CLARIFICATION' && run.clarification && !cancelling && clarificationSubmittedId !== run.clarification.id) renderClarification(run.clarification);
    else { clearClarification(); pollTimer = setTimeout(poll, 1000); }
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
      if (progress) progress.textContent = cancelling ? '暂未确认取消结果，请检查网络。' : '连接暂时中断，正在重新确认任务状态。';
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
      progress.textContent = error.responseKnown ? error.message : '请求结果暂未确认。';
      clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000);
    } finally {
      compatibilityExecuting = false;
    }
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
    let pendingSubmission = validGuidanceSubmission(guidanceSubmission) && guidanceSubmission.clarification_id === c.id
      && (c.schema_version !== GUIDANCE_SCHEMA || (guidanceSubmission.schema_version === GUIDANCE_SCHEMA
        && guidanceSubmission.intent_revision === c.intent_revision && guidanceSubmission.step_revision === c.step_revision))
      ? guidanceSubmission : null;
    let submitting = false, controls = [], reviseId = pendingSubmission && pendingSubmission.revise_clarification_id || null;
    const editing = [];
    function current() { return !disposed && clarificationArea === area && clarificationId === key && run && run.status === 'WAITING_CLARIFICATION' && !cancelling; }
    function setDisabled(value) { controls.forEach(item => item.elements.forEach(control => { control.disabled = value; })); editing.forEach(button => { button.disabled = value; }); }
    function renderFields(fields, initial, title) {
      if (submitting) return;
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
          guidanceSubmission = pendingSubmission; persistActive();
        }
        const submitted = pendingSubmission; submitting = true; confirm.disabled = true; setDisabled(true); hint.textContent = '';
        try {
          // This click begins a new execution segment after the customer has
          // made a choice.  Do not include time spent reading the form in the
          // answer latency for that continuation.
          clientDeliveryStartedAt=Date.now(); persistActive();
          const accepted = await request('POST', '/runs/' + encodeURIComponent(run.run_id) + '/clarify', submitted);
          if (disposed || !current()) return;
          // The durable acknowledgement legitimately still projects the old
          // clarification.  Mark it locally before update so polling resumes
          // until the queued worker publishes a new step or final answer.
          clarificationSubmittedId = c.id;
          pendingSubmission = null; guidanceSubmission = null;
          await update(accepted);
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
            // An uncertain send is retried byte-for-byte with the same ID;
            // changing choices before confirmation could create two answers.
            if (error.responseKnown) { pendingSubmission = null; guidanceSubmission = null; setDisabled(false); }
            confirm.disabled = false; confirm.textContent = error.responseKnown ? '确认并继续' : '重试确认';
            hint.textContent = error.responseKnown ? error.message : '提交结果暂未确认，请重试确认同一次选择。';
            persistActive();
          }
        } finally { submitting = false; }
      };
      form.appendChild(confirm);
    }
    renderFields(c.fields, pendingSubmission ? pendingSubmission.choices : {}, c.question);
    if (c.schema_version === GUIDANCE_SCHEMA && (c.revisable_steps || []).length) {
      const details = el('details'); details.appendChild(el('summary', '修改已确认条件'));
      c.revisable_steps.forEach(step => { const button = el('button', step.question); button.onclick = () => { if (!current() || submitting || pendingSubmission) return; reviseId = step.id; renderFields(step.fields, step.choices, '修改：' + step.question); }; details.appendChild(button); editing.push(button); });
      const back = el('button', '返回当前问题'); back.onclick = () => { if (!current() || submitting || pendingSubmission) return; reviseId = null; renderFields(c.fields, {}, c.question); }; details.appendChild(back); editing.push(back); area.appendChild(details);
    }
    body.appendChild(area); body.scrollTop = body.scrollHeight;
    // Restore an interrupted submission only when it still targets the exact
    // server-projected step.  The server replays the stored result for the
    // same id and never lets it overwrite a newer choice.
    if (pendingSubmission && clarificationSubmittedId !== c.id && run.clarification_rejected !== true) void confirm.onclick();
  }
  async function submitQuestion() {
    if (!boot.enabled) { progress.textContent = boot.disabled_reason || '请先完成配置并启用可用能力。'; return; }
    if (!pendingCreate && !input.value.trim()) return;
    if (run && !isTerminal(run.status)) return;
    const restoringClosedRequest = pendingCreate !== null && closeRequested;
    question = pendingCreate ? pendingCreate.question : input.value.trim(); send.disabled = true; closeRequested = restoringClosedRequest;
    if (!pendingCreate) {
      activeQuestionRendered = false; showActiveQuestion(); activeGuidanceSchema = boot.guidance_schema_version || null;
      pendingCreate = { client_request_id: newId(), conversation_id: conversation, client_session_id: clientSession,
        window_token: boot.window_token, question, history: sessions.history(conversation), output_format: format.value };
      if (activeGuidanceSchema === GUIDANCE_SCHEMA) pendingCreate.guidance_schema_version = GUIDANCE_SCHEMA;
      const contextRef = sessions.contextRef(conversation); if (contextRef) pendingCreate.context_ref = contextRef;
    }
    if (!clientDeliveryStartedAt) clientDeliveryStartedAt=Date.now();
    if (sessions) sessions.setPendingQuestion(conversation, question);
    input.value = ''; progress.textContent = '正在接纳请求'; run = null; persistActive();
    try {
      const submitted = pendingCreate;
      const accepted = await request('POST', '/runs', submitted);
      if (disposed) {
        const retained = retainLateAdmission(accepted, submitted);
        void cancelLateAdmission(accepted, submitted, retained);
        return;
      }
      if (accepted && accepted.accepted === false) {
        pendingCreate = null; clientDeliveryStartedAt=0; if (sessions) sessions.clearPendingQuestion(conversation); send.disabled = false; send.textContent = '发送'; progress.textContent = accepted.message || '当前使用人数较多，请稍后再问。'; clearActive(); return;
      }
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
      progress.textContent = error.responseKnown ? error.message : '请求结果暂未确认。';
      if (run) { persistActive(); clearTimeout(pollTimer); pollTimer = setTimeout(poll, 1000); }
      else {
        if (error.responseKnown) { pendingCreate = null; clientDeliveryStartedAt=0; if (sessions) sessions.clearPendingQuestion(conversation); clearActive(); }
        else persistActive();
        send.disabled = false; send.textContent = error.responseKnown ? '发送' : '重试确认'; input.value = question;
      }
    }
  }
  async function resumeActive(record) {
    conversation = record.conversation_id; question = questionFromRecord(record) || persistedQuestion(record.conversation_id);
    run = record.run || null; pendingCreate = record.pending_create || null;
    activeQuestionRendered = false; showActiveQuestion();
    clientDeliveryStartedAt=Number.isSafeInteger(record.client_delivery_started_at) && record.client_delivery_started_at>0 ? record.client_delivery_started_at : 0;
    clarificationSubmittedId = typeof record.queued_clarification_id === 'string' ? record.queued_clarification_id : null;
    guidanceSubmission = validGuidanceSubmission(record.guidance_submission) ? record.guidance_submission : null;
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
        if (run.status === 'WAITING_CLARIFICATION' && run.clarification && !cancelling && clarificationSubmittedId !== run.clarification.id) renderClarification(run.clarification);
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
    cancelling = true; progress.textContent = '正在取消';
    if (clarificationArea) clarificationArea.querySelectorAll('button,input,select').forEach(control => { control.disabled = true; });
    try { const current = await request('POST', '/runs/' + encodeURIComponent(run.run_id) + '/cancel', binding(), { keepalive: true }); if (disposed) return; await update(current); } catch (_) { if (disposed) return; progress.textContent = '暂未确认取消结果，请检查网络。'; }
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
    panel = el('section', null, 'panel'); panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', '魔核 AI');
    const head = el('div', null, 'head'); head.appendChild(el('strong', '魔核 AI')); const history = el('button', '历史'); const fresh = el('button', '新对话'); const close = el('button', '关闭'); head.append(history, fresh, close); panel.appendChild(head);
    body = el('div', null, 'body'); panel.appendChild(body); message('能确定就直接查；有歧义逐步选清，明确后立即查询。聊天只保留在本设备 24 小时。', 'muted');
    const footer = el('div', null, 'footer'); progress = el('div', '', 'muted'); progress.setAttribute('role', 'status'); footer.appendChild(progress); input = el('textarea'); input.placeholder = '例如：今天本店现金业绩多少？'; input.maxLength = 4000; footer.appendChild(input); const actions = el('div', null, 'actions'); format = el('select'); [['screen','仅查看数据'],['screen_and_xlsx','数据和 Excel']].forEach(([value,label]) => { const o = el('option', label); o.value = value; format.appendChild(o); }); actions.appendChild(format); send = el('button', '发送', 'primary'); const cancel = el('button', '停止'); actions.append(send,cancel); footer.appendChild(actions); panel.appendChild(footer); root.appendChild(panel);
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
    send.onclick = () => { void submitQuestion(); };
    cancel.onclick = stop; close.onclick = () => { stop(); panel.hidden = true; };
    fresh.onclick = () => { if (pendingCreate || (run && !isTerminal(run.status))) { message('请先确认当前任务状态。'); return; } conversation = sessions.create().id; body.textContent = ''; progress.textContent = ''; run = null; activeQuestionRendered = false; clearActive(); };
    history.onclick = () => { if (pendingCreate || (run && !isTerminal(run.status))) return; body.textContent = ''; progress.textContent = ''; sessions.load().slice().reverse().forEach(s => { const b = el('button', s.rounds[0] ? s.rounds[0].question.slice(0,30) : '新对话'); b.onclick = () => { conversation = s.id; body.textContent = ''; progress.textContent = ''; s.rounds.forEach(r => { message(r.question,'question'); renderAnswer(r.presentation || {summary:r.answer},false); }); }; body.appendChild(b); }); };
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
  return () => { persistActive(); disposed = true; clearTimeout(pollTimer); clearInterval(expiryTimer); window.removeEventListener('pagehide', unload); window.removeEventListener('beforeunload', unload); host.remove(); };
}
