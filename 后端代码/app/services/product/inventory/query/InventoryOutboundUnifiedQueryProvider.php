<?php
declare(strict_types=1);
namespace app\services\product\inventory\query;
final class InventoryOutboundUnifiedQueryProvider extends InventoryOperationalUnifiedQueryProvider { public function pageCode(): string { return 'inventory_outbound'; } }
