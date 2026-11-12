<?php
declare(strict_types=1);
use JunctionStore\Container\ServiceContainer;
use JunctionStore\Settings\SettingsManager;
use JunctionStore\Infrastructure\Logger\WpLogger;
use JunctionStore\Infrastructure\Payment\{JazzCashGateway,EasyPaisaGateway};
use JunctionStore\Infrastructure\Verification\{WpOtpRepository,TwilioSmsProvider,LocalSmsProvider};
use JunctionStore\Infrastructure\AI\{OpenAiBridge,WcProductRepository,WpChatRepository};
use JunctionStore\Infrastructure\Logistics\{TcsCourier,LeopardsCourier,TraxCourier,CityMappingService,RateCalculator,CourierCircuitBreaker,LogisticsLogger};
use JunctionStore\Infrastructure\WooCommerce\OrderMetaRepository;
use JunctionStore\Infrastructure\Admin\DashboardService;
use JunctionStore\Infrastructure\Affiliate\{WpAffiliateRepository,WpAffiliateProductRepository,WpCommissionRepository,WpPayoutRepository};
use JunctionStore\Domain\AI\{AiProviderInterface,ProductRepositoryInterface,ChatRepositoryInterface,PromptComposer,ContextSelector};
use JunctionStore\Domain\AI\Security\PromptInjectionDetector;
use JunctionStore\Domain\Verification\{SmsProviderInterface,Repository\OtpRepositoryInterface};
use JunctionStore\Domain\Affiliate\Repository\{AffiliateRepositoryInterface,AffiliateProductRepositoryInterface,CommissionRepositoryInterface,PayoutRepositoryInterface};
use JunctionStore\Application\Verification\{SendCodOtpUseCase,VerifyCodOtpUseCase};
use JunctionStore\Application\AI\{HandleAiChatUseCase,ProductContextService};
use JunctionStore\Application\Logistics\BookShipmentUseCase;
use JunctionStore\Application\Affiliate\{RegisterAffiliateUseCase,TrackAffiliateClickUseCase,RecordCommissionUseCase,ProcessPayoutUseCase,SubmitAffiliateProductUseCase,TrackAffiliateProductClickUseCase};
use JunctionStore\Settings\SetupWizard;
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
    $c->singleton(\JunctionStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class, function(){global $wpdb;return new \JunctionStore\Infrastructure\Inventory\WpInventoryProductRepository($wpdb);});
    $c->singleton(\JunctionStore\Domain\Inventory\Repository\StockRepositoryInterface::class, function(){global $wpdb;return new \JunctionStore\Infrastructure\Inventory\WpStockRepository($wpdb);});
    $c->singleton(\JunctionStore\Domain\Inventory\Repository\LocationRepositoryInterface::class, function(){global $wpdb;return new \JunctionStore\Infrastructure\Inventory\WpLocationRepository($wpdb);});
    $c->singleton(\JunctionStore\Domain\POS\Repository\POSSessionRepositoryInterface::class, function(){global $wpdb;return new \JunctionStore\Infrastructure\POS\WpPOSSessionRepository($wpdb);});
    $c->singleton(\JunctionStore\Domain\POS\Repository\POSOrderRepositoryInterface::class, function(){global $wpdb;return new \JunctionStore\Infrastructure\POS\WpPOSOrderRepository($wpdb);});

    // Inventory use cases
    $c->singleton(\JunctionStore\Application\Inventory\AdjustStockUseCase::class, fn()=>new \JunctionStore\Application\Inventory\AdjustStockUseCase($c->make(\JunctionStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\JunctionStore\Domain\Inventory\Repository\StockRepositoryInterface::class),new WpLogger()));
    $c->singleton(\JunctionStore\Application\Inventory\GenerateBarcodeUseCase::class, fn()=>new \JunctionStore\Application\Inventory\GenerateBarcodeUseCase($c->make(\JunctionStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class)));
    $c->singleton(\JunctionStore\Application\Inventory\SyncWooCommerceStockUseCase::class, fn()=>new \JunctionStore\Application\Inventory\SyncWooCommerceStockUseCase($c->make(\JunctionStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\JunctionStore\Domain\Inventory\Repository\StockRepositoryInterface::class),$c->make(\JunctionStore\Domain\Inventory\Repository\LocationRepositoryInterface::class),$c->make(\JunctionStore\Application\Inventory\AdjustStockUseCase::class),new WpLogger()));

    // POS use cases
    $c->singleton(\JunctionStore\Application\POS\ProcessPOSSaleUseCase::class, fn()=>new \JunctionStore\Application\POS\ProcessPOSSaleUseCase($c->make(\JunctionStore\Domain\POS\Repository\POSOrderRepositoryInterface::class),$c->make(\JunctionStore\Domain\POS\Repository\POSSessionRepositoryInterface::class),$c->make(\JunctionStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\JunctionStore\Application\Inventory\AdjustStockUseCase::class),new WpLogger()));
    $c->singleton(\JunctionStore\Application\POS\SyncPOSQueueUseCase::class, fn()=>new \JunctionStore\Application\POS\SyncPOSQueueUseCase($c->make(\JunctionStore\Domain\POS\Repository\POSOrderRepositoryInterface::class),$c->make(\JunctionStore\Application\POS\ProcessPOSSaleUseCase::class),new WpLogger()));

    // Analytics
    $c->singleton(\JunctionStore\Infrastructure\Integration\Analytics\GA4Service::class, fn()=>new \JunctionStore\Infrastructure\Integration\Analytics\GA4Service($s));
    $c->singleton(\JunctionStore\Infrastructure\Integration\Analytics\FacebookPixelService::class, fn()=>new \JunctionStore\Infrastructure\Integration\Analytics\FacebookPixelService($s));

    // Marketing
    $c->singleton(\JunctionStore\Infrastructure\Integration\WhatsApp\WhatsAppService::class, fn()=>new \JunctionStore\Infrastructure\Integration\WhatsApp\WhatsAppService($s,new WpLogger()));
    $c->singleton(\JunctionStore\Infrastructure\Integration\Email\MailchimpService::class, fn()=>new \JunctionStore\Infrastructure\Integration\Email\MailchimpService($s,new WpLogger()));
    $c->singleton(\JunctionStore\Infrastructure\Integration\Email\KlaviyoService::class, fn()=>new \JunctionStore\Infrastructure\Integration\Email\KlaviyoService($s,new WpLogger()));

    // Payments
    $c->singleton(\JunctionStore\Infrastructure\Integration\Accounting\QuickBooksService::class, fn()=>new \JunctionStore\Infrastructure\Integration\Accounting\QuickBooksService($s,new WpLogger()));
    $c->singleton(\JunctionStore\Infrastructure\Integration\Marketplace\DarazSyncService::class, fn()=>new \JunctionStore\Infrastructure\Integration\Marketplace\DarazSyncService($s,new WpLogger()));
    $c->singleton(\JunctionStore\Infrastructure\Logistics\PostEx\PostExCourier::class, fn()=>new \JunctionStore\Infrastructure\Logistics\PostEx\PostExCourier($s,new WpLogger()));
    $c->singleton(\JunctionStore\Infrastructure\Logistics\Rider\RiderCourier::class, fn()=>new \JunctionStore\Infrastructure\Logistics\Rider\RiderCourier($s,new WpLogger()));

    // Barcode labels
    $c->singleton(\JunctionStore\Infrastructure\Barcode\BarcodeLabel::class, fn()=>new \JunctionStore\Infrastructure\Barcode\BarcodeLabel($c->make(\JunctionStore\Domain\Inventory\Repository\InventoryProductRepositoryInterface::class),$c->make(\JunctionStore\Domain\Inventory\Repository\StockRepositoryInterface::class)));
};
