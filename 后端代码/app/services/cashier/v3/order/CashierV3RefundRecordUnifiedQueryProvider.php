<?php
namespace app\services\cashier\v3\order;
final class CashierV3RefundRecordUnifiedQueryProvider extends AbstractCashierV3OrderCenterUnifiedQueryProvider { public function pageCode(): string { return CashierV3OrderCenterUnifiedQueryContract::PAGE_BY_TYPE['refund']; } }
