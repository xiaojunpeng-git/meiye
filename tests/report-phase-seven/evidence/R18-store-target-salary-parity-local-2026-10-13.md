# R18 store target rankings and employee salary: local self-test

## Scope and fixed files

- Baseline shared-checkout HEAD observed at evidence freeze: `ecd9000cda40b4b9ce882b3651d4dc9ec4bae7db`. The shared checkout contains unrelated work; in particular, `StoreUnifiedReportPhaseSixServices.php` already had uncommitted changes before this task. These full-file hashes identify the tested version and do not authorize staging unrelated hunks.
- Backend source: `后端代码/app/services/cashier/v3/dashboard/CashierV3StoreTargetDashboardReadModel.php` SHA-256 `d52679d65719d3df36951922ee971a91261f8004b912d6e15c3ec984e3bce2a5`; `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` SHA-256 `77a74d990191b4c399e33da3642f98704f3eb5ab5f97080fe579a7a259534e29`.
- Automated tests: `tests/report-phase-seven/php/store-target-dashboard-contract.php` SHA-256 `8258b22879588d98648b2b0754dc5d9a5528e3ad3ba9f50ae74f9552cf292f6f`; `store-target-service-customer-readonly.php` SHA-256 `7ac3da44e89ab38b9593937a769fde973f77984fe204d46806f3cbac9f5429f1`; `store-target-salary-parity-readonly.php` SHA-256 `a9361ea52a4fcaa62a57356e6a7933826715e125ac0feafd529dfed4c4c0d7df`.
- Frontend source: none. Database migration: none. Engineering scripts: none. Generated artifacts: none. This evidence file is not business source code.
- Business-boundary comments were added or updated in both backend source files and the new read-only test. Existing page layout and controls were not changed.

## Data architecture self-check

- Source/grain: employee salary summary remains the authoritative report projection over effective per-employee sales, refund and service performance facts. The target's cash and consumption rankings now use those same employee totals before display rounding. Sales quantity and distinct buyer counts retain their separately scoped effective-sale facts; they are not salary amounts.
- Time/status/reversal: both salary and target use the selected store business-date range. The salary projection excludes voided/reversed service and sale facts, includes successful refund deductions on the refund business date, and preserves the original positive sale. No write event, transaction, Outbox, replay or business-data mutation is introduced by this read-only change.
- Scope/snapshot: the target endpoint first enforces the signed-in operator's store scope; that one authorized store is passed to the salary read. Employee IDs are stable ranking keys and names are the recorded snapshots. The browser receives totals only; it neither expands scope nor recalculates amounts.
- Precision/performance: cent amounts, project-count millionths and customer-count tenths stay integer until response projection. The ranking sorts by exact cent values, then employee ID, and caps the displayed list at 100. Local full-year salary projection for the comparison store returned five employees in about 249 ms. No new indexes or migration were needed.
- Reconciliation: one salary summary query and the target rankings were compared per employee and per metric. The same source feeds the existing salary detail/summary and the new target projection; no historical facts were rewritten.

## Local verification

- Yangzhou Oushang store 23, 2026-10-01 through 2026-10-13: the read-only integration compared every target employee with salary summary categories plus presale cash, consumption, labor, project count, service visits and service people. All 12 assertions passed. Cash ranking is Wang Panpan 640871 cents, Zhang Qingqing 165912 cents and Wang Shenghui 165712 cents; consumption ranking has five employees. The known local refund is reflected in these net amounts.
- Existing excluded-friend sample was refreshed to local `FW101000627`; five read-only assertions passed. Its consumption and projects remain while visit/people equal the shared customer reader. The test now compares numeric values rather than rejecting an integral tenth-unit result on PHP type alone.
- `bash tests/report-phase-seven/run-static.sh` passed, including target frontend contract. Both modified backend classes passed local PHP 7.4 lint; the new test passed PHP lint. Scoped `git diff --check` passed.
- Sent only a USR1 hot reload to the local `mohe-cashier-app` workers. In the actual 18091 browser, refreshed “本月” showed the three salary-matching cash amounts and all five matching consumption rows. “今日” showed the three refund deductions -109, -111 and -113 yuan; the browser was returned to “本月”. No checkout or refund command was submitted.

## Gate

Development and local self-test are complete. The product manager confirmed acceptance of this fixed version on 2026-10-13. At the evidence freeze, no R18 commit, remote deployment, production data change or push had been performed. Deployment and push remain unauthorized for this round.
