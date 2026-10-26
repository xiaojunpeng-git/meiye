// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
export function TMap(key) {
  return new Promise(function(resolve, reject) {
    window.init = function() {
      resolve(window.qq); // 关键
    };
    var script = document.createElement('script');
    script.type = 'text/javascript';
    script.src = `https://map.qq.com/api/js?v=2.exp&callback=init&key=${key}`;
    script.onerror = reject;
    document.head.appendChild(script);
  });
}
