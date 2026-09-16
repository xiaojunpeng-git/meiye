// Device-only transcript. Server identity is an isolation key, never an authority.
export const RETENTION_MS = 86400000;
export const HISTORY_ROUNDS = 20;
export function newId() {
  if (!globalThis.crypto || !globalThis.crypto.getRandomValues) throw new Error('当前设备不支持安全会话，请升级浏览器。');
  return Array.from(globalThis.crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
}
export class DeviceSessions {
  constructor(storage, identity, clock = () => Date.now()) {
    if (typeof identity !== 'string' || !identity) throw new Error('缺少登录身份');
    this.storage = storage; this.clock = clock;
    this.key = 'mohe-ai:v1:' + encodeURIComponent(identity);
    this.runtimeKey = this.key + ':runtime';
  }
  load() {
    let data;
    try { data = JSON.parse(this.storage.getItem(this.key) || '[]'); } catch (_) { data = []; }
    const now = this.clock();
    const valid = Array.isArray(data) ? data.filter(s => s && typeof s.id === 'string' && Number.isSafeInteger(s.created_at) && s.created_at <= now && s.created_at + RETENTION_MS > now && Array.isArray(s.rounds)).map(s => {
      const { pending_question: pendingQuestion, ...session } = s;
      const rounds = s.rounds.filter(r => r && typeof r.question === 'string' && typeof r.answer === 'string' && Number.isSafeInteger(r.created_at) && r.created_at <= now && r.created_at + RETENTION_MS > now);
      const pending = pendingQuestion && typeof pendingQuestion.question === 'string' && pendingQuestion.question
        && Number.isSafeInteger(pendingQuestion.created_at) && pendingQuestion.created_at <= now && pendingQuestion.created_at + RETENTION_MS > now
        ? { question: pendingQuestion.question, created_at: pendingQuestion.created_at } : null;
      return { ...session, rounds, ...(pending ? { pending_question: pending } : {}) };
    }) : [];
    this.save(valid); return valid;
  }
  save(sessions) { this.storage.setItem(this.key, JSON.stringify(sessions)); }
  create(id = newId()) { const sessions = this.load(); const session = { id, created_at: this.clock(), rounds: [] }; sessions.push(session); this.save(sessions); return session; }
  // Every customer question remains available to the model as de-identified
  // conversational context, including a failed turn. This lets “try that
  // again” refer to the latest attempted topic rather than silently jumping
  // to an older successful answer. The server projects questions only; a
  // failure message or an unverified candidate never becomes a business fact.
  history(id) {
    const s = this.load().find(s => s.id === id);
    return s ? s.rounds.slice(-HISTORY_ROUNDS).map(r => ({ question: r.question, answer: r.answer })) : [];
  }
  contextRef(id) {
    const session = this.load().find(value => value.id === id);
    const last = session && session.rounds.length ? session.rounds.slice().reverse().find(row => row.context_eligible !== false) : null;
    const ref = last && last.presentation && last.presentation.context_ref;
    // Forward an opaque reference only. The server must validate ownership,
    // expiry and whether this new question actually refers to the old answer.
    return typeof ref === 'string' && ref.length > 0 ? ref : null;
  }
  pendingQuestion(id) {
    const session = this.load().find(value => value.id === id);
    const pending = session && session.pending_question;
    return pending && typeof pending.question === 'string' ? pending.question : '';
  }
  setPendingQuestion(id, question) {
    if (typeof question !== 'string' || !question) return false;
    const sessions = this.load(); const session = sessions.find(value => value.id === id);
    if (!session) return false;
    session.pending_question = { question, created_at: this.clock() }; this.save(sessions); return true;
  }
  clearPendingQuestion(id) {
    const sessions = this.load(); const session = sessions.find(value => value.id === id);
    if (!session || !Object.prototype.hasOwnProperty.call(session, 'pending_question')) return false;
    delete session.pending_question; this.save(sessions); return true;
  }
  append(id, question, answer, presentation, delivery = null, options = {}) {
    const sessions = this.load(); const s = sessions.find(s => s.id === id);
    if (!s) throw new Error('会话已到期，请新建对话。');
    if (typeof question !== 'string' || typeof answer !== 'string') throw new Error('无效对话');
    // A Run can be observed by the entry being removed and by its replacement
    // at nearly the same time.  The server result is immutable, therefore a
    // completed Run is one conversation round rather than two client events.
    const runId = delivery && typeof delivery.run_id === 'string' ? delivery.run_id : '';
    const generation = delivery && Number.isInteger(delivery.generation) ? delivery.generation : null;
    if (runId && generation !== null && s.rounds.some(row => row.run_id === runId && row.generation === generation)) {
      // The first observer already saved this terminal turn.  A remounted
      // observer may still hold its recovery marker; remove that marker here
      // too so it cannot be mistaken for a later unfinished question.
      delete s.pending_question; this.save(sessions); return false;
    }
    s.rounds.push({ question, answer, presentation,
      ...(options.contextEligible === false ? { context_eligible: false } : {}),
      ...(runId && generation !== null ? { run_id: runId, generation } : {}), created_at: this.clock() }); delete s.pending_question; this.save(sessions);
    return true;
  }
  loadRuntime() {
    let value;
    try { value = JSON.parse(this.storage.getItem(this.runtimeKey) || 'null'); } catch (_) { value = null; }
    const now = this.clock();
    if (!value || typeof value !== 'object' || typeof value.session_id !== 'string' || typeof value.conversation_id !== 'string'
      || !Number.isSafeInteger(value.created_at) || value.created_at > now || value.created_at + RETENTION_MS <= now) {
      this.storage.removeItem(this.runtimeKey); return null;
    }
    return value;
  }
  saveRuntime(value) { this.storage.setItem(this.runtimeKey, JSON.stringify(value)); }
  clearRuntime() { this.storage.removeItem(this.runtimeKey); }
  // A user-initiated history clear must not leave an active-run recovery
  // record behind: otherwise a later remount could silently restore a task
  // from the conversation the user explicitly removed.
  clear() { this.storage.removeItem(this.key); this.clearRuntime(); }
}
export const isTerminal = status => ['COMPLETED', 'PARTIAL_SUCCEEDED', 'FAILED', 'CANCELLED'].includes(status);
export function acceptRun(current, incoming) {
  if (!incoming || typeof incoming.run_id !== 'string' || !Number.isInteger(incoming.generation)) return current;
  if (current && (current.run_id !== incoming.run_id || current.generation !== incoming.generation || isTerminal(current.status))) return current;
  // RunStore.version is the server-owned projection order. Once observed, a
  // delayed/legacy response cannot remove it or replay an older guidance step.
  // Versionless fixtures/old runs remain compatible until a version is seen.
  if (Object.prototype.hasOwnProperty.call(incoming, 'version') && (!Number.isSafeInteger(incoming.version) || incoming.version < 1)) return current;
  if (current && Number.isSafeInteger(current.version) && (!Number.isSafeInteger(incoming.version) || incoming.version <= current.version)) return current;
  return { ...(current || {}), ...incoming };
}
export function safeDownloadUrl(value, origin) {
  try { const url = new URL(value, origin); return url.origin === origin && ['http:', 'https:'].includes(url.protocol) ? url.href : null; } catch (_) { return null; }
}
