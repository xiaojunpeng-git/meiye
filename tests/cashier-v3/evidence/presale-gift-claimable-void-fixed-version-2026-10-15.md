# 预售与赠送来源记录作废：固定验收版本

- 基线 HEAD：`82b111d1772cf8e8f0a02bad12c4968d2e79f0f3`。
- 本闭环补丁指纹（SHA-256）：`d1833b452df3ddaf2f4c9b7ee22dde6a30fda223e0c31c7903991ac080ee53fb`。
- 指纹算法：本清单已跟踪文件执行 `git diff --binary HEAD -- <文件>` 得到 SHA-256 `fb96f46823118f40399ae484630a07494ce6a768e09983c6940bc0adac625e04`；新增文件先按四个迁移文件的文件名顺序、再按新增 SQLite 测试执行 `shasum -a 256 <文件>`，对该清单再次哈希，得 `355bb51db4bfd24b21d88e10624f3aa39069f1025bc837a705be6f9a72569357`；最后把两个哈希各占一行再次做 SHA-256。不含本证据文件和其他工作区改动。
- 固定版本形成时的状态：开发与隔离自测完成，待产品经理对本固定版本明确确认验收；当时未提交、未迁移、未部署、未推送。后续本地联调见下文。

## 已确认范围

- 预售、赠送主列表均可作废来源记录。无额外业务状态条件；保留登录、岗位权限和操作审计。按本次确认，来源记录可在当前岗位功能权限及员工有效数据范围共同授权的门店内跨店作废，客户端门店 ID 不能扩权；当前列表仍显示所选门店记录。
- 保留既有领用数量、领用明细和出库事实，只关闭剩余未领用资格。作废后不再领用、修改领用备注或直接作废既有领用；明细可只读查看。
- 赠送作废后，会员详情“有效赠送”不再显示该权益，“全部记录”显示“已作废”。

## 闭环文件与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/PresaleClaimView.vue` | `a7620fb9d5b8f0bc8405b8f4a0c9c1b7a6e2e5fddb4885e522eb7013f8f2fb5d` |
| 前端源码 | `前端代码/cashier-v3/src/services/presaleClaimApi.js` | `4cb88dd956832f83a48ae7e74fc7b7c44204e8b922abd9f36b16eaf53cc3c034` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/PresaleClaim.php` | `249a519cd41fde4cf19fa470d8f1ebd2c7581c9d4fb7b6890aa5955be844d07d` |
| 后端源码 | `后端代码/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php` | `85249b05a5da594338a63a06f3fe4a88034017d88a6e06ac61f7a304a9270166` |
| 后端源码 | `后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` | `81d866e4c61af9df013978c94731d5137233db292d482a2c42f53e1d6cd7f479` |
| 后端源码 | `后端代码/route/store.php` | `fb0793ff039c22561caf0ce70fa9bc05707b514310ba35260bb15eadd38f47e6` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-15-预售赠送领用资格作废/00-升级清单.md` | `2e8fdadf21d2b78425c47b598691402cf9f1fedf202bf9b5b5945bc82819e531` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-15-预售赠送领用资格作废/01-升级前检查.sql` | `8f173304c9f310527122cebe6f873619b612fc9e618e0edc4fda1c55352935e6` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-15-预售赠送领用资格作废/02-正式升级.sql` | `ba8121760f443d71d036316d38dd1cd986b800a7fb5b38371e2b43b4d69f0133` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-15-预售赠送领用资格作废/03-升级后验证.sql` | `c9dc8e42704eb92d242195e9f3b8ac7e0f640d9b0ce7adc57eed87c023dcf51e` |
| 自动化测试 | `tests/cashier-v3/php/presale-gift-claimable-void-sqlite.php` | `c7db00728bf7b88764aa86697822d00c08f760bc8f00dc1bd981d6cdc6df6e37` |
| 自动化测试 | `tests/cashier-v3/php/gift-product-presale-claim-contract.php` | `df8d0e45fbcbc274701964afdc13a369f70e5470483d26ba219550b40cdff669` |
| 自动化测试 | `tests/cashier-v3/php/direct-gift-member-detail-expiry-contract.php` | `094c23bcb72f01ec6d6734edd88209da2d3ff0530c2b87f54e5f4b094b869d32` |
| 自动化测试 | `tests/cashier-v3/js/member-gift-presale-query-contract.mjs` | `1831838b8b245b8c0c38f9db4147c8335d39762e927a789812a6ab7b899dffa3` |

## 自测

- 隔离 SQLite 事务测试 `presale-gift-claimable-void-sqlite.php`：PASS。覆盖部分领用、赠送全部领用、原已关闭、审计与原关闭操作号、幂等、岗位授权跨店作废与目标门店审计、未授权及显式隔离门店拒绝、岗位功能撤销拒绝、来源作废后直接操作拒绝；不连接真实数据库。
- 门店端 `npm run build`：PASS；预构建 70 项检查通过，Vite 构建通过。构建生成的本地 `dist/` 为忽略的派生产物。
- 两个服务、门店控制器、门店路由和新增测试的 PHP 语法检查：PASS。
- `gift-product-presale-claim-contract.php`、`direct-gift-authority-contract.php`、`member-detail-v2-contract.php`、`direct-gift-member-detail-expiry-contract.php`：PASS。
- `gift-product-presale-claim-frontend-contract.mjs`、`member-gift-presale-query-contract.mjs`：PASS。会员详情两条旧静态断言按现有代码行为更新后复测通过。
- `git diff --check`：PASS。已在现有浏览器页面只读查看预售与赠送的作废按钮和确认弹窗，未点击确认。接口失败后错误改为在确认弹窗内显示，避免原先页面级提示被遮挡。

## 后续本地联调：单笔真实页面操作

- 目标是 `18091` 连接的本机 Docker 开发库 `ruihao`，不是远程瑞昊实例。作废前只读核对 `JSJ-PRESALE-0000043471` / `RH102-presale-0000043471`：扬州欧尚店（store_id 23）、预售 5 件、已领用 0 件、状态 `OPEN`、版本 2。
- 首次页面提交显示通用失败。只读预检确认本地库缺少 `eb_cashier_v3_presale_claimable_void`，且升级键 `20261015-001-presale-gift-claimable-void` 未登记。按本闭环 `01/02/03` SQL 在本机 Docker 开发库执行加表升级并验证字段、索引、升级记录；升级不改既有来源记录。执行前审计表不存在，属于新增表；本地回滚须在尚无作废记录时才可撤销表，已发生业务作废后必须保留审计事实。
- 产品经理对本笔作废另行明确确认后，在 `18091` 页面点击一次“确认作废”，说明沿用弹窗中的 `235`。页面从“可领用”列表移除该行；“已作废”筛选清空默认销售日期后显示该行，状态“已作废”，操作列仅“明细”。
- 只读数据库复核：来源状态 `VOIDED`、数量仍为 5、已领用仍为 0、版本升至 3；审计记录原状态 `OPEN`、关闭剩余 5 件、store_id 23、说明 `235`、员工操作人 1715；该来源的领用记录数仍为 0。本次未操作赠送记录，未执行远程数据库变更。

## 交接边界

- 迁移 SQL 已在上述本机 Docker 开发库执行；当前 `18091` 和数据库端口由本机 Docker/Colima 转发，本次未连接或修改远程客户实例。赠送的会员详情实际数据联动以及任何客户实例的逐实例迁移仍须在相应授权阶段验证。
- 产品经理验收本固定版本前不创建本地 commit。验收后只按本清单逐文件暂存，并遵守项目分阶段提交规则。部署、远程数据库和推送另需对应授权。
- 本次补充或更新业务注释的源码：`PresaleClaimView.vue`、`presaleClaimApi.js`、`PresaleClaim.php`、`CashierV3PresaleClaimServices.php`、`CashierV3MemberDetailQueryServices.php`。
- 工程脚本：无。生成物：忽略的 `前端代码/cashier-v3/dist/`。验收证据：本文件。

## 产品经理验收与发布授权

- 2026-10-15，产品经理在本轮对话确认“我测试没有问题,本轮commit,部署瑞昊,push;然后部署渼约008”。本清单的 14 个源码、迁移和测试文件在确认后逐项重算 SHA-256，与上表完全一致；验收后新增的只是本证据文件中的本地联调和授权记录。
- 本轮发布目标及顺序：先本地 commit，瑞昊 `rh` 逐实例备份、迁移、部署和线上验证，通过后 push，再对渼约 `008` 单独备份、迁移、部署和验证。`007`、`012` 不在授权范围内。
