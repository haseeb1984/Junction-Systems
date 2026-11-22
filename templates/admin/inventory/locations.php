<?php defined('ABSPATH') || exit; ?>
<div class="wrap pkstore-admin">
    <h1>📍 Inventory Locations</h1>
    <?php if ($msg !== ''): ?>
        <div class="notice notice-<?= $status === 'error' ? 'error' : 'success' ?> is-dismissible"><p><?= esc_html($msg) ?></p></div>
    <?php endif; ?>

    <p>Each location keeps its own stock ledger. Online Store stock is published as the combined sellable quantity across active locations.</p>

    <div style="display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:20px;align-items:start;">
        <div class="card" style="padding:16px;">
            <h2>Active Locations</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr><th>Name</th><th>Type</th><th>POS</th><th>Online</th><th>Address</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($locations as $location): ?>
                        <tr>
                            <td><strong><?= esc_html($location->name) ?></strong></td>
                            <td><?= esc_html(ucfirst($location->type)) ?></td>
                            <td><?= $location->isPos ? 'Enabled' : '—' ?></td>
                            <td><?= $location->isOnline ? 'Combined online stock' : '—' ?></td>
                            <td><?= esc_html($location->address ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($locations)): ?>
                        <tr><td colspan="5">No active locations found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="description">Locations are retained because stock movements and orders refer to their IDs. This screen does not delete or deactivate locations.</p>
        </div>

        <div class="card" style="padding:20px;">
            <h2>Add Location</h2>
            <form method="POST">
                <?php wp_nonce_field('pkstore_inventory_action'); ?>
                <input type="hidden" name="pkstore_inventory_action" value="add_location">
                <table class="form-table">
                    <tr>
                        <th><label for="location_name">Name *</label></th>
                        <td><input type="text" id="location_name" name="location_name" class="regular-text" maxlength="100" required></td>
                    </tr>
                    <tr>
                        <th><label for="location_type">Type</label></th>
                        <td>
                            <select id="location_type" name="location_type">
                                <option value="physical">Physical store</option>
                                <option value="warehouse">Warehouse</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="location_address">Address</label></th>
                        <td><textarea id="location_address" name="location_address" class="large-text" rows="3"></textarea></td>
                    </tr>
                    <tr>
                        <th>POS Register</th>
                        <td><label><input type="checkbox" name="location_is_pos" value="1"> Allow this physical location in the POS register</label></td>
                    </tr>
                </table>
                <?php submit_button('Add Location'); ?>
            </form>
        </div>
    </div>
</div>
