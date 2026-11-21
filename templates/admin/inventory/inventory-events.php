<?php defined('ABSPATH') || exit; ?>
<div class="wrap pkstore-admin">
    <h1>🔄 Inventory Sync Health</h1>
    <p>Read-only view of WooCommerce sale, return and aggregate stock-sync events. Events are processed automatically by the background workers.</p>

    <?php if (!empty($missingQueues)): ?>
        <div class="notice notice-warning"><p>
            Some inventory queue tables are unavailable: <?= esc_html(implode(', ', $missingQueues)) ?>.
            Check the database migration status before relying on this screen.
        </p></div>
    <?php endif; ?>

    <?php
    $renderQueue = static function (string $heading, array $events): void {
        ?>
        <h2><?= esc_html($heading) ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Reference</th>
                    <th>Details</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Next retry</th>
                    <th>Last error category</th>
                    <th>First seen</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= esc_html($event['event'] ?? '') ?></td>
                    <td><strong><?= esc_html($event['reference'] ?? '') ?></strong></td>
                    <td><?= esc_html($event['details'] ?? '') ?></td>
                    <td><code><?= esc_html($event['status'] ?? '') ?></code>
                        <?php if (!empty($event['claimed_at'])): ?>
                            <br><small>Claimed: <?= esc_html($event['claimed_at']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= esc_html((string) ($event['attempts'] ?? 0)) ?></td>
                    <td><?= esc_html($event['next_attempt_at'] ?? '—') ?></td>
                    <td><?= esc_html($event['last_error'] ?: '—') ?></td>
                    <td><?= esc_html($event['created_at'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$events): ?>
                <tr><td colspan="8" style="text-align:center;padding:20px;color:#646970;">No queued events found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <p class="description">Showing the latest <?= esc_html((string) count($events)) ?> event(s), up to 100.</p>
        <?php
    };
    ?>

    <?php $renderQueue('Online store sale events', $salesEvents); ?>
    <?php $renderQueue('Cancellation and refund restock events', $returnEvents); ?>
    <?php $renderQueue('WooCommerce aggregate stock-sync events', $stockEvents); ?>

    <p class="description">This screen does not force-complete events or change stock. Ambiguous mappings and lifecycle conflicts require a controlled reconciliation workflow.</p>
</div>
