// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
/**
 * @description 鉴权指令
 * 当传入的权限当前用户没有时，会移除该组件
 * 用例：<Tag v-auth="['store']">text</Tag>
 * */
import store from '@/store';
import { includeArray } from '@/libs/system';

export default {
    inserted (el, binding, vnode) {
        const { value } = binding;
        const access = store.state.store.user.info.access;

        if (value && value instanceof Array && value.length && access && access.length) {
            const isPermission = includeArray(value, access);
            if (!isPermission) {
                el.parentNode && el.parentNode.removeChild(el);
            }
        }
    }
}
