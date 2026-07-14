// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import Vue from 'vue';
/**
 * @description 鉴权指令
 * 当传入的权限当前用户没有时，会移除该组件
 * 用例：<Tag v-auth="['admin']">text</Tag>
 * */
import store from '@/store';
import { includeArray } from '@/libs/system';

export default {
  inserted(el, binding, vnode) {
    const { value } = binding;

    // 智能获取权限：根据当前页面类型选择对应的权限来源
    let access;
    const isAgent = Vue.prototype.__isAgentPath();

    if (isAgent) {
      // agent页面从localStorage获取权限
      const agentAuth = localStorage.getItem('agent_unique_auth');
      access = agentAuth ? agentAuth.split(',') : [];
    } else {
      // 普通页面从store获取权限
      access = store.state.admin.user.info.access || [];
    }

    if (
      value &&
      value instanceof Array &&
      value.length &&
      access &&
      access.length
    ) {
      const isPermission = includeArray(value, access);
      if (!isPermission) {
        el.parentNode && el.parentNode.removeChild(el);
      }
    }
  }
};
