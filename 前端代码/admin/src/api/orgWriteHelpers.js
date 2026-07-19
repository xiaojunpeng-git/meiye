/**
 * 组织工作台写接口：响应分类与 token 绑定（纯函数，供页面与确定性测试复用）
 * 不依赖 axios / 全局拦截器。
 */

export function stableSerialize(value) {
  if (value === null || typeof value !== 'object') {
    return JSON.stringify(value);
  }
  if (Array.isArray(value)) {
    return `[${value.map((v) => stableSerialize(v)).join(',')}]`;
  }
  const keys = Object.keys(value).sort();
  return `{${keys.map((k) => `${JSON.stringify(k)}:${stableSerialize(value[k])}`).join(',')}}`;
}

export function normalizeOrgWritePayload(payload) {
  if (payload == null) return {};
  if (typeof payload !== 'object') return { value: payload };
  const clone = Array.isArray(payload) ? payload.slice() : { ...payload };
  if (!Array.isArray(clone) && Object.prototype.hasOwnProperty.call(clone, 'request_token')) {
    delete clone.request_token;
  }
  return clone;
}

export function orgWriteFingerprint(action, payload) {
  return `${String(action || '')}|${stableSerialize(normalizeOrgWritePayload(payload))}`;
}

/**
 * @param {*} res 拦截器 resolve 的值（可能为 undefined）
 * @returns {{ kind: 'success'|'unknown', status: number, msg: string, res?: object }}
 */
export function classifyOrgWriteResolved(res) {
  if (res == null || typeof res !== 'object') {
    return {
      kind: 'unknown',
      status: 0,
      msg: '提交结果未知，请确认是否已生效后再操作',
    };
  }
  const status = Number(res.status);
  if (status === 200) {
    return { kind: 'success', status: 200, msg: res.msg || '成功', res };
  }
  if (status >= 500) {
    return {
      kind: 'unknown',
      status,
      msg: '提交结果未知，请确认是否已生效后再操作',
    };
  }
  return {
    kind: 'unknown',
    status: status || 0,
    msg: '提交结果未知，请确认是否已生效后再操作',
  };
}

/**
 * @param {*} err 拦截器 reject 或包装错误
 * @returns {{ kind: 'business_fail'|'unknown', status: number, msg: string, err?: object }}
 */
export function classifyOrgWriteRejected(err) {
  if (err == null) {
    return {
      kind: 'unknown',
      status: 0,
      msg: '提交结果未知，请确认是否已生效后再操作',
    };
  }
  const httpStatus = Number(
    (err.response && err.response.status) || err.status || 0
  );
  const bizStatus = Number(err.status || 0);
  const msg = (err && err.msg) || (err && err.message) || '';

  if (bizStatus === 400 || bizStatus === 400011 || bizStatus === 400012) {
    return {
      kind: 'business_fail',
      status: bizStatus,
      msg: msg || '操作失败',
      err,
    };
  }
  if (
    httpStatus >= 500 ||
    httpStatus === 408 ||
    err.code === 'ECONNABORTED' ||
    /timeout/i.test(String(msg)) ||
    /network error/i.test(String(msg))
  ) {
    return {
      kind: 'unknown',
      status: httpStatus || bizStatus || 0,
      msg: '提交结果未知，请确认是否已生效后再操作',
      err,
    };
  }
  if (err.__orgWriteKind === 'business_fail') {
    return {
      kind: 'business_fail',
      status: bizStatus || 400,
      msg: msg || '操作失败',
      err,
    };
  }
  return {
    kind: 'unknown',
    status: httpStatus || bizStatus || 0,
    msg: '提交结果未知，请确认是否已生效后再操作',
    err,
  };
}

/**
 * token 与 action + 规范化 payload 绑定：同指纹复用，变更加新。
 * @param {{ token?: string, fingerprint?: string }|null} prev
 * @param {string} action
 * @param {*} payload
 * @param {() => string} genToken
 */
export function resolveOrgWriteToken(prev, action, payload, genToken) {
  const fingerprint = orgWriteFingerprint(action, payload);
  if (prev && prev.fingerprint === fingerprint && prev.token) {
    return { token: prev.token, fingerprint, reused: true };
  }
  return { token: genToken(), fingerprint, reused: false };
}
