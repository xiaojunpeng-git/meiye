# OPT5 本地人员组织选择器修复与自测

## 问题与范围

- 本地 `18081` 人员页编辑弹窗的组织树显示旧区域“厦门 / 管辖门店”；线上页面由产品经理反馈为正常，本次未改线上。
- `18081` 经同源代理到本地 `18093`，连接本地 `ruihao` 库。库中正式组织有“集团”与 102 家门店，但 `organization_source_mode=organization` 缺少正式切源凭证，因此通用 `buildPickerTree` 回退旧区域树。只读 `source_status` 的唯一 blocker 为“缺少有效正式切源凭证”。
- 本次仅修 `resource=org_store_tree` 选择接口。正式组织模式下，该接口在原生数据完整时读取 `organization` / `organization_store`；不完整时失败关闭。真正 legacy 模式沿用旧树。未改数据库、切源配置、线上实例或前端页面。
- 受限门店范围只显示被授权门店及其祖先组织；仅全量授权可见无门店的组织节点。移除了该接口对 `ids` 的无效空操作分支。

## 自测

- PHP 语法：两个服务文件及回归脚本通过；限定 diff `git diff --check` 通过。
- 本地真实库只读回归 `org-store-tree-local-contract.php`：9 项通过，覆盖正式根节点、102 家可见门店、单门店裁剪、空权限、原生完整性失败关闭及 legacy 模式兼容。脚本在只读事务内回滚。
- 实际浏览器：刷新 `http://127.0.0.1:18081/admin/setting/staff/index` 后，列表筛选和新建人员弹窗“所属组织”均显示“集团”，不再显示“厦门 / 管辖门店”；新建弹窗仅查看后取消，未保存人员。
- 这是 Codex 本地自测，尚待产品经理对本固定版本确认验收；未 commit、部署或 push。

## 固定版本与文件分组

基线 HEAD：`8ab7310aa1432de09c312df0377402e82b611192`。本闭环补丁指纹（两份服务 diff 与新增测试 diff）：`1247043bfd8963c84034367afc96a52a2967c548aede3c98d950c1cfd52336ba`。

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/organization/OrganizationScopeService.php` | `0cc1d9ed6d0e1ba09c58ca1423bf4718f87f3cf96ac593badfe345d375b62db3` |
| 后端源码 | `后端代码/app/services/organization/OrganizationResourceSelectorServices.php` | `a4b5e2a0728ecb686bb981a311729f5503098491a3155a3e8ecf214fb9324490` |
| 自动化测试 | `tests/organization-store-v3/php/org-store-tree-local-contract.php` | `8b53d43016e08298b7626b97e13911c463d12ce2fe12112bc251c6db89a5e5a2` |

前端源码、数据库迁移、工程脚本、生成物：无。验收证据：本文。上述服务文件的注释已补充正式组织 ID 边界、权限裁剪和兼容条件；工作区其他任务改动未纳入本闭环。
