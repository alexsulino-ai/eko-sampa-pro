<?php
/**
 * Optional WooCommerce admin bridge: link WC orders to Eko orders (woo_order_id).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Hooks only when WooCommerce is active; does not change frontend routes or stack.
 */
final class Eko_Sampa_Wc_Bridge {

    public function register_hooks(): void {
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_order_panel'], 20, 1);
    }

    /**
     * @param \WC_Order|\WP_Post $order WC_Order in modern admin; guard for type.
     */
    public function render_order_panel($order): void {
        if (! $order instanceof \WC_Order) {
            return;
        }

        if (! current_user_can('manage_options') && ! current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS)) {
            return;
        }

        $wid = $order->get_id();
        if ($wid <= 0) {
            return;
        }

        $eko = (new Eko_Sampa_Order())->get_by_woo_order_id($wid);
        if (! is_array($eko)) {
            echo '<div class="eko-sampa-wc-bridge notice notice-info inline" style="margin-top:12px;"><p>';
            echo esc_html__('No Eko Sampa order uses this WooCommerce order ID in woo_order_id.', 'eko-sampa');
            echo '</p><p class="description">';
            echo esc_html__('Set woo_order_id on an Eko order from the app to link them.', 'eko-sampa');
            echo '</p></div>';

            return;
        }

        $eid   = (int) ($eko['id'] ?? 0);
        $print = Eko_Sampa_Frontend_Router::get_url('print', $eid);
        $list  = Eko_Sampa_Frontend_Router::get_url('orders');

        echo '<div class="eko-sampa-wc-bridge" style="margin-top:12px;padding:10px;border:1px solid #c3c4c7;background:#fff;">';
        echo '<p><strong>' . esc_html__('Eko Sampa', 'eko-sampa') . '</strong></p>';
        echo '<p>' . esc_html(
            sprintf(
                /* translators: %d: Eko order ID. */
                __('Linked OS #%d', 'eko-sampa'),
                $eid
            )
        ) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($print) . '" target="_blank" rel="noopener noreferrer">';
        echo esc_html__('Open print view', 'eko-sampa') . '</a> ';
        echo '<a class="button" href="' . esc_url($list) . '">' . esc_html__('Open orders in app', 'eko-sampa') . '</a></p>';
        echo '</div>';
    }
}
