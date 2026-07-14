/**
 * 判断当前URL路径的第一个单词是否为'agent'
 * @returns {boolean}
 */
export function isAgentPath() {
  const path = window.location.pathname;
  return path.split('/')[1]
    ? path.split('/')[1].toLowerCase() === 'agent'
    : false;
}
