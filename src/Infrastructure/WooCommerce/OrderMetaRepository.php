<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\WooCommerce;

final class OrderMetaRepository {
    public function saveTrackingNumber(string $oid, string $tn, string $cc): void {
        $orderId = (int) $oid;
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if (!($order instanceof \WC_Order)) {
            throw new \RuntimeException('Unable to reload the WooCommerce order after courier booking.');
        }

        $order->update_meta_data('_tracking_' . $cc, $tn);
        $order->update_meta_data('_tracking_courier', $tn);
        $order->update_meta_data('_booked_courier', $cc);
        $order->add_order_note('[PK Store] Booked with ' . strtoupper($cc) . '. Tracking: ' . sanitize_text_field($tn));
        if ((int) $order->save() !== $orderId) {
            throw new \RuntimeException('Unable to persist the confirmed courier tracking reference.');
        }
    }

    public function getTrackingNumber(string $oid): string {
        $order = wc_get_order((int) $oid);
        return ($order instanceof \WC_Order) ? (string) $order->get_meta('_tracking_courier') : '';
    }

    public function getBookedCourier(string $oid): string {
        $order = wc_get_order((int) $oid);
        return ($order instanceof \WC_Order) ? (string) $order->get_meta('_booked_courier') : '';
    }
}
