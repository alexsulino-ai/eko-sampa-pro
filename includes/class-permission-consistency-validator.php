<?php
/**
 * Detects REST vs model permission drift (static / CI-friendly checks).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Run structural checks so REST gates and model `is_unrestricted()` cannot diverge silently.
 */
final class Eko_Sampa_Permission_Consistency_Validator {

    /**
     * @return array{violations: list<array<string, string>>, checks: list<array<string, mixed>>, checked_at: string}
     */
    public static function run(): array {
        $violations = [];
        $checks     = [];
        $base       = defined('EKO_SAMPA_PLUGIN_DIR')
            ? rtrim((string) EKO_SAMPA_PLUGIN_DIR, '/\\')
            : dirname(__DIR__);
        $dir        = $base . DIRECTORY_SEPARATOR;

        $checks[] = [
            'name'   => 'helper_exists',
            'ok'     => function_exists('eko_sampa_services_actor_has_elevated_scope'),
            'detail' => 'helpers-permission-contract.php must define eko_sampa_services_actor_has_elevated_scope()',
        ];
        if (! function_exists('eko_sampa_services_actor_has_elevated_scope')) {
            $violations[] = [
                'code'    => 'missing_permission_contract_helper',
                'message' => 'eko_sampa_services_actor_has_elevated_scope() is missing.',
            ];
        }

        $needle = 'eko_sampa_services_actor_has_elevated_scope';
        $files  = [
            'rest_api' => $dir . 'includes' . DIRECTORY_SEPARATOR . 'class-rest-api.php',
            'service'  => $dir . 'includes' . DIRECTORY_SEPARATOR . 'class-service.php',
        ];
        foreach ($files as $label => $path) {
            $ok     = is_readable($path) && str_contains((string) file_get_contents($path), $needle);
            $checks[] = [
                'name'   => 'source_uses_contract_' . $label,
                'ok'     => $ok,
                'detail' => $path,
            ];
            if (! $ok) {
                $violations[] = [
                    'code'    => 'permission_contract_not_wired_' . $label,
                    'message' => 'File must call ' . $needle . ': ' . $path,
                ];
            }
        }

        $ref = new \ReflectionClass(Eko_Sampa_Service::class);
        $m   = $ref->getMethod('is_unrestricted');
        $decl = $m->getDeclaringClass()->getName();
        $checks[] = [
            'name'   => 'service_overrides_is_unrestricted',
            'ok'     => $decl === Eko_Sampa_Service::class,
            'detail' => 'Declaring class: ' . $decl,
        ];
        if ($decl !== Eko_Sampa_Service::class) {
            $violations[] = [
                'code'    => 'service_is_unrestricted_not_overridden',
                'message' => 'Eko_Sampa_Service must override is_unrestricted() to align with REST.',
            ];
        }

        return [
            'violations' => $violations,
            'checks'     => $checks,
            'checked_at' => gmdate('c'),
        ];
    }
}
