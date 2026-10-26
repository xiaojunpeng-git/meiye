# Customer Care Core Permanent Tests

Full permanent gate (host PHP plus isolated Docker MySQL 5.6.51):

```bash
bash tests/customer-care/run-all.sh
```

Set `CUSTOMER_CARE_SKIP_MYSQL56=1` only for a fast local pure-contract run. Such a run is not
final evidence.

The full suite covers the pure PHP task/record state machine, owner and management boundaries,
positive resource versions, sequential replay, natural-key conflicts, cross-store reassignment,
task-linked and standalone immutable records, actual follower/related-business snapshots,
followed-time boundaries, optional next-task atomicity and replay, appointment fail-closed behavior,
migration structure, nullable task uniqueness and SHA-256 integrity.
The isolated MySQL gate executes the DDL and postcheck on 5.6.51, verifies non-destructive
partial-DDL recovery and fail-closed rejection cases, and executes all eight command methods
through the production ThinkPHP repository. It also proves transaction rollback plus same-key
retry and corrupted-receipt rejection.

Concurrency runs in a fresh coordinator process after the sequential repository process exits.
Two independent ThinkPHP worker connections submit the same create command. The coordinator
uses `PROCESSLIST`, `INNODB_TRX` and `INNODB_LOCK_WAITS` to prove that worker A is paused inside
the task insert trigger while worker B waits on
`eb_cashier_v3_command_receipt.uk_idempotency_key`; after release, exactly one worker executes
and the other replays the same immutable result. A second run fails the first transaction only
after the other worker is proven waiting, then verifies that the waiter takes over, creates one
successful receipt/task/operation set and does not inherit the rolled-back placeholder. The suite
does not claim shared Gateway, route, event/fact or UI integration.
