// 24字节入口token以base64url压缩为32字符，扫码还原原token；不把调查scene误当推广人。
export function surveyEntryToken(options = {}) {
  if (options.entry) {
    if (!/^[a-f0-9]{48}$/.test(String(options.entry))) throw new Error('问卷链接无效');
    return String(options.entry);
  }
  if (!options.scene) return '';
  const scene = decodeURIComponent(String(options.scene));
  if (!/^[A-Za-z0-9_-]{32}$/.test(scene)) throw new Error('问卷链接无效');
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  let hex = '';
  for (let i = 0; i < scene.length; i += 4) {
    const bits = (alphabet.indexOf(scene[i]) << 18) | (alphabet.indexOf(scene[i + 1]) << 12) | (alphabet.indexOf(scene[i + 2]) << 6) | alphabet.indexOf(scene[i + 3]);
    for (const shift of [16, 8, 0]) hex += ((bits >> shift) & 255).toString(16).padStart(2, '0');
  }
  return hex;
}
