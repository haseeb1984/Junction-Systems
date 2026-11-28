<?php defined('ABSPATH') || exit; ?>
<div class="wrap pkstore-admin">
    <h1>🔄 Inventory Sync Health</h1>
    <p>Queue monitor for WooCommerce sale, return and aggregate stock-sync events, plus the immutable stock-cycle event trail. Review-state sale events can be retried; cycle-bound returns can be retried only after ledger evidence passes. Legacy returns require owner reconciliation and cannot be automatically retried. Ignore actions remain reasoned and audited. Cycle-event records are read-only; stock-sync actions remain disabled pending lease-fencing.</p>

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
    $renderQueue = static function (string $heading, array $events, ?string $queueType = null, bool $allowIgnore = true): void {
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
                            <?php if (($event['status'] ?? '') === 'needs_review'
                                && ($queueType !== 'returns' || (!empty($event['cycle_uuid']) && !empty($event['reconciliation_ok'])))): ?>
                                <form method="post" style="margin-bottom:8px;">
                                    <?php wp_nonce_field('pkstore_inventory_action'); ?>
                                    <input type="hidden" name="pkstore_inventory_action" value="retry_inventory_event">
                                    <input type="hidden" name="queue_type" value="<?= esc_attr($queueType) ?>">
                                    <input type="hidden" name="queue_id" value="<?= esc_attr((string) ($event['id'] ?? 0)) ?>">
                                    <input type="text" name="reason" required minlength="5" maxlength="1000" placeholder="Reason for retry" style="max-width:180px;">
                                    <?php if ($queueType === 'returns'): ?>
                                        <label><input type="checkbox" name="confirm_reconciliation" value="1" required> I reviewed the original sale and return-ledger evidence below</label>
                                    <?php endif; ?>
                                    <button type="submit" class="button button-secondary">Retry</button>
                                </form>
                                <?php if ($allowIgnore
                                    && ($queueType === 'sales'
                                        ? (($event['cycle_uuid'] ?? '') === '00000000-0000-0000-0000-000000000000')
                                        : ($queueType !== 'returns' || empty($event['cycle_uuid'])))): ?>
                                <form method="post">
                                    <?php wp_nonce_field('pkstore_inventory_action'); ?>
                                    <input type="hidden" name="pkstore_inventory_action" value="ignore_inventory_event">
                                    <input type="hidden" name="queue_type" value="<?= esc_attr($queueType) ?>">
                                    <input type="hidden" name="queue_id" value="<?= esc_attr((string) ($event['id'] ?? 0)) ?>">
                                    <input type="text" name="reason" required minlength="5" maxlength="1000" placeholder="Reason to ignore" style="max-width:180px;">
                                    <label><input type="checkbox" name="confirm_ignore" value="1" required> Confirm this is not a Junction-managed stock movement or is a duplicate</label>
                                    <button type="submit" class="button button-secondary">Ignore</button>
                                </form>
                                <?php endif; ?>
                            <?php elseif (($event['status'] ?? '') === 'needs_review' && $queueType === 'returns' && empty($event['cycle_uuid'])): ?>
                                <span class="description">Legacy return: retry disabled because no immutable cycle snapshot exists. Owner-led manual reconciliation is required; do not replay stock automatically.</span>
                            <?php elseif (($event['status'] ?? '') === 'needs_review' && $queueType === 'returns' && !empty($event['cycle_uuid'])): ?>
                                <span class="description">Retry disabled: original sale/return ledger evidence is missing or inconsistent.</span>
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

    <h2>Return reconciliation evidence</h2>
    <p class="description">Read-only checks for cycle-bound returns in needs_review/failed. A retry is appropriate only when every original sale movement and cumulative return movement reconciles to the captured allocation. Legacy returns without immutable cycle snapshots are excluded and remain manual-reconciliation-only. This panel never changes stock.</p>
    <?php if (empty($returnEvidence)): ?>
        <p>No cycle-bound return events currently require reconciliation.</p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Queue / order / item</th><th>Cycle / event</th><th>Captured quantity</th><th>Allocation ledger evidence</th><th>Assessment</th></tr></thead>
            <tbody>
            <?php foreach ($returnEvidence as $evidence): ?>
                <tr>
                    <td>#<?= esc_html((string) $evidence['queue_id']) ?> / #<?= esc_html((string) $evidence['order_id']) ?><br>Item <?= esc_html((string) $evidence['item_id']) ?></td>
                    <td><code><?= esc_html(substr((string) $evidence['cycle_uuid'], 0, 12)) ?></code><br><?= esc_html($evidence['kind']) ?></td>
                    <td>Event qty: <?= esc_html((string) $evidence['quantity']) ?><br>Event cumulative: <?= esc_html((string) $evidence['cumulative_quantity']) ?>
                        <?php if (isset($evidence['reduced_quantity'])): ?>
                            <br>Sale reduced: <?= esc_html((string) $evidence['reduced_quantity']) ?>
                            <br>Already returned: <?= esc_html((string) $evidence['returned_quantity']) ?>
                            <br>Refund baseline: <?= esc_html((string) $evidence['refund_baseline']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($evidence['allocations'])): ?>
                            Allocation snapshot unavailable
                        <?php else: ?>
                            <?php foreach ($evidence['allocations'] as $allocation): ?>
                                Product #<?= esc_html((string) $allocation['product_id']) ?> / Location #<?= esc_html((string) $allocation['location_id']) ?>:
                                sale <?= !empty($allocation['sale_verified']) ? 'verified' : 'MISSING/MISMATCH' ?>,
                                returned <?= esc_html((string) $allocation['actual_returned']) ?>/<?= esc_html((string) $allocation['expected_returned']) ?>
                                (<?= esc_html((string) $allocation['return_movement_count']) ?> movements)<br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= esc_html($evidence['state']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Legacy return reconciliation evidence</h2>
    <p class="description">Read-only checks for older WooCommerce returns that have no stock-cycle UUID. Where the order item still has its original allocation snapshot, the screen compares the original sale movement and exact legacy return references. This evidence does not enable automatic retry. POS-origin returns are checked against their saved product/location/order mapping and exact ledger references; orders without a trustworthy saved mapping or allocation snapshot remain manual-reconciliation-only.</p>
    <?php if (empty($legacyReturnEvidence)): ?>
        <p>No legacy return events currently require reconciliation.</p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Queue / order / item</th><th>Return event</th><th>Saved quantities</th><th>Allocation ledger evidence</th><th>Assessment</th></tr></thead>
            <tbody>
            <?php foreach ($legacyReturnEvidence as $evidence): ?>
                <tr>
                    <td>#<?= esc_html((string) $evidence['queue_id']) ?> / #<?= esc_html((string) $evidence['order_id']) ?><br>Item <?= esc_html((string) $evidence['item_id']) ?></td>
                    <td><?= esc_html($evidence['kind']) ?><br>Event qty <?= esc_html((string) $evidence['quantity']) ?><br>Cumulative <?= esc_html((string) $evidence['cumulative_quantity']) ?></td>
                    <td>
                        <?php if (isset($evidence['reduced_quantity'])): ?>
                            Sale reduced: <?= esc_html((string) $evidence['reduced_quantity']) ?><br>
                            Already returned: <?= esc_html((string) $evidence['returned_quantity']) ?>
                        <?php else: ?>
                            Snapshot unavailable
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (empty($evidence['allocations'])): ?>
                            Original allocation snapshot unavailable or event identity is ambiguous
                        <?php else: ?>
                            <?php foreach ($evidence['allocations'] as $allocation): ?>
                                Product #<?= esc_html((string) $allocation['product_id']) ?> / Location #<?= esc_html((string) $allocation['location_id']) ?>:
                                sale <?= !empty($allocation['sale_verified']) ? 'verified' : 'MISSING/MISMATCH' ?>,
                                returned <?= esc_html((string) $allocation['actual_returned']) ?>/<?= esc_html((string) $allocation['expected_returned']) ?>
                                (<?= esc_html((string) $allocation['return_movement_count']) ?> movements)<br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= esc_html($evidence['state']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>WooCommerce stock-cycle events</h2>
    <p class="description">Immutable reduction/return event records used to bind each real WooCommerce stock change to its own Junction ledger cycle. This table is read-only; retry actions operate on the linked durable sale/return queue rows above.</p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Cycle / event</th><th>Order / item</th><th>Kind / source hook</th>
                <th>Quantity</th><th>Cumulative</th><th>Status</th><th>Attempts / next retry</th><th>Last error category</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (($cycleEvents ?? []) as $event): ?>
            <tr>
                <td><code><?= esc_html(substr((string) ($event['cycle_uuid'] ?? ''), 0, 8)) ?></code><br>
                    <small><?= esc_html(substr((string) ($event['event_uuid'] ?? ''), 0, 8)) ?></small></td>
                <td><strong>#<?= esc_html((string) ($event['wc_order_id'] ?? '')) ?></strong><br>
                    <small>Item <?= esc_html((string) ($event['wc_order_item_id'] ?? '—')) ?></small></td>
                <td><?= esc_html($event['event_kind'] ?? '') ?><br>
                    <small><?= esc_html($event['source_hook'] ?? '') ?></small></td>
                <td><?= esc_html((string) ($event['quantity'] ?? 0)) ?></td>
                <td><?= esc_html((string) ($event['cumulative_quantity'] ?? 0)) ?></td>
                <td><code><?= esc_html($event['status'] ?? '') ?></code>
                    <?php if (!empty($event['claimed_at'])): ?><br><small>Claimed: <?= esc_html($event['claimed_at']) ?></small><?php endif; ?>
                </td>
                <td><?= esc_html((string) ($event['attempts'] ?? 0)) ?><br>
                    <small><?= esc_html($event['next_attempt_at'] ?? '—') ?></small></td>
                <td><?= esc_html($event['last_error'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($cycleEvents)): ?>
            <tr><td colspan="8" style="text-align:center;padding:20px;color:#646970;">No stock-cycle events found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <p class="description">Showing the latest <?= esc_html((string) count($cycleEvents ?? [])) ?> cycle event(s), up to 100.</p>
    <?php $renderQueue('WooCommerce aggregate stock-sync events', $stockEvents, 'stock', false); ?>

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
    <p class="description">Retry is available for retained sale, return and lease-fenced stock-sync events in needs_review; Ignore remains limited to legacy sale events and legacy returns; cycle-bound sales and returns are retry-only. Ignore does not change stock; it requires explicit confirmation that the event is not Junction-managed or is a duplicate, and records the operator decision. Stock-sync queue actions and force-complete remain unavailable.</p>
</div>
