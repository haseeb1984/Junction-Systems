<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\Database;
final class Installer {
    public static function installSchema(): void {
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $cs=$wpdb->get_charset_collate();
        if ($wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_otp_attempts (id CHAR(36) NOT NULL,phone_e164 VARCHAR(16) NOT NULL,hashed_code VARCHAR(255) NOT NULL,order_id VARCHAR(32) NOT NULL,is_verified TINYINT(1) NOT NULL DEFAULT 0,attempt_count TINYINT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,PRIMARY KEY (id),INDEX idx_po (phone_e164,order_id),INDEX idx_pc (phone_e164,created_at)) ENGINE=InnoDB {$cs};") === false) { throw new \RuntimeException("Unable to create required PK Store table."); }
        if ($wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_logistics_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,log_type VARCHAR(20) NOT NULL DEFAULT 'request',courier_code VARCHAR(20) NOT NULL DEFAULT '',order_id VARCHAR(32) DEFAULT NULL,endpoint VARCHAR(512) NOT NULL,payload LONGTEXT NOT NULL,http_status SMALLINT NOT NULL DEFAULT 0,duration_ms INT NOT NULL DEFAULT 0,idempotency_key VARCHAR(64) DEFAULT NULL,created_at DATETIME NOT NULL,PRIMARY KEY (id),UNIQUE INDEX idx_idem (idempotency_key),INDEX idx_order (order_id),INDEX idx_courier (courier_code,created_at)) ENGINE=InnoDB {$cs};") === false) { throw new \RuntimeException("Unable to create required PK Store table."); }
        if ($wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_chat_history (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,session_uuid CHAR(36) NOT NULL,role VARCHAR(20) NOT NULL,content TEXT NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY (id),INDEX idx_session (session_uuid,created_at)) ENGINE=InnoDB {$cs};") === false) { throw new \RuntimeException("Unable to create required PK Store table."); }
        if ($wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pkstore_city_mappings (id INT UNSIGNED NOT NULL AUTO_INCREMENT,wc_city VARCHAR(100) NOT NULL,tcs_id INT NOT NULL DEFAULT 0,leopards_code VARCHAR(10) NOT NULL DEFAULT '',trax_name VARCHAR(100) NOT NULL DEFAULT '',zone CHAR(1) NOT NULL DEFAULT 'B',province VARCHAR(50) NOT NULL DEFAULT '',is_active TINYINT(1) NOT NULL DEFAULT 1,updated_at DATETIME NOT NULL,PRIMARY KEY (id),UNIQUE INDEX idx_city (wc_city)) ENGINE=InnoDB {$cs};") === false) { throw new \RuntimeException("Unable to create required PK Store table."); }
        self::seedCities();
        AffiliateInstaller::run();
        InventoryInstaller::run();
    }
    public static function createCustomOrderStatuses(): void {
        add_action('init',function(){foreach(['wc-shipped'=>'Shipped','wc-out-for-delivery'=>'Out for Delivery','wc-delivery-failed'=>'Delivery Failed','wc-returned'=>'Returned (RTO)']as $s=>$l){register_post_status($s,['label'=>$l,'public'=>true,'show_in_admin_all_list'=>true,'show_in_admin_status_list'=>true,'label_count'=>_n_noop($l.' <span class="count">(%s)</span>',$l.' <span class="count">(%s)</span>')]);}});
        add_filter('wc_order_statuses',function($s){return array_merge($s,['wc-shipped'=>'Shipped','wc-out-for-delivery'=>'Out for Delivery','wc-delivery-failed'=>'Delivery Failed','wc-returned'=>'Returned (RTO)']);});
    }
    private static function seedCities(): void {
        global $wpdb; $t=$wpdb->prefix.'pkstore_city_mappings';
        if((int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}")>0)return;
        foreach([['karachi',5,'KHI','Karachi','A','Sindh'],['lahore',1,'LHR','Lahore','A','Punjab'],['islamabad',2,'ISB','Islamabad','A','Federal'],['rawalpindi',3,'RWP','Rawalpindi','A','Punjab'],['faisalabad',4,'FSD','Faisalabad','A','Punjab'],['multan',6,'MUL','Multan','A','Punjab'],['peshawar',7,'PSH','Peshawar','B','KPK'],['hyderabad',11,'HYD','Hyderabad','B','Sindh'],['quetta',9,'QTA','Quetta','B','Balochistan'],['sialkot',14,'SKT','Sialkot','A','Punjab'],['gujranwala',15,'GRW','Gujranwala','A','Punjab'],['sukkur',12,'SUK','Sukkur','B','Sindh'],['abbottabad',22,'ABT','Abbottabad','B','KPK'],['gilgit',30,'GIL','Gilgit','C','GB'],['muzaffarabad',31,'MZD','Muzaffarabad','C','AJK'],['bahawalpur',18,'BWP','Bahawalpur','B','Punjab'],['sargodha',16,'SGD','Sargodha','B','Punjab'],['larkana',25,'LRK','Larkana','C','Sindh'],['mardan',23,'MRD','Mardan','B','KPK'],['dera ghazi khan',20,'DGK','Dera Ghazi Khan','C','Punjab']]as $c)
            $wpdb->insert($t,['wc_city'=>$c[0],'tcs_id'=>$c[1],'leopards_code'=>$c[2],'trax_name'=>$c[3],'zone'=>$c[4],'province'=>$c[5],'is_active'=>1,'updated_at'=>current_time('mysql')]);
    }
}
