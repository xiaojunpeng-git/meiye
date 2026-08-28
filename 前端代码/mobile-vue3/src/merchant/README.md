# 商家端唯一源码边界

商家端只从本工程 `mobile-vue3` 读取。底部五个入口的页面正本固定在
`src/merchant/pages`，H5 与微信小程序都从同一套 Vue 3 / uni-app x 源码构建，
不从 `unpackage`、`dist` 或旧 `uniapp` 产物启动。

| 底部入口 | 页面正本 | 路由 |
| --- | --- | --- |
| 经营 | `pages/home/index.uvue` | `/src/merchant/pages/home/index` |
| 数据 | `pages/warehouse/index.uvue` | `/src/merchant/pages/warehouse/index` |
| 客户 | `pages/customers/index.uvue` | `/src/merchant/pages/customers/index` |
| 工作台 | `pages/workbench/index.uvue` | `/src/merchant/pages/workbench/index` |
| 我的 | `pages/profile/index.uvue` | `/src/merchant/pages/profile/index` |

底部入口统一由 `src/shared/components/merchant-primary-tab-bar.uvue` 渲染，
显隐由 `src/shared/platform/merchant-tab-permissions.uts` 与服务端权限共同决定。
