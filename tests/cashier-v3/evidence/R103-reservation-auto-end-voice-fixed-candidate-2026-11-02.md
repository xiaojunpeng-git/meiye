# R103 预约服务到点结束及语音提醒：本地固定候选

- 开发基线 HEAD：`9f20e88a1aa42cd63aaa6d010889b7d0bb93cade`；固定时 HEAD：`4d35b33488633902a8313027398302544d2d6c54`。期间两笔 R31 提交未触及本轮文件。
- 本轮 16 个文件按下列顺序执行 `shasum -a 256`，其输出再次 SHA-256：`95a8c463f3993ac1df79601165b4299f5f22d8cd2628dcb5624c120ab6928854`。共享工作区有其他任务的未提交改动，后续只能隔离本轮增量，不能直接全量暂存。

## 本轮范围

- 平台设置新增“服务语音播报”，手机端／门店端／全部，默认手机端。只控制语音终端，不改变手动结束服务。
- 门店端与商家端在服务剩余五分钟、五秒时分别播放女声提示；详情打开且页面可见时到点即时请求结束。
- 服务端 `reservation:auto-end --watch` 常驻命令逐秒扫描到期服务，复用原结束服务事务、权益核销、服务事实和审计；系统操作人以 0 明示，不冒充员工。行锁与状态复核防多端重复落账。异常保留服务中并记录失败，后续继续扫描。
- 升级时写入自动结束保护线，仅在升级后开始的服务参加自动结束，避免历史超时预约批量核销。

## 文件分类与 SHA-256

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端 | `后端代码/app/command/ReservationAutoEnd.php` | `5819f87138ef83c5fe42e3a91192c742a33698af4e0b9a58927cd6b24d7a4268` |
| 后端 | `后端代码/config/console.php` | `9f5917c57f7de6263fc2c385464d3cdc5c94a2d5dea488b1db6f18aac25e774e` |
| 后端 | `后端代码/app/services/cashier/v3/CashierV3OperatorScope.php` | `d6e20415177bb598b8586005e619aea09e7175828fae490fde577f9b29b663bc` |
| 后端 | `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php` | `4066fc4258781a300f0f6b1ce2f93462413cfb3ffdabb4421125a3b19f517fc3` |
| 后端 | `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationCompletionFactServices.php` | `97e8b2a647a50c9ff1c8f7d18b0b428c0c65c884c6edcbc397b5d9f95c17ebe2` |
| 后端 | `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php` | `6db77f256f6f872b366ddfa608600062f4a2ec49b3228daeb884058a167e11da` |
| 后端 | `后端代码/app/services/system/config/SystemConfigServices.php` | `31bb4ddfda822330126e735e17b8c90a3cfb76f556251bccacf66ac5c6970d44` |
| 后端 | `后端代码/app/controller/admin/v1/system/config/SystemConfig.php` | `c12f96c9bde7c9d4bc0ceeb8652b976b44d63b8b8e489ef08f52a45514e1874b` |
| 迁移 | `后端代码/database/upgrades/20261102_r103_reservation_service_voice_mode.sql` | `2acc71db45fafc58427d31cb80ff6be615b2eed46192a6cea4cb5c0bafe6b664` |
| 前端 | `前端代码/mobile-vue3/src/merchant/pages/reservations/index.uvue` | `d2554918d82023d1a96c1b75cb5b8b351b639606245259476e67d3af01540012` |
| 前端 | `前端代码/cashier-v3/src/components/reservation/ReservationDetailOverlay.vue` | `5143c880f0d3d38517de177805d8a11b3e016081a5bbcbb1c8b61fb16ef533ae` |
| 音频 | `前端代码/mobile-vue3/static/audio/reservation-five-minutes.mp3` | `d406256a42cca2496461a5d18fdce93472b42b5574fdd8b6435fde12bfad94a4` |
| 音频 | `前端代码/mobile-vue3/static/audio/reservation-time-up.mp3` | `c64fdb466e2aceb70fde3e6f173c9d223b7024278ca1383669241716b36acfd8` |
| 音频 | `前端代码/cashier-v3/public/audio/reservation-five-minutes.mp3` | `d406256a42cca2496461a5d18fdce93472b42b5574fdd8b6435fde12bfad94a4` |
| 音频 | `前端代码/cashier-v3/public/audio/reservation-time-up.mp3` | `c64fdb466e2aceb70fde3e6f173c9d223b7024278ca1383669241716b36acfd8` |
| 测试 | `tests/cashier-v3/php/r103-reservation-auto-end-local.php` | `e680e56810875602daddcf543f31a50ec8317230662241e34ee90730dc7aedc0` |

业务注释已在后端预约模块、系统操作者、事实写入与两端倒计时分支补充，说明保护线、系统身份、事务边界和播报去重。

## 本地验证

- PHP 7.4：相关后端文件语法通过，`reservation:auto-end --help` 含 `--watch`，未安装配置时单次命令返回 `disabled`。
- PHP 7.4 本地数据库事务：配置 SQL 执行两遍无重复；把一张服务中夹具暂置到到期窗口，扫描器完成一次服务及核销、写入自动结束审计；重复结束不被接受；事务回滚后预约和配置数量恢复原值。输出 `R103_AUTO_END_LOCAL PASS`。
- 门店 Vue 3 构建通过；商家 HBuilderX Uni-app X H5 编译通过。移动工程旧全量检查的缺失文件和既有断言失败不能作为本轮通过证据。
- `git diff --check` 对本轮已跟踪核心文件通过。

## 限制与发布前提

- 未进行真实线上服务结束或远程数据库操作；未在浏览器实听音频，浏览器及系统的自动播放限制仍须验收时核对。
- 页面关闭时须为**每个已授权实例**单独托管 `reservation:auto-end --watch`，否则只有打开页面的即时请求会到点结束；定时命令部署和迁移须逐站授权与验证。
- 到点核销若因权益、数据库或其他原有业务校验失败，事务会回滚并保留服务中，不能为了自动结束绕过核销保护。
- 本候选未提交、未部署、未 push；按 AGENTS.md，待产品经理确认此固定版本。
