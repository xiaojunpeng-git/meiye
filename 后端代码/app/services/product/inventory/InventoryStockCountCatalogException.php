<?php
declare(strict_types=1);

namespace app\services\product\inventory;

/** Carries every stale catalog identity found before an atomic stock count starts writing facts. */
final class InventoryStockCountCatalogException extends \RuntimeException
{
    private array $invalidLines;

    public function __construct(array $invalidLines)
    {
        parent::__construct('inventory_stock_count_catalog_not_found');
        $this->invalidLines = array_values($invalidLines);
    }

    /** Client-safe identities only; catalog state and internal SQL details stay on the server. */
    public function invalidLines(): array
    {
        return $this->invalidLines;
    }
}
