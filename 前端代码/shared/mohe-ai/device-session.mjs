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
  }
  load() {
    let data;
    try { data = JSON.parse(this.storage.getItem(this.key) || '[]'); } catch (_) { data = []; }
    const now = this.clock();
    const valid = Array.isArray(data) ? data.filter(s => s && typeof s.id === 'string' && Number.isSafeInteger(s.created_at) && s.created_at <= now && s.created_at + RETENTION_MS > now && Array.isArray(s.rounds)).map(s => ({ ...s, rounds: s.rounds.filter(r => r && typeof r.question === 'string' && typeof r.answer === 'string' && Number.isSafeInteger(r.created_at) && r.created_at <= now && r.created_at + RETENTION_MS > now) })) : [];
    this.save(valid); return valid;
  }
  save(sessions) { this.storage.setItem(this.key, JSON.stringify(sessions)); }
  create(id = newId()) { const sessions = this.load(); const session = { id, created_at: this.clock(), rounds: [] }; sessions.push(session); this.save(sessions); return session; }
  history(id) { const s = this.load().find(s => s.id === id); return s ? s.rounds.slice(-HISTORY_ROUNDS).map(r => ({ question: r.question, answer: r.answer })) : []; }
  contextRef(id) {
    const session = this.load().find(value => value.id === id);
    const last = session && session.rounds.length ? session.rounds[session.rounds.length - 1] : null;
    const ref = last && last.presentation && last.presentation.context_ref;
    // Forward an opaque reference only. The server must validate ownership,
    // expiry and whether this new question actually refers to the old answer.
    return typeof ref === 'string' && ref.length > 0 ? ref : null;
  }
  append(id, question, answer, presentation) {
    const sessions = this.load(); const s = sessions.find(s => s.id === id);
    if (!s) throw new Error('会话已到期，请新建对话。');
    if (typeof question !== 'string' || typeof answer !== 'string') throw new Error('无效对话');
    s.rounds.push({ question, answer, presentation, created_at: this.clock() }); this.save(sessions);
  }
  clear() { this.storage.removeItem(this.key); }
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
