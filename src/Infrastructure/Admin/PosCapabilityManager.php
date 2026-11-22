<?php
declare(strict_types=1);

namespace PkStore\Infrastructure\Admin;

final class PosCapabilityManager {
    public function register(): void {
        add_action('init', [$this, 'ensureRolesAndCapabilities'], 1);
        add_action('show_user_profile', [$this, 'renderLocationField']);
        add_action('edit_user_profile', [$this, 'renderLocationField']);
        add_action('personal_options_update', [$this, 'saveLocationField']);
        add_action('edit_user_profile_update', [$this, 'saveLocationField']);
    }

    public function ensureRolesAndCapabilities(): void {
        foreach (['administrator', 'shop_manager'] as $roleName) {
            $role = get_role($roleName);
            if ($role && !$role->has_cap('use_junction_pos')) {
                $role->add_cap('use_junction_pos');
            }
        }

        $cashier = get_role('junction_cashier');
        if (!$cashier) {
            add_role('junction_cashier', 'Junction Cashier', [
                'read' => true,
                'use_junction_pos' => true,
            ]);
        } elseif (!$cashier->has_cap('use_junction_pos')) {
            $cashier->add_cap('use_junction_pos');
        }
    }

    public function renderLocationField(\WP_User $user): void {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_inventory_locations';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        $locations = [];
        if ($exists === $table) {
            $locations = $wpdb->get_results(
                "SELECT id,name FROM {$table} WHERE is_active=1 AND is_pos=1 ORDER BY name ASC",
                ARRAY_A
            ) ?: [];
        }
        $selected = (int) get_user_meta($user->ID, '_pkstore_pos_location_id', true);
        ?>
        <h2>Junction POS Access</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="pkstore_pos_location_id">Assigned POS location</label></th>
                <td>
                    <select name="pkstore_pos_location_id" id="pkstore_pos_location_id">
                        <option value="0">— Not assigned —</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?= esc_attr((string) $location['id']) ?>" <?= selected($selected, (int) $location['id'], false) ?>>
                                <?= esc_html((string) $location['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Junction Cashier users can only open and operate a POS session at their assigned POS-enabled location. Administrators and WooCommerce managers can access all POS locations.</p>
                    <?php if (!$exists): ?>
                        <p class="description">Inventory locations are not available until the inventory database is installed.</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    public function saveLocationField(int $userId): void {
        if (!current_user_can('manage_options') || !current_user_can('edit_user', $userId)) return;
        check_admin_referer('update-user_' . $userId);

        $locationId = absint($_POST['pkstore_pos_location_id'] ?? 0);
        if ($locationId === 0) {
            delete_user_meta($userId, '_pkstore_pos_location_id');
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pkstore_inventory_locations';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id=%d AND is_active=1 AND is_pos=1",
            $locationId
        ));
        if ((int) $exists === $locationId) {
            update_user_meta($userId, '_pkstore_pos_location_id', $locationId);
        }
    }
}
