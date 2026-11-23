<?php
declare(strict_types=1);
use PkStore\Container\ServiceContainer;
use PkStore\Settings\SettingsManager;
use PkStore\Infrastructure\Logger\WpLogger;
use PkStore\Infrastructure\Payment\{JazzCashGateway,EasyPaisaGateway};
use PkStore\Infrastructure\Verification\{WpOtpRepository,TwilioSmsProvider,LocalSmsProvider};
use PkStore\Infrastructure\AI\{OpenAiBridge,WcProductRepository,WpChatRepository};
use PkStore\Infrastructure\Logistics\{TcsCourier,LeopardsCourier,TraxCourier,CityMappingService,RateCalculator,CourierCircuitBreaker,LogisticsLogger};
use PkStore\Infrastructure\WooCommerce\OrderMetaRepository;
use PkStore\Infrastructure\Admin\DashboardService;
use PkStore\Infrastructure\Affiliate\{WpAffiliateRepository,WpAffiliateProductRepository,WpCommissionRepository,WpPayoutRepository};
use PkStore\Domain\AI\{AiProviderInterface,ProductRepositoryInterface,ChatRepositoryInterface,PromptComposer,ContextSelector};
use PkStore\Domain\AI\Security\PromptInjectionDetector;
use PkStore\Domain\Verification\{SmsProviderInterface,Repository\OtpRepositoryInterface};
use PkStore\Domain\Affiliate\Repository\{AffiliateRepositoryInterface,AffiliateProductRepositoryInterface,CommissionRepositoryInterface,PayoutRepositoryInterface};
use PkStore\Application\Verification\{SendCodOtpUseCase,VerifyCodOtpUseCase};
use PkStore\Application\AI\{HandleAiChatUseCase,ProductContextService};
use PkStore\Application\Logistics\BookShipmentUseCase;
use PkStore\Application\Affiliate\{RegisterAffiliateUseCase,TrackAffiliateClickUseCase,RecordCommissionUseCase,ProcessPayoutUseCase,SubmitAffiliateProductUseCase,TrackAffiliateProductClickUseCase};
use PkStore\Settings\SetupWizard;
return static function(ServiceContainer $c):void{
    $s=SettingsManager::getInstance();
    $c->singleton(SettingsManager::class,fn()=>$s);
    $c->singleton(WpLogger::class,fn()=>new WpLogger());
    $c->singleton(OrderMetaRepository::class,fn()=>new OrderMetaRepository());
    $c->singleton(SmsProviderInterface::class,function()use($s){$l=new WpLogger();return $s->get('DEFAULT_SMS_PROVIDER','twilio')==='local'?new LocalSmsProvider($l,$s):new TwilioSmsProvider($l,$s);});
    $c->singleton(OtpRepositoryInterface::class,function(){global $wpdb;return new WpOtpRepository($wpdb);});
    $c->singleton(SendCodOtpUseCase::class,fn()=>new SendCodOtpUseCase($c->make(OtpRepositoryInterface::class),$c->make(SmsProviderInterface::class),new WpLogger()));
    $c->singleton(VerifyCodOtpUseCase::class,fn()=>new VerifyCodOtpUseCase($c->make(OtpRepositoryInterface::class),new WpLogger()));
    $c->singleton(AiProviderInterface::class,fn()=>new OpenAiBridge(new WpLogger(),$s));
    $c->singleton(ProductRepositoryInterface::class,function(){global $wpdb;return new WcProductRepository($wpdb);});
    $c->singleton(ChatRepositoryInterface::class,function(){global $wpdb;return new WpChatRepository($wpdb);});
    $c->singleton(PromptInjectionDetector::class,fn()=>new PromptInjectionDetector());
    $c->singleton(ContextSelector::class,fn()=>new ContextSelector());
    $c->singleton(PromptComposer::class,fn()=>new PromptComposer());
    $c->singleton(ProductContextService::class,fn()=>new ProductContextService($c->make(ProductRepositoryInterface::class),$c->make(ContextSelector::class),$c->make(PromptComposer::class)));
    $c->singleton(HandleAiChatUseCase::class,fn()=>new HandleAiChatUseCase($c->make(AiProviderInterface::class),$c->make(ChatRepositoryInterface::class),$c->make(ProductContextService::class),$c->make(PromptInjectionDetector::class),new WpLogger()));
    $c->singleton(CityMappingService::class,function(){global $wpdb;return new CityMappingService($wpdb);});
    $c->singleton(RateCalculator::class,fn()=>new RateCalculator($c->make(CityMappingService::class)));
    $c->singleton(LogisticsLogger::class,function(){global $wpdb;return new LogisticsLogger($wpdb);});
    $c->singleton(TcsCourier::class,fn()=>new TcsCourier(new WpLogger(),$c->make(CityMappingService::class),$c->make(RateCalculator::class),$c->make(LogisticsLogger::class),$s));
    $c->singleton(LeopardsCourier::class,fn()=>new LeopardsCourier(new WpLogger(),$c->make(CityMappingService::class),$c->make(RateCalculator::class),$c->make(LogisticsLogger::class),$s));
    $c->singleton(TraxCourier::class,fn()=>new TraxCourier(new WpLogger(),$c->make(CityMappingService::class),$c->make(RateCalculator::class),$c->make(LogisticsLogger::class),$s));
    $c->singleton(CourierCircuitBreaker::class,fn()=>new CourierCircuitBreaker([$c->make(TcsCourier::class),$c->make(LeopardsCourier::class),$c->make(TraxCourier::class)]));
    $c->singleton(BookShipmentUseCase::class,fn()=>new BookShipmentUseCase($c->make(CourierCircuitBreaker::class),$c->make(OrderMetaRepository::class),new WpLogger()));
    $c->singleton(DashboardService::class,function(){global $wpdb;return new DashboardService($wpdb);});
    $c->singleton(SetupWizard::class,fn()=>new SetupWizard($s));
    $c->singleton(AffiliateRepositoryInterface::class,function(){global $wpdb;return new WpAffiliateRepository($wpdb);});
    $c->singleton(AffiliateProductRepositoryInterface::class,function(){global $wpdb;return new WpAffiliateProductRepository($wpdb);});
    $c->singleton(CommissionRepositoryInterface::class,function(){global $wpdb;return new WpCommissionRepository($wpdb);});
    $c->singleton(PayoutRepositoryInterface::class,function(){global $wpdb;return new WpPayoutRepository($wpdb);});
    $c->singleton(RegisterAffiliateUseCase::class,fn()=>new RegisterAffiliateUseCase($c->make(AffiliateRepositoryInterface::class),new WpLogger()));
    $c->singleton(TrackAffiliateClickUseCase::class,fn()=>new TrackAffiliateClickUseCase($c->make(AffiliateRepositoryInterface::class)));
    $c->singleton(ProcessPayoutUseCase::class,fn()=>new ProcessPayoutUseCase($c->make(AffiliateRepositoryInterface::class),$c->make(PayoutRepositoryInterface::class),$s,new WpLogger()));
    $c->singleton(RecordCommissionUseCase::class,fn()=>new RecordCommissionUseCase($c->make(AffiliateRepositoryInterface::class),$c->make(CommissionRepositoryInterface::class),$c->make(ProcessPayoutUseCase::class),new WpLogger()));
    $c->singleton(SubmitAffiliateProductUseCase::class,fn()=>new SubmitAffiliateProductUseCase($c->make(AffiliateRepositoryInterface::class),$c->make(AffiliateProductRepositoryInterface::class),new WpLogger()));
    $c->singleton(TrackAffiliateProductClickUseCase::class,fn()=>new TrackAffiliateProductClickUseCase($c->make(AffiliateProductRepositoryInterface::class)));

    // Inventory + POS repositories
    $c->singleton(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class, function(){global $wpdb;return new \PkStore\Infrastructure\Inventory\WpInventoryProductRepository($wpdb);});
    $c->singleton(\PkStore\Domain\Inventory\Repository\StockRepositoryInterface::class, function(){global $wpdb;return new \PkStore\Infrastructure\Inventory\WpStockRepository($wpdb);});
    $c->singleton(\PkStore\Domain\Inventory\Repository\LocationRepositoryInterface::class, function(){global $wpdb;return new \PkStore\Infrastructure\Inventory\WpLocationRepository($wpdb);});
    $c->singleton(\PkStore\Domain\POS\Repository\POSSessionRepositoryInterface::class, function(){global $wpdb;return new \PkStore\Infrastructure\POS\WpPOSSessionRepository($wpdb);});
    $c->singleton(\PkStore\Domain\POS\Repository\POSOrderRepositoryInterface::class, function(){global $wpdb;return new \PkStore\Infrastructure\POS\WpPOSOrderRepository($wpdb);});

    // Inventory use cases
    $c->singleton(\PkStore\Application\Inventory\AdjustStockUseCase::class, fn()=>new \PkStore\Application\Inventory\AdjustStockUseCase($c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\StockRepositoryInterface::class),new WpLogger()));
    $c->singleton(\PkStore\Application\Inventory\GenerateBarcodeUseCase::class, fn()=>new \PkStore\Application\Inventory\GenerateBarcodeUseCase($c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class)));
    $c->singleton(\PkStore\Application\Inventory\SyncWooCommerceStockUseCase::class, fn()=>new \PkStore\Application\Inventory\SyncWooCommerceStockUseCase($c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\StockRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\LocationRepositoryInterface::class),$c->make(\PkStore\Application\Inventory\AdjustStockUseCase::class),new WpLogger()));

    $c->singleton(\PkStore\Domain\Inventory\Repository\PurchaseOrderRepositoryInterface::class, function(){global $wpdb;return new \PkStore\Infrastructure\Inventory\WpPurchaseOrderRepository($wpdb);});
    $c->singleton(\PkStore\Application\Inventory\CreatePurchaseOrderUseCase::class, fn()=>new \PkStore\Application\Inventory\CreatePurchaseOrderUseCase($c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\LocationRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\PurchaseOrderRepositoryInterface::class)));
    $c->singleton(\PkStore\Application\Inventory\ReceivePurchaseOrderUseCase::class, fn()=>new \PkStore\Application\Inventory\ReceivePurchaseOrderUseCase($c->make(\PkStore\Domain\Inventory\Repository\PurchaseOrderRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\StockRepositoryInterface::class),new WpLogger()));

    $c->singleton(\PkStore\Application\Inventory\TransferStockUseCase::class, fn()=>new \PkStore\Application\Inventory\TransferStockUseCase($c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\LocationRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\StockRepositoryInterface::class),new WpLogger()));

    // POS use cases
    $c->singleton(\PkStore\Application\POS\ProcessPOSSaleUseCase::class, fn()=>new \PkStore\Application\POS\ProcessPOSSaleUseCase($c->make(\PkStore\Domain\POS\Repository\POSOrderRepositoryInterface::class),$c->make(\PkStore\Domain\POS\Repository\POSSessionRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\StockRepositoryInterface::class),new WpLogger()));
    $c->singleton(\PkStore\Application\POS\SyncPOSQueueUseCase::class, fn()=>new \PkStore\Application\POS\SyncPOSQueueUseCase($c->make(\PkStore\Domain\POS\Repository\POSOrderRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),new WpLogger()));

    // Analytics
    $c->singleton(\PkStore\Infrastructure\Integration\Analytics\GA4Service::class, fn()=>new \PkStore\Infrastructure\Integration\Analytics\GA4Service($s));
    $c->singleton(\PkStore\Infrastructure\Integration\Analytics\FacebookPixelService::class, fn()=>new \PkStore\Infrastructure\Integration\Analytics\FacebookPixelService($s));

    // Marketing
    $c->singleton(\PkStore\Infrastructure\Integration\WhatsApp\WhatsAppService::class, fn()=>new \PkStore\Infrastructure\Integration\WhatsApp\WhatsAppService($s,new WpLogger()));
    $c->singleton(\PkStore\Infrastructure\Integration\Email\MailchimpService::class, fn()=>new \PkStore\Infrastructure\Integration\Email\MailchimpService($s,new WpLogger()));
    $c->singleton(\PkStore\Infrastructure\Integration\Email\KlaviyoService::class, fn()=>new \PkStore\Infrastructure\Integration\Email\KlaviyoService($s,new WpLogger()));

    // Payments
    $c->singleton(\PkStore\Infrastructure\Integration\Accounting\QuickBooksService::class, fn()=>new \PkStore\Infrastructure\Integration\Accounting\QuickBooksService($s,new WpLogger()));
    $c->singleton(\PkStore\Infrastructure\Integration\Marketplace\DarazSyncService::class, fn()=>new \PkStore\Infrastructure\Integration\Marketplace\DarazSyncService($s,new WpLogger()));
    $c->singleton(\PkStore\Infrastructure\Logistics\PostEx\PostExCourier::class, fn()=>new \PkStore\Infrastructure\Logistics\PostEx\PostExCourier($s,new WpLogger()));
    $c->singleton(\PkStore\Infrastructure\Logistics\Rider\RiderCourier::class, fn()=>new \PkStore\Infrastructure\Logistics\Rider\RiderCourier($s,new WpLogger()));

    // Barcode labels
    $c->singleton(\PkStore\Infrastructure\Barcode\BarcodeLabel::class, fn()=>new \PkStore\Infrastructure\Barcode\BarcodeLabel($c->make(\PkStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\PkStore\Domain\Inventory\Repository\StockRepositoryInterface::class)));
};
