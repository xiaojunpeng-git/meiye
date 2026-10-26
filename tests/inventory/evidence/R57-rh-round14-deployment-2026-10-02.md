# R57 瑞昊第14轮库存发布记录

## 授权与固定版本

- 产品经理明确确认本轮测试通过，并授权 commit、部署瑞昊、push；在指出生产结构依赖后，明确回复“允许”执行三份迁移。
- 本地业务闭环 commit：dfd7627f，R57 请货转调拨与草稿保存契约闭环；此前本轮提交为 def6d6ed、d087486a、3811414d、66a47b06、25c9da6f。
- 隔离发布 commit：b573aff89fc7792f744761ebd56787b8ee98b2f4；父版本为现网 cf6a90a65eb0f6ee1eeabd7b8e4ba638b44b8284。
- 发布分支：release/r57-inventory-round14-20261002；仅推送 Gitee origin，不推送主开发分支及 GitHub。
- 此包只替换已验收库存变化。cashier-v3 与 shared 源码保持现网基线，inventory-vue3 与 dfd7627f 完全一致；不宣称是整个工作区最新收银包。

## 实例、备份、迁移与回滚

- 唯一目标：rh-server，正式站 /www/wwwroot/rh.cc3798.com，数据库 ruihao；8080 预览仅更新门店端集成包。
- 备份目录：/www/backups/rh.cc3798.com/20261002-r57-inventory-dfd7627f，权限 700。
- 全库备份 ruihao-before.sql.gz 已通过 gzip 完整性检查；SHA-256 d0d81a95051f3cd8fe6dcf9f10e01a8a22fb948c028fd06f3f87d79eded2cf73。
- 后端20个受影响文件及正式/预览前端包均已归档。发布前20个后端文件与 cf6a90a 逐文件哈希一致；发布后与隔离提交逐文件校验一致。
- 执行迁移：2026-08-08-库存V3调拨人快照、2026-10-02-库存V3请货受理方、2026-10-02-库存V3业务与操作信息分离。升级前调拨人3列为0，前检查 PASS。
- 升级后：调拨人3列齐全、无非法人员快照；受理方目录表1、单据新增列2、总部/办公室/采购部三条目录；非总部错误受理方及缺失快照均0；审计7列检查 PASS、非法新流水操作人0。
- 无历史业务日期、操作人或受理部门猜测回填；不拆分总部库存，不进行发货/收货/真实资金操作。
- 应急回滚：先恢复备份的20个后端文件及HTML，再重启 ruihao_swoole；保留新增兼容列与目录。全库恢复与物理删列另行确认，不在本次自动执行范围。
- 临时连接凭据仅存受限 client.cnf，发布验证结束后已删除；不提交、不输出凭据。

## 文件分组与构建

- 前端源码：inventory-vue3 的 App.vue、InventoryBusinessModal.vue、inventoryApi.js、vite.config.js。
- 后端源码：库存请货控制器2个、库存服务/统一查询18个文件（含2个路由文件）；完整列表由隔离提交相对 cf6a90a 的 Git diff 及远程 r57-backend-paths.txt 记录。
- 数据库迁移：上述迁移目录；新增迁移正本仍在 后端代码/database/upgrades/。
- 自动化测试与自测证据：tests/inventory/ 下本轮契约与验收记录；不发布业务可写测试到站点。
- 工程脚本：无永久脚本改动。临时发布工具只处理本实例备份、白名单文件及只读检查。
- 生成物：从隔离 Git 提交不可变 archive 构建，依赖链接既有 node_modules；inventory-vue3、cashier-v3 构建成功。未构建/发布有其他任务脏改动的 admin 工程。
- 前端静态资源追加部署，不删除旧资源；HTML 采用同目录原子替换。
- 正式和预览 cashier HTML SHA-256：f8976fe4b343e89105fe319dfd0b5f12ef2c0d151bc0649d832731c0bdcf1b67。
- cashier JS index-4ce02b09.js SHA-256：674d4eecc3f71e4c0b6998f41129efbf7739c1029a0a0f480b3bf2c8a5fb91ef。
- inventory HTML SHA-256：9b4dae7e61d72366cfc08c97de10c24047c143ace52439f733d6d85e4a4558f8；JS index-d544833b.js：8095ba38452c6e45f4a186c878acc626f542007d20d7dc561e2810af9c194e15。

## 验证结果

- 20个线上 PHP 文件语法检查通过；Swoole 重启后 manager_count=1、pid=25521。
- 正式门店端、8080预览门店端、平台库存页面及主JS均 HTTPS 200。
- 线上只读服务：受理目录三项正确，请货9笔，8笔可转调拨/待履约，中文关键词查询9笔；调拨0笔。没有创建线上测试单据。
- 线上 request-keyword-mysql-regression.php 与 cross-transfer-key-order-contract.php 通过；本地操作审计 UI 契约通过。
- 旧 request-dialog-contract.mjs 共22项通过、1项失败：断言仍查找早期日期标签三元表达式，实际代码已改为统一业务日期标签映射。本次真实页面验证了正确标签、列顺序和筛选结果；不宣称此旧静态脚本全通过。
- 真人浏览器路径：打开正式 admin/stock/request → 新建请货，供货方总部仓时出现“受理部门”及三项 → 取消 → 最近7天业务日期筛选查到9笔，分别显示请货日期/操作日期/操作人 → 转调拨，自动关联 QH2610010009、调入宣城龙腾店、10条待履约明细与数量 → 取消，未保存/发货。
- 截图：/tmp/r57-rh-request-list.jpg、/tmp/r57-rh-acceptance-department.jpg、/tmp/r57-rh-transfer-prefill.jpg。
- 本次线上页面加载正常；此前本地开发热更新嵌入页偶发空白尚未证明根因消除，不扩大为全系统已修复声明。

## 推送门禁

以上线上文件、数据库结构、只读查询和浏览器路径验证完成后，才执行已授权隔离分支 push。其他工作区改动保留且不提交/不部署。
