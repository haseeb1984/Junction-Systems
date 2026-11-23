<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Integration\Accounting;
use PkStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class QuickBooksService {
    private const AUTH_URL  = 'https://oauth.platform.intuit.com/op/v1/token';
    private const BASE_LIVE = 'https://quickbooks.api.intuit.com/v3/company/';
    private const BASE_SAND = 'https://sandbox-quickbooks.api.intuit.com/v3/company/';
    public function __construct(private readonly SettingsManager $s, private readonly LoggerInterface $log) {}

    private function getToken(): ?string {
        $cached = $this->s->get('QUICKBOOKS_ACCESS_TOKEN');
        $expiresAt = (int) $this->s->get('QUICKBOOKS_ACCESS_TOKEN_EXPIRES', '0');
        if ($cached && $expiresAt > time() + 60) return $cached;
        $refreshToken=$this->s->get('QUICKBOOKS_REFRESH_TOKEN');
        $clientId=$this->s->get('QUICKBOOKS_CLIENT_ID');
        $clientSecret=$this->s->get('QUICKBOOKS_CLIENT_SECRET');
        if (!$refreshToken||!$clientId||!$clientSecret) return null;
        $r=wp_remote_post(self::AUTH_URL,['body'=>['grant_type'=>'refresh_token','refresh_token'=>$refreshToken],'headers'=>['Authorization'=>'Basic '.base64_encode($clientId.':'.  $clientSecret),'Content-Type'=>'application/x-www-form-urlencoded']]);
        if (is_wp_error($r)) return null;
        $b=json_decode(wp_remote_retrieve_body($r),true);
        if (empty($b['access_token'])) return null;
        // Save new refresh token
        $rotatedRefreshToken = (string) ($b['refresh_token'] ?? '');
        if ($rotatedRefreshToken !== '' && !hash_equals($refreshToken, $rotatedRefreshToken)) {
            if (!$this->s->setSecret('QUICKBOOKS_REFRESH_TOKEN', $rotatedRefreshToken)) {
                $this->log->critical('QuickBooks refresh token rotation could not be encrypted and persisted.');
                return null;
            }
        }
        $accessToken = (string) $b['access_token'];
        $expiresIn = max(60, (int) ($b['expires_in'] ?? 3600));
        if (($accessToken !== $cached && !$this->s->setSecret('QUICKBOOKS_ACCESS_TOKEN', $accessToken))
            || !$this->s->set('QUICKBOOKS_ACCESS_TOKEN_EXPIRES', (string) (time() + $expiresIn))) {
            $this->log->critical('QuickBooks access token could not be securely persisted.');
            return null;
        }
        return $accessToken;
    }

    private function request(string $method, string $endpoint, array $body=[]): ?array {
        $token=$this->getToken(); if (!$token) return null;
        $realmId=$this->s->get('QUICKBOOKS_REALM_ID');
        $isSandbox=$this->s->get('QUICKBOOKS_SANDBOX','1')=== '1';
        $base=($isSandbox?self::BASE_SAND:self::BASE_LIVE).$realmId.'/';
        $opts=['method'=>strtoupper($method),'timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json']];
        if ($body) $opts['body']=wp_json_encode($body);
        $r=wp_remote_request($base.$endpoint,$opts);
        if (is_wp_error($r)){$this->log->error('QB request failed',['error'=>$r->get_error_message()]);return null;}
        return json_decode(wp_remote_retrieve_body($r),true);
    }

    public function createSalesReceipt(\WC_Order $order): ?string {
        if (get_post_meta($order->get_id(),'_qb_sales_receipt_id',true)) return null;
        $lines=[];
        foreach ($order->get_items() as $item) {
            $lines[]=['Amount'=>(float)$item->get_total(),'DetailType'=>'SalesItemLineDetail','SalesItemLineDetail'=>['ItemRef'=>['value'=>'1','name'=>$item->get_name()],'Qty'=>$item->get_quantity(),'UnitPrice'=>(float)($item->get_total()/$item->get_quantity())]];
        }
        $payload=['Line'=>$lines,'CustomerRef'=>['value'=>'1','name'=>$order->get_billing_first_name().' '.$order->get_billing_last_name()],'CurrencyRef'=>['value'=>'PKR'],'TxnDate'=>$order->get_date_created()->format('Y-m-d'),'PrivateNote'=>'WooCommerce Order #'.$order->get_order_number()];
        $res=$this->request('POST','salesreceipt?minorversion=65',$payload);
        $id=$res['SalesReceipt']['Id']??null;
        if ($id) { update_post_meta($order->get_id(),'_qb_sales_receipt_id',$id); $this->log->info('QB SalesReceipt created',['qb_id'=>$id,'order'=>$order->get_id()]); }
        return $id;
    }

    public function createInventoryItem(string $name, string $sku, int $price): ?string {
        $payload=['Name'=>$name,'Sku'=>$sku,'Type'=>'Inventory','UnitPrice'=>$price,'TrackQtyOnHand'=>true,'QtyOnHand'=>0,'AssetAccountRef'=>['value'=>'5'],'IncomeAccountRef'=>['value'=>'79'],'ExpenseAccountRef'=>['value'=>'80'],'InvStartDate'=>date('Y-m-d')];
        $res=$this->request('POST','item?minorversion=65',$payload);
        return $res['Item']['Id']??null;
    }

    public function register(): void {
        add_action('woocommerce_payment_complete',[$this,'onOrderPaid']);
    }

    public function onOrderPaid(int $orderId): void {
        try { $order=wc_get_order($orderId); if ($order) $this->createSalesReceipt($order); }
        catch (\Throwable $e) { $this->log->error('QB sync failed',['error'=>$e->getMessage()]); }
    }
}
