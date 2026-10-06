# 第 28 轮：切店八家门店及组织归属自测

- 基线 HEAD：`fb8a3060c768f44d49e36a30a63b9bc841d7c0a0`
- 范围：仅本地门店端切店组织树；未提交、未部署、未推送。
- 问题：任职门店先于数据范围候选去重，但任职查询未返回门店所属组织，导致“舒城龙舒店”误入“未归属组织”，选择“集团”仅有 7 家。
- 修复：任职查询返回组织 ID/名称；真实无归属的授权门店直接作为根层门店显示，不生成“未归属组织”分组，也不丢失其切店权限。

## 固定文件指纹（SHA-256）

- `后端代码/app/services/employee/EmployeeInternalLoginServices.php`：`5d7e24f334555f866816f429790787250b8679b8b6a4a51fda0e9a6f064fb9df`
- `后端代码/app/services/cashier/v3/CashierV3StoreLoginServices.php`：`38053965728df60ff065d7fbcf3baa6e3830b03fe8b38349cf91ab8d8a154d42`
- `tests/organization-store-v3/php/store-switch-tree-attribution-contract.php`：`0ce60b02b0d7a5579f0d02af0890b7623ee00f10b5f1b9a4f2541c337e84afb2`

## 自测

- 本地 `mohe-platform-app`、`mohe-cashier-app` 只读回归：`PASS candidate_count=8 tree_count=8 direct_org=181 unassigned_group=hidden`。
- `node tests/organization-store-v3/js/store-v3-login-entry-contract.mjs`：全部通过，`ASSERT_FAILED=0`。
- 三个变更文件 PHP 语法检查通过；`git diff --check` 通过。
- 本地热更新页面 `18091`，账号孙贤君、当前门店舒城龙舒店：切店弹窗选择“集团”后列出 8 家（含舒城龙舒店），左侧不再出现“未归属组织”。未真正切换门店，避免改变当前办理上下文。

## 注释与边界

- 在任职查询处补充组织归属投影的去重原因注释；在组织树回退处说明无归属门店保留、不得扩大授权的边界。
- 未改前端收银组件、数据权限定义或员工门店分配；不触碰工作区其他未提交改动。
- 开发与自测完成，待产品经理对上述固定版本确认验收；按项目门禁，本轮尚未 commit / 部署 / push。
