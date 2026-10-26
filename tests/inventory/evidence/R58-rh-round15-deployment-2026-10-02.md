# R58 第15轮瑞昊发布与验证

## 授权、版本与范围

- 产品经理明确测试通过并授权 commit、部署瑞昊、push；另行明确允许执行迁移 20261002-002。
- 已验收本地闭环：10c6a54f（工具/总部草稿/实盘结算）、2b21f2b7（多批次/导入库存），后者准确提交20个文件，其他任务脏改动保留。
- 固定发布提交：3f35e19378c23ae574835f4eec4f9f0700f67736，父版4cd445bb；分支 release/r58-inventory-count-20261002。
- 发布树仅叠加上述两个闭环的34个文件；cashier-v3/shared保持现网基线，inventory-vue3与已验收提交一致。不包含其他任务尚未发布的改动，也不宣称全工作区同步。
- 目标仅rh-server：正式站 /www/wwwroot/rh.cc3798.com、ruihao数据库、20800进程；预览 /www/wwwroot/rh.cc3798.com-8080 只更新门店集成静态包。其他客户实例不变。

## 构建与文件分组

- 前端源码：inventory-vue3的App.vue、InventoryBusinessModal.vue、countWorksheet.js、inventoryApi.js、countSubmission.js。
- 后端源码：13个库存控制器、服务、统一查询及admin路由文件；发布前12个既有文件逐一匹配现网4cd445bb指纹，另1个为新批次目录服务。
- 数据库迁移：2026-10-02-总部盘点草稿入口，仅三个API权限登记，不改库存结构、不增角色权限。
- 自动化测试/验收证据：两闭环提交中的tests/inventory文件；没有把测试脚本发布到站点业务目录。
- 工程脚本：无永久修改。生成物由固定Git archive构建，只读构建暂存 /tmp/r58-build.oQFYvk，不维护第二套可编辑源码。
- 当前热更新唯一源的库存模块及现网已发布收银源集成构建：inventory 1821模块、cashier 1988模块，均成功；已有大chunk提醒。admin未构建、未替换。
- 五项JS合同再次通过；隔离库存库21项业务回归再次通过、failed=0，测试夹具/事件回滚。

## 备份、迁移与回滚

备份目录 /www/backups/rh.cc3798.com/20261002-r58-count-2b21f2b7（目录700、菜单备份600）：

- backend-before.tar.gz：62dc93762df92f217990a32e234543db5bcd342d5a2bbd1b0a83bd64a42b6c0f。
- prod-before.tar.gz：b5b1d3d39adfd3c2bf004fc5a7623ca883a88d7a9684a3b326b0d79e2aa47169。
- preview-before.tar.gz：9209c6093768100bc4a41b7fdda50721b513094fb3b7a5b9df38a0ce92d3b05a。
- system-menus-before.json：1866条，a785dcbd3d77d6b545c1996fe0cb60b0854696e9b937ff3993e679c9b634970b。
- 全库既有回滚点：R57发布前 ruihao-before.sql.gz；本次仅修改已单独备份的菜单表，不写库存、草稿、资金或历史事实。
- 升级前实际数据库ruihao、草稿表存在、三API登记为0。升级后三项各1条，auth_type=2、is_del=0、unique_auth均为inventory-v3-platform-warehouse-manage。角色授权未增加。
- 备份后逐文件原子替换13个PHP文件；Swoole重启manager_count=1、pid8295。权限登记读取沿用菜单DAO直接查询，未清空全站Redis或会话。
- 静态资源追加，不删除旧资源；HTML同目录原子替换。
- 回滚：恢复备份12个既有后端文件及正式/预览HTML，移除本次新增且无旧版依赖的InventoryCountBatchCatalog.php，再重启Swoole；仅移除本次新增三条API登记。不得恢复全库覆盖新业务，不删除草稿或库存事实。

## 线上验证

- 13个线上PHP语法检查通过，发布后每个文件与固定发布提交SHA-256一致。
- 正式门店端、8080预览门店端、平台库存入口HTTPS均200。
- cashier HTML：aecaf3c161525983534f6b9387fc4171f8ab78112fb3b0a94cd8dbed51b516db。
- inventory HTML：3b35f316d2db3248d3eb0512fe688949bd899bc71570378bdf679570f4d2378e。
- cashier主JS index-fbb1ea4e.js：350a6a4545a0b31a006131e39d2b817fb9b1bc2e1f590b5ed05dc4c17984c702（正式/预览HTTPS下载均一致）。
- inventory主JS index-02f6d189.js：a9f473c77ffb25065e5d8128349cbf0a8e5bde2f00e4f419a1494e633a864afe（HTTPS下载一致）。
- 浏览器真人路径：正式admin/inventory/count → 新建盘点，完整工具按钮包括导入库存可见 → 导入库存，读取559个有效总部规格，显示导入0批次且库存尚未修改 → 取消回列表。未保存草稿、未完成盘点。
- 只读服务核对总部仓128：当前有效可导入批次0，与页面一致；门店80亦无有效批次。再检查现有库存门店89：有效目录560规格、可导入97批次，数量/批号/生产日/到期日正确返回，成本无权限全部null。目录已失效规格不纳入导入，不伪造空仓或改数据。
- 线上截图：R58-rh-count-tools-2026-10-02.png。本次没有在门店登录UI执行正式盘点结算；真实结算逻辑以隔离库回归和产品经理验收为证，不冒充线上业务写入验证。

## 推送边界

上述指纹、运行状态、只读服务及浏览器入口验证完成后，按授权仅推送Gitee origin的本轮隔离发布分支；不推送混合开发分支，不推送GitHub。发布记录与截图后续作为单独证据提交归档。
