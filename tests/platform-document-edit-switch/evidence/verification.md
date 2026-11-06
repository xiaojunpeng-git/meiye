# DATA-R5 平台单据修改总开关

开发与自测完成，待产品经理对固定候选明确验收。指纹 `e18f46c1f0d11c95595209c1240cfc64c2ee11b1c6778d0e14a182f747c839f4`；基线 HEAD `14874f1e7f53fa0ca36b72d5b845825897d80477`，详见 fixed-candidate.json。本轮仅本地实现，未提交、未部署、未 push、未连接远程。

## 已确认范围

系统信息“充值最大赠送比例”下方增加“平台端允许修改单据”，默认开启。关闭后平台订单中心的人员/业绩归属、日期、来源、备注、收款方式、退款、作废、补交等写操作均禁止，仅保留查询、详情、打印、导出；门店端不受影响。不是岗位授权配置，不改变原账号、组织范围和历史业务事实。业绩区间/提成规则属于配置而非业务单据，本次不修改其规则。

## 数据架构自检

权威源是当前实例 system_config 的唯一配置键；无新单据、事实、指标或历史回填。新定义默认 1，重复迁移保留现值。表单按原配置授权保存并验证二值，读取数据库避免进程缓存。平台入口按权威 action 清单阻断，未知动作仍失败；打印与导出命令是明确例外。共用事务在真实平台身份重建后再次取配置共享锁，与配置写入串行化，客户端伪造身份/命令类型不构成授权。原业务事务、幂等、资源锁和冲销保持不变。门店身份不进入平台配置分支。

## 代码与注释

后端：PlatformDocumentEditPolicy（新）、PlatformOrderCenterActionServices、PlatformOrderCenterReadServices、CashierV3CommandGatewayServices、SystemConfigServices、SystemConfig 控制器。新增策略及修改分支均说明身份、配置、缓存或事务边界。

前端：OrderCenterView、SalesOrderDetailOverlay、platformDocumentEditPolicy.js（新）。两端继续复用页面和动作，仅平台消费新增开关；前端判定与后端清单语义一致，旧弹窗提交也受校验。

迁移：database/upgrades/20261106_platform_document_edit_switch.sql；只在本地安装，默认已恢复开启。

测试：php/local.php、php/boundaries.php、js/policy.mjs。工程脚本：无新增。生成物：/tmp/data-r5-cashier-build（不作为源码、不提交）。证据在本目录。

## 验证结果

- 本地事务回滚测试 261 项 PASS，包含全部 243 个 action/别名分类、默认值、幂等迁移、关闭时真实列表/详情、直接写入拒绝、订单不变、重新开启、旧成功命令重试拒绝、实际 XLSX 生成和下载。
- 真实设置保存/读回、非法值、同订单门店操作及绕过平台入口直接进事务共 10 项 PASS。
- JS 243 项清单比对及真实 requestAction 包装器验证：关闭旧弹窗不发送，查询/打印/导出继续发送，开启恢复。
- Vue3 build 通过，主 JS index-15fa2c29.js，CSS index-a0561cf6.css；PHP 语法通过。平台设置使用现有动态表单，无平台前端源码改动。
- 本地真实浏览器：新增位置及默认开启；关闭后保存成功，刷新仍关闭；订单手艺人/日期/作废入口隐藏，详情可读、打印保留；退款页无办理按钮，查询/导出仍存在；恢复开启后日期、手艺人、作废入口恢复。

## 固定与待办

共享工作区原有其他任务修改已记 baseline.json，本轮未暂存或改动共享 HEAD。固定候选以列出的文件指纹标识；后续提交必须隔离本轮补丁并保留已上线功能，不将原有未验收修改夹带提交。尚待产品经理验收；本轮无上线授权。
