// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
export function formatRichText(html) {
  let newContent = html.replace(/<img[^>]*>/gi, function (match, capture) {
    match = match
      .replace(/style="[^"]+"/gi, "")
      .replace(/style='[^']+'/gi, "");
    match = match
      .replace(/width="[^"]+"/gi, "")
      .replace(/width='[^']+'/gi, "");
    match = match
      .replace(/height="[^"]+"/gi, "")
      .replace(/height='[^']+'/gi, "");
    return match;
  });
  newContent = newContent.replace(
    /style="[^"]+"/gi,
    function (match, capture) {
      match = match
        .replace(/width:[^;]+;/gi, "max-width:100%;")
        .replace(/width:[^;]+;/gi, "max-width:100%;");
      return match;
    }
  );
  // newContent = newContent.replace(/<br[^>]*\/>/gi, "");
  newContent = newContent.replace(
    /\<img/gi,
    '<img style="max-width:100%;height:auto;display:block;margin-top:0;margin-bottom:0;"'
  );
  return newContent;
}
