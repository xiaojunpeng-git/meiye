import { isAgentPath } from './pathUtils';
import util from '@/libs/util';

class AuthManager {
  static getToken() {
    console.log(isAgentPath(), 'isAgentPath');
    return isAgentPath()
      ? util.cookies.get('agent_token')
      : util.cookies.get('token');
  }

  static setToken(token) {
    if (isAgentPath()) {
      util.cookies.set('agent_token', token);
    } else {
      util.cookies.set('token', token);
    }
  }

  static clearToken() {
    if (isAgentPath()) {
      util.cookies.remove('agent_token');
    } else {
      util.cookies.remove('token');
    }
  }
}

export default AuthManager;
