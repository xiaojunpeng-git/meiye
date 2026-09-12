// Deliberately bypasses generic error loggers: request bodies contain local chat.
export function browserTransport(prefix, tokenReader) {
  return async (method, path, payload, options = {}) => {
    const token = tokenReader();
    if (!token) throw new Error('请先登录');
    const url = prefix + path;
    const bindingHeaders = {};
    if (method === 'GET' && payload) {
      const names = { client_session_id:'X-Mohe-Ai-Client-Session-Id', run_delivery_token:'X-Mohe-Ai-Run-Delivery-Token', generation:'X-Mohe-Ai-Generation', window_token:'X-Mohe-Ai-Window-Token' };
      Object.keys(payload).forEach(key => { if (!names[key]) throw new Error('无效请求字段'); bindingHeaders[names[key]]=String(payload[key]); });
    }
    const response = await fetch(url, { method, credentials: 'omit', keepalive: options.keepalive === true,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'Authori-zation': 'Bearer ' + token, ...bindingHeaders },
      ...(method !== 'GET' ? { body: JSON.stringify(payload || {}) } : {}) });
    if (options.binary) { if (!response.ok) throw new Error('下载未完成'); return response.blob(); }
    const result = await response.json();
    if (!response.ok || result.status !== 200) {
      const error = new Error(typeof result.msg === 'string' ? result.msg : '请求未完成');
      error.responseKnown = true;
      error.httpStatus = response.status;
      error.reason = result.data && typeof result.data.error_code === 'string' ? result.data.error_code : null;
      throw error;
    }
    return result.data;
  };
}
