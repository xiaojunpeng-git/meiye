# mobile-vue3

这是手机端 Vue 3 / uni-app x 的唯一源码工程。商家端和会员端在同一工程内
按业务边界隔离，不复制出第二套手机端源码。

## 固定入口

- 商家端源码：`src/merchant/`
- 商家端协议：`src/merchant/contracts/`
- 商家端登录入口：`src/app/pages/bootstrap/index.uvue`
- 会员端源码：`src/member/`
- 底层公共能力：`src/shared/`
- 微信小程序编译产物：`unpackage/dist/dev/mp-weixin/`（只读派生产物，不是源码）

商家端的页面、接口客户端、会话和导航分别放在 `src/merchant/pages`、
`src/merchant/api`、`src/merchant/platform`、`src/merchant/contracts`。`src/shared` 只保留网络传输、
运行时配置、平台适配和请求上下文等基础能力，不放会员或商家业务页面。

旧 `前端代码/uniapp` 保持兼容冻结，不再作为商家端启动入口。

## 启动与检查

从仓库根目录执行：

```sh
sh scripts/start-mobile-merchant.sh
sh scripts/check-mobile-merchant.sh
```

启动脚本只会打开这个工程的微信小程序编译链路，并打印当前源码指纹、
本地接口地址和开发者工具导入目录；不会保存账号密码，也不会启动生产配置。
首次在微信开发者工具中把服务地址填写为 `http://127.0.0.1:18093`，并仅在本地
开发设置中关闭合法域名校验；这些设置由开发者工具保存，后续执行启动脚本无需
再次寻找或导入旧会员端工程。

永久检查仍使用：

```sh
sh ../../tests/mobile-vue3/run-all.sh
```

Run the permanent checks with the HBuilderX bundled Node runtime:

```sh
sh ../../tests/mobile-vue3/run-all.sh
```

The old `uniapp` project remains compatibility-frozen until each feature has
been replaced and independently accepted.
