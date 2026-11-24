<?php
declare(strict_types=1);
namespace PkStore\Application\Inventory;
use PkStore\Domain\Inventory\Repository\{InventoryProductRepositoryInterface, LocationRepositoryInterface, StockRepositoryInterface};
use Psr\Log\LoggerInterface;
final class TransferStockUseCase {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $products,
        private readonly LocationRepositoryInterface $locations,
        private readonly StockRepositoryInterface $stock,
        private readonly LoggerInterface $log,
    ) {}
    public function execute(int $productId, int $fromLocationId, int $toLocationId, int $quantity, string $referenceId, string $note = ''): string {
        if ($productId < 1 || $fromLocationId < 1 || $toLocationId < 1 || $fromLocationId === $toLocationId || $quantity < 1) {
            throw new \InvalidArgumentException('A product, two different locations and a positive whole quantity are required.');
        }
        $product = $this->products->findById($productId);
        if (!$product || !$product->isActive) throw new \DomainException('Active inventory product not found.');
        $from = $this->locations->findById($fromLocationId);
        $to = $this->locations->findById($toLocationId);
        if (!$from || !$from->isActive || !$to || !$to->isActive) throw new \DomainException('Both transfer locations must be active.');
        $referenceId = trim($referenceId);
        if ($referenceId === '' || strlen($referenceId) > 64) throw new \InvalidArgumentException('A stable transfer reference of 1-64 characters is required.');
        $cleanNote = sanitize_text_field($note);
        $cleanNote = function_exists('mb_substr') ? mb_substr($cleanNote, 0, 255) : substr($cleanNote, 0, 255);
        $this->stock->transferStock($productId, $fromLocationId, $toLocationId, $quantity, $referenceId, $cleanNote, get_current_user_id());
        $this->log->info('Inventory stock transferred.', ['product_id'=>$productId,'from_location_id'=>$fromLocationId,'to_location_id'=>$toLocationId,'quantity'=>$quantity,'reference_id'=>$referenceId]);
        return $referenceId;
    }
}
