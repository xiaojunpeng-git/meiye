# 会员“我的项目”排除作废来源：固定候选

- 基线 HEAD：`d9d0c5353102e8baa32664d7af32278ffa02ecef`。
- 仅修改会员卡包的读取范围：权益自身未删除但其来源订单已作废（`terminal_action=2`）时，“有效”和“全部”均不显示；列表与总数在 SQL 分页前统一过滤。正常订单（包括旧空标记）仍显示。权益和订单历史均不改写。
- 本次隔离补丁指纹：`f097efab8b342aa89c7578afee6064e4878e0c1b86bca9cf8beea4be40bcc262`（DAO 差异、服务新增三行、隔离测试内容）。

## 文件指纹

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端 | `后端代码/app/dao/user/UserCardHolderDao.php` | `eaa9f698ce3aec330a78d5379780cb0b896e5aca2f55829d1a94094fb33756f1` |
| 后端 | `后端代码/app/services/user/UserCardHolderServices.php` | `8c263bbbf28744740e765492dba87bd76cfb98905fdac01e6662db466a7e6b26` |
| 测试 | `tests/member/php/card-pack-void-source-sqlite.php` | `0c459fe16a90f8683bd384fc139ac75905eaf4b9fc4d7b20cf953566e2ab2bc7` |

服务文件还有其他任务对卡包分类的暂存与未暂存改动，不属于本候选；提交时只暂存本次三行。会员 Vue2 页面未修改。

## 自测

- PHP 7.4：后端两文件及隔离测试脚本语法通过。
- PHP 7.4 + SQLite 内存数据库：正常 `terminal_action=0/NULL` 两项保留，已作废一项排除；列表总数为 2，第二页正常返回，未请求过滤的 DAO 查询保持原有 3 项；3 项检查通过。
- `git diff --check` 通过。未取得 010 会员页面登录态进行页面复测，未连接或修改线上库。

本次新增业务注释：以上两个后端文件。未涉及数据库迁移、工程脚本或生成物。
