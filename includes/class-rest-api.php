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
                    'permission_callback' => [$this, 'require_templates_cap'],
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => [$this, 'route_templates_update'],
                    'permission_callback' => [$this, 'require_templates_cap'],
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
            '/templates/(?P<id>\d+)/thumbnail',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_templates_thumbnail'],
                'permission_callback' => [$this, 'require_templates_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)/thumbnail/generate',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_templates_thumbnail_generate'],
                'permission_callback' => [$this, 'require_templates_cap'],
            ]
        );

        register_rest_route(
            self::NS,
            '/templates/(?P<id>\d+)/placeholders',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'route_templates_placeholders'],
                'permission_callback' => [$this, 'require_templates_cap'],
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
        $params = $this->json_params($request);
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
        if (is_array($row) && Eko_Sampa_Template_Thumbnail::needs_regeneration($row)) {
            Eko_Sampa_Template_Thumbnail_Generator::generate_for_id((int) $id);
            $row = (new Eko_Sampa_Template())->get((int) $id);
        }

        return new \WP_REST_Response(is_array($row) ? Eko_Sampa_Template_Thumbnail::enrich_row($row) : $row, 201);
    }

    public function route_templates_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = (new Eko_Sampa_Template())->get($id);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return new \WP_REST_Response(Eko_Sampa_Template_Thumbnail::enrich_row($row));
    }

    public function route_templates_update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id     = (int) $request['id'];
        $params = $this->json_params($request);
        $err    = $this->validate_template_json_payload($params);
        if ($err instanceof \WP_Error) {
            return $err;
        }

        $ok = (new Eko_Sampa_Template())->update($id, $params);
        if (! $ok) {
            return new \WP_Error(
                'eko_sampa_update_failed',
                __('Could not update template.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        $row = (new Eko_Sampa_Template())->get($id);
        if (is_array($row) && Eko_Sampa_Template_Thumbnail::needs_regeneration($row)) {
            Eko_Sampa_Template_Thumbnail_Generator::generate_for_id($id);
            $row = (new Eko_Sampa_Template())->get($id);
        }

        return new \WP_REST_Response(is_array($row) ? Eko_Sampa_Template_Thumbnail::enrich_row($row) : $row);
    }

    public function route_templates_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id     = (int) $request['id'];
        $strict = in_array((string) $request->get_param('strict'), ['1', 'true', 'yes'], true);
        $res    = eko_sampa_safe_delete_template($id, ['strict' => $strict]);
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
        $id = (int) $request['id'];
        $new = (new Eko_Sampa_Template())->duplicate($id);
        if (! $new) {
            return new \WP_Error(
                'eko_sampa_duplicate_failed',
                __('Could not duplicate template.', 'eko-sampa'),
                array_merge(['status' => 400], $this->wpdb_debug_data())
            );
        }

        Eko_Sampa_Template_Thumbnail::copy($id, (int) $new);

        $row = (new Eko_Sampa_Template())->get((int) $new);

        return new \WP_REST_Response(is_array($row) ? Eko_Sampa_Template_Thumbnail::enrich_row($row) : $row, 201);
    }

    public function route_templates_thumbnail(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = (new Eko_Sampa_Template())->get($id);
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

        $saved = Eko_Sampa_Template_Thumbnail::save_from_data_url($id, $image, $visual_hash);
        if ($saved instanceof \WP_Error) {
            Eko_Sampa_Template_Thumbnail::clear_generating($id);
            Eko_Sampa_Template_Thumbnail::release_generation_lock($id);

            return $saved;
        }

        $fresh = (new Eko_Sampa_Template())->get($id);
        if (! is_array($fresh)) {
            return new \WP_REST_Response(['ok' => true], 200);
        }

        return new \WP_REST_Response(Eko_Sampa_Template_Thumbnail::enrich_row($fresh));
    }

    public function route_templates_thumbnail_generate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $row = (new Eko_Sampa_Template())->get($id);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $generated = Eko_Sampa_Template_Thumbnail_Generator::generate_from_row($row);
        if ($generated instanceof \WP_Error) {
            return $generated;
        }

        $fresh = (new Eko_Sampa_Template())->get($id);

        return new \WP_REST_Response(is_array($fresh) ? Eko_Sampa_Template_Thumbnail::enrich_row($fresh) : ['ok' => true]);
    }

    public function route_templates_placeholders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = (new Eko_Sampa_Template())->get($id);
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

    public function route_orders_list(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response((new Eko_Sampa_Order())->list($this->list_args($request)));
    }

    public function route_orders_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $order  = new Eko_Sampa_Order();
        $raw    = $this->json_params($request);
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

        return new \WP_REST_Response((new Eko_Sampa_Order())->get((int) $new), 201);
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

        return new \WP_REST_Response((new Eko_Sampa_Order())->get((int) $new), 201);
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
