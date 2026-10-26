# R14-00001 瑞昊导购轮次与地推现金归列发布记录

## 授权与版本

- 产品经理已对第 14 轮固定版本确认测试无问题，并明确要求 commit、部署瑞昊、push。
- 本地业务提交：`fd5946203ef8ec981317167d88e736addc3bf130`，仅 7 个本轮文件；其他任务原暂存补丁 SHA-256 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46` 前后不变。
- 瑞昊发布基线：`68e9cf2c96671fc0150231785ad595f1b059953d`；精确发布提交：`5237b3c5dd67c97e43d79e2813b8b867bbec63a8`，仅在该基线上集成本轮 7 个文件。
- 已推送到 `origin/release/store-cashier-c14-main`，远端读回为 `5237b3c5dd67c97e43d79e2813b8b867bbec63a8`。未推送开发线其他提交或 GitHub。

## 构建与目标

- 18091 热更新容器 `/app` 挂载唯一源码正本 `前端代码/cashier-v3/`。从精确发布提交冻结 cashier-v3、inventory-vue3、shared 依赖构建 8080 门店端包；Vite 4.5.14、1999 模块构建通过。CSS 声明与线上旧包相同，差异仅为两个 Vue 单文件组件随模板变更而更新的作用域选择器哈希。
- 后端只替换瑞昊正式实例 `/www/wwwroot/rh.cc3798.com/app/services/report/StoreUnifiedReportPhaseTwoServices.php`；正式、既有预览门店端分别替换 `/www/wwwroot/rh.cc3798.com/public/view_cashier_v3/` 与 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3/` 的静态资源及入口。未部署其他客户或其他业务文件。
- 无数据库迁移、数据修改或跨实例配置更改。预览端原有 20801 进程未监听，本次不改变该环境；所验浏览器会话通过当前路由使用正式业务后端。
- 冻结构建包 SHA-256：`ba41cc8f2e8d22d39feb90084708f42e6a7f14bd6fa4a99fe6c943a99347ba2c`；后端服务：`754dd1560f3b1f328812672b6ad5f3cc2406df0e6cdd53981d5366f7b7026abc`；入口 HTML：`4a6eddc50267a80bde2b465e95d70adebb566051252465ad38d7e58f36b3208d`；主 JS：`b96213f6927a52a7441d1a7efdcfb9d169989d9355da3cf5220c7e13a1678571`。

## 备份、验证与回滚

- 发布前独立备份：`/www/backups/rh.cc3798.com/20261012-r14-5237b3c5`，权限 700；正式端与预览端归档均通过 gzip 校验。正式归档 SHA-256 `6b56300aca6178089ec6815c790443765ee06a458088dcf17adc4b41383afdec`，预览归档 `e628ba3a858fe0b5c711d4ef21db2774d88fc2148b8dba61a32b31b9dbe469c3`。
- 替换前严格核对正式 PHP 旧指纹 `5afed54ee881d2f282c9f7d56b97aaaae15da5624205b1ee6544c82902ec77f9` 和两端旧入口 `debd29216b5504ce238c10bd35cfe40644f494dde006a551c63cca8e11aab215`。后端单文件原子替换并重启瑞昊 Swoole，manager PID 10774、20800 接口 HTTP 200；新资源先安装，再原子替换两端 HTML，旧资源保留。
- 正式和预览入口、JS、CSS 均经 HTTPS 返回 200；正式 HTML 与预览 JS 的实际响应 SHA-256 与构建包一致。匿名配置接口 HTTP 200，原有登录门禁未改变。
- 线上只读对账：第 1 轮的 200 元只进第一次列，第二次为 0；另一笔未选轮次的 1000 元进“无轮次”列，第 1～3 次为 0。两笔的后端合计一致，导出包含新列、顺序与取值说明。订单中心重新加载新包后显示导购“第1轮”；旧标签页未刷新时仍运行旧脚本，不视为新包故障。
- 发布前后 `ruihao` 数据库升级日志最新均为 id 222、`20261012-repayment-cross-industry-reward`，没有本轮数据库写入。无实体收款、订单或打印测试。
- 回滚仅在确认没有后续发布后执行：从各自归档恢复正式后端文件与两端旧 `index.html`，重启瑞昊 Swoole；旧哈希资源仍在，不需数据库回滚。不得用发布前全库覆盖后续业务数据。

## 文件归类

- 前端源码：订单中心 Vue、报表 Vue、导购展示服务；后端源码：统一报表服务。
- 自动化测试：订单导购展示 JS 合同、报表投影 PHP 合同。
- 数据库迁移与工程脚本：无。生成物：冻结 8080 门店端包，不作为可编辑源码提交。
- 验收证据：本记录、`R14-field-acquisition-guide-round-local-2026-10-12.md`；两张含真实会员信息的线上截图仅留本地，不加入 Git 发布。
