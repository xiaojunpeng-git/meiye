<?php
declare(strict_types=1);

namespace app\services\product\inventory;

/** Carries the submitted line position for a server-authoritative stock-count rejection. */
final class InventoryStockCountLineException extends \RuntimeException
{
    private int $lineIndex;

    public function __construct(string $code, int $lineIndex)
    {
        parent::__construct($code);
        $this->lineIndex = $lineIndex;
    }

    /** One-based position in submitted counted lines; the client resolves the draft row and name. */
    public function lineIndex(): int
    {
        return $this->lineIndex;
    }
}
