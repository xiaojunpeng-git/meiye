/** 仅在微信构建中将推广页独占图片归入 users 分包；源图字节不变，H5不参与。
 * 一旦其他包开始引用，拒绝构建，避免产生主包引用未加载分包资源的隐患。
 */
const fs = require('fs');
const crypto = require('crypto');
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
function relocatePromotionAssets(assets) {
  const from = 'static/promotion/';
  const target = 'pages/users/static/promotion/';
  const pictures = Object.keys(assets).filter(file => file.startsWith(from));
  const references = [];
  for (const [file, asset] of Object.entries(assets)) {
    if (!/\.(js|wxml|wxss|json)$/.test(file)) continue;
    const source = asset.source().toString();
    if (!source.includes('/' + from)) continue;
    if (!file.startsWith('pages/users/')) throw Error('Promotion image referenced outside users package: ' + file);
    // 保留绝对路径语义，只替换已核对的资源目录，不改业务代码和其他URL。
    const next = source.split('/' + from).join('/' + target);
    assets[file] = { source: () => next, size: () => Buffer.byteLength(next) };
    references.push(file);
  }
  if (!pictures.length || !references.length) throw Error('Expected promotion assets and references');
  const moved = pictures.map(file => {
    const destination = target + file.slice(from.length);
    if (assets[destination]) throw Error('Duplicate promotion image: ' + destination);
    const sha256 = hash(assets[file].source());
    assets[destination] = assets[file];
    delete assets[file];
    return { from: file, to: destination, sha256 };
  });
  return { moved, references };
}
class MemberMiniPromotionAssetsPlugin {
  apply(compiler) {
    let proof;
    // 在CSS去重与包体上限检查之前调整资源归属；后面的上限门禁仍然保留。
    compiler.hooks.emit.tap({ name: 'MemberMiniPromotionAssets', stage: 9999 }, compilation => {
      proof = relocatePromotionAssets(compilation.assets);
    });
    compiler.hooks.done.tap('MemberMiniPromotionAssetsProof', () => {
      if (proof) fs.writeFileSync(compiler.options.output.path + '-promotion-assets.json', JSON.stringify(proof, null, 2));
    });
  }
}
module.exports = { MemberMiniPromotionAssetsPlugin, relocatePromotionAssets };
