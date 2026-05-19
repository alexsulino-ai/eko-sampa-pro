<?php
/**
 * Reusable CRUD action buttons (Tailwind variants + Heroicons-style SVG).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string, array{label: string, title: string, icon: string, btn: string, btn_hover: string}>
 */
function eko_sampa_crud_action_variants(): array {
    return [
        'view'      => [
            'label'     => __('View', 'eko-sampa'),
            'title'     => __('View details', 'eko-sampa'),
            'icon'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>',
            'btn'       => 'border-cyan-200/90 bg-cyan-50/90 text-cyan-800',
            'btn_hover' => 'hover:border-cyan-300 hover:bg-cyan-100',
        ],
        'edit'      => [
            'label'     => __('Edit', 'eko-sampa'),
            'title'     => __('Edit record', 'eko-sampa'),
            'icon'      => '<path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>',
            'btn'       => 'border-amber-200/90 bg-amber-50/90 text-amber-900',
            'btn_hover' => 'hover:border-amber-300 hover:bg-amber-100',
        ],
        'editor'    => [
            'label'     => __('Editor', 'eko-sampa'),
            'title'     => __('Open visual editor', 'eko-sampa'),
            'icon'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 6.749-3.22M9.53 16.122A15.998 15.998 0 0 0 12 21c2.485 0 4.797-.657 6.749-1.812M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/>',
            'btn'       => 'border-violet-200/90 bg-violet-50/90 text-violet-900',
            'btn_hover' => 'hover:border-violet-300 hover:bg-violet-100',
        ],
        'create_order' => [
            'label'     => __('Create order', 'eko-sampa'),
            'title'     => __('Create order from this template', 'eko-sampa'),
            'icon'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/>',
            'btn'       => 'border-indigo-500/90 bg-indigo-600 text-white shadow-sm',
            'btn_hover' => 'hover:border-indigo-600 hover:bg-indigo-700',
        ],
        'duplicate' => [
            'label'     => __('Duplicate', 'eko-sampa'),
            'title'     => __('Duplicate record', 'eko-sampa'),
            'icon'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9 9.06 9.06 0 0 0-1.5.124v7.5c0 .621.504 1.125 1.125 1.125h7.5Z"/>',
            'btn'       => 'border-emerald-200/90 bg-emerald-50/90 text-emerald-900',
            'btn_hover' => 'hover:border-emerald-300 hover:bg-emerald-100',
        ],
        'print'     => [
            'label'     => __('Print', 'eko-sampa'),
            'title'     => __('Open print view', 'eko-sampa'),
            'icon'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18M6.34 6.34l.393-.393a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-6.364-2.515L6.34 6.34Zm12.728 0 .393.393a2.25 2.25 0 0 0 0 3.182l-2.909 2.909m6.364-2.515-3.182 3.182"/>',
            'btn'       => 'border-slate-200/90 bg-slate-50/90 text-slate-700',
            'btn_hover' => 'hover:border-slate-300 hover:bg-slate-100',
        ],
        'delete'    => [
            'label'     => __('Delete', 'eko-sampa'),
            'title'     => __('Delete record', 'eko-sampa'),
            'icon'      => '<path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>',
            'btn'       => 'border-red-200/90 bg-red-50/90 text-red-800',
            'btn_hover' => 'hover:border-red-300 hover:bg-red-100',
        ],
    ];
}

/**
 * Render one CRUD action control (link or button).
 *
 * @param string               $type   view|edit|editor|duplicate|print|delete
 * @param array<string, mixed> $args   href (Alpine expr), click (Alpine expr), show, title, label, target, size (sm|md), icon_only
 */
function eko_sampa_crud_action(string $type, array $args = []): void {
    $variants = eko_sampa_crud_action_variants();
    if (! isset($variants[ $type ])) {
        return;
    }

    // Capability gating is client-side only (ekoSampaCan + x-show/:disabled). Do not skip
    // rendering in PHP — keys like service.view break under sanitize_key and SPA HTML
    // must be the same for every logged-in user.

    $v         = $variants[ $type ];
    $label     = isset($args['label']) ? (string) $args['label'] : $v['label'];
    $title     = isset($args['title']) ? (string) $args['title'] : $v['title'];
    $size      = isset($args['size']) && $args['size'] === 'sm' ? 'sm' : 'md';
    $icon_only = ! empty($args['icon_only']);
    $pad       = $size === 'sm' ? 'px-1.5 py-1' : 'px-2 py-1';
    $text      = $size === 'sm' ? 'text-[11px]' : 'text-xs';
    $icon_sz   = $size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4';
    $base      = 'inline-flex items-center gap-1 rounded-lg border font-medium transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-slate-400/60 ' . $pad . ' ' . $text . ' ' . $v['btn'] . ' ' . $v['btn_hover'];

    $is_button = ! empty($args['click']);
    $tag       = $is_button ? 'button' : 'a';

    $attrs = [
        'class'      => $base,
        'title'      => $title,
        'aria-label' => $title,
    ];

    if (! empty($args['show'])) {
        $attrs['x-show'] = (string) $args['show'];
    }
    if (! empty($args['can'])) {
        $can_expr = function_exists('eko_sampa_alpine_can_expr')
            ? eko_sampa_alpine_can_expr((string) $args['can'])
            : "ekoSampaCan('" . esc_attr((string) $args['can']) . "')";
        $policy   = isset($args['policy']) ? (string) $args['policy'] : 'hidden';
        if ($policy === 'disabled') {
            if ($is_button) {
                $attrs[':disabled'] = '!' . $can_expr;
            } else {
                $attrs[':class'] =
                    "((" . $can_expr . ") ? '" . esc_attr($base) . "' : '" . esc_attr($base . ' opacity-40 pointer-events-none cursor-not-allowed') . "')";
                $attrs[':aria-disabled'] = '!' . $can_expr;
                unset($attrs['class']);
            }
        } else {
            $existing         = isset($attrs['x-show']) ? (string) $attrs['x-show'] : '';
            $attrs['x-show'] = $existing !== '' ? '(' . $existing . ') && ' . $can_expr : $can_expr;
        }
    }
    if (! empty($args['disabled'])) {
        $attrs[':disabled'] = (string) $args['disabled'];
    }
    if (! empty($args['loading'])) {
        $loading_expr = (string) $args['loading'];
        $attrs['x-bind:class'] = "((" . $loading_expr . ") ? 'opacity-50 pointer-events-none' : '')";
        if ($is_button) {
            $existing_disabled = isset($attrs[':disabled']) ? (string) $attrs[':disabled'] : '';
            $attrs[':disabled'] = $existing_disabled !== ''
                ? '(' . $existing_disabled . ') || ' . $loading_expr
                : $loading_expr;
        }
    }

    if ($is_button) {
        $attrs['type'] = 'button';
        $attrs['@click'] = (string) $args['click'];
    } elseif (! empty($args['href'])) {
        $attrs[':href'] = (string) $args['href'];
    }

    if (! empty($args['target'])) {
        $attrs['target'] = (string) $args['target'];
        if ($args['target'] === '_blank') {
            $attrs['rel'] = 'noopener noreferrer';
        }
    }

    $attr_html = '';
    foreach ($attrs as $key => $val) {
        if ($key === 'class') {
            $attr_html .= ' class="' . esc_attr($val) . '"';
            continue;
        }
        if (str_starts_with($key, '@') || str_starts_with($key, ':') || str_starts_with($key, 'x-')) {
            $attr_html .= ' ' . esc_attr($key) . '="' . esc_attr($val) . '"';
            continue;
        }
        $attr_html .= ' ' . esc_attr($key) . '="' . esc_attr($val) . '"';
    }

    echo '<' . $tag . $attr_html . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '<svg class="' . esc_attr($icon_sz) . ' shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">';
    echo $v['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo '</svg>';
    if (! $icon_only) {
        echo '<span>' . esc_html($label) . '</span>';
    }
    echo '</' . $tag . '>';
}

/**
 * Wrapper for a row of CRUD actions (table cell).
 */
function eko_sampa_crud_actions_group_open(): void {
    echo '<div class="flex flex-wrap items-center justify-end gap-1.5" role="group" aria-label="' . esc_attr__('Actions', 'eko-sampa') . '">';
}

function eko_sampa_crud_actions_group_close(): void {
    echo '</div>';
}

/**
 * Render a row of actions from configuration.
 *
 * @param list<array<string, mixed>> $actions Each item: type + optional href, click, show, disabled, loading, ...
 */
function eko_sampa_crud_actions_render(array $actions): void {
    eko_sampa_crud_actions_group_open();
    foreach ($actions as $action) {
        if (! is_array($action) || empty($action['type'])) {
            continue;
        }
        $type = sanitize_key((string) $action['type']);
        unset($action['type']);
        eko_sampa_crud_action($type, $action);
    }
    eko_sampa_crud_actions_group_close();
}
