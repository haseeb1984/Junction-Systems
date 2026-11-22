<?php defined('ABSPATH') || exit; ?>
<div class="wrap pkstore-admin">
    <h1>🔄 Inventory Sync Health</h1>
    <p>Queue monitor for WooCommerce sale, return and aggregate stock-sync events. Review-state sale and return events can be retried or ignored with a mandatory reason and an audit record. Stock-sync actions remain disabled pending lease-fencing.</p>

    <?php if (!empty($missingQueues)): ?>
        <div class="notice notice-warning"><p>
            Some inventory queue tables are unavailable: <?= esc_html(implode(', ', $missingQueues)) ?>.
            Check the database migration status before relying on this screen.
        </p></div>
    <?php endif; ?>

    <?php
    $queueLabels = [
        'sales' => 'Online sales',
        'returns' => 'Returns',
        'stock' => 'Stock sync',
    ];
    ?>
    <div class="notice notice-info" style="padding:10px 14px;">
        <strong>Queue status totals</strong>
        <div style="display:flex;gap:28px;flex-wrap:wrap;margin-top:8px;">
            <?php foreach ($queueLabels as $queueKey => $queueLabel): ?>
                <div>
                    <strong><?= esc_html($queueLabel) ?></strong><br>
                    <?php foreach (['pending', 'processing', 'needs_review'] as $queueStatus): ?>
                        <span style="margin-right:10px;"><?= esc_html(ucfirst($queueStatus)) ?>:
                            <?= esc_html((string) ($queueCounts[$queueKey][$queueStatus] ?? 0)) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="description">Totals include all retained queue rows. Completed events are removed by the current workers and are not counted here.</p>
    </div>

    <?php
    $renderQueue = static function (string $heading, array $events, ?string $queueType = null): void {
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
                    <?php if ($queueType !== null): ?><th>Operator action</th><?php endif; ?>
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
                    <?php if ($queueType !== null): ?>
                        <td>
                            <?php if (($event['status'] ?? '') === 'needs_review'): ?>
                                <form method="post" style="margin-bottom:8px;">
                                    <?php wp_nonce_field('pkstore_inventory_action'); ?>
                                    <input type="hidden" name="pkstore_inventory_action" value="retry_inventory_event">
                                    <input type="hidden" name="queue_type" value="<?= esc_attr($queueType) ?>">
                                    <input type="hidden" name="queue_id" value="<?= esc_attr((string) ($event['id'] ?? 0)) ?>">
                                    <input type="text" name="reason" required minlength="5" maxlength="1000" placeholder="Reason for retry" style="max-width:180px;">
                                    <button type="submit" class="button button-secondary">Retry</button>
                                </form>
                                <form method="post">
                                    <?php wp_nonce_field('pkstore_inventory_action'); ?>
                                    <input type="hidden" name="pkstore_inventory_action" value="ignore_inventory_event">
                                    <input type="hidden" name="queue_type" value="<?= esc_attr($queueType) ?>">
                                    <input type="hidden" name="queue_id" value="<?= esc_attr((string) ($event['id'] ?? 0)) ?>">
                                    <input type="text" name="reason" required minlength="5" maxlength="1000" placeholder="Reason to ignore" style="max-width:180px;">
                                    <button type="submit" class="button button-secondary">Ignore</button>
                                </form>
                            <?php else: ?>
                                <span class="description">Available only for needs_review</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$events): ?>
                <tr><td colspan="<?= $queueType !== null ? '9' : '8' ?>" style="text-align:center;padding:20px;color:#646970;">No queued events found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <p class="description">Showing the latest <?= esc_html((string) count($events)) ?> event(s), up to 100.</p>
        <?php
    };
    ?>

    <?php $renderQueue('Online store sale events', $salesEvents, 'sales'); ?>
    <?php $renderQueue('Cancellation and refund restock events', $returnEvents, 'returns'); ?>
    <?php $renderQueue('WooCommerce aggregate stock-sync events', $stockEvents); ?>

    <h2>Recent operator audit</h2>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr><th>Queue</th><th>Event ID</th><th>Action</th><th>State change</th><th>Reason</th><th>Operator</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach (($operatorAudit ?? []) as $audit): ?>
            <tr>
                <td><?= esc_html($audit['queue_type'] ?? '') ?></td>
                <td><?= esc_html((string) ($audit['queue_id'] ?? '')) ?></td>
                <td><?= esc_html($audit['action'] ?? '') ?></td>
                <td><?= esc_html(($audit['previous_status'] ?? '') . ' → ' . ($audit['resulting_status'] ?? '')) ?></td>
                <td><?= esc_html($audit['reason'] ?? '') ?></td>
                <td><?= esc_html((string) ($audit['actor_id'] ?? '')) ?></td>
                <td><?= esc_html($audit['created_at'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($operatorAudit)): ?><tr><td colspan="7">No operator actions recorded.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="description">Retry and ignore are limited to retained sale/return events in needs_review. Ignore does not change stock; it records an explicit operator decision. Stock-sync queue actions and force-complete remain unavailable.</p>
</div>
