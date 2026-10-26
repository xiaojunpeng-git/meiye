# 第20轮：房间设置与服务名称固定验收版本

## 状态

- 基线 HEAD：`b35506e7adc1abce832d41df04dfd7a92fc5a733`
- 闭环文件清单指纹（按下列 13 个源码/迁移/测试文件 SHA-256 清单再摘要）：`9e4e6af82f7ab3ce0ddf81f2bfef979140c0961662ae13750406934e291bfa62`
- 产品经理验收：2026-10-18 明确确认“测试没有问题”，并授权本轮 commit、依次部署瑞昊、push、部署渼约008。
- 当前阶段：产品经理验收通过，进入已授权发布流程。
- 本记录在提交后补记 commit，在逐实例发布后另附部署验证记录。

## 本轮新增与修复

1. “新增房间”从页面头部移动到查询按钮右侧。
2. 房间设置头部新增“服务名称”页签，点击后在同一路由内切换。
3. 服务名称支持按名称和状态查询、新增、编辑、排序、停用与重新启用。
4. 服务名称按当前会话门店独立保存，不连接房间、预约、收银、订单、商品、项目或报表。
5. “新增服务”右侧新增门店级“服务开关”，按钮显示当前开/关状态，点击后立即保存且刷新可恢复。
6. 服务开关仅作为独立设置保存，没有预约、收银、订单、商品、项目、报表或服务名称行状态等业务消费者。
7. 名称、排序、行启停和服务开关写入均带幂等键、乐观版本及同事务审计；门店内名称唯一，跨门店互相隔离。

## 数据与迁移

- 主表：`eb_cashier_v3_service_name`
- 独立开关表：`eb_cashier_v3_service_name_setting`
- 审计表：`eb_cashier_v3_service_name_audit`
- 升级键：`20261018-001-store-service-name-master`
- 本地 `ruihao` 测试库完成升级前检查、结构验证和集成测试。
- 集成测试产生的临时服务名称、开关和审计记录均已按测试门店精确清理。
- 用户已保存的服务名称资料完整保留；页面开关测试最终恢复为“开”。

## 自测结果

- `node tests/cashier-v3/js/service-name-settings-frontend-contract.mjs`：PASS
- `php tests/cashier-v3/php/service-name-settings-contract.php`：PASS
- `php tests/cashier-v3/php/service-name-settings-local-integration.php`（容器内）：PASS
  - 覆盖新增、重复请求幂等、编辑、旧版本冲突、排序、停用、状态筛选、门店隔离、门店内重名、开关默认值、开关保存、开关即时与延迟幂等重放、开关旧版本冲突、开关门店隔离、同事务审计和自动清理。
- PHP 语法检查：PASS
- `git diff --check`：PASS
- `npm run build`：PASS，Vite 共转换 2014 个模块。
- 本地真实页面：PASS
  - 房间页“查询”右侧显示“新增房间”。
  - “服务名称”页签可切换。
  - 新增两条记录后将第二条上移，刷新后的顺序正确。
  - 停用后按钮变为“启用”，选择“已停用”只显示停用记录。
  - 服务开关从“开”切为“关”，刷新后仍为“关”；恢复“开”并再次刷新后仍为“开”。
  - 服务名称行状态未随总开关变化，验证无业务联动。
  - 浏览器控制台无 error 或 warning。

## 构建候选指纹

- `dist/index.html`：`df045a822ee00f1b8e30149163c13e925dfa534bd90ca5083884cc541bd661f1`
- `dist/assets/index-f7cba78c.js`：`b093972f874190346e514f64b030a42b61821a68b25692be4688e1a549a136d5`
- `dist/assets/index-3917944f.css`：`3917944fe17ecf83f39b855b7b78932aed5feea494899d15d18ca29bb74865a8`

## 固定文件 SHA-256

- `前端代码/cashier-v3/src/views/RoomSettingsView.vue`：`ef28c37aa0e6a9c1d4c37bed2415e315718562811a85d8e36754c1c6e1688d53`
- `前端代码/cashier-v3/src/services/roomManagementApi.js`：`1be4ed0568444b35bfc333992a36f3a008b8bf361d436137c555843558812bfd`
- `后端代码/route/cashier-v3.php`：`a9830416658bb5df790b6343aa78d4e852b9ae0bc4a69e9b20d4ddc09c3441e1`
- `后端代码/app/controller/store/system/ServiceNames.php`：`fab071d10f4b5869526c57e6670782f8a460082ef430b82d95030713a9b4e9ce`
- `后端代码/app/services/store/ServiceNameSettingsServices.php`：`880b4742f17baa5f4a5310fdd51e067f5572d0b19d422910b2b736ddfa1fd756`
- `后端代码/database/upgrades/2026-10-18-门店服务名称基础资料/00-升级清单.md`：`98f22f932a54f183eecad899ffbed82ea9bd8d6ebe207886e3ad07da86ea16c8`
- `后端代码/database/upgrades/2026-10-18-门店服务名称基础资料/01-升级前检查.sql`：`491c3f06fd0dee144d7edc869522db8f330d1f0f923d42832ee87e36537dec53`
- `后端代码/database/upgrades/2026-10-18-门店服务名称基础资料/02-正式升级.sql`：`159111e88188957588bb3c508f1ad2788e16a58fd6e0abcb1118425a4455bc79`
- `后端代码/database/upgrades/2026-10-18-门店服务名称基础资料/03-升级后验证.sql`：`56d3e56c3f85df0fd73852bc1faf5f5d50f8718942c08624c7a68433af63b814`
- `后端代码/database/upgrades/2026-10-18-门店服务名称基础资料/04-回滚或应急说明.md`：`a2b790896704933fba1c08ed567a1f2b0e1bcc7342a09e577a9421514bea7cd2`
- `tests/cashier-v3/js/service-name-settings-frontend-contract.mjs`：`7fe9035bcd82af04f3d6375139d2b009af93aff88e223daaaa0ba933e305f142`
- `tests/cashier-v3/php/service-name-settings-contract.php`：`3a87a38fb330d1def1cb23d7883c053847da228fa09609e1d9d507e8e4286e88`
- `tests/cashier-v3/php/service-name-settings-local-integration.php`：`e2b883e060ac83c92c5efd02a7b59d2f42311fff61b1af6d33b907d56ad8ae2f`

## 文件分组

- 前端源码：`RoomSettingsView.vue`、`roomManagementApi.js`
- 后端源码：`cashier-v3.php`、`ServiceNames.php`、`ServiceNameSettingsServices.php`
- 数据库迁移：`2026-10-18-门店服务名称基础资料/`
- 自动化测试：3 个服务名称合同/集成测试文件
- 生成物：`cashier-v3/dist/`，仅作本地候选验证，不作为源码正本
- 验收证据：本文
