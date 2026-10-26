import Setting from '@/setting';
import util from '@/libs/util';
import Vue from 'vue';
import { browserTransport } from '../../../shared/mohe-ai/browser-transport.mjs';

// AI configuration and questions must not pass through generic payload loggers.
const request = browserTransport(String(Setting.apiBaseURL).replace(/\/+$/, '') + '/ai', () =>
  (typeof Vue.prototype.__getToken === 'function' ? Vue.prototype.__getToken() : '') || util.cookies.get('token') || '');
export default request;
