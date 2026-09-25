# R41 瑞昊发布与线上真人验收

## 授权与固定版本

- 产品经理已确认本轮成果并要求部署瑞昊、push。
- 功能提交：`2db88466edad84b4996e7b5752f2973bc0a3b5d6`（`R41 经营概览对象分组连续问答闭环`）。
- 目标实例：`rh.cc3798.com`，运行目录 `/www/wwwroot/rh.cc3798.com`，数据库只读核对为 `ruihao`；其他客户实例未修改。
- 本轮不含数据库迁移、前端构建或小程序代码，只从上述提交归档提取并发布三个后端 PHP 文件，没有从脏工作区同步。

## 发布前门禁与回滚

- 发布前 `active_ai_runs=0`、`active_ai_exports=0`，磁盘可用 223GB。
- execution worker、独立 Excel worker、supervisor 均为 `active`；Swoole `manager_count=1`；管理页面 HTTP 200。
- 独立备份：`/www/backups/rh.cc3798.com/20260925-r41-2db88466-kx66ie/`，含三个覆盖前文件及 `SHA256SUMS`。
- 回滚方式：精确恢复备份中的三个文件，清理运行缓存并成组重启 Swoole、execution worker、Excel worker 与 supervisor。本轮未写生产业务数据、未执行迁移，回滚不覆盖数据库。

## 发布文件与指纹

| 文件（相对后端目录） | 提交与线上 SHA-256 |
| --- | --- |
| `app/services/ai/AiGatewayServices.php` | `c696ab45a483f1aee487a2f38339034b72ea88b246e464211635fa1bcbbedeac` |
| `app/services/ai/contract/AiIntentResultContract.php` | `cc594fa030374016fad2dd44b5197639556bfe5d00fb9b3e5cd21fb12862a456` |
| `app/services/ai/contract/AiIntentUnderstandingContract.php` | `db2071e5b488baefd8869ff6283c31d57e1bac5734c3f6822f6501ed0576b2c6` |

三个文件远程 PHP 语法检查通过，发布后逐项指纹与功能提交一致。缓存已清理；Swoole 单 manager，三项 AI 服务均为 `active`，页面仍为 HTTP 200。

## 线上真人连续问答

在已登录瑞昊平台端分别新建两个会话测试，测试会话和 Run 记录均保留，没有删除。

1. 门店维度
   - 首问“这个月的经营情况如何”：完整返回本期经营概览，页面用时 17 秒。
   - 追问“各门店的经营详情呢”：页面用时 7 秒；返回 14 家有实际业绩的门店、合计 171,170 元，统计期间 2026-09-01 至 2026-09-25，并明确隐藏 88 家零值门店。
   - Run：首问 `36ef3b35f5506c28300effb441f765ca78b74cea5ea14993`，追问 `c2d0e69eb5d090f24891af4b88198e8a71e8298747f9f535`；同一 conversation，generation 1→2，均 `COMPLETED`。
2. 人员维度
   - 首问“这个月的经营情况如何”：完整返回本期经营概览，页面用时 13 秒。
   - 追问“各员工的经营详情呢”：页面用时 7 秒；返回 32 位有销售人业绩的人员、合计 169,660 元，统计期间 2026-09-01 至 2026-09-25，并明确隐藏 2 位零值人员、当前展示前 20 条。
   - Run：首问 `3ce49b6cb4dfadfe9461d4301a23e8be310f919333a7ccc7`，追问 `452b334472da373b28b6aeb8c0e8079b38d487a1e4279c5c`；同一 conversation，generation 1→2，均 `COMPLETED`。

两条追问均继承首问的本月范围，没有错误保留旧分组，没有进入“系统没有读取或计算数据”的失败结果。验收结束后再次核对 `active_runs=0`、`active_exports=0`。以上是线上真人交互和运行状态验证，不等同于全量账务审计，也不以四个样本宣称 P95。

## 推送边界

- 发布前远端分支 `origin/release/store-cashier-c14-main` 位于 `44f83d3e`，功能提交仅领先 1 个提交。
- 推送采用普通 fast-forward，不 force，不修改服务器 Git 工作区。
- 工作区其他未提交任务文件未暂存、未部署、未包含在本轮提交或推送新增内容中。
