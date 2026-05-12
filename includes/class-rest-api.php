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

    public function register_hooks(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
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
                    'permission_callback' => [$this, 'require_services_cap'],
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
            '/orders/(?P<id>\d+)/duplicate',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'route_orders_duplicate'],
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
        return current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_SERVICES);
    }

    public function require_templates_cap(): bool {
        return current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_TEMPLATES);
    }

    public function require_orders_cap(): bool {
        return current_user_can('manage_options') || current_user_can(Eko_Sampa_Roles::CAP_MANAGE_ORDERS);
    }

    /**
     * @return array<string, mixed>
     */
    private function list_args(\WP_REST_Request $request): array {
        $args = [
            'limit'   => (int) $request->get_param('limit'),
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
            return new \WP_Error('eko_sampa_create_failed', __('Could not create client.', 'eko-sampa'), ['status' => 400]);
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
            return new \WP_Error('eko_sampa_update_failed', __('Could not update client.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Client())->get($id));
    }

    public function route_clients_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Client())->delete($id);
        if (! $ok) {
            return new \WP_Error('eko_sampa_delete_failed', __('Could not delete client.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    public function route_services_list(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response((new Eko_Sampa_Service())->list($this->list_args($request)));
    }

    public function route_services_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (new Eko_Sampa_Service())->create($this->json_params($request));
        if (! $id) {
            return new \WP_Error('eko_sampa_create_failed', __('Could not create service.', 'eko-sampa'), ['status' => 400]);
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
            return new \WP_Error('eko_sampa_update_failed', __('Could not update service.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Service())->get($id));
    }

    public function route_services_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Service())->delete($id);
        if (! $ok) {
            return new \WP_Error('eko_sampa_delete_failed', __('Could not delete service.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    public function route_fields_list(\WP_REST_Request $request): \WP_REST_Response {
        $sid = (int) $request['id'];

        return new \WP_REST_Response((new Eko_Sampa_Service_Field())->list_for_service($sid));
    }

    public function route_fields_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $sid = (int) $request['id'];
        $id  = (new Eko_Sampa_Service_Field())->create($sid, $this->json_params($request));
        if (! $id) {
            return new \WP_Error('eko_sampa_create_failed', __('Could not create field.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Service_Field())->get((int) $id), 201);
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
        $fid = (int) $request['fid'];
        $ok  = (new Eko_Sampa_Service_Field())->update($fid, $this->json_params($request));
        if (! $ok) {
            return new \WP_Error('eko_sampa_update_failed', __('Could not update field.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Service_Field())->get($fid));
    }

    public function route_fields_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $fid = (int) $request['fid'];
        $ok  = (new Eko_Sampa_Service_Field())->delete($fid);
        if (! $ok) {
            return new \WP_Error('eko_sampa_delete_failed', __('Could not delete field.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    public function route_templates_list(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response((new Eko_Sampa_Template())->list($this->list_args($request)));
    }

    public function route_templates_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (new Eko_Sampa_Template())->create($this->json_params($request));
        if (! $id) {
            return new \WP_Error('eko_sampa_create_failed', __('Could not create template.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Template())->get((int) $id), 201);
    }

    public function route_templates_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = (new Eko_Sampa_Template())->get($id);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return new \WP_REST_Response($row);
    }

    public function route_templates_update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Template())->update($id, $this->json_params($request));
        if (! $ok) {
            return new \WP_Error('eko_sampa_update_failed', __('Could not update template.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Template())->get($id));
    }

    public function route_templates_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Template())->delete($id);
        if (! $ok) {
            return new \WP_Error('eko_sampa_delete_failed', __('Could not delete template.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    public function route_templates_duplicate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $new = (new Eko_Sampa_Template())->duplicate($id);
        if (! $new) {
            return new \WP_Error('eko_sampa_duplicate_failed', __('Could not duplicate template.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Template())->get((int) $new), 201);
    }

    public function route_orders_list(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response((new Eko_Sampa_Order())->list($this->list_args($request)));
    }

    public function route_orders_create(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (new Eko_Sampa_Order())->create($this->json_params($request));
        if (! $id) {
            return new \WP_Error('eko_sampa_create_failed', __('Could not create order.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Order())->get((int) $id), 201);
    }

    public function route_orders_get(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $row = (new Eko_Sampa_Order())->get($id);
        if (! is_array($row)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        return new \WP_REST_Response($row);
    }

    public function route_orders_update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Order())->update($id, $this->json_params($request));
        if (! $ok) {
            return new \WP_Error('eko_sampa_update_failed', __('Could not update order.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Order())->get($id));
    }

    public function route_orders_delete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $ok = (new Eko_Sampa_Order())->delete($id);
        if (! $ok) {
            return new \WP_Error('eko_sampa_delete_failed', __('Could not delete order.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response(['deleted' => true]);
    }

    public function route_orders_duplicate(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id  = (int) $request['id'];
        $new = (new Eko_Sampa_Order())->duplicate($id);
        if (! $new) {
            return new \WP_Error('eko_sampa_duplicate_failed', __('Could not duplicate order.', 'eko-sampa'), ['status' => 400]);
        }

        return new \WP_REST_Response((new Eko_Sampa_Order())->get((int) $new), 201);
    }

    public function route_orders_render(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id = (int) $request['id'];
        $order = (new Eko_Sampa_Order())->get($id);
        if (! is_array($order)) {
            return new \WP_Error('eko_sampa_not_found', __('Not found.', 'eko-sampa'), ['status' => 404]);
        }

        $tid = (int) ($order['template_id'] ?? 0);
        $tpl = (new Eko_Sampa_Template())->get($tid);
        if (! is_array($tpl)) {
            return new \WP_Error('eko_sampa_bad_template', __('Template not found.', 'eko-sampa'), ['status' => 400]);
        }

        $ctx = $this->build_print_context($order);
        $html = (new Eko_Sampa_Template_Renderer())->render($tpl, $ctx, false);

        return new \WP_REST_Response($html);
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

    /**
     * @return array<string, mixed>
     */
    private function json_params(\WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (! is_array($params)) {
            $params = $request->get_body_params();
        }

        return is_array($params) ? $params : [];
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array<string, string>
     */
    private function build_print_context(array $order): array {
        $ctx = [
            'order_id' => (string) ($order['id'] ?? ''),
        ];

        $cid = (int) ($order['client_id'] ?? 0);
        if ($cid > 0) {
            $client = (new Eko_Sampa_Client())->get($cid);
            if (is_array($client)) {
                foreach (['nome', 'email', 'telefone', 'documento', 'cidade', 'estado'] as $k) {
                    $ctx[ $k ] = (string) ($client[ $k ] ?? '');
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
                        $ctx[ strtolower($key) ] = is_scalar($v) ? (string) $v : wp_json_encode($v) ?: '';
                    }
                }
            }
        }

        return $ctx;
    }
}
