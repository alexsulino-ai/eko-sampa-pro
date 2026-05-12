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
if (isset($out['html']) && is_string($out['html'])) {
    $out['html'] = wp_kses_post($out['html']);
}

?>
<div class="eko-sampa-print-page mx-auto max-w-5xl p-6">
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
    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm print:border-0 print:shadow-none">
        <?php
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns escaped inline styles and escaped text nodes.
        echo $out['html'];
        ?>
    </div>
</div>
<style>
@media print {
  body { background: #fff !important; }
  .eko-sampa-print-page .print\:hidden { display: none !important; }
}
</style>
