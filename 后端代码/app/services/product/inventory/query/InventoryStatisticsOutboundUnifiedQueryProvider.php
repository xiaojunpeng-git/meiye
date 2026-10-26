<?php
declare(strict_types=1);
namespace app\services\product\inventory\query;
final class InventoryStatisticsOutboundUnifiedQueryProvider extends InventoryStatisticsUnifiedQueryProvider { public function pageCode(): string { return 'inventory_statistics_outbound'; } }
