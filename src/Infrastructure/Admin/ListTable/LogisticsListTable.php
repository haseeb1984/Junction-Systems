<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Admin\ListTable;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class LogisticsListTable extends \WP_List_Table {
    public function __construct(private readonly \wpdb $db) {
        parent::__construct([
            'singular' => 'Log Entry',
            'plural' => 'Log Entries',
            'ajax' => false,
        ]);
    }

    public function get_columns(): array {
        return [
            'created_at' => 'Time',
            'courier_code' => 'Courier',
            'log_type' => 'Type',
            'endpoint' => 'Endpoint',
            'http_status' => 'HTTP',
            'duration_ms' => 'Duration',
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'created_at' => ['created_at', true],
            'duration_ms' => ['duration_ms', false],
            'http_status' => ['http_status', false],
        ];
    }

    public function column_courier_code($item): string {
        $courier = strtoupper((string) ($item['courier_code'] ?? ''));
        if ($courier === '') return '<em>—</em>';

        $colors = [
            'TCS' => '#1d6fa4',
            'LEOPARDS' => '#d63638',
            'TRAX' => '#00a32a',
        ];
        $color = $colors[$courier] ?? '#646970';

        return '<span style="background:' . esc_attr($color) . ';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">'
            . esc_html($courier) . '</span>';
    }

    public function column_log_type($item): string {
        return match ($item['log_type'] ?? '') {
            'request' => '<span style="color:#0073aa;">→ Request</span>',
            'response' => '<span style="color:#00a32a;">← Response</span>',
            'failure' => '<span style="color:#d63638;">✗ Failure</span>',
            default => '<span>—</span>',
        };
    }

    public function column_http_status($item): string {
        $status = (int) ($item['http_status'] ?? 0);
        if ($status < 1) return '<em>N/A</em>';

        $color = $status >= 500 ? '#d63638' : ($status >= 400 ? '#dba617' : '#00a32a');
        return '<span style="color:' . esc_attr($color) . ';font-weight:600;">' . $status . '</span>';
    }

    public function column_duration_ms($item): string {
        $duration = (int) ($item['duration_ms'] ?? 0);
        if ($duration < 1) return '<em>—</em>';

        $style = $duration > 10000
            ? 'color:#d63638;'
            : ($duration > 5000 ? 'color:#dba617;' : '');

        return '<span style="' . esc_attr($style) . '">' . number_format($duration) . ' ms</span>';
    }

    public function column_endpoint($item): string {
        $endpoint = (string) ($item['endpoint'] ?? '');
        $path = parse_url($endpoint, PHP_URL_PATH);
        if (!is_string($path) || $path === '') $path = $endpoint;

        return '<code title="' . esc_attr($endpoint) . '">'
            . esc_html(substr($path, -40)) . '</code>';
    }

    public function column_created_at($item): string {
        try {
            $date = new \DateTimeImmutable($item['created_at'] ?? 'now');
            return '<span title="' . esc_attr($date->format('Y-m-d H:i:s')) . '">'
                . esc_html(wp_date('M j, H:i', $date->getTimestamp())) . '</span>';
        } catch (\Throwable) {
            return esc_html($item['created_at'] ?? '');
        }
    }

    public function column_default($item, $column): string {
        return esc_html((string) ($item[$column] ?? '—'));
    }

    public function prepare_items(): void {
        $table = $this->db->prefix . 'pkstore_logistics_log';
        $allowedOrderBy = ['created_at', 'duration_ms', 'http_status'];
        $orderBy = in_array($_GET['orderby'] ?? '', $allowedOrderBy, true)
            ? sanitize_key($_GET['orderby'])
            : 'created_at';
        $direction = strtoupper((string) ($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $where = '1=1';
        $args = [];
        $courier = sanitize_key($_GET['courier'] ?? '');
        if ($courier !== '') {
            $where .= ' AND courier_code=%s';
            $args[] = $courier;
        }
        $type = sanitize_key($_GET['log_type'] ?? '');
        if ($type !== '') {
            $where .= ' AND log_type=%s';
            $args[] = $type;
        }

        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int) $this->db->get_var(
            $args ? $this->db->prepare($countSql, ...$args) : $countSql
        );

        $pageSize = 30;
        $offset = ($this->get_pagenum() - 1) * $pageSize;
        $queryArgs = array_merge($args, [$pageSize, $offset]);
        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderBy} {$direction} LIMIT %d OFFSET %d";
        $this->items = $this->db->get_results(
            $this->db->prepare($sql, ...$queryArgs),
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
