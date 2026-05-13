<?php
/**
 * Standalone print layout (no app chrome). Uses blank theme template.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$order_id = Eko_Sampa_Frontend_Router::current_order_id();
$order    = (new Eko_Sampa_Order())->get($order_id);
if (! is_array($order)) {
    echo '<p>' . esc_html__('Order not found.', 'eko-sampa') . '</p>';

    return;
}

$tid = (int) ($order['template_id'] ?? 0);
$tpl = (new Eko_Sampa_Template())->get($tid);
if (! is_array($tpl)) {
    echo '<p>' . esc_html__('Template not found.', 'eko-sampa') . '</p>';

    return;
}

$ctx = Eko_Sampa_Order::template_render_context($order);
$out = (new Eko_Sampa_Template_Renderer())->render($tpl, $ctx, true);
// Renderer already escapes inline CSS and text; wp_kses_post strips safe img/style needed for parity with live preview.

$wm = max(1, (int) ($out['width_mm'] ?? 210));
$hm = max(1, (int) ($out['height_mm'] ?? 297));

?>
<div class="eko-sampa-print-outer mx-auto max-w-none p-6 print:p-0">
    <div class="mb-4 flex flex-wrap items-center gap-3 print:hidden">
        <button
            type="button"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
            onclick="window.print()"
        >
            <?php echo esc_html__('Print', 'eko-sampa'); ?>
        </button>
        <a class="text-sm text-indigo-600 underline" href="<?php echo esc_url(Eko_Sampa_Frontend_Router::get_url('orders')); ?>">
            <?php echo esc_html__('Back to orders', 'eko-sampa'); ?>
        </a>
    </div>
    <div class="eko-sampa-print-card rounded-lg border border-slate-200 bg-white p-4 shadow-sm print:border-0 print:bg-transparent print:p-0 print:shadow-none">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns escaped inline styles and escaped text nodes.
        echo $out['html'];
        ?>
    </div>
</div>
<style>
/* Screen: let the canvas use true mm width without max-width clipping. */
.eko-sampa-print-outer {
    width: fit-content;
    max-width: 100%;
    margin-left: auto;
    margin-right: auto;
}
.eko-sampa-print-card {
    display: flow-root;
}
@media print {
    @page {
        size: <?php echo (int) $wm; ?>mm <?php echo (int) $hm; ?>mm;
        margin: 0;
    }
    html,
    body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }
    body.eko-sampa-frontend {
        background: #fff !important;
    }
    .eko-sampa-print-outer {
        display: block;
        width: auto !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .eko-sampa-print-card {
        border: 0 !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    .eko-sampa-print-page .print\:hidden,
    .eko-sampa-print-outer .print\:hidden {
        display: none !important;
    }
    .eko-sampa-print-root {
        box-shadow: none !important;
        page-break-inside: avoid;
        break-inside: avoid;
    }
}
</style>
