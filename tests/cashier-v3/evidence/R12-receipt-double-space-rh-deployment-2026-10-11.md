# R12-00012 签字间距加倍瑞昊发布记录

## 验收与提交

- 产品经理验收本次补调并明确授权 commit、部署瑞昊、push；固定版本及原话见 `R12-receipt-double-space-pm-acceptance-2026-10-11.md`。
- 本地业务提交：`1f022a626b88b2c616fa440d0de92ea57ba8f196`，`R12-00012 小票三方签字间距加倍闭环`。只有前端源码 1 个、自动化测试 1 个、验收证据 3 个。
- 精确发布提交：`b19e3d4ab32381c1f7e971e505f79bbdb45af8ea`；父提交为已推送基线 `2eb6302be2424f3efa0aac9a6d8cd9542c2918d6`。只集成上述 5 个文件，不推本地其他任务的提交历史。
- 未切换 checkout 或新建 worktree。无关暂存区 SHA-256 仍为 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`；其余脏工作区保持原样。
- 本次仅将 iframe 预览与顶层打印签字行最小高度 10mm 改为 20mm，并更新职责注释；字号、内容、金额、纸宽、分页保护和撕纸区不变。

## 构建与文件分组

- 18091 `mohe-cashier-v3-src` 仍挂载唯一正本 `前端代码/cashier-v3/` 至 `/app`；inventory/shared 来自同一正本。
- 冻结归档来自精确发布提交；cashier、inventory、shared 三棵树与本地业务提交一致，195 个源文件逐一 SHA-256 校验通过，构建后仍一致。只读构建快照在验证后清理，不形成第二份可编辑源码。
- inventory/cashier `npm ci`，cashier `npm run build` 成功，Vite 4.5.14 / 1999 模块；仅既有大分包提示。关键依赖与热更新容器相同，完整版本见 manifest。
- 新主 JS `assets/index-6d347f40.js`，2071215 字节，SHA-256 `24f24df5754589ebb6e2956a18207e85248b40b8035683021cf33a37c3c6fa04`。
- 将新主 JS 的两处 20mm 及对应注释还原为旧文后，与上一版线上主 JS 逐字节完全一致；其他 CSS、Excel 分包、图片内容未变。构建日志的字符长度不是 UTF-8 文件字节数，已核对并排除误报。
- 新 HTML SHA-256 `dfb9b43e059536e801e27db5a11694abdbbea585bd8012354c4891aedd81880e`；发布包 SHA-256 `392b8d08b06815ecac544497c0163c1f856cf3f9f7739dba15ddf0933a2d3fe0`。
- 源码清单：`前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js`；测试：`tests/cashier-v3/js/sales-order-receipt-print-contract.mjs`。后端源码、数据库迁移、永久工程脚本：无。
- 生成物：入口 HTML 及 4 个哈希资源，只作为发布产物，不混入源码提交；构建与来源清单 `R12-receipt-double-space-release-manifest-2026-10-11.json`。
- 验收与上线证据：本轮 selftest、PM acceptance、两张截图、manifest、online-assets 及本记录；均与业务源码分组管理。

## 实例、备份与部署

- 仅瑞昊 `rh.cc3798.com`，主机 `iZbp1fif4p5s7gj96nnec0Z`。正式静态目录 `/www/wwwroot/rh.cc3798.com/public/view_cashier_v3`，同步该实例既有 8080 集成静态目录 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3`。
- 只读事务核对数据库 `ruihao` / MySQL 5.7.44-log，升级版本仍为 221 / `20261011-003-inventory-no-expiry`。无数据库或业务写入，不执行迁移、数据回滚或新增数据库备份；既有数据库备份策略保持。
- 发布前和切换前均核对两个旧 HTML SHA-256 为 `82dde804e3662d51772fb4cb53d9e50a1eb516780466472dc011e19bb70c9cf2`，与上一版发布一致。
- 新独立备份 `/www/backups/rh.cc3798.com/20261011-r12-spacing-b19e3d4a`，gzip 校验通过：`formal-before.tar.gz` 45861981 字节 / SHA-256 `31cc271f0e9107ee76c3b39e2ecede2a0dfdbb67e0e7229b917f0557e0b33027`；`preview-before.tar.gz` 45861978 字节 / SHA-256 `11c171321a9ffc438687e7fa412dcd973998e9fede8d8f2261f958fc3a65b01c`。
- 上传包及候选 5 文件校验后，先安装哈希资源，再原子替换两处入口。同名资源先 cmp；保留旧哈希资源；设置失败恢复入口的保护，本次未触发回滚。
- 部署完成：`2026-10-11T22:18:07+08:00`。只发布静态文件，不改 Nginx、Swoole、配置或服务器 Git，不重启服务，不触及其他客户。
- 回滚点：先确认无后续发布，再从上述两个独立备份分别恢复 `./index.html`，原子替换对应入口并验证旧指纹；新增哈希资源可保留，不覆盖其他文件或数据库。

## 验证与限制

- 小票合同 20 PASS / 0 FAIL；作废小票恢复回归 PASS；diff check 通过。实际本地订单三行高度均为 75.58 CSS px (20mm)，产品经理已验收。
- 正式与 preview-8080 共 10 项 HTTPS 资源 HTTP 200 且 SHA-256 全部匹配；预览 HTML 按既有 Nginx sub_filter 路径转换验证。详见 `R12-receipt-double-space-online-assets-2026-10-11.json`。
- 下载的正式 JS 包含有序三方签字标签及两处 20mm 规则；76mm 纸宽的 21px 分支保留，归一化 bundle 对比证明字号与其他逻辑未变。
- manager PID 21836 / 启动时间 21:09:13、20800 监听保持；匿名 `/cashierapi/config` 返回预期鉴权状态 410000 / 请登录。预约模块 `CashierV3ReservationModule.php` SHA-256 仍为 `061a89583e6110f490196ceee5955020f763d3791d99fcc243408629faa99efa`。一次补充只读检查误用了不存在的 `CashierV3ReservationServices.php` 路径，随后按正本定位到上述正确模块并通过校验，无远程变更。
- 浏览器正式入口加载 `/view_cashier_v3/assets/index-6d347f40.js`，登录页正常，错误/警告 0；截图 `R12-receipt-double-space-rh-entry-2026-10-11.png`。临时验证标签已关闭，用户原标签保留。
- 无生产门店登录会话，因此不声称已验证生产登录后订单小票 UI 或实体打印；未创建生产订单、未触发实体打印。preview-8080 的 20801 后端既有不可用限制未改动，不声称其业务全功能通过。

## 归档与 Push 边界

线上静态资源、鉴权与运行态验证已通过。按本次明确授权，归档后仅向 Gitee `origin/release/store-cashier-c14-main` 快进推送本轮精确发布和证据提交，不强推，不推其他远程，不夹带其他任务文件。最终远程提交哈希在 push 后用 `git ls-remote` 核对，并回报产品经理；本文生成时该最后步骤尚未执行。
