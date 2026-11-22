<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Admin\ListTable;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class OtpAuditTable extends \WP_List_Table {
    public function __construct(private readonly \wpdb $db) {
        parent::__construct([
            'singular' => 'OTP Attempt',
            'plural' => 'OTP Attempts',
            'ajax' => false,
        ]);
    }

    public function get_columns(): array {
        return [
            'phone_e164' => 'Phone',
            'order_id' => 'Order',
            'attempt_count' => 'Attempts',
            'is_verified' => 'Status',
            'created_at' => 'Requested At',
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'created_at' => ['created_at', true],
            'attempt_count' => ['attempt_count', false],
            'is_verified' => ['is_verified', false],
        ];
    }

    public function column_phone_e164($item): string {
        $phone = (string) ($item['phone_e164'] ?? '');
        $masked = strlen($phone) > 7
            ? substr($phone, 0, 5) . '*****' . substr($phone, -3)
            : '***';
        return '<code>' . esc_html($masked) . '</code>';
    }

    public function column_order_id($item): string {
        $orderId = absint($item['order_id'] ?? 0);
        if ($orderId < 1) return '<em>—</em>';

        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : false;
        if (!($order instanceof \WC_Order)) {
            return '<span>#' . esc_html((string) $orderId) . '</span>';
        }

        return '<a href="' . esc_url($order->get_edit_order_url()) . '">#'
            . esc_html((string) $orderId) . '</a>';
    }

    public function column_attempt_count($item): string {
        $count = (int) ($item['attempt_count'] ?? 0);
        $color = $count >= 3 ? '#d63638' : ($count >= 2 ? '#dba617' : '#00a32a');
        return '<span style="color:' . esc_attr($color) . ';font-weight:700;">'
            . esc_html((string) $count) . ' / 3</span>';
    }

    public function column_is_verified($item): string {
        $verified = (bool) ($item['is_verified'] ?? false);
        $attempts = (int) ($item['attempt_count'] ?? 0);
        try {
            $expires = new \DateTimeImmutable($item['expires_at'] ?? 'now');
            $expired = new \DateTimeImmutable() > $expires;
        } catch (\Throwable) {
            $expired = true;
        }

        if ($verified) return '<span style="color:#00a32a;font-weight:600;">✓ Verified</span>';
        if ($attempts >= 3) return '<span style="color:#d63638;">✗ Exhausted</span>';
        if ($expired) return '<span style="color:#646970;">⏱ Expired</span>';
        return '<span style="color:#0073aa;">◷ Pending</span>';
    }

    public function column_created_at($item): string {
        try {
            $date = new \DateTimeImmutable($item['created_at'] ?? 'now');
            return '<span title="' . esc_attr($date->format('Y-m-d H:i:s')) . '">'
                . esc_html(human_time_diff($date->getTimestamp()) . ' ago') . '</span>';
        } catch (\Throwable) {
            return esc_html($item['created_at'] ?? '');
        }
    }

    public function column_default($item, $column): string {
        return esc_html((string) ($item[$column] ?? '—'));
    }

    public function prepare_items(): void {
        $table = $this->db->prefix . 'pkstore_otp_attempts';
        $allowedOrderBy = ['created_at', 'attempt_count', 'is_verified', 'order_id'];
        $orderBy = in_array($_GET['orderby'] ?? '', $allowedOrderBy, true)
            ? sanitize_key($_GET['orderby'])
            : 'created_at';
        $direction = strtoupper((string) ($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $total = (int) $this->db->get_var("SELECT COUNT(*) FROM {$table}");
        $pageSize = 25;
        $offset = ($this->get_pagenum() - 1) * $pageSize;

        $this->items = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$table} ORDER BY {$orderBy} {$direction} LIMIT %d OFFSET %d",
                $pageSize,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page' => $pageSize,
            'total_pages' => (int) ceil($total / $pageSize),
        ]);
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            'created_at',
        ];
    }
}
