# Two Store Reports: All-Zero Column Visibility

- Source baseline: `278d65bfe6d027c9ddd619070afaafc77231e5b5` (local unified source tree).
- Scope: `store_item_analysis` and `phase_six_salary_summary` only. Numeric columns are hidden only when every authorized result row is numeric zero. Identity columns, fact rows, metric calculations and drilldowns remain unchanged. Empty results keep their headers. The shared query path supplies screen and export columns; category groups and summary-row keys follow visible columns.
- Source files: `后端代码/app/services/report/StoreUnifiedReportServices.php`, `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php`, `后端代码/app/services/report/StoreReportZeroColumnVisibilityServices.php`.
- Tests: `tests/report-phase-six/php/report-zero-column-visibility-contract.php`, `tests/report-phase-six/php/report-zero-column-visibility-local-readonly.php`, `tests/report-phase-six/php/r9-salary-presale-readonly.php`.
- No frontend source, migration, engineering script or generated business bundle changed for this candidate. No remote instance was modified.

## Fixed File SHA-256

| File | SHA-256 |
| --- | --- |
| `后端代码/app/services/report/StoreUnifiedReportServices.php` | `d70c31fbc30e7fe56d3d628eb4e868e6577cf55bd9656094be099051f1f7daca` |
| `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `d234169cfeb9b781710bb876ce7e60f21f74f75627ba2375d5b88b947833b881` |
| `后端代码/app/services/report/StoreReportZeroColumnVisibilityServices.php` | `a48114accd85817a03c0c56d232217cd833377017471db085994904b7668960b` |
| `tests/report-phase-six/php/report-zero-column-visibility-contract.php` | `ce232d164e6d9d8ca9c43cb2ab3c91e4685fad29d50e0ae777acbcfc48ba5f5d` |
| `tests/report-phase-six/php/report-zero-column-visibility-local-readonly.php` | `b1c9b1e509eb946ef066c8cc38c76bfc3dd6d5b3bd68403edfc9c8a4a4f867cc` |
| `tests/report-phase-six/php/r9-salary-presale-readonly.php` | `088a15943809b87bdf229b02f97c963ec2f1676e48d738d7e49dd7d4fd362bd7` |

## Verification

- PHP 7.4 syntax: three source files pass.
- New visibility contract: pass, including zero formats, missing values, offsetting signed rows, empty result, groups and summary row.
- Local RH mirror (read-only): store 80 on 2026-10-17 and salary store 126 on 2026-10-08 pass. Store screen/export columns and totals agree; reduced-header XLSX renders.
- Existing presale salary fixture: pass after updating its zero-column expectation. Existing 32 salary metric drilldowns: pass.
- Two older static contracts fail on source-text expectations already absent from baseline (`category grouping follows...`, `salary column-source descriptions...`). The salary document-column integration cannot locate its historical detail fixture. None is treated as a pass.
- `StoreUnifiedReportServices.php` also contains an unrelated pre-existing unstaged sales-detail change. It is not part of this candidate and must not be staged with this patch.

Status: development and self-test complete; awaiting product-manager acceptance. No commit, deployment or push authorized by this record.
