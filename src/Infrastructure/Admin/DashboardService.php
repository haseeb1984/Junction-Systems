<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Admin;

final class DashboardService {
    private const TTL = HOUR_IN_SECONDS;

    public function __construct(private readonly \wpdb $db) {}

    public function getDeliveryHealth(): array {
        $cached = get_transient('pkstore_dash_health');
        if ($cached !== false) return $cached;

        $since = time() - (30 * DAY_IN_SECONDS);
        $summary = [];
        $this->forEachOrder([
            'type' => 'shop_order',
            'date_created' => '>' . $since,
            'meta_key' => '_booked_courier',
        ], static function ($order) use (&$summary): void {
            if (!($order instanceof \WC_Order)) return;
            $courier = strtoupper((string) $order->get_meta('_booked_courier'));
            if ($courier === '') return;

            if (!isset($summary[$courier])) {
                $summary[$courier] = [
                    'courier' => $courier,
                    'total' => 0,
                    'delivered' => 0,
                    'rto' => 0,
                    'in_transit' => 0,
                ];
            }

            $summary[$courier]['total']++;
            $status = $order->get_status();
            if ($status === 'completed') $summary[$courier]['delivered']++;
            if ($status === 'returned') $summary[$courier]['rto']++;
            if (in_array($status, ['shipped', 'out-for-delivery'], true)) {
                $summary[$courier]['in_transit']++;
            }
        });

        foreach ($summary as &$row) {
            $total = max(1, (int) $row['total']);
            $row['delivery_rate'] = round(((int) $row['delivered'] / $total) * 100, 1);
            $row['rto_rate'] = round(((int) $row['rto'] / $total) * 100, 1);
        }
        unset($row);

        set_transient('pkstore_dash_health', $summary, self::TTL);
        return $summary;
    }

    public function getLogisticsSummary(): array {
        $cached = get_transient('pkstore_dash_logistics');
        if ($cached !== false) return $cached;

        $today = strtotime('today');
        $since24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $logTable = $this->db->prefix . 'pkstore_logistics_log';

        $summary = [
            'total_today' => $this->countOrders([
                'type' => 'shop_order',
                'date_created' => '>=' . $today,
            ]),
            'pending_booking' => 0,
            'booking_failures_24h' => (int) $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$logTable} WHERE log_type='failure' AND created_at>=%s",
                $since24h
            )),
            'avg_api_response_ms' => (int) $this->db->get_var($this->db->prepare(
                "SELECT COALESCE(AVG(duration_ms),0) FROM {$logTable} WHERE log_type='response' AND created_at>=%s",
                $since24h
            )),
        ];

        $this->forEachOrder([
            'type' => 'shop_order',
            'meta_key' => '_tracking_courier',
        ], static function ($order) use (&$summary): void {
            if (!($order instanceof \WC_Order)) return;
            $tracking = (string) $order->get_meta('_tracking_courier');
            if (str_starts_with($tracking, 'PENDING-')) {
                $summary['pending_booking']++;
            }
        });

        set_transient('pkstore_dash_logistics', $summary, self::TTL);
        return $summary;
    }

    public function getOtpSummary(): array {
        $cached = get_transient('pkstore_dash_otp');
        if ($cached !== false) return $cached;

        $since24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $table = $this->db->prefix . 'pkstore_otp_attempts';
        $row = $this->db->get_row($this->db->prepare(
            "SELECT COUNT(*) AS total,SUM(is_verified=1) AS verified FROM {$table} WHERE created_at>=%s",
            $since24h
        ), ARRAY_A);
        $total = max(1, (int) ($row['total'] ?? 0));
        $verified = (int) ($row['verified'] ?? 0);
        $summary = [
            'total_24h' => $total,
            'verified_24h' => $verified,
            'verification_rate' => round(($verified / $total) * 100, 1),
        ];
        set_transient('pkstore_dash_otp', $summary, self::TTL);
        return $summary;
    }

    public function getAiSummary(): array {
        $cached = get_transient('pkstore_dash_ai');
        if ($cached !== false) return $cached;

        $since24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $table = $this->db->prefix . 'pkstore_chat_history';
        $row = $this->db->get_row($this->db->prepare(
            "SELECT COUNT(DISTINCT session_uuid) AS sessions,COUNT(*) AS messages FROM {$table} WHERE created_at>=%s",
            $since24h
        ), ARRAY_A);
        $sessions = max(1, (int) ($row['sessions'] ?? 0));
        $messages = (int) ($row['messages'] ?? 0);
        $summary = [
            'total_sessions_24h' => $sessions,
            'total_messages_24h' => $messages,
            'avg_messages_per_session' => round($messages / $sessions, 1),
        ];
        set_transient('pkstore_dash_ai', $summary, self::TTL);
        return $summary;
    }

    public function bustAllCaches(): void {
        foreach (['pkstore_dash_health', 'pkstore_dash_logistics', 'pkstore_dash_otp', 'pkstore_dash_ai'] as $key) {
            delete_transient($key);
        }
    }

    /**
     * Iterate WooCommerce orders through the active order datastore (HPOS or CPT).
     */
    private function forEachOrder(array $args, callable $callback): int {
        if (!function_exists('wc_get_orders')) return 0;

        $page = 1;
        $maxPages = 1;
        $total = 0;
        do {
            $query = wc_get_orders(array_merge($args, [
                'limit' => 100,
                'paged' => $page,
                'return' => 'objects',
                'paginate' => true,
            ]));
            if (!is_object($query) || !isset($query->orders, $query->max_num_pages, $query->total)) {
                return 0;
            }
            $maxPages = max(1, (int) $query->max_num_pages);
            $total = (int) $query->total;
            foreach ($query->orders as $order) {
                $callback($order);
            }
            $page++;
        } while ($page <= $maxPages);

        return $total;
    }

    private function countOrders(array $args): int {
        if (!function_exists('wc_get_orders')) return 0;
        $query = wc_get_orders(array_merge($args, [
            'limit' => 1,
            'return' => 'ids',
            'paginate' => true,
        ]));
        return is_object($query) && isset($query->total) ? (int) $query->total : 0;
    }
}
