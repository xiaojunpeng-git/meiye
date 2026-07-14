// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import { tableDelApi } from '@/api/common';
export function modalSure (delfromData) {
    return new Promise((resolve, reject) => {
        let content = '';
        if (delfromData.info !== undefined) {
            // content = `<p>${delfromData.title}</p><p>${delfromData.info}</p>`
            content = `<p>${delfromData.info}</p>`
        } else {
            content = `<p>确定要${delfromData.title}吗？</p><p>${delfromData.title}后将无法恢复，请谨慎操作！</p>`
        }
        this.$Modal.confirm({
            title: delfromData.title,
            content: content,
            loading: true,
            onOk: () => {
                setTimeout(() => {
                    this.$Modal.remove();
                    if (delfromData.success) {
                        delfromData.success.then(async res => {
                            resolve(res);
                        }).catch(res => {
                            reject(res)
                        })
                    } else {
                        tableDelApi(delfromData).then(async res => {
                            resolve(res);
                        }).catch(res => {
                            reject(res)
                        })
                    }
                }, 300);
            },
            onCancel: () => {
               // this.$Message.info('取消成功');
            }
        });
    })
}
