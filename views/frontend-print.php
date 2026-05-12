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

if (! function_exists('eko_sampa_build_print_context')) {
    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, string>
     */
    function eko_sampa_build_print_context(array $order): array {
        $ctx = [
            'order_id' => (string) ($order['id'] ?? ''),
        ];

        $cid = (int) ($order['client_id'] ?? 0);
        if ($cid > 0) {
            $client = (new Eko_Sampa_Client())->get($cid);
            if (is_array($client)) {
                foreach (['nome', 'email', 'telefone', 'documento', 'cidade', 'estado'] as $k) {
                    $ctx[ $k ]             = (string) ($client[ $k ] ?? '');
                    $ctx[ 'client_' . $k ] = (string) ($client[ $k ] ?? '');
                }
            }
        }

        $raw = $order['dynamic_data_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $key = sanitize_title((string) $k);
                    if ($key !== '') {
                        $ctx[ strtolower($key) ] = is_scalar($v) ? (string) $v : (wp_json_encode($v) ?: '');
                    }
                }
            }
        }

        return $ctx;
    }
}

$ctx = eko_sampa_build_print_context($order);
$out = (new Eko_Sampa_Template_Renderer())->render($tpl, $ctx, true);

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
