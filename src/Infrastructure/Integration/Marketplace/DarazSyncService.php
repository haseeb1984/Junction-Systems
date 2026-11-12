<?php
declare(strict_types=1);
namespace JunctionStore\Infrastructure\Integration\Marketplace;
use JunctionStore\Settings\SettingsManager;
use Psr\Log\LoggerInterface;

final class DarazSyncService {
    private const BASE = 'https://api.daraz.pk/rest';
    public function __construct(private readonly SettingsManager $s, private readonly LoggerInterface $log) {}

    private function sign(array $params): string {
        $appSecret=$this->s->get('DARAZ_APP_SECRET');
        ksort($params);
        $str=''; foreach ($params as $k=>$v) $str.=$k.$v;
        return strtoupper(hash_hmac('sha256',$str,$appSecret));
    }

    private function request(string $method, array $params=[], ?string $body=null): ?array {
        $appKey=$this->s->get('DARAZ_APP_KEY'); $token=$this->s->get('DARAZ_ACCESS_TOKEN');
        if (!$appKey||!$token) return null;
        $params['app_key']=$appKey; $params['access_token']=$token;
        $params['sign_method']='sha256'; $params['timestamp']=round(microtime(true)*1000);
        $params['sign']=$this->sign($params);
        $url=self::BASE.$method.'?'.http_build_query($params);
        $opts=['timeout'=>20,'headers'=>['Content-Type'=>'application/json']];
        if ($body){$opts['method']='POST';$opts['body']=$body;}
        $r=wp_remote_request($url,$opts);
        if (is_wp_error($r)) return null;
        return json_decode(wp_remote_retrieve_body($r),true);
    }

    public function pushProductFeed(): int {
        $products=wc_get_products(['status'=>'publish','limit'=>100]);
        $items=[]; $pushed=0;
        foreach ($products as $product) {
            $items[]=['attributes'=>['name'=>$product->get_name(),'short_description'=>$product->get_short_description(),'brand'=>'Generic'],'skus'=>[['SellerSku'=>$product->get_sku()?:'WC-'.$product->get_id(),'price'=>(float)$product->get_price(),'quantity'=>$product->get_stock_quantity()??0,'package_length'=>10,'package_width'=>10,'package_height'=>10,'package_weight'=>0.5]]];
        }
        if (empty($items)) return 0;
        $xml='<Request><Product>'.implode('',array_map(fn($i)=>'<Skus><Sku><SellerSku>'.$i['skus'][0]['SellerSku'].'</SellerSku><price>'.$i['skus'][0]['price'].'</price><quantity>'.$i['skus'][0]['quantity'].'</quantity></Sku></Skus>', $items)).'</Product></Request>';
        $res=$this->request('/product/create',['format'=>'JSON'],$xml);
        if ($res&&($res['code']??'')==='0') $pushed=count($items);
        $this->log->info('Daraz product feed pushed.',['count'=>$pushed]);
        return $pushed;
    }

    public function pullOrders(): array {
        $res=$this->request('/orders/get',['format'=>'JSON','status'=>'pending','limit'=>50]);
        $orders=$res['data']['orders']??[];
        $imported=0;
        foreach ($orders as $darazOrder) {
            $existing=get_posts(['post_type'=>'shop_order','meta_key'=>'_daraz_order_id','meta_value'=>$darazOrder['order_id'],'numberposts'=>1]);
            if ($existing) continue;
            // Create WC order from Daraz order
            $wcOrder=wc_create_order(['status'=>'processing','customer_note'=>'Daraz Order #'.$darazOrder['order_id']]);
            if (!is_wp_error($wcOrder)){
                $wcOrder->update_meta_data('_daraz_order_id',$darazOrder['order_id']);
                $wcOrder->update_meta_data('_daraz_source','daraz');
                $wcOrder->set_billing_first_name($darazOrder['address_billing']['first_name']??'Daraz');
                $wcOrder->set_billing_last_name($darazOrder['address_billing']['last_name']??'Customer');
                $wcOrder->set_billing_phone($darazOrder['address_billing']['phone']??'0300000000');
                $wcOrder->set_billing_address_1($darazOrder['address_billing']['address1']??'Daraz Platform');
                $wcOrder->set_billing_city($darazOrder['address_billing']['city']??'Karachi');
                $wcOrder->save();
                $imported++;
            }
        }
        $this->log->info('Daraz orders pulled.',['imported'=>$imported]);
        return ['orders'=>count($orders),'imported'=>$imported];
    }

    public function register(): void {
        if (!wp_next_scheduled('pkstore_daraz_sync')) wp_schedule_event(time(),'hourly','pkstore_daraz_sync');
        add_action('pkstore_daraz_sync',[$this,'pullOrders']);
        add_action('woocommerce_update_product',[$this,'onProductUpdate']);
    }

    public function onProductUpdate(int $productId): void {
        // Push updated product to Daraz
        try { $this->pushProductFeed(); } catch (\Throwable $e) {}
    }
}
