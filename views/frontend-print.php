<?php
/**
 * Standalone print layout — same EkoCanvasRenderer engine as order live preview.
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

$ctx     = Eko_Sampa_Order::template_render_context($order);
$rnd     = new Eko_Sampa_Template_Renderer();
$preview = $rnd->build_editor_preview_payload($tpl, $ctx);

$wm = max(1, (int) ($preview['width_mm'] ?? 210));
$hm = max(1, (int) ($preview['height_mm'] ?? 297));

$payload_json = wp_json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (! is_string($payload_json)) {
    $payload_json = '{}';
}

?>
<div class="eko-sampa-print-page eko-sampa-print-outer mx-auto max-w-none p-6 print:p-0">
    <div class="mb-4 flex flex-wrap items-center gap-3 print:hidden">
        <button
            type="button"
            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
            id="eko-sampa-print-trigger"
            disabled
        >
            <?php echo esc_html__('Print', 'eko-sampa'); ?>
        </button>
        <span id="eko-sampa-print-status" class="eko-sampa-print-status"><?php echo esc_html__('Loading preview…', 'eko-sampa'); ?></span>
        <a class="text-sm text-indigo-600 underline" href="<?php echo esc_url(Eko_Sampa_Frontend_Router::get_resource_url('orders', 'view', $order_id)); ?>">
            <?php echo esc_html__('Back to order', 'eko-sampa'); ?>
        </a>
    </div>
    <div class="eko-sampa-print-card rounded-lg border border-slate-200 bg-white p-4 shadow-sm print:border-0 print:bg-transparent print:p-0 print:shadow-none">
        <div id="eko-sampa-print-mount" class="eko-sampa-print-mount" data-auto-print="0"></div>
    </div>
</div>
<style>
@media print {
    @page {
        size: <?php echo (int) $wm; ?>mm <?php echo (int) $hm; ?>mm;
        margin: 0;
    }
}
body.eko-sampa-print-ready #eko-sampa-print-trigger:not(:disabled) {
    opacity: 1;
}
body:not(.eko-sampa-print-ready) #eko-sampa-print-trigger {
    opacity: 0.5;
    pointer-events: none;
}
</style>
<script>
window.ekoSampaPrintPayload = <?php echo $payload_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
<?php if ((defined('EKO_SAMPA_DEBUG') && EKO_SAMPA_DEBUG) || (isset($_GET['eko_render_debug']) && (string) $_GET['eko_render_debug'] === '1')) : ?>
window.EKO_RENDER_DEBUG = true;
<?php endif; ?>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('eko-sampa-print-trigger');
    if (btn) {
        btn.addEventListener('click', function () { window.print(); });
    }
});
document.addEventListener('eko-sampa-print-ready', function () {
    var btn = document.getElementById('eko-sampa-print-trigger');
    if (btn) { btn.disabled = false; }
});
</script>
