// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
export function importAll(r) {
  const __modules = {};
  r.keys().forEach(key => {
    const m = r(key).default;
    const n = m.name;
    __modules[n] = m;
  });
  return __modules;
}

export function arraysEqual(arr1, arr2) {
  // 如果两个数组的长度不同，直接返回 false
  if (arr1.length !== arr2.length) {
    return false;
  }

  // 将两个数组分别排序
  const sortedArr1 = arr1.slice().sort();
  const sortedArr2 = arr2.slice().sort();

  // 比较排序后的数组
  for (let i = 0; i < sortedArr1.length; i++) {
    if (sortedArr1[i] !== sortedArr2[i]) {
      return false;
    }
  }

  return true;
}

/**
 * 函数防抖 (只执行最后一次点击)
 * @param {*} fn
 * @param {*} t
 * @returns
 */
export const Debounce = (fn, t) => {
  const delay = t || 500;
  let timer;
  return function() {
    const args = arguments;
    if (timer) {
      clearTimeout(timer);
    }
    timer = setTimeout(() => {
      timer = null;
      fn.apply(this, args);
    }, delay);
  };
};

export function isXlsUpload(file) {
  const typeArry = ['.xls', '.xlsx'];
  const type = file.name.substring(file.name.lastIndexOf('.'));
  const isFile = typeArry.indexOf(type) > -1;
  if (!isFile) {
    Message.error('上传文件格式不对');
  }
  return isFile;
}
