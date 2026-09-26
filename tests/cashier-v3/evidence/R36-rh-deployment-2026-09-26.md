# R36 瑞昊部署记录

## 授权与版本

产品经理确认本轮测试通过，授权 commit、部署瑞昊和 push；另行确认保留线上现有会员跨店查看逻辑。业务发布固定提交 `38ccfc10`，含此前 `e8aafc19` 及 C36/R36 各闭环；没有夹带其他未提交改动。

唯一目标：rh.cc3798.com，数据库只读核实为 ruihao；未访问其他客户。线上最近升级记录为 `20260924-001-cashier-v3-checkout-reservation-service-link-v1`（代码 302f841c）。本次无迁移、无配置变更、无生产业务数据写入。

## 发布范围

- 门店端：当前 18091 对应 cashier-v3 正本构建，cashier-v3/shared/inventory-vue3 受跟踪源码无未提交差异。Vite 1972 模块构建通过，仅已有大 chunk 提示。
- 后端：CardOperationAuthorityServices、CardOperationCheckoutSettlementServices、CardOperationKernel、CardOperationResourceDiscovery、CardRuleEntitlementAuthorityServices、EntitlementActualAmountAllocator、EntitlementProjectionServices、DirectSnapshotEntitlementSettlementServices、MemberDetailQueryServices、OrderCenterRecordQueryServices，共 10 个文件，全部从固定 Git 提交导出。
- 两个静态入口：`/www/wwwroot/rh.cc3798.com/public/view_cashier_v3` 与 `/www/wwwroot/rh.cc3798.com-8080/public/view_cashier_v3`。
- 业务后端：`/www/wwwroot/rh.cc3798.com/app/services/cashier/v3`，现有收银 API 由主实例 20800 服务；未修改废弃预览后端或启用额外进程。
- 先上传新哈希资源，后切换入口 HTML；保留旧资源供已打开页面继续加载。

## 备份与回滚

独立目录：`/www/backups/rh.cc3798.com/20260926-r36-38ccfc10`。

- backend-before.tar.gz：0b53ca5e066989c8d342fbb4693f781d8ecd52d05c82ae8b950dd6a42018e2e7
- cashier-main-before.tar.gz：297ae8fd3edf7399e2d93ae27c3c19533b879eed281dc4ff58e5254c0908af12
- cashier-preview-before.tar.gz：297ae8fd3edf7399e2d93ae27c3c19533b879eed281dc4ff58e5254c0908af12

目录保留 backend-files.txt、SHA256SUMS.release 和固定提交导出的 staged 文件。回滚恢复 10 个后端文件及两个入口的备份，重启瑞昊 Swoole 和同实例常驻 worker；无需数据库回滚。

## 部署后验证

- 10 个线上 PHP 文件语法通过、SHA-256 与固定 Git 提交逐项一致。
- 正式及 preview-8080 入口均返回新 HTML；JS `index-a2e202a6.js`、CSS `index-1db94e0e.css` 均经公网下载，与本地构建字节一致。
- 线上纯内核合同测试 PASS，不连接交易写入路径。本地隔离卡操作 31 项、规则 11 项回归通过。
- Swoole 重启后 manager_count=1；AI execution、Excel export、supervisor 三个服务均 active。近期错误日志检查未发现 Fatal/Parse/Uncaught。
- 浏览器真实打开新版门店端登录页；当前线上登录态无效，未声称完成登录后真实结账、替换或核销验收，也未创建生产测试交易。
- 本轮线上发布校验通过后执行双远端 push；具体结果以本任务后续工具回执为准。
