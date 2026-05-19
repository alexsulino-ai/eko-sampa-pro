<?php
/**
 * REST API for the frontend app (CRUD + gallery + admin filters).
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers `eko-sampa/v1` routes (cookie auth + application passwords).
 */
final class Eko_Sampa_Rest_Api {

    private const NS = 'eko-sampa/v1';

    /** Max raw JSON body size for mutating requests (bytes). */
    private const MAX_JSON_BODY_BYTES = 524288;

    /** Max encoded `json_data` for templates (bytes), below global body cap. */
    private const MAX_TEMPLATE_JSON_BYTES = 393216;

    public function register_hooks(): void {
        add_filter('rest_pre_dispatch', [$this, 'enforce_json_body_limit'], 10, 3);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Reject oversized bodies early for this namespace (DoS / runaway editor payloads).
     *
     * @param mixed $result
     */
    public function enforce_json_body_limit($result, $server, \WP_REST_Request $request) {
        unset($server);
        if (null !== $result) {
            return $result;
        }

        $route = (string) $request->get_route();
        if ($route === '' || ! str_contains($route, '/' . self::NS . '/')) {
            return $result;
        }

        $method = $request->get_method();
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $result;
        }

        $len = strlen((string) $request->get_body());
        if ($len > self::MAX_JSON_BODY_BYTES) {
            return new \WP_Error(
                'eko_sampa_request_too_large',
                __('Request body is too large.', 'eko-sampa'),
                ['status' => 413]
            );
        }

        return $result;
    }

    public function register_routes(): void {
        register_rest_route(
            self::NS,
            '/me',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_me'],
                'permission_callback' => [$this, 'require_app_user'],
            ]
        );

        register_rest_route(
            self::NS,
            '/internals/derivation-stats',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_internals_derivation_stats'],
                'permission_callback' => [$this, 'require_admin'],
            ]
        );

        register_rest_route(
            self::NS,
            '/internals/public-experience-stats',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_internals_public_experience_stats'],
                'permission_callback' => [$this, 'require_admin'],
            ]
        );

        register_rest_route(
            self::NS,
            '/internals/health',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_internals_health'],
                'permission_callback' => [$this, 'require_admin'],
            ]
        );

        register_rest_route(
            self::NS,
            '/users',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_users'],
                'permission_callback' => [$this, 'require_admin'],
                'args'                => [
                    'search' => ['type' => 'string', 'required' => false],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/clients',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_clients_list'],
                    'permission_callback' => [$this, 'require_clients_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'route_clients_create'],
                    'permission_callback' => [$this, 'require_clients_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/clients/(?P<id>\d+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_clients_get'],
                    'permission_callback' => [$this, 'require_clients_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'route_clients_update'],
                    'permission_callback' => [$this, 'require_clients_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'route_clients_delete'],
                    'permission_callback' => [$this, 'require_clients_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/services',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_services_list'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'route_services_create'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/services/(?P<id>\d+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_services_get'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'route_services_update'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'route_services_delete'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/services/(?P<id>\d+)/fields',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_fields_list'],
                    'permission_callback' => [$this, 'require_service_fields_read_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'route_fields_create'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/services/(?P<id>\d+)/fields/reorder',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_fields_reorder'],
                'permission_callback' => [$this, 'require_services_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/services/(?P<id>\d+)/fields/check-slug',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_fields_check_slug'],
                'permission_callback' => [$this, 'require_services_cap'],
                'args'                => [
                    'slug'    => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'exclude' => [
                        'required' => false,
                        'type'     => 'integer',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/services/(?P<sid>\d+)/fields/(?P<fid>\d+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_fields_get'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'route_fields_update'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'route_fields_delete'],
                    'permission_callback' => [$this, 'require_services_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/public/catalog',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_public_catalog'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'page'     => [
                        'required' => false,
                        'type'     => 'integer',
                        'default'  => 1,
                        'minimum'  => 1,
                    ],
                    'per_page' => [
                        'required' => false,
                        'type'     => 'integer',
                        'default'  => 24,
                        'minimum'  => 1,
                        'maximum'  => 48,
                    ],
                    'search'   => [
                        'required' => false,
                        'type'     => 'string',
                    ],
                    'categoria' => [
                        'required' => false,
                        'type'     => 'string',
                    ],
                    'scope'    => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => 'all',
                        'enum'     => [
                            'all',
                            'featured',
                            'recent',
                            'popular',
                            'trending_today',
                            'trending_week',
                            'recently_printed',
                            'most_saved',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/public/categories',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_public_categories'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NS,
            '/public/templates/(?P<id>\d+)/session',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_public_template_start_session'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NS,
            '/public/telemetry',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_public_telemetry'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'event' => [
                        'required' => true,
                        'type'     => 'string',
                        'enum'     => ['conversion_modal_open', 'editor_boot'],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)/persist-to-mine',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_templates_persist_session_to_mine'],
                'permission_callback' => [$this, 'require_app_user'],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_templates_list'],
                    'permission_callback' => [$this, 'require_templates_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'route_templates_create'],
                    'permission_callback' => [$this, 'require_templates_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_templates_get'],
                    'permission_callback' => [$this, 'template_rest_read_permission'],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'route_templates_update'],
                    'permission_callback' => [$this, 'template_rest_write_permission'],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'route_templates_delete'],
                    'permission_callback' => [$this, 'require_templates_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)/duplicate',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_templates_duplicate'],
                'permission_callback' => [$this, 'require_templates_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)/duplicate-diagnostics',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_templates_duplicate_diagnostics'],
                'permission_callback' => [$this, 'require_templates_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)/thumbnail',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_templates_thumbnail'],
                'permission_callback' => [$this, 'template_rest_write_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)/thumbnail/generate',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_templates_thumbnail_generate'],
                'permission_callback' => [$this, 'template_rest_write_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)/placeholders',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_templates_placeholders'],
                'permission_callback' => [$this, 'template_rest_read_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/quick-print/options',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_quick_print_options'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::NS,
            '/quick-print/jobs',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_quick_print_jobs_create'],
                'permission_callback' => [$this, 'quick_print_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/quick-print/jobs/(?P<id>\d+)',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_quick_print_job_get'],
                'permission_callback' => [$this, 'quick_print_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/quick-print/jobs/(?P<id>\d+)/cancel',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_quick_print_job_cancel'],
                'permission_callback' => [$this, 'quick_print_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/quick-print/jobs/(?P<id>\d+)/browser-handoff',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_quick_print_job_browser_handoff'],
                'permission_callback' => [$this, 'quick_print_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/quick-print/jobs/(?P<id>\d+)/complete',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_quick_print_job_complete'],
                'permission_callback' => [$this, 'quick_print_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/quick-print/jobs/(?P<id>\d+)/reprint',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_quick_print_job_reprint'],
                'permission_callback' => [$this, 'quick_print_permission'],
            ]
        );

        register_rest_route(
            self::NS,
            '/orders',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_orders_list'],
                    'permission_callback' => [$this, 'require_orders_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'route_orders_create'],
                    'permission_callback' => [$this, 'require_orders_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/orders/(?P<id>\d+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_orders_get'],
                    'permission_callback' => [$this, 'require_orders_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'route_orders_update'],
                    'permission_callback' => [$this, 'require_orders_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'route_orders_delete'],
                    'permission_callback' => [$this, 'require_orders_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/orders/render-draft',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_orders_render_draft'],
                'permission_callback' => [$this, 'require_orders_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/orders/(?P<id>\d+)/duplicate',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_orders_duplicate'],
                'permission_callback' => [$this, 'require_orders_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/orders/(?P<id>\d+)/duplicate-revision',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_orders_duplicate_revision'],
                'permission_callback' => [$this, 'require_orders_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/orders/(?P<id>\d+)/render',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_orders_render'],
                'permission_callback' => [$this, 'require_orders_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/lookups/order-form',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_lookups_order_form'],
                'permission_callback' => [$this, 'require_orders_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/gallery',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => [$this, 'route_gallery_list'],
                    'permission_callback' => [$this, 'require_templates_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'route_gallery_upload'],
                    'permission_callback' => [$this, 'require_templates_cap'],
                ],
            ]
        );

        register_rest_route(
            self::NS,
            '/gallery/(?P<file>[a-zA-Z0-9._-]+)',
            [
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'route_gallery_delete'],
                    'permission_callback' => [$this, 'require_templates_cap'],
                ],
            ]
        );
    }

    public function require_app_user(): bool {
        return is_user_logged_in()
            && (current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_ACCESS_DASHBOARD));
    }

    public function require_admin(): bool {
        return current_user_can('manage_options');
    }

    public function require_clients_cap(): bool {
        return current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_CLIENTS);
    }

    public function require_services_cap(): bool {
        return function_exists('eko_sampa_services_actor_has_elevated_scope')
            && eko_sampa_services_actor_has_elevated_scope();
    }

    public function require_templates_cap(): bool {
        return current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES);
    }

    public function template_rest_read_permission(\WP_REST_Request $request): bool {
        if ($this->require_templates_cap()) {
            return true;
        }

        return $this->template_rest_session_token_valid_for($request, (int) $request['id']);
    }

    public function template_rest_write_permission(\WP_REST_Request $request): bool {
        if ($this->require_templates_cap()) {
            return true;
        }

        return $this->template_rest_session_token_valid_for($request, (int) $request['id']);
    }

    private function template_rest_session_token_valid_for(\WP_REST_Request $request, int $id): bool {
        return $this->template_session_token_valid($id, $this->get_request_session_token($request));
    }

    private function template_session_token_valid(int $template_id, string $token): bool {
        if ($template_id <= 0 || $token === '') {
            return false;
        }
        $row = ( new Eko_Sampa_Template() )->get_row_by_id($template_id);

        return Eko_Sampa_Template_Derivation::request_can_use_session_row($row, $token);
    }

    public function get_request_session_token(\WP_REST_Request $request): string {
        $q = $request->get_param('session_token');
        if (is_string($q) && trim($q) !== '') {
            return preg_replace('/[^a-f0-9]/i', '', sanitize_text_field($q));
        }
        $params = $request->get_json_params();
        if (is_array($params) && isset($params['session_token']) && is_string($params['session_token'])) {
            return preg_replace('/[^a-f0-9]/i', '', sanitize_text_field($params['session_token']));
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function template_rest_resolve_row(\WP_REST_Request $request): ?array {
        $id = (int) $request['id'];
        if ($id <= 0) {
            return null;
        }
        $m     = new Eko_Sampa_Template();
        $token = $this->get_request_session_token($request);

        if ($this->require_templates_cap()) {
            $r = $m->get_row_by_id($id);

            return is_array($r) ? $r : null;
        }
        if ($token !== '') {
            $r = $m->get_row_by_id($id);
            if (Eko_Sampa_Template_Derivation::request_can_use_session_row($r, $token)) {
                return $r;
            }

            return null;
        }

        return $m->get($id);
    }

    public function require_orders_cap(): bool {
        return current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS);
    }

    /**
     * Read service field definitions when editing orders (no manage_services required).
     */
    public function require_service_fields_read_cap(): bool {
        return $this->require_services_cap() || $this->require_orders_cap();
    }

    /**
     * @return array<string, mixed>
     */
    private function list_args(\WP_REST_Request $request): array {
        $lim = (int) $request->get_param('limit');
        $args = [
            'limit'   => $lim > 0 ? $lim : 50,
            'offset'  => (int) $request->get_param('offset'),
            'orderby' => (string) $request->get_param('orderby'),
            'order'   => (string) $request->get_param('order'),
            's'       => (string) $request->get_param('s'),
        ];

        if ($request->get_param('status') !== null) {
            $args['status'] = (string) $request->get_param('status');
        }

        if ($request->get_param('categoria') !== null) {
            $args['categoria'] = (string) $request->get_param('categoria');
        }

        if (current_user_can('manage_options')) {
            $fid = (int) $request->get_param('filter_user_id');
            if ($fid > 0) {
                $args['filter_user_id'] = $fid;
            }
        }

        if ($request->get_param('catalog_public') !== null
            && in_array((string) $request->get_param('catalog_public'), ['1', 'true', 'yes'], true)) {
            $args['catalog_public_only'] = true;
        }

        return $args;
    }

    public function route_me(\WP_REST_Request $request): \WP_REST_Response {
        unset($request);

        $u = wp_get_current_user();

        return new \WP_REST_Response(
            [
                'id'           => $u->ID,
                'display_name' => $u->display_name,
                'email'        => $u->user_email,
                'is_admin'     => current_user_can('manage_options'),
            ]
        );
    }

    public function route_internals_derivation_stats(\WP_REST_Request $request): \WP_REST_Response {
        unset($request);
        Eko_Sampa_Template_Derivation::publish_observability_snapshot();
        $snap = get_option(Eko_Sampa_Template_Derivation::OPTION_OBSERVABILITY, []);

        return new \WP_REST_Response(is_array($snap) ? $snap : new \stdClass(), 200);
    }

    public function route_internals_public_experience_stats(\WP_REST_Request $request): \WP_REST_Response {
        unset($request);

        return new \WP_REST_Response(Eko_Sampa_Public_Experience_Service::instance()->get_aggregated_stats(), 200);
    }

    public function route_internals_health(\WP_REST_Request $request): \WP_REST_Response {
        unset($request);

        return new \WP_REST_Response(Eko_Sampa_Public_Analytics::instance()->build_health_payload(), 200);
    }

    public function route_public_telemetry(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $nonce = (string) $request->get_header('X-WP-Nonce');
        if (! wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error('eko_sampa_invalid', __('Invalid request.', 'eko-sampa'), ['status' => 403]);
        }
        $event = sanitize_key((string) $request->get_param('event'));
        $ok    = Eko_Sampa_Public_Experience_Service::instance()->ingest_public_telemetry($event);
        if ($ok instanceof \WP_Error) {
            return $ok;
        }

        return new \WP_REST_Response(['ok' => true], 200);
    }

    public function route_users(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $search = sanitize_text_field((string) $request->get_param('search'));
        $args    = [
            'number'  => 50,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => ['ID', 'display_name', 'user_email'],
        ];
        if ($search !== '') {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
        }

        $q = new \WP_User_Query($args);

        /** @var array<int, \WP_User> $users */
        $users = $q->get_results();
        $out   = [];
        foreach ($users as $user) {
            $out[] = [
                'id'           => (int) $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
            ];
        }

        return new \WP_REST_Response($out);
    }

    public function route_clients_list(\WP_REST_Request $request): \WP_REST_Response {
        $rows = (new Eko_Sampa_Client())->list($this->list_args($request));

        return new \WP_REST_Response($rows);
    }

    public function route_clients_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (new Eko_Sampa_Client())->create($this->json_params($request));
        if (! $id) {
            return new \WP_Error(
                'eko_sampa_create_failed',
                __('Could not create client.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        $row = (new Eko_Sampa_Client())->get((int) $id);

        return new \WP_REST_Response($row, 201);
    }

    public function route_clients_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = (new Eko_Sampa_Client())->get($id);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return new \WP_REST_Response($row);
    }

    public function route_clients_update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Client())->update($id, $this->json_params($request));
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_update_failed',
                __('Could not update client.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        return new \WP_REST_Response((new Eko_Sampa_Client())->get($id));
    }

    public function route_clients_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Client())->delete($id);
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_delete_failed',
                __('Could not delete client.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    public function route_services_list(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response((new Eko_Sampa_Service())->list($this->list_args($request)));
    }

    public function route_services_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (new Eko_Sampa_Service())->create($this->json_params($request));
        if (! $id) {
            return new \WP_Error(
                'eko_sampa_create_failed',
                __('Could not create service.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        return new \WP_REST_Response((new Eko_Sampa_Service())->get((int) $id), 201);
    }

    public function route_services_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = (new Eko_Sampa_Service())->get($id);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return new \WP_REST_Response($row);
    }

    public function route_services_update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Service())->update($id, $this->json_params($request));
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_update_failed',
                __('Could not update service.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        return new \WP_REST_Response((new Eko_Sampa_Service())->get($id));
    }

    public function route_services_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];

        $strict = in_array((string) $request->get_param('strict'), ['1', 'true', 'yes'], true);
        $raw_ul = $request->get_param('unlink_refs');
        $unlink = true;
        if (null !== $raw_ul && '' !== (string) $raw_ul) {
            $unlink = ! in_array($raw_ul, [0, '0', false, 'false', 'no'], true);
        }

        $repair_legacy = ! in_array((string) $request->get_param('repair_legacy'), ['0', 'false', 'no'], true);
        $ensure_schema   = in_array((string) $request->get_param('ensure_schema'), ['1', 'true', 'yes'], true);

        if (! function_exists('eko_sampa_safe_delete_service')) {
            return new \WP_Error(
                'eko_sampa_delete_failed',
                __('Could not delete service.', 'eko-sampa'),
                array_merge(['status' => 500, 'debug' => ['failed_at' => 'helper_missing']], $this->wpdb_debug_data())
            );
        }

        $result = eko_sampa_safe_delete_service(
            $id,
            [
                'strict_block'  => $strict,
                'unlink_refs'   => $unlink,
                'repair_legacy' => $repair_legacy,
                'ensure_schema' => $ensure_schema,
            ]
        );

        if (! empty($result['ok'])) {
            $payload = [
                'deleted' => true,
                'code'    => $result['code'] ?? 'deleted',
            ];
            if (! empty($result['debug']['repairs'])) {
                $payload['repairs'] = $result['debug']['repairs'];
            }

            return new \WP_REST_Response($payload);
        }

        $code    = (string) ( $result['code'] ?? 'eko_sampa_delete_failed' );
        $message = (string) ( $result['message'] ?? __('Could not delete service.', 'eko-sampa') );
        $status  = $this->service_delete_error_status($code);

        $data = array_merge(
            [
                'status' => $status,
                'debug'  => is_array($result['debug'] ?? null) ? $result['debug'] : [],
            ],
            $this->wpdb_debug_data()
        );

        return new \WP_Error($code, $message, $data);
    }

    private function service_delete_error_status(string $code): int {
        return match ($code) {
            'eko_sampa_not_found' => 404,
            'eko_sampa_delete_forbidden' => 403,
            'eko_sampa_delete_blocked_dependencies' => 409,
            'eko_sampa_bad_request' => 400,
            default => 400,
        };
    }

    public function route_fields_list(\WP_REST_Request $request): \WP_REST_Response {
        $sid = (int) $request['id'];

        return new \WP_REST_Response((new Eko_Sampa_Service_Field())->list_for_service($sid));
    }

    public function route_fields_check_slug(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $sid = (int) $request['id'];
        if ($sid <= 0) {
            return new \WP_Error('eko_sampa_bad_request', __('Invalid service id.', 'eko-sampa'), ['status' => 400]);
        }

        $field      = new Eko_Sampa_Service_Field();
        $normalized = $field->normalize_field_slug((string) $request->get_param('slug'));
        if ($normalized === '') {
            return new \WP_Error(
                'eko_sampa_field_slug_required',
                __('Provide a non-empty slug (letters, numbers, or hyphens).', 'eko-sampa'),
                ['status' => 400]
            );
        }

        $exclude   = (int) $request->get_param('exclude');
        $available = $field->slug_is_available($sid, $normalized, $exclude > 0 ? $exclude : null);
        $conflict  = $available ? null : $field->find_field_id_by_service_slug($sid, $normalized);

        return new \WP_REST_Response(
            [
                'slug'              => $normalized,
                'available'         => $available,
                'conflict_field_id' => $conflict,
            ]
        );
    }

    public function route_fields_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $sid    = (int) $request['id'];
        $params = $this->json_params($request);
        $field  = new Eko_Sampa_Service_Field();
        if ($sid <= 0) {
            return new \WP_Error(
                'eko_sampa_bad_request',
                __('Invalid service id.', 'eko-sampa'),
                ['status' => 400]
            );
        }
        $slug = $field->normalize_field_slug((string) ($params['slug'] ?? ''));
        if ($slug === '') {
            return new \WP_Error(
                'eko_sampa_field_slug_required',
                __('Provide a non-empty slug (letters, numbers, or hyphens).', 'eko-sampa'),
                ['status' => 400]
            );
        }
        if (! $field->slug_is_available($sid, $slug, null)) {
            $conflict_id = $field->find_field_id_by_service_slug($sid, $slug);
            $data        = ['status' => 409];
            if ($conflict_id !== null) {
                $data['existing_field_id'] = $conflict_id;
            }

            return new \WP_Error(
                'eko_sampa_field_slug_exists',
                $conflict_id !== null
                    /* translators: 1: slug, 2: numeric field id */
                    ? sprintf(
                        __('The slug "%1$s" is already used by field #%2$d in this service. Remove or rename that field, or pick another slug.', 'eko-sampa'),
                        $slug,
                        $conflict_id
                    )
                    : __('This slug is already used for another field in this service.', 'eko-sampa'),
                $data
            );
        }

        $id = $field->create($sid, $params);
        if (! $id) {
            return $this->field_create_failure_error($field);
        }

        return new \WP_REST_Response($field->get((int) $id), 201);
    }

    public function route_fields_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $fid = (int) $request['fid'];
        $row = (new Eko_Sampa_Service_Field())->get($fid);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return new \WP_REST_Response($row);
    }

    public function route_fields_update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $fid    = (int) $request['fid'];
        $params = $this->json_params($request);
        $field  = new Eko_Sampa_Service_Field();
        $row    = $field->get($fid);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }
        $sid = (int) ($row['service_id'] ?? 0);
        if (array_key_exists('slug', $params)) {
            $slug = $field->normalize_field_slug((string) $params['slug']);
            if ($slug === '') {
                return new \WP_Error(
                    'eko_sampa_field_slug_required',
                    __('Provide a non-empty slug (letters, numbers, or hyphens).', 'eko-sampa'),
                    ['status' => 400]
                );
            }
            if (! $field->slug_is_available($sid, $slug, $fid)) {
                $conflict_id = $field->find_field_id_by_service_slug($sid, $slug);
                $data        = ['status' => 409];
                if ($conflict_id !== null && $conflict_id !== $fid) {
                    $data['existing_field_id'] = $conflict_id;
                }

                return new \WP_Error(
                    'eko_sampa_field_slug_exists',
                    $conflict_id !== null && $conflict_id !== $fid
                        ? sprintf(
                            /* translators: 1: slug, 2: numeric field id */
                            __('The slug "%1$s" is already used by field #%2$d in this service. Remove or rename that field, or pick another slug.', 'eko-sampa'),
                            $slug,
                            $conflict_id
                        )
                        : __('This slug is already used for another field in this service.', 'eko-sampa'),
                    $data
                );
            }
        }

        $ok = $field->update($fid, $params);
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_update_failed',
                __('Could not update field.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        return new \WP_REST_Response($field->get($fid));
    }

    public function route_fields_reorder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $sid    = (int) $request['id'];
        $params = $this->json_params($request);
        $order  = $params['order'] ?? null;
        if (! is_array($order)) {
            return new \WP_Error(
                'eko_sampa_bad_request',
                __('Provide an "order" array of field ids.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        $ids = [];
        foreach ($order as $v) {
            $ids[] = (int) $v;
        }

        $ok = (new Eko_Sampa_Service_Field())->reorder_for_service($sid, $ids);
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_reorder_failed',
                __('Could not reorder fields (ids must match all fields of this service exactly once).', 'eko-sampa'),
                ['status' => 400]
            );
        }

        return new \WP_REST_Response((new Eko_Sampa_Service_Field())->list_for_service($sid));
    }

    public function route_fields_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $fid = (int) $request['fid'];
        $ok  = (new Eko_Sampa_Service_Field())->delete($fid);
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_delete_failed',
                __('Could not delete field.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    public function route_templates_list(\WP_REST_Request $request): \WP_REST_Response {
        $rows = (new Eko_Sampa_Template())->list($this->list_args($request));

        return new \WP_REST_Response($this->enrich_template_rows($rows));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function enrich_template_rows(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = Eko_Sampa_Template_Thumbnail::enrich_row($row);
        }

        return $out;
    }

    public function route_templates_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $params = $this->sanitize_template_admin_only_fields($this->json_params($request));
        $err    = $this->validate_template_json_payload($params);
        if ($err instanceof \WP_Error) {
            return $err;
        }

        $id = (new Eko_Sampa_Template())->create($params);
        if (! $id) {
            return new \WP_Error(
                'eko_sampa_create_failed',
                __('Could not create template.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        $row = (new Eko_Sampa_Template())->get((int) $id);
        if (is_array($row) && Eko_Sampa_Template_Thumbnail::should_auto_server_thumbnail_after_template_write($row)) {
            Eko_Sampa_Template_Thumbnail_Generator::generate_for_id((int) $id, ['request_source' => 'template_write_hook']);
            $row = (new Eko_Sampa_Template())->get((int) $id);
        }

        return new \WP_REST_Response(is_array($row) ? Eko_Sampa_Template_Thumbnail::enrich_row($row) : $row, 201);
    }

    public function route_templates_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = $this->template_rest_resolve_row($request);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $enriched = Eko_Sampa_Template_Thumbnail::enrich_row($row);
        $inspect  = $request->get_param('inspect_duplicate');
        if ($inspect === 'deep') {
            $enriched['duplicate_inspect_deep'] = Eko_Sampa_Template_Duplicate_Diagnostics::deep($id);
        } elseif ($inspect) {
            $enriched['duplicate_inspect'] = Eko_Sampa_Template_Thumbnail::inspect_duplicate_readiness($id);
        }

        return new \WP_REST_Response($enriched);
    }

    public function route_templates_duplicate_diagnostics(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        if (! is_array(( new Eko_Sampa_Template() )->get($id))) {
            return new \WP_Error(
                'eko_sampa_not_found',
                __('Source template was not found or is not visible for your account.', 'eko-sampa'),
                ['status' => 404, 'failure_reason' => 'source_not_found']
            );
        }

        return new \WP_REST_Response(Eko_Sampa_Template_Duplicate_Diagnostics::duplicate_diagnostics_bundle($id));
    }

    public function route_templates_update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id     = (int) $request['id'];
        $params = $this->sanitize_template_admin_only_fields($this->json_params($request));
        $err    = $this->validate_template_json_payload($params);
        if ($err instanceof \WP_Error) {
            return $err;
        }

        $row = $this->template_rest_resolve_row($request);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $tok = $this->get_request_session_token($request);
        if ($tok !== '' && Eko_Sampa_Template_Derivation::is_session_row($row)) {
            $allowed = array_flip(['json_data', 'width_mm', 'height_mm']);
            $params  = array_intersect_key($params, $allowed);
        }

        if (Eko_Sampa_Template_Derivation::is_master_row($row) && ! current_user_can('manage_options')) {
            return new \WP_Error(
                'eko_sampa_master_immutable',
                __('Official (master) templates cannot be modified here. Use “Use template” to open a working copy.', 'eko-sampa'),
                ['status' => 403]
            );
        }

        $tok = $this->get_request_session_token($request);
        $tpl = new Eko_Sampa_Template();
        $ok  = $tpl->update($id, $params, $tok !== '' ? $tok : null);
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_update_failed',
                __('Could not update template.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        $fresh = $this->template_rest_resolve_row($request);
        if (is_array($fresh) && Eko_Sampa_Template_Thumbnail::should_auto_server_thumbnail_after_template_write($fresh)) {
            Eko_Sampa_Template_Thumbnail_Generator::generate_for_id($id, ['request_source' => 'template_write_hook']);
            $fresh = $this->template_rest_resolve_row($request);
        }

        return new \WP_REST_Response(is_array($fresh) ? Eko_Sampa_Template_Thumbnail::enrich_row($fresh) : $fresh);
    }

    public function route_templates_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id     = (int) $request['id'];
        $strict = in_array((string) $request->get_param('strict'), ['1', 'true', 'yes'], true);
        $res    = eko_sampa_safe_delete_template(
            $id,
            [
                'strict'         => $strict,
                'session_token'  => $this->get_request_session_token($request),
            ]
        );
        if (empty($res['ok'])) {
            $status = match ($res['code'] ?? '') {
                'eko_sampa_delete_forbidden' => 403,
                'eko_sampa_delete_blocked_dependencies' => 409,
                default => 400,
            };

            return new \WP_Error(
                (string) ( $res['code'] ?? 'eko_sampa_delete_failed' ),
                (string) ( $res['message'] ?? __('Could not delete template.', 'eko-sampa') ),
                array_merge(['status' => $status], ['debug' => $res['debug'] ?? []])
            );
        }

        return new \WP_REST_Response(['deleted' => true, 'debug' => $res['debug'] ?? []]);
    }

    public function route_templates_duplicate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $tpl = new Eko_Sampa_Template();
        if (! is_array($tpl->get($id))) {
            return new \WP_Error(
                'eko_sampa_duplicate_source_not_found',
                __('Source template was not found or is not visible for your account.', 'eko-sampa'),
                array_merge(['status' => 404], ['failure_reason' => 'source_not_found'])
            );
        }

        $payload = $tpl->build_duplicate_create_data($id);
        if (! is_array($payload)) {
            return new \WP_Error(
                'eko_sampa_duplicate_payload_failed',
                __('Could not build duplicate payload.', 'eko-sampa'),
                ['status' => 400, 'failure_reason' => 'payload_unavailable']
            );
        }

        $try = $tpl->try_create($payload, false);
        if (empty($try['success'])) {
            $report = Eko_Sampa_Template_Duplicate_Diagnostics::sanitize_try_for_api($try);

            Eko_Sampa_Storage_Audit::append(
                'template_duplicate_insert_failed',
                [
                    'source_template_id' => $id,
                    'failure_reason'     => (string) ( $try['failure_reason'] ?? '' ),
                    'mysql_errno'        => (int) ( $try['mysql_errno'] ?? 0 ),
                    'sql_state'          => (string) ( $try['sql_state'] ?? '' ),
                    'offending_column'   => (string) ( $try['offending_column'] ?? '' ),
                ]
            );

            if (defined('EKO_SAMPA_DEBUG') && EKO_SAMPA_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(
                    '[eko-sampa] template_duplicate_insert_failed template=' . $id
                    . ' reason=' . (string) ( $try['failure_reason'] ?? '' )
                    . ' errno=' . (int) ( $try['mysql_errno'] ?? 0 )
                );
            }

            $detail = trim((string) ($try['json_decode_error'] ?? ''));
            $base   = __('Could not duplicate template.', 'eko-sampa');
            $msg    = $detail !== ''
                ? sprintf(
                    /* translators: 1: generic duplicate failure, 2: technical detail (e.g. json_object_coerce_decode_failed). */
                    __('%1$s [%2$s]', 'eko-sampa'),
                    $base,
                    $detail
                )
                : $base;

            return new \WP_Error(
                'eko_sampa_duplicate_failed',
                $msg,
                array_merge(
                    ['status' => 400],
                    [
                        'failure_reason'      => (string) ( $try['failure_reason'] ?? 'wpdb_insert_unknown' ),
                        'db_last_error'       => (string) ( $try['wpdb_error'] ?? $this->wpdb_last_error_snippet() ),
                        'mysql_errno'         => (int) ( $try['mysql_errno'] ?? 0 ),
                        'sql_state'           => (string) ( $try['sql_state'] ?? '' ),
                        'offending_column'    => (string) ( $try['offending_column'] ?? '' ),
                        'json_decode_error'   => (string) ( $try['json_decode_error'] ?? '' ),
                        'insert_diagnostics'  => $try['insert_diagnostics'] ?? null,
                        'duplicate_try'       => is_array($report) ? $report : [],
                    ],
                    $this->wpdb_debug_data()
                )
            );
        }

        $new_id = (int) ( $try['id'] ?? 0 );
        if ($new_id <= 0) {
            return new \WP_Error(
                'eko_sampa_duplicate_failed',
                __('Could not duplicate template.', 'eko-sampa'),
                ['status' => 500, 'failure_reason' => 'insert_id_missing']
            );
        }

        $copy = Eko_Sampa_Template_Thumbnail::copy($id, $new_id);

        $warnings = [];
        if (true !== $copy) {
            $warnings['thumbnail_copy_failed'] = is_wp_error($copy)
                ? [
                    'code'    => $copy->get_error_code(),
                    'message' => $copy->get_error_message(),
                ]
                : ['code' => 'unknown', 'message' => __('Thumbnail copy returned false.', 'eko-sampa')];
            Eko_Sampa_Storage_Audit::append('duplicate_thumbnail_copy_failed', [
                'source_template_id' => $id,
                'new_template_id'    => $new_id,
                'error'              => is_wp_error($copy) ? $copy->get_error_code() : 'not_wp_error',
            ]);
        } elseif (! Eko_Sampa_Template_Thumbnail::exists($new_id)) {
            $warnings['thumbnail_missing'] = [
                'code'    => 'thumbnail_missing',
                'message' => __('Source had no readable thumbnail file; new template has no JPEG yet.', 'eko-sampa'),
            ];
        }

        $row = (new Eko_Sampa_Template())->get($new_id);
        if (! is_array($row)) {
            return new \WP_Error(
                'eko_sampa_duplicate_fetch_failed',
                __('Duplicate was created but could not be reloaded.', 'eko-sampa'),
                ['status' => 500, 'failure_reason' => 'reload_failed', 'template_id' => $new_id]
            );
        }

        $out = Eko_Sampa_Template_Thumbnail::enrich_row($row);
        if (! empty($try['schema_integrity_bridge'])) {
            $out['schema_integrity_bridge'] = $try['schema_integrity_bridge'];
        }
        if ($warnings !== []) {
            $out['duplicate_warnings'] = $warnings;
        }

        Eko_Sampa_Storage_Manager::audit('template_duplicated', ['from' => $id, 'to' => $new_id]);

        return new \WP_REST_Response($out, 201);
    }

    public function route_templates_thumbnail(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = $this->template_rest_resolve_row($request);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $params      = $this->json_params($request);
        $image       = isset($params['image']) ? (string) $params['image'] : '';
        $visual_hash = isset($params['visual_hash']) ? (string) $params['visual_hash'] : '';
        $force       = ! empty($params['force']);
        if ($image === '' && isset($params['dataUrl'])) {
            $image = (string) $params['dataUrl'];
        }

        if ($image === '') {
            return $this->route_templates_thumbnail_generate($request);
        }

        if ($visual_hash === '') {
            $visual_hash = Eko_Sampa_Template_Thumbnail_Visual::hash_from_row($row);
        }

        if (! $force && ! Eko_Sampa_Template_Thumbnail::needs_regeneration($row, $visual_hash)) {
            return new \WP_REST_Response(Eko_Sampa_Template_Thumbnail::enrich_row($row));
        }

        if (! Eko_Sampa_Template_Thumbnail::acquire_generation_lock($id)) {
            return new \WP_Error(
                'eko_sampa_thumb_locked',
                __('Thumbnail generation already in progress.', 'eko-sampa'),
                ['status' => 409]
            );
        }

        Eko_Sampa_Template_Thumbnail::mark_generating($id);

        $capture = Eko_Sampa_Template_Thumbnail::resolve_capture_source_for_client_upload($params);
        $saved   = Eko_Sampa_Template_Thumbnail::save_from_data_url($id, $image, $visual_hash, $capture);
        if ($saved instanceof \WP_Error) {
            Eko_Sampa_Template_Thumbnail::clear_generating($id);
            Eko_Sampa_Template_Thumbnail::release_generation_lock($id);

            return $saved;
        }

        $fresh = $this->template_rest_resolve_row($request);
        if (! is_array($fresh)) {
            return new \WP_REST_Response(['ok' => true], 200);
        }

        return new \WP_REST_Response(Eko_Sampa_Template_Thumbnail::enrich_row($fresh));
    }

    public function route_templates_thumbnail_generate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $row = $this->template_rest_resolve_row($request);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $params = $this->json_params($request);
        $force  = ! empty($params['force']);
        $src    = isset($params['source']) ? (string) $params['source'] : '';
        $tier   = Eko_Sampa_Template_Thumbnail::normalize_generate_request_source($src);

        if (Eko_Sampa_Template_Thumbnail::refuse_regeneration_due_to_capture_tier($row, $tier, $force)) {
            Eko_Sampa_Storage_Audit::append(
                'thumbnail_generate_skipped_tier',
                [
                    'template_id'       => $id,
                    'request_source'    => $src,
                    'incoming_tier'     => $tier,
                    'capture_source_db' => (string) ( $row['thumbnail_capture_source'] ?? '' ),
                    'stored_visual'     => (string) ( $row['thumbnail_visual_hash'] ?? '' ),
                    'php_visual_hash'   => Eko_Sampa_Template_Thumbnail_Visual::hash_from_row($row),
                ]
            );

            return new \WP_REST_Response(Eko_Sampa_Template_Thumbnail::enrich_row($row), 200);
        }

        $generated = Eko_Sampa_Template_Thumbnail_Generator::generate_from_row(
            $row,
            [
                'force'            => $force,
                'request_source'   => $src,
            ]
        );
        if ($generated instanceof \WP_Error) {
            return $generated;
        }

        $fresh = $this->template_rest_resolve_row($request);

        return new \WP_REST_Response(is_array($fresh) ? Eko_Sampa_Template_Thumbnail::enrich_row($fresh) : ['ok' => true]);
    }

    public function route_templates_placeholders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = $this->template_rest_resolve_row($request);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $tokens = Eko_Sampa_Placeholder_Tokens::collect_from_template_row($row);

        return new \WP_REST_Response(
            [
                'template_id'   => $id,
                'placeholders'  => $tokens,
            ]
        );
    }

    public function route_public_template_start_session(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $source_id = (int) $request['id'];
        $body      = $this->json_params($request);

        return Eko_Sampa_Public_Experience_Service::instance()->fork_public_catalog_session(
            $source_id,
            is_array($body) ? $body : []
        );
    }

    /**
     * Public catalog rows (master + official catalog only). No json_data in payload.
     */
    public function route_public_catalog(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        try {
            $payload = Eko_Sampa_Public_Experience_Service::instance()->build_public_catalog_payload($request);
        } catch (\Throwable $e) {
            if (defined('EKO_SAMPA_DEBUG') && EKO_SAMPA_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('[eko-sampa] route_public_catalog: ' . $e->getMessage());
            }

            return new \WP_Error(
                'eko_sampa_public_catalog_failed',
                __('Could not load the public catalog.', 'eko-sampa'),
                ['status' => 503]
            );
        }
        if ($payload instanceof \WP_Error) {
            return $payload;
        }

        if (is_array($payload) && ! empty($payload['items']) && is_array($payload['items'])) {
            $ids = [];
            foreach ($payload['items'] as $it) {
                if (is_array($it) && ! empty($it['id'])) {
                    $ids[] = (int) $it['id'];
                }
            }
            if ($ids !== []) {
                Eko_Sampa_Public_Analytics::instance()->queue_catalog_views($ids);
            }
        }

        return new \WP_REST_Response($payload);
    }

    public function route_public_categories(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        unset($request);
        $payload = Eko_Sampa_Public_Experience_Service::instance()->build_public_categories_payload();
        if ($payload instanceof \WP_Error) {
            return $payload;
        }

        return new \WP_REST_Response($payload);
    }

    public function route_templates_persist_session_to_mine(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $session_id = (int) $request['id'];
        $params     = $this->json_params($request);
        $token      = $this->get_request_session_token($request);
        if ($token === '') {
            return new \WP_Error('eko_sampa_invalid', __('session_token is required.', 'eko-sampa'), ['status' => 400]);
        }

        $tpl = new Eko_Sampa_Template();
        $row = $tpl->get_row_by_id($session_id);
        if (! Eko_Sampa_Template_Derivation::request_can_use_session_row($row, $token)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $uid = (int) get_current_user_id();
        if ($uid <= 0) {
            return new \WP_Error(
                'eko_sampa_auth_required',
                __('Log in to save templates to your library.', 'eko-sampa'),
                ['status' => 401]
            );
        }

        $max = Eko_Sampa_Template_Derivation::max_saved_templates_per_user();
        if ($tpl->count_user_saved_templates($uid) >= $max) {
            return new \WP_Error(
                'eko_sampa_saved_template_limit',
                __('You reached the maximum number of saved templates.', 'eko-sampa'),
                ['status' => 403, 'max_saved_templates_per_user' => $max]
            );
        }

        $nome = isset($params['nome']) ? sanitize_text_field((string) $params['nome']) : '';
        if ($nome === '') {
            $nome = (string) ( $row['nome'] ?? __( 'My template', 'eko-sampa' ) );
        }

        $payload = [
            'nome'                  => $nome,
            'categoria'             => (string) ( $row['categoria'] ?? '' ),
            'descricao'             => (string) ( $row['descricao'] ?? '' ),
            'client_id'             => (int) ( $row['client_id'] ?? 0 ),
            'product_id'            => (int) ( $row['product_id'] ?? 0 ),
            'service_id'            => (int) ( $row['service_id'] ?? 0 ),
            'width_mm'              => (int) ( $row['width_mm'] ?? 0 ),
            'height_mm'             => (int) ( $row['height_mm'] ?? 0 ),
            'preview_image'         => '',
            'json_data'             => $row['json_data'] ?? null,
            'template_type'         => Eko_Sampa_Template_Derivation::TYPE_USER,
            'parent_template_id'    => (int) ( $row['parent_template_id'] ?? 0 ),
            'saved_from_session_id' => $session_id,
            'is_public'             => 0,
            'is_public_catalog'     => 0,
            'allow_personalization' => 1,
        ];

        $try = $tpl->try_create($payload, false);
        if (empty($try['success'])) {
            return new \WP_Error(
                'eko_sampa_persist_failed',
                __('Could not save template.', 'eko-sampa'),
                array_merge(
                    ['status' => 400],
                    ['failure_reason' => (string) ( $try['failure_reason'] ?? '' )],
                    $this->wpdb_debug_data()
                )
            );
        }

        $new_id = (int) ( $try['id'] ?? 0 );
        if ($new_id <= 0) {
            return new \WP_Error('eko_sampa_persist_failed', __('Could not save template.', 'eko-sampa'), ['status' => 500]);
        }

        $parent_id = (int) ( $row['parent_template_id'] ?? 0 );
        /**
         * Fires after a session template was persisted into the user library (session row is removed next).
         *
         * @param int $session_id Closed session template id.
         * @param int $new_id     New user-owned template id.
         * @param int $parent_id  `parent_template_id` from the session row (catalog/master lineage).
         */
        do_action('eko_sampa_session_persisted_to_user_template', $session_id, $new_id, $parent_id);

        Eko_Sampa_Template_Thumbnail::copy($session_id, $new_id);

        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'eko_sampa_quick_print_jobs', ['template_id' => $session_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'eko_sampa_templates', ['id' => $session_id], ['%d']);
        Eko_Sampa_Template_Thumbnail::delete($session_id);

        $saved = $tpl->get($new_id);
        if (! is_array($saved)) {
            return new \WP_REST_Response(
                [
                    'id'            => $new_id,
                    'session_closed'=> $session_id,
                ],
                201
            );
        }

        $out = Eko_Sampa_Template_Thumbnail::enrich_row($saved);
        $out['session_closed'] = $session_id;

        return new \WP_REST_Response($out, 201);
    }

    public function route_orders_list(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response((new Eko_Sampa_Order())->list($this->list_args($request)));
    }

    public function route_orders_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $order = new Eko_Sampa_Order();
        $raw   = $this->json_params($request);
        $qtok  = $this->get_request_session_token($request);
        if ($qtok !== '' && ( ! isset($raw['session_token']) || trim((string) $raw['session_token']) === '' )) {
            $raw['session_token'] = $qtok;
        }
        $params = $order->prepare_create_data($raw);

        $relations = $order->relations_validate($params, null);

        if (! $relations['ok']) {
            $failed = isset($relations['debug']['failed_at']) ? (string) $relations['debug']['failed_at'] : 'unknown';
            $base   = __('Could not create order: service or template is missing or not allowed for your account.', 'eko-sampa');
            $msg    = $failed !== '' && $failed !== 'unknown'
                ? $base . ' [' . $failed . ']'
                : $base;

            if ($failed === 'service_not_visible_for_order'
                && empty($relations['debug']['service_exists'])
                && ! empty($relations['debug']['template_id'])) {
                $msg .= ' ' . __(
                    'The template references a missing service. Edit the template to link a real service, or create the order with template placeholders only (service left empty).',
                    'eko-sampa'
                );
            }

            return new \WP_Error(
                'eko_sampa_order_invalid_relations',
                $msg,
                [
                    'status'    => 400,
                    'failed_at' => $failed,
                    'debug'     => $relations['debug'],
                ]
            );
        }

        if (isset($relations['debug']['service_id']) && (int) $relations['debug']['service_id'] > 0) {
            $params['service_id'] = (int) $relations['debug']['service_id'];
        }

        $id = $order->create($params);
        if (! $id) {
            return new \WP_Error(
                'eko_sampa_create_failed',
                __('Could not create order.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        $guest_flow = isset($params['_eko_order_template_session']) && is_string($params['_eko_order_template_session'])
            && preg_replace('/[^a-f0-9]/i', '', (string) $params['_eko_order_template_session']) !== '';
        do_action(
            'eko_sampa_order_created',
            (int) $id,
            [
                'template_id'           => (int) ( $params['template_id'] ?? 0 ),
                'guest_template_flow'   => $guest_flow,
            ]
        );

        $created = $order->get((int) $id);

        return new \WP_REST_Response(
            is_array($created) ? $order->enrich_row_for_api($created) : ['id' => $id],
            201
        );
    }

    public function route_orders_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id    = (int) $request['id'];
        $model = new Eko_Sampa_Order();
        $row   = $model->get($id);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return new \WP_REST_Response($model->enrich_row_for_api($row));
    }

    public function route_orders_update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $cur = (new Eko_Sampa_Order())->get($id);
        if (is_array($cur) && ($cur['status'] ?? '') === 'completed') {
            return new \WP_Error(
                'eko_sampa_order_immutable',
                __('Completed orders are immutable. Duplicate as revision to continue production.', 'eko-sampa'),
                ['status' => 409]
            );
        }

        $ok = (new Eko_Sampa_Order())->update($id, $this->json_params($request));
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_update_failed',
                __('Could not update order.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        $fresh = (new Eko_Sampa_Order())->get($id);

        return new \WP_REST_Response(
            is_array($fresh) ? ( new Eko_Sampa_Order() )->enrich_row_for_api($fresh) : ['id' => $id]
        );
    }

    public function route_orders_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $res = eko_sampa_safe_delete_order($id);
        if (empty($res['ok'])) {
            $status = 'eko_sampa_delete_forbidden' === ( $res['code'] ?? '' ) ? 403 : 400;

            return new \WP_Error(
                (string) ( $res['code'] ?? 'eko_sampa_delete_failed' ),
                (string) ( $res['message'] ?? __('Could not delete order.', 'eko-sampa') ),
                array_merge(['status' => $status], ['debug' => $res['debug'] ?? []])
            );
        }

        return new \WP_REST_Response(['deleted' => true, 'debug' => $res['debug'] ?? []]);
    }

    public function route_orders_duplicate_revision(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $new = (new Eko_Sampa_Order())->duplicate_as_revision($id);
        if (! $new) {
            return new \WP_Error(
                'eko_sampa_duplicate_revision_failed',
                __('Only completed orders can be duplicated as a new revision.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        $model = new Eko_Sampa_Order();
        $row   = $model->get((int) $new);

        return new \WP_REST_Response(
            is_array($row) ? $model->enrich_row_for_api($row) : ['id' => (int) $new],
            201
        );
    }

    public function route_orders_duplicate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $new = (new Eko_Sampa_Order())->duplicate($id);
        if (! $new) {
            return new \WP_Error(
                'eko_sampa_duplicate_failed',
                __('Could not duplicate order.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        $model = new Eko_Sampa_Order();
        $row   = $model->get((int) $new);

        return new \WP_REST_Response(
            is_array($row) ? $model->enrich_row_for_api($row) : ['id' => (int) $new],
            201
        );
    }

    public function route_orders_render(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $order = (new Eko_Sampa_Order())->get($id);
        if (! is_array($order)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $uid = (int) ($order['user_id'] ?? 0);
        $oid = (int) ($order['id'] ?? 0);
        if (($order['status'] ?? '') === 'completed'
            && $uid > 0
            && $oid > 0
            && Eko_Sampa_Order_Completed_Snapshot::is_ready($uid, $oid)) {
            $bundle = Eko_Sampa_Order_Completed_Snapshot::load_render_bundle($order);
            if (is_array($bundle)) {
                $tpl  = $bundle['template_row'];
                $ctx  = $bundle['context'];
                $rnd  = new Eko_Sampa_Template_Renderer();
                $html = $rnd->render($tpl, $ctx, true);

                $html['editorPreview']          = $rnd->build_editor_preview_payload($tpl, $ctx);
                $html['template_placeholders']  = Eko_Sampa_Placeholder_Tokens::collect_from_template_row($tpl);
                $html['render_source']          = 'completed_snapshot';
                $html                           = array_merge(
                    $html,
                    Eko_Sampa_Order_Completed_Snapshot::render_integrity_hints($uid, $oid, (string) ($order['status'] ?? ''))
                );
                $html['operational_meta'] = $this->order_operational_meta_payload($order);

                return new \WP_REST_Response($html);
            }
        }

        $tid = (int) ($order['template_id'] ?? 0);
        $tpl = (new Eko_Sampa_Template())->get($tid);
        if (! is_array($tpl)) {
            return new \WP_Error('eko_sampa_bad_template', __('Template not found.', 'eko-sampa'), ['status' => 400]);
        }

        $ctx  = Eko_Sampa_Order::template_render_context($order);
        $rnd  = new Eko_Sampa_Template_Renderer();
        $html = $rnd->render($tpl, $ctx, true);

        $html['editorPreview']     = $rnd->build_editor_preview_payload($tpl, $ctx);
        $html['template_placeholders'] = Eko_Sampa_Placeholder_Tokens::collect_from_template_row($tpl);
        $html['render_source']          = (($order['status'] ?? '') === 'completed')
            ? 'live_template_pre_snapshot_fallback'
            : 'live_template';
        $html = array_merge(
            $html,
            Eko_Sampa_Order_Completed_Snapshot::render_integrity_hints(
                $uid,
                $oid,
                (string) ($order['status'] ?? '')
            )
        );
        $html['operational_meta'] = $this->order_operational_meta_payload($order);

        return new \WP_REST_Response($html);
    }

    public function route_orders_render_draft(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $p   = $this->json_params($request);
        $tid = absint((int) ($p['template_id'] ?? 0));
        if ($tid <= 0) {
            return new \WP_Error(
                'eko_sampa_need_template',
                __('Template is required for preview.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        $tpl = (new Eko_Sampa_Template())->get($tid);
        if (! is_array($tpl)) {
            return new \WP_Error('eko_sampa_bad_template', __('Template not found.', 'eko-sampa'), ['status' => 400]);
        }

        $order = [
            'id'                  => (int) ($p['id'] ?? 0),
            'template_id'         => $tid,
            'client_id'           => absint((int) ($p['client_id'] ?? 0)),
            'dynamic_data_json'   => $p['dynamic_data_json'] ?? null,
        ];

        $ctx  = Eko_Sampa_Order::template_render_context($order);
        $rnd  = new Eko_Sampa_Template_Renderer();
        $html = $rnd->render($tpl, $ctx, true);

        $html['editorPreview']     = $rnd->build_editor_preview_payload($tpl, $ctx);
        $html['template_placeholders'] = Eko_Sampa_Placeholder_Tokens::collect_from_template_row($tpl);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $h = isset($html['html']) && is_string($html['html']) ? $html['html'] : '';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(
                '[eko-sampa] render-draft tid=' . $tid
                . ' client_id=' . (int) ($order['client_id'] ?? 0)
                . ' order_row_id=' . (int) ($order['id'] ?? 0)
                . ' html_len=' . strlen($h)
            );
        }

        return new \WP_REST_Response($html);
    }

    public function route_lookups_order_form(\WP_REST_Request $request): \WP_REST_Response {
        $args            = $this->list_args($request);
        $args['limit']   = 500;
        $args['offset']  = 0;
        $args['orderby'] = $args['orderby'] !== '' ? $args['orderby'] : 'id';
        $args['order']   = $args['order'] !== '' ? $args['order'] : 'ASC';

        return new \WP_REST_Response(
            [
                'clients'   => array_values((new Eko_Sampa_Client())->list($args)),
                'services'  => array_values((new Eko_Sampa_Service())->list($args)),
                'templates' => array_values(
                    $this->enrich_template_rows((new Eko_Sampa_Template())->list($args))
                ),
            ]
        );
    }

    public function route_gallery_list(\WP_REST_Request $request): \WP_REST_Response {
        unset($request);

        $uid = get_current_user_id();
        $svc = new Eko_Sampa_Upload_Service();

        return new \WP_REST_Response($svc->list_images($uid));
    }

    public function route_gallery_upload(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        unset($request);

        $files = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null;
        if ($files === null) {
            return new \WP_Error('eko_sampa_no_file', __('Missing file.', 'eko-sampa'), ['status' => 400]);
        }

        $svc = new Eko_Sampa_Upload_Service();
        $res = $svc->handle_upload(get_current_user_id(), $files);
        if (is_wp_error($res)) {
            return $res;
        }

        return new \WP_REST_Response(['url' => (string) $res], 201);
    }

    public function route_gallery_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $name = (string) $request['file'];
        $ok   = (new Eko_Sampa_Upload_Service())->delete_image(get_current_user_id(), $name);
        if (! $ok) {
            return new \WP_Error('eko_sampa_delete_failed', __('Could not delete file.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    private function field_create_failure_error(Eko_Sampa_Service_Field $field): \WP_Error {
        $reason = (string) ($field->last_create_failure() ?? '');
        $err    = $this->wpdb_last_error_snippet();
        $data   = array_merge(
            ['status' => 400, 'failure_reason' => $reason],
            $this->wpdb_debug_data(),
            $err !== '' ? ['db_last_error' => $err] : []
        );

        $code = 'eko_sampa_create_failed';
        $msg  = __('Could not create field.', 'eko-sampa');

        if ($reason === 'fields_table_missing') {
            $code = 'eko_sampa_fields_table_missing';
            $msg  = __(
                'Dynamic fields table is missing. Open WordPress admin once to run the Eko Sampa database upgrade, then try again.',
                'eko-sampa'
            );
        } elseif ($reason === 'service_not_accessible') {
            $code = 'eko_sampa_service_not_found';
            $msg  = __('Service not found or not allowed for your account.', 'eko-sampa');
            $data['status'] = 404;
        } elseif ($err !== '' && (stripos($err, 'unknown column') !== false || stripos($err, "doesn't exist") !== false)) {
            $code = 'eko_sampa_db_schema_outdated';
            $msg  = __(
                'Could not create field: the database is missing recent Eko Sampa columns. Open WordPress admin once to run the upgrade, then try again.',
                'eko-sampa'
            );
        } elseif ($reason === 'db_insert_failed' && $err !== '') {
            $msg = __('Could not create field. Database reported an error (see details).', 'eko-sampa');
        }

        return new \WP_Error($code, $msg, $data);
    }

    /**
     * @return string
     */
    private function wpdb_last_error_snippet(): string {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return '';
        }

        $err = trim((string) $wpdb->last_error);
        if ($err === '') {
            return '';
        }

        return strlen($err) > 500 ? substr($err, 0, 500) : $err;
    }

    /**
     * Extra context when a wpdb write fails (only with WP_DEBUG, for local diagnosis).
     *
     * @return array<string, string>
     */
    private function wpdb_debug_data(): array {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return [];
        }

        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return [];
        }

        $err = (string) $wpdb->last_error;

        return $err !== '' ? ['db_last_error' => $err] : [];
    }

    /**
     * Non-render metadata for order print/preview clients (never merged into canvas context).
     *
     * @param array<string, mixed> $order
     *
     * @return array{order_id: int, order_title: ?string, status: string}
     */
    private function order_operational_meta_payload(array $order): array {
        $oid = (int) ($order['id'] ?? 0);
        $t   = null;
        if (isset($order['order_title']) && is_string($order['order_title'])) {
            $t = sanitize_text_field($order['order_title']);
            if ($t === '') {
                $t = null;
            }
        }

        return [
            'order_id'    => $oid,
            'order_title' => $t,
            'status'      => sanitize_key((string) ($order['status'] ?? '')),
        ];
    }

    /**
     * Quick print (editor) — feature-gated; uses template capability or guest session token on the same template/job surface.
     */
    public function quick_print_permission(\WP_REST_Request $request = null): bool {
        if (! apply_filters('eko_sampa_quick_print_enabled', true)) {
            return false;
        }

        if ($this->require_templates_cap()) {
            return true;
        }

        if (! $request instanceof \WP_REST_Request) {
            return false;
        }

        return $this->quick_print_guest_request_allowed($request);
    }

    private function quick_print_guest_request_allowed(\WP_REST_Request $request): bool {
        $token = $this->get_request_session_token($request);
        if ($token === '') {
            return false;
        }

        $route = (string) $request->get_route();
        $method = (string) $request->get_method();

        if ($method === 'POST' && preg_match('#/quick-print/jobs$#', $route)) {
            $params = $this->json_params($request);
            $tid    = (int) ( $params['template_id'] ?? 0 );

            return $this->template_session_token_valid($tid, $token);
        }

        if (preg_match('#/quick-print/jobs/(\d+)(?:/|$)#', $route, $m)) {
            $jid = (int) ( $m[1] ?? 0 );
            if ($jid <= 0) {
                return false;
            }
            $row = ( new Eko_Sampa_Quick_Print_Job() )->get_for_user($jid, 0, $token);

            return is_array($row);
        }

        return false;
    }

    public function route_quick_print_options(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        unset($request);

        $printers = apply_filters(
            'eko_sampa_quick_print_printers',
            [
                [
                    'id'    => '__system__',
                    'label' => __('System print dialog (OS chooses printer)', 'eko-sampa'),
                ],
            ]
        );

        $presets = apply_filters(
            'eko_sampa_quick_print_presets',
            [
                [
                    'id'    => 'default',
                    'label' => __('Default', 'eko-sampa'),
                ],
                [
                    'id'    => 'color_accurate',
                    'label' => __('Color-accurate (browser hint)', 'eko-sampa'),
                ],
            ]
        );

        return new \WP_REST_Response(
            [
                'printers' => is_array($printers) ? $printers : [],
                'presets'  => is_array($presets) ? $presets : [],
            ]
        );
    }

    /**
     * Quick-print snapshot must be supplied by the editor (live canvas); never rebuilt from DB here.
     *
     * @param array<string, mixed> $preview
     */
    private function validate_quick_print_client_snapshot(array $preview): ?\WP_Error {
        $enc = wp_json_encode($preview, JSON_UNESCAPED_UNICODE);
        if (! is_string($enc) || $enc === '') {
            return new \WP_Error(
                'eko_sampa_quick_print_invalid_snapshot',
                __('Invalid quick print snapshot.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        if (strlen($enc) > self::MAX_TEMPLATE_JSON_BYTES) {
            return new \WP_Error(
                'eko_sampa_quick_print_snapshot_too_large',
                __('Quick print snapshot is too large.', 'eko-sampa'),
                ['status' => 413]
            );
        }

        if (! isset($preview['elements']) || ! is_array($preview['elements'])) {
            return new \WP_Error(
                'eko_sampa_quick_print_invalid_snapshot',
                __('Quick print snapshot must include an elements array.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        $count = count($preview['elements']);
        if ($count < 1 || $count > 400) {
            return new \WP_Error(
                'eko_sampa_quick_print_invalid_snapshot',
                __('Quick print snapshot must have between 1 and 400 elements.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        $wm = (int) ( $preview['width_mm'] ?? 0 );
        $hm = (int) ( $preview['height_mm'] ?? 0 );
        if ($wm < 1 || $wm > 2000 || $hm < 1 || $hm > 2000) {
            return new \WP_Error(
                'eko_sampa_quick_print_invalid_snapshot',
                __('Quick print snapshot has invalid dimensions.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        foreach ($preview['elements'] as $el) {
            if (! is_array($el)) {
                continue;
            }
            if (sanitize_key((string) ( $el['type'] ?? '' )) !== 'image') {
                continue;
            }
            $src = isset($el['src']) ? trim((string) $el['src']) : '';
            if ($src === '') {
                $src = isset($el['content']) ? trim((string) $el['content']) : '';
            }
            if ($src === '') {
                return new \WP_Error(
                    'eko_sampa_quick_print_invalid_image',
                    __('Quick print snapshot has an image element without a source URL.', 'eko-sampa'),
                    ['status' => 400]
                );
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $preview Validated client snapshot
     *
     * @return array<string, mixed>
     */
    private function sanitize_quick_print_snapshot_for_response(array $preview): array {
        $wm = max(1, min(2000, (int) ( $preview['width_mm'] ?? 210 )));
        $hm = max(1, min(2000, (int) ( $preview['height_mm'] ?? 297 )));
        $bg  = isset($preview['background_color']) ? (string) $preview['background_color'] : '#ffffff';
        $els = $preview['elements'];

        return Eko_Sampa_Render_Schema::envelope(
            [
                'width_mm'          => $wm,
                'height_mm'         => $hm,
                'background_color' => $bg,
                'elements'          => is_array($els) ? $els : [],
            ]
        );
    }

    public function route_quick_print_jobs_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $params = $this->json_params($request);
        $tid    = (int) ( $params['template_id'] ?? 0 );
        if ($tid <= 0) {
            return new \WP_Error('eko_sampa_invalid', __('Invalid template.', 'eko-sampa'), ['status' => 400]);
        }

        $uid  = (int) get_current_user_id();
        $gtok = $this->get_request_session_token($request);
        if ($uid > 0) {
            if (! is_array(( new Eko_Sampa_Template() )->get($tid))) {
                return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
            }
        } elseif (! $this->template_session_token_valid($tid, $gtok)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $preview_in = $params['editor_preview'] ?? null;
        if (! is_array($preview_in)) {
            return new \WP_Error(
                'eko_sampa_quick_print_missing_snapshot',
                __('Live editor snapshot (editor_preview) is required.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        $snap_err = $this->validate_quick_print_client_snapshot($preview_in);
        if ($snap_err instanceof \WP_Error) {
            return $snap_err;
        }

        if ($uid <= 0) {
            $qp_guard = Eko_Sampa_Public_Experience::guard_guest_quick_print_create($uid, $tid, $gtok);
            if ($qp_guard instanceof \WP_Error) {
                return $qp_guard;
            }
        }

        $preview = $this->sanitize_quick_print_snapshot_for_response($preview_in);

        $model = new Eko_Sampa_Quick_Print_Job();
        if (! $model->is_storage_ready()) {
            return new \WP_Error(
                'eko_sampa_quick_print_unavailable',
                __('Quick print jobs table is not installed yet.', 'eko-sampa'),
                ['status' => 503]
            );
        }

        $qty  = (int) ( $params['quantity'] ?? 1 );
        $pk   = isset($params['printer_key']) ? (string) $params['printer_key'] : '__system__';
        $preset = isset($params['preset_key']) ? (string) $params['preset_key'] : 'default';

        $job = $model->create($uid, $tid, $qty, $pk, $preset, $uid <= 0 ? $gtok : '');
        if ($job instanceof \WP_Error) {
            return $job;
        }

        if ($uid <= 0) {
            Eko_Sampa_Public_Experience::record_guest_quick_print_hour_usage();
            Eko_Sampa_Public_Experience::bump_quick_print_metric();
        }

        if (is_array($job)) {
            do_action('eko_sampa_quick_print_job_created', $job);
        }

        $qty_eff = is_array($job) ? (int) ( $job['quantity'] ?? 1 ) : max(1, $qty);
        $hint    = $qty_eff > 1
            ? sprintf(
                /* translators: %d: number of sheets printed from the quick-print preview in one dialog */
                __( 'The print dialog will output %d sheet(s) from the preview (one dialog). Use your printer’s “copies” only if you need duplicates of the whole set.', 'eko-sampa' ),
                max(1, $qty_eff)
            )
            : __(
                'Browsers print one sheet per dialog. Set “copies” in the system printer UI to match the quantity when supported.',
                'eko-sampa'
            );

        return new \WP_REST_Response(
            [
                'job'            => $job,
                'editor_preview' => $preview,
                'quantity_hint'  => $hint,
            ],
            201
        );
    }

    public function route_quick_print_job_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id   = (int) $request['id'];
        $uid  = (int) get_current_user_id();
        $gtok = $this->get_request_session_token($request);
        $row  = ( new Eko_Sampa_Quick_Print_Job() )->get_for_user($id, $uid, $uid <= 0 ? $gtok : '');
        if ($row === null) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return new \WP_REST_Response($row);
    }

    public function route_quick_print_job_cancel(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id    = (int) $request['id'];
        $uid   = (int) get_current_user_id();
        $gtok  = $this->get_request_session_token($request);
        $model = new Eko_Sampa_Quick_Print_Job();
        $row   = $model->get_for_user($id, $uid, $uid <= 0 ? $gtok : '');
        if ($row === null) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        if ( ( $row['status'] ?? '' ) !== Eko_Sampa_Quick_Print_Job::STATUS_QUEUED) {
            return new \WP_Error(
                'eko_sampa_quick_print_invalid_state',
                __('Only queued jobs can be cancelled.', 'eko-sampa'),
                ['status' => 409]
            );
        }

        $r = $model->set_status($id, $uid, Eko_Sampa_Quick_Print_Job::STATUS_CANCELLED, $uid <= 0 ? $gtok : '');
        if ($r instanceof \WP_Error) {
            return $r;
        }

        return new \WP_REST_Response($model->get_for_user($id, $uid, $uid <= 0 ? $gtok : ''));
    }

    public function route_quick_print_job_browser_handoff(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id    = (int) $request['id'];
        $uid   = (int) get_current_user_id();
        $gtok  = $this->get_request_session_token($request);
        $model = new Eko_Sampa_Quick_Print_Job();
        $row   = $model->get_for_user($id, $uid, $uid <= 0 ? $gtok : '');
        if ($row === null) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $st = (string) ( $row['status'] ?? '' );
        if ($st !== Eko_Sampa_Quick_Print_Job::STATUS_QUEUED) {
            return new \WP_Error(
                'eko_sampa_quick_print_invalid_state',
                __('Job is not in a printable queue state.', 'eko-sampa'),
                ['status' => 409]
            );
        }

        $r = $model->set_status($id, $uid, Eko_Sampa_Quick_Print_Job::STATUS_SENT_TO_BROWSER, $uid <= 0 ? $gtok : '');
        if ($r instanceof \WP_Error) {
            return $r;
        }

        return new \WP_REST_Response($model->get_for_user($id, $uid, $uid <= 0 ? $gtok : ''));
    }

    public function route_quick_print_job_complete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id    = (int) $request['id'];
        $uid   = (int) get_current_user_id();
        $gtok  = $this->get_request_session_token($request);
        $model = new Eko_Sampa_Quick_Print_Job();
        $row   = $model->get_for_user($id, $uid, $uid <= 0 ? $gtok : '');
        if ($row === null) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $st = (string) ( $row['status'] ?? '' );
        if ($st !== Eko_Sampa_Quick_Print_Job::STATUS_SENT_TO_BROWSER) {
            return new \WP_Error(
                'eko_sampa_quick_print_invalid_state',
                __('Job must be in “sent to browser” state to complete.', 'eko-sampa'),
                ['status' => 409]
            );
        }

        $r = $model->set_status($id, $uid, Eko_Sampa_Quick_Print_Job::STATUS_COMPLETED, $uid <= 0 ? $gtok : '');
        if ($r instanceof \WP_Error) {
            return $r;
        }

        $fresh = $model->get_for_user($id, $uid, $uid <= 0 ? $gtok : '');
        if (is_array($fresh)) {
            do_action('eko_sampa_quick_print_job_completed', $fresh);
        }

        return new \WP_REST_Response($fresh);
    }

    public function route_quick_print_job_reprint(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id     = (int) $request['id'];
        $uid    = (int) get_current_user_id();
        $gtok   = $this->get_request_session_token($request);
        $model  = new Eko_Sampa_Quick_Print_Job();
        $source = $model->get_for_user($id, $uid, $uid <= 0 ? $gtok : '');
        if ($source === null) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $params = $this->json_params($request);
        $raw    = $params['editor_preview'] ?? null;
        if (! is_array($raw)) {
            return new \WP_Error(
                'eko_sampa_quick_print_missing_snapshot',
                __('Live editor snapshot (editor_preview) is required for reprint.', 'eko-sampa'),
                ['status' => 400]
            );
        }

        $snap_err = $this->validate_quick_print_client_snapshot($raw);
        if ($snap_err instanceof \WP_Error) {
            return $snap_err;
        }

        $preview = $this->sanitize_quick_print_snapshot_for_response($raw);
        $tid     = (int) ( $source['template_id'] ?? 0 );
        if ($uid > 0) {
            if (! is_array(( new Eko_Sampa_Template() )->get($tid))) {
                return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
            }
        } elseif (! $this->template_session_token_valid($tid, $gtok)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        if (! $model->is_storage_ready()) {
            return new \WP_Error(
                'eko_sampa_quick_print_unavailable',
                __('Quick print jobs table is not installed yet.', 'eko-sampa'),
                ['status' => 503]
            );
        }

        $qty = isset($params['quantity']) ? (int) $params['quantity'] : (int) ( $source['quantity'] ?? 1 );
        $pk  = isset($params['printer_key']) ? (string) $params['printer_key'] : (string) ( $source['printer_key'] ?? '__system__' );
        $preset = isset($params['preset_key']) ? (string) $params['preset_key'] : (string) ( $source['preset_key'] ?? 'default' );

        $job = $model->create($uid, $tid, $qty, $pk, $preset, $uid <= 0 ? $gtok : '');
        if ($job instanceof \WP_Error) {
            return $job;
        }

        return new \WP_REST_Response(
            [
                'job'            => $job,
                'editor_preview' => $preview,
            ],
            201
        );
    }

    /**
     * Decoded JSON body for mutating routes.
     *
     * `WP_REST_Request::get_json_params()` returns null when WordPress did not treat the body as
     * JSON (e.g. missing or non-standard Content-Type), even if the client sent a JSON object.
     * We fall back to decoding the raw body so models receive the intended fields.
     *
     * @return array<string, mixed>
     */
    private function json_params(\WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (is_array($params)) {
            return $params;
        }

        $body = (string) $request->get_body();
        if ($body !== '') {
            $trim = ltrim($body);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($body, true);
                if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $fallback = $request->get_body_params();

        return is_array($fallback) ? $fallback : [];
    }

    /**
     * Strip derivation / lifecycle columns non-admins must not set via REST (master/session metadata).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function sanitize_template_admin_only_fields(array $params): array {
        if (current_user_can('manage_options')) {
            return $params;
        }

        foreach (
            [
                'template_type',
                'parent_template_id',
                'session_token',
                'expires_at',
                'last_activity_at',
                'saved_from_session_id',
                'session_fingerprint',
                'session_lifecycle',
                'is_public_catalog',
                'is_user_shareable',
                'is_marketplace_item',
            ] as $k
        ) {
            unset($params[ $k ]);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validate_template_json_payload(array $params): ?\WP_Error {
        if (! array_key_exists('json_data', $params)) {
            return null;
        }

        $raw = $params['json_data'];
        if (is_string($raw)) {
            $len = strlen($raw);
        } else {
            $enc = wp_json_encode($raw, JSON_UNESCAPED_UNICODE);
            $len = is_string($enc) ? strlen($enc) : 0;
            if (false === $enc) {
                return new \WP_Error(
                    'eko_sampa_invalid_json',
                    __('Invalid template JSON.', 'eko-sampa'),
                    ['status' => 400]
                );
            }
        }

        if ($len > self::MAX_TEMPLATE_JSON_BYTES) {
            return new \WP_Error(
                'eko_sampa_payload_too_large',
                __('Template layout JSON is too large.', 'eko-sampa'),
                ['status' => 413]
            );
        }

        return null;
    }
}
