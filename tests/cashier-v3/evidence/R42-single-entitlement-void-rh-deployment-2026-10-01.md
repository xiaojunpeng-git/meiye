# R42 单条纯权益消费整单作废：瑞昊发布记录

- 产品经理确认固定版本验收并授权本地提交、部署瑞昊及 push。本地闭环提交 `080a7616`，仅含 9 个本闭环文件；以瑞昊上一发布版 `3a4888d6` 为父版形成等价独立发布提交 `604db10e`，推送至 `origin/release/r42-single-entitlement-void-20261001`。逐文件比对发布提交和本地闭环提交，本闭环文件无差异；开发分支其他历史未推送。
- 唯一部署目标为瑞昊 `rh.cc3798.com`；后端站点 `/www/wwwroot/rh.cc3798.com`，正式和预览门店端目录分别为该站点及 `/www/wwwroot/rh.cc3798.com-8080` 下的 `public/view_cashier_v3/`。无数据库迁移、配置变更或生产业务数据写入，其他客户实例未操作。门店端构建来自当前 18091 热更新入口绑定的 `前端代码/cashier-v3/` 源码，`npm run build` 通过。
- 独立备份与回滚点：`/www/backups/rh.cc3798.com/20261001-r42-single-entitlement-080a7616/`，包括两个旧 PHP 文件及正式、预览门店端完整静态目录压缩包；压缩包已通过读取校验。旧 PHP 指纹分别为 `6e8280e28f92c217834999fc93b7fb513e161b9f5877439b7e99bc1ed75e4de4` 和 `effae346df4597e6dcbbc9edc17e42eda343ce2a0ee24bb92f859abab7da29d6`。
- 暂存后 PHP 7.4 语法检查通过。线上运行的 `CashierV3OrderLifecycleModule.php` 指纹为 `a12bcdd2d7c3d716754e7f443f85799520d22776587ee9e7b622e0f91b0affe9`，`CashierV3SalesOrderQueryServices.php` 为 `5c3380f47edced2ef7b9ca4d817c28fcde587726143cfd03c203c97eeb51ca91`，均与验收版一致。正式和预览静态目录 `index.html` 均为 `a592ee51eb90158c12b23806a31cce8de2adfb8528bc73392ead18bdb6bf8f4e`；通过预览公开 URL 获取的主 JS 指纹为 `9934931aa8ecc4f3a0b00fca4e78394e3ed79f3dcd29b3e0275e5265b667b5a1`，与本地构建一致。部署保留旧哈希静态资源以保护已打开的浏览器标签。
- `/etc/init.d/ruihao_swoole restart` 成功，20800 端口监听。正式和预览门店端 HTTP 均为 200；运行文件 PHP 语法检查通过，最近错误日志未检出相关 Fatal、Parse、Uncaught 错误。线上验证仅为只读入口、资源和运行态检查，没有作废真实订单；业务路径由本地定向合同测试及产品经理验收覆盖。
- 回滚仅需恢复该目录中两个 PHP 备份与对应正式、预览静态目录包，然后重启瑞昊单实例 Swoole；本次无数据库回滚。此文件为发布后证据，不属于已验收业务提交。
