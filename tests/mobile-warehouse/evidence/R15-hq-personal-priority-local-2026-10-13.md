# OPT5 R15：总部“个人”权限优先，本人员工业绩入口

## 范围与固定版本

- 产品经理确认的业务口径：总部明确设为“个人”时，旧门店来源的“本店”不能扩权；各端经营数据与报表按本人范围读取。
- 基线 HEAD：`c4de5addec14e4e6ef3a49be6b1715826606772d`。本闭环补丁 SHA-256：`0dba56a4bb0081948e69be8670dd6fc96cd4df5924e5a9f7a4d845505c234edb`。补丁计算时排除了 `MobileMerchantSessionServices.php` 中本轮开始前已有的登录状态表初始化改动；该改动不属于本闭环，后续提交必须单独挑选本闭环 hunk。
- 当前为本地开发与自测完成，待产品经理对该固定版本确认验收；未 commit、未部署、未 push、未上传体验版。浏览器 H5 目前连接瑞昊线上旧接口，无法将本地修复记为线上通过。

## 文件清单与 SHA-256

| 类别 | 文件（均相对源码正本） | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/organization/EmployeeDataScopeServices.php` | `2d095f58d959db372d92b1dec3c8072c8029f195b4b98572bc5bd5d523649572` |
| 后端源码 | `后端代码/app/services/mobile/merchant/MobileMerchantAnalyticsEntryPolicy.php` | `49a9b634e3192ff4698495365fe42fdff0c4e51ed38d516585bd3b33732ea114` |
| 后端源码 | `后端代码/app/services/mobile/merchant/MobileMerchantSessionServices.php` | `01a2eb7496eec7f08a9e6dedeb9f20f6c981a5b217e41efba84330f0b59810cb` |
| 后端源码 | `后端代码/app/services/mobile/merchant/MobileMerchantRequestContextResolver.php` | `f192e06a5faac4e0a46b53d44d569a565e17d4d140be1859a50ea7e22e6468ed` |
| 后端源码 | `后端代码/app/services/mobile/dashboard/MobileMerchantDashboardServices.php` | `33bdc49c7a8028ea4fe51742b6a8442a95d28275ee326d59fc6041d262d74a7d` |
| 前端源码 | `前端代码/mobile-vue3/src/shared/platform/mobile-navigation.uts` | `270758711249dff53d6f781552b13d619e1dd82aa09bb5020cabc040d6f29ea7` |
| 前端源码 | `前端代码/mobile-vue3/src/merchant/pages/employee-performance/index.uvue` | `f8a891501baba19d3904dbbf37e89572aeeef56b5dfb97a39f380d49f8ada1f1` |
| 自动化测试 | `tests/organization-store-v3/php/staff-selected-stores-transaction.php` | `a1e3632d41661dd93f4b153f7abf26d80b74f8d3a6ab76615881cd87924df4cc` |
| 自动化测试 | `tests/mobile-warehouse/php/ranking-scope-contract.php` | `09863e7d44518614b60251c6c3a2359f9647e18152541e186836f6372ed75c6b` |

数据库迁移、工程脚本、生成物：本轮无。验收证据：本文。上列后端与前端业务文件均补充或更新了本轮权限、入口和返回约束的注释。

## 核实与自测

1. 瑞昊线上只读核实：账号 `A18951448755` 对应员工 714 杜秋梅，总部员工资料接口的最新范围为 `source_type=hq, scope_mode=personal`；同时数仓概览返回 `entryType=store, entryNodeId=22`。这证实线上当前把历史本店授权合并进了个人范围。直接打开本人业绩详情可以正常显示。
2. 本地数据库约有 207 名员工同时存在有效的总部个人与本店来源记录，因此修复放在共享数据权限解析层，保留总部无明确个人授权时的原有门店来源行为。
3. `bash tests/mobile-warehouse/run-contract.sh` 通过：总部个人与旧本店混合时入口只能指向本人，组织/门店排行不可见；原指定门店、组织及分页合同继续通过。
4. `docker exec -i mohe-platform-app php < tests/organization-store-v3/php/staff-selected-stores-transaction.php` 通过：总部个人覆盖旧本店、门店上下文不扩权、兼具超管角色仍受显式个人限制、订单列表不绕过、会话识别个人、个人不能获取整店经营能力或直达整店首页；切回指定门店后仅恢复显式门店范围。所有测试写入均在本地事务结束时回滚。
5. 对员工 714 的本地镜像执行临时事务：加入总部个人设置后，数仓接口仅返回 `employee-performance` 且 `employeeId=714`，不返回整店汇总与排行；请求员工 715 的详情被服务端拒绝。事务已回滚，复查其总部权限行仍为 0。
6. 相关 PHP 文件语法检查、前端两个 UTS 脚本解析检查、`git diff --check` 均通过。个人详情无页面栈时返回“我的”，避免再次进入数据页形成循环。
7. 全量 `tests/mobile-vue3/run-all.sh` 未通过：54 项中 32 通过、22 失败；失败包括测试引用已不存在的旧源码路径及当前 `WEB` 目标与旧断言不符，与本轮两处 UTS 改动无直接对应。该整套测试不能作为本轮全绿证据。

## 边界

- 统一数据权限、经营数仓与报表采用总部个人优先；客户、预约等操作性功能的岗位授权与业务对象规则未在本闭环改动。
- 本地 H5 当前仍请求瑞昊线上旧后端。本轮未获部署授权，账号真实线上入口须待验收及单独部署后复核。
