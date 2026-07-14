// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2021 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
import BasicLayout from '@/layouts/basic-layout';
import Setting from "@/setting";

const pre = 'product_';

export default {
    path: `${Setting.routePre}/product/`,
    name: 'product',
    header: 'product',
    meta: {
        // 授权标识
        auth: ['store-product']
    },
    redirect: {
        name: `${pre}productList`
    },
    component: BasicLayout,
    children: [
        {
            path: 'index',
            name: `${pre}productList`,
            meta: {
                title: '商品列表',
                auth: ['store-product-index']
            },
            component: () => import('@/pages/product/productList')
        },
        {
            path: 'product_attr',
            name: `${pre}productAttr`,
            meta: {
                auth: ['store-product-product-attr'],
                title: '商品规格'
            },
            component: () => import('@/pages/product/productAttr')
        },
        {
            path: 'product_reply',
            name: `${pre}productReply`,
            meta: {
                title: '商品评价',
                auth: ['store-product-product_reply']
            },
            component: () => import('@/pages/product/productReply')
        },
        {
            path: 'shipping_template',
            name: `${pre}shippingTemplate`,
            meta: {
                auth: ['store-shipping_template'],
                title: '运费模板'
            },
            component: () => import('@/pages/product/shippingTemplates')
        },
        {
            path: 'edit_product/:id?',
            name: `${pre}productEdit`,
            meta: {
                title: '商品编辑',
                auth: ['store-product-product_reply'],
            },
            component: () => import('@/pages/product/productEdit')
        },
        {
            path: 'category',
            name: `${pre}category`,
            meta: {
                title: '商品分类',
                auth: ['store-product-category-index'],
            },
            component: () => import('@/pages/product/category')
        }
    ]
};
