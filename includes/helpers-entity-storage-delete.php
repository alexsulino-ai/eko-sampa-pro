<?php
/**
 * Safe delete helpers for templates and orders (storage-aware).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @param array<string, mixed> $defaults
 *
 * @return array<string, mixed>
 */
function eko_sampa_merge_template_delete_options(int $template_id, array $defaults): array {
    $defaults['template_id'] = $template_id;

    return apply_filters('eko_sampa_safe_delete_template_options', $defaults, $template_id);
}

/**
 * Safe delete for a template row + thumbnails. Does not remove completed-order snapshots.
 *
 * @param array{strict?: bool, audit?: bool} $options
 *
 * @return array<string, mixed>
 */
function eko_sampa_safe_delete_template(int $template_id, array $options = []): array {
    $opts = eko_sampa_merge_template_delete_options(
        $template_id,
        array_merge(
            [
                'strict' => false,
                'audit'  => true,
            ],
            $options
        )
    );

    $strict = ! empty($opts['strict']);
    $tpl    = new Eko_Sampa_Template();
    $row    = $tpl->get_row_by_id($template_id);
    if (! is_array($row)) {
        return [
            'ok'      => false,
            'code'    => 'eko_sampa_not_found',
            'message' => __('Not found.', 'eko-sampa'),
            'debug'   => ['template_id' => $template_id],
            'audit'   => [],
        ];
    }

    if (! is_array($tpl->get($template_id))) {
        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_forbidden',
            'message' => __('You do not have permission to delete this template.', 'eko-sampa'),
            'debug'   => ['template_id' => $template_id],
            'audit'   => [],
        ];
    }

    $inspect = Eko_Sampa_Template_Relations_Inspector::inspect_for($template_id);
    if ($strict && ( new Eko_Sampa_Template_Relations_Inspector() )->delete_readiness($template_id)['has_blockers_for_strict_delete']) {
        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_blocked_dependencies',
            'message' => __('Template still has non-completed orders. Resolve or disable strict mode.', 'eko-sampa'),
            'debug'   => ['inspect' => $inspect],
            'audit'   => [],
        ];
    }

    $ok = $tpl->delete($template_id);
    if (! $ok) {
        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_failed',
            'message' => __('Could not delete template.', 'eko-sampa'),
            'debug'   => ['template_id' => $template_id],
            'audit'   => [],
        ];
    }

    Eko_Sampa_Template_Thumbnail::delete($template_id);

    if (! empty($opts['audit'])) {
        /**
         * Fires after a template was deleted via safe delete.
         *
         * @param int   $template_id Template id.
         * @param array $inspect     Inspector snapshot.
         */
        do_action('eko_sampa_after_safe_delete_template', $template_id, $inspect);
    }

    return [
        'ok'      => true,
        'code'    => 'deleted',
        'message' => __('Template deleted.', 'eko-sampa'),
        'debug'   => ['inspect' => $inspect],
        'audit'   => [],
    ];
}

/**
 * @param array<string, mixed> $defaults
 *
 * @return array<string, mixed>
 */
function eko_sampa_merge_order_delete_options(int $order_id, array $defaults): array {
    $defaults['order_id'] = $order_id;

    return apply_filters('eko_sampa_safe_delete_order_options', $defaults, $order_id);
}

/**
 * Safe delete order row + completed snapshot directory.
 *
 * @return array<string, mixed>
 */
function eko_sampa_safe_delete_order(int $order_id, array $options = []): array {
    $opts = eko_sampa_merge_order_delete_options(
        $order_id,
        array_merge(
            [
                'audit' => true,
            ],
            $options
        )
    );

    $ord = new Eko_Sampa_Order();
    $row = $ord->get_row_by_id($order_id);
    if (! is_array($row)) {
        return [
            'ok'      => false,
            'code'    => 'eko_sampa_not_found',
            'message' => __('Not found.', 'eko-sampa'),
            'debug'   => ['order_id' => $order_id],
            'audit'   => [],
        ];
    }

    if (! is_array($ord->get($order_id))) {
        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_forbidden',
            'message' => __('You do not have permission to delete this order.', 'eko-sampa'),
            'debug'   => ['order_id' => $order_id],
            'audit'   => [],
        ];
    }

    $inspect = Eko_Sampa_Order_Relations_Inspector::inspect_for($order_id);

    $ok = $ord->delete($order_id);
    if (! $ok) {
        return [
            'ok'      => false,
            'code'    => 'eko_sampa_delete_failed',
            'message' => __('Could not delete order.', 'eko-sampa'),
            'debug'   => ['order_id' => $order_id, 'inspect' => $inspect],
            'audit'   => [],
        ];
    }

    if (! empty($opts['audit'])) {
        /** @param int $order_id Order id. */
        do_action('eko_sampa_after_safe_delete_order', $order_id, $inspect);
    }

    return [
        'ok'      => true,
        'code'    => 'deleted',
        'message' => __('Order deleted.', 'eko-sampa'),
        'debug'   => ['inspect' => $inspect],
        'audit'   => [],
    ];
}
