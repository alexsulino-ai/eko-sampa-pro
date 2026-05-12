<?php
/**
 * Profile summary (frontend route).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$user = wp_get_current_user();

?>
<div class="mx-auto max-w-3xl rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
    <h2 class="text-lg font-semibold text-slate-900"><?php echo esc_html__('Profile', 'eko-sampa'); ?></h2>
    <?php if ($user->exists()) : ?>
        <dl class="mt-4 space-y-3 text-sm">
            <div>
                <dt class="font-medium text-slate-500"><?php echo esc_html__('Display name', 'eko-sampa'); ?></dt>
                <dd class="text-slate-900"><?php echo esc_html($user->display_name); ?></dd>
            </div>
            <div>
                <dt class="font-medium text-slate-500"><?php echo esc_html__('Email', 'eko-sampa'); ?></dt>
                <dd class="text-slate-900"><?php echo esc_html($user->user_email); ?></dd>
            </div>
            <div>
                <dt class="font-medium text-slate-500"><?php echo esc_html__('Roles', 'eko-sampa'); ?></dt>
                <dd class="text-slate-900"><?php echo esc_html(implode(', ', (array) $user->roles)); ?></dd>
            </div>
        </dl>
    <?php endif; ?>
</div>
