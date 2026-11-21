<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Barcode;
use PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface;
use PkStore\Domain\Inventory\Repository\StockRepositoryInterface;
use PkStore\Core\Branding\BrandingManager;
use PkStore\Core\Feature\FeatureManager;

final class BarcodeLabel {
    public function __construct(
        private readonly InventoryProductRepositoryInterface $productRepo,
        private readonly StockRepositoryInterface $stockRepo,
    ) {}

    public function renderA4Sheet(array $productIds, int $labelsPerProduct = 1): void {
        $branding   = BrandingManager::getInstance();
        $storeName  = $branding->getStoreName();
        $primaryCol = $branding->getPrimaryColor();
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Barcode Labels</title>
<style>
@page{size:A4;margin:5mm}
body{margin:0;font-family:Arial,sans-serif;background:#fff}
.label-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:3mm;padding:5mm}
.label{border:1px solid #ccc;border-radius:2px;padding:3mm;text-align:center;height:35mm;display:flex;flex-direction:column;align-items:center;justify-content:center;page-break-inside:avoid}
.label-store{font-size:7px;font-weight:700;color:'.esc_attr($primaryCol).';text-transform:uppercase;letter-spacing:.05em;margin-bottom:1mm}
.label-name{font-size:9px;font-weight:700;margin:1mm 0;line-height:1.2;max-height:22px;overflow:hidden}
.label-barcode{font-family:"Libre Barcode 128",monospace;font-size:28px;line-height:1;margin:1mm 0}
.label-code{font-size:7px;color:#333;letter-spacing:.1em}
.label-price{font-size:9px;font-weight:700;color:'.esc_attr($primaryCol).';margin-top:1mm}
@media print{.no-print{display:none}.label-barcode{font-size:28px}}
</style>
<link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+128&display=swap" rel="stylesheet">
</head><body>
<div class="no-print" style="padding:16px;background:#f6f7f7;display:flex;gap:12px;align-items:center;">
<strong>Label Sheet Preview</strong> — '.count($productIds).' product(s), '.$labelsPerProduct.' label(s) each
<button onclick="window.print()" style="background:'.$primaryCol.';color:#fff;border:none;padding:8px 20px;border-radius:4px;cursor:pointer;font-size:14px;">Print Labels</button>
<a href="javascript:history.back()" style="padding:8px 16px;border:1px solid #ddd;border-radius:4px;text-decoration:none;color:#333;">Back</a>
</div>
<div class="label-grid">';
        foreach ($productIds as $productId) {
            $product = $this->productRepo->findById((int)$productId);
            if (!$product) continue;
            $barcode = $product->getBarcode() ?: $product->sku;
            $repeat  = max(1, $labelsPerProduct);
            for ($i = 0; $i < $repeat; $i++) {
                echo '<div class="label">
<div class="label-store">'.esc_html($storeName).'</div>
<div class="label-name">'.esc_html(substr($product->name, 0, 30)).'</div>
<div class="label-barcode">'.esc_html($barcode).'</div>
<div class="label-code">'.esc_html($barcode).'</div>
<div class="label-price">PKR '.number_format($product->salePricePkr).'</div>
</div>';
            }
        }
        echo '</div></body></html>';
    }

    public function renderZPL(array $productIds, int $labelsPerProduct = 1): void {
        $branding   = BrandingManager::getInstance();
        $storeName  = $branding->getStoreName();
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="labels.zpl"');
        foreach ($productIds as $productId) {
            $product = $this->productRepo->findById((int)$productId);
            if (!$product) continue;
            $barcode  = $product->getBarcode() ?: $product->sku;
            $name     = substr($product->name, 0, 25);
            $price    = 'PKR '.number_format($product->salePricePkr);
            for ($i = 0; $i < max(1, $labelsPerProduct); $i++) {
                echo "^XA
";
                echo "^FO20,10^A0N,18,18^FD".strtoupper($storeName)."^FS
";
                echo "^FO20,30^A0N,20,20^FD{$name}^FS
";
                echo "^FO20,55^BY2^BCN,60,Y,N,N^FD{$barcode}^FS
";
                echo "^FO20,130^A0N,18,18^FD{$price}^FS
";
                echo "^XZ
";
            }
        }
    }

    public function adminPage(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized.');
        if (!FeatureManager::getInstance()->isEnabled('barcode_labels')) wp_die('Barcode labels are not available on the current package.');
        if (isset($_POST['pkstore_print_labels'])) {
            check_admin_referer('pkstore_print_labels');
            $ids    = array_map('intval', $_POST['product_ids'] ?? []);
            $copies = max(1, min(100, (int)($_POST['copies_per_label'] ?? 1)));
            $format = sanitize_key($_POST['format'] ?? 'a4');
            if (!empty($ids)) {
                if ($format === 'zpl') { $this->renderZPL($ids, $copies); exit; }
                $this->renderA4Sheet($ids, $copies); exit;
            }
        }
        $products = $this->productRepo->findAll(200, 0);
        include PKSTORE_DIR . '/templates/admin/inventory/barcode-labels.php';
    }
}
