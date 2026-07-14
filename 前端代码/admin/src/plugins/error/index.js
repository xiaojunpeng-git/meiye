// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import store from '@/store';
import util from '@/libs/util';

export default {
  install(Vue, options) {
    Vue.config.errorHandler = function(error, instance, info) {
      Vue.nextTick(() => {
        // store 追加 log
        store.dispatch('admin/log/push', {
          message: `${info}: ${error.message}`,
          type: 'error',
          meta: {
            error
            // instance
          }
        });
        // 只在开发模式下打印 log
        if (process.env.NODE_ENV === 'development') {
          util.log.capsule('iView Admin', 'ErrorHandler', 'error');
          util.log.error('>>>>>> 错误信息 >>>>>>');
          util.log.error('>>>>>> Vue 实例 >>>>>>');
          util.log.error('>>>>>> Error >>>>>>');
        }
      });
    };
  }
};
