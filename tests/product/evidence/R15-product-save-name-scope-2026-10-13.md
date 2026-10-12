# R15 商品保存网关错误与重名校验：本地自测

- 基线 HEAD：`0205c96a4c9d0f99d10321e2efea5f800f8f334f`；工作区另有其他任务改动，本闭环未提交。
- 目标：仅本地 `18081` / `mohe-mysql.ruihao`；未连接或修改瑞昊线上实例。
- 根因：商品 `603508` 的保存请求在 128 MB PHP 内存限额下耗尽内存，Nginx 返回 502。该平台卡项有 102 个门店副本、824 条直接关联，旧目录写锁沿共享项目扩张到至少 2,175 张其他卡。
- 修复：卡项资料保存锁定本卡及其直接关联；门店同步对被修改项目锁定直接引用卡及完整定义，不再沿未修改项目继续反向扩张。普通目录写入保留原完整扩张口径。
- 重名口径：新增或改名时，在同一 `type/relation_id` 商品目录内按数据库排序规则检查未删除商品；同一商品原名保存不拦截，平台主商品与下发的门店副本分属不同目录。命名锁串行化同目录并发保存。
- 历史限制：本地未删除根商品按 `type/relation_id/store_name` 统计已有 153 组重名。本次不批量改名；原名编辑仍可保存，新增或改为重名会被拒绝。

## 本地验证

1. 平台编辑 `603508`，开启“欠款不允许核销”并保存：页面正常返回商品列表；刷新编辑页后开关仍开启。截图：`R15-card-save-persisted-2026-10-12.png`。
2. 将该商品名称临时改为同目录现有名称 `9999致敬卡` 后尝试保存：接口 HTTP 200、业务状态 400，消息 `商品名称已存在，请更换名称`；数据库原名称未变。刷新页面清除未保存的测试输入。
3. 通过应用商品同步服务补齐此前失败的本地门店副本；`pid=603508` 的 102 个副本均为 `block_writeoff_on_debt=1`。
4. 通过应用服务重同步关联项目 `79591` 至门店 `6`：成功；卡 `603592` 对应的门店项目 `95844` 仍有且仅有 1 条映射。
5. `platform-card-rules/php/card-rule-contract.php` PASS；`c2-catalog-writer-lock-contract.php` 29/29 PASS；`c2-catalog-lock-order-contract.mjs` PASS；4 个后端改动文件 `php -l` 通过；相关文件 `git diff --check` 通过。
6. 最后一条同类同步失败日志停在 `2026-10-12 23:52:37 +08:00`，本轮后续手动同步未再新增。

## 文件边界

- 前端源码：本闭环未新增修改；商品开关来自此前同轮未提交开发。
- 后端源码：`StoreProductServices.php`（在此前同轮欠款核销变更上追加重名校验与卡项锁范围）、`StoreCatalogWriteLockGuard.php`、`StoreBranchProductServices.php`、`StoreCardRelatedServices.php`。
- 数据库迁移：本闭环无新增；此前同轮欠款核销字段迁移仍待整轮验收。
- 自动化测试：`tests/platform-card-rules/php/card-rule-contract.php`、`tests/cashier-v3/php/c2-catalog-writer-lock-contract.php`。
- 工程脚本：无。生成物：无。验收证据：本文件及上述截图。

## 固定文件 SHA-256

- `StoreCatalogWriteLockGuard.php`: `0a502d086f70c065570107f33bb02566304c768422cb8b5dfe3421072f77f259`
- `StoreProductServices.php`: `6cde65b456150cc117c3f3a26bfb6c4228577a47fb8b591809082c00034b46d6`
- `StoreBranchProductServices.php`: `50e65d2870ec29e2249883ed2afdbd541d17d5f5c0d08bfe712e7e8219586658`
- `StoreCardRelatedServices.php`: `59ec66683d184e3d1a988e2038826fc82d77add16ec08618f4112af3f70226e9`
- `card-rule-contract.php`: `e19dbf1d9abe855c2e29f8f28b5ef09c3a8d7dd8a54fca359a10559964bc7bcf`
- `c2-catalog-writer-lock-contract.php`: `b8d0ed04d6ed321f86f1f0efc7edd848551a5f534b67f87f725835467b3c19a9`
- Screenshot: `d90598df58733c442ae070af97f599abd15397e5363f1a7507f6722cc4e67129`

状态：开发与本地自测完成，待产品经理确认本轮固定版本验收；未 commit、未部署、未 push。
