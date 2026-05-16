<?php
/**
 * Shared contract for dependency / delete-readiness snapshots (per entity).
 *
 * Concrete inspectors (services today; templates, orders, clients later) extend this class.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

abstract class Eko_Sampa_Entity_Relations_Inspector {

    /**
     * Full inspection payload (counts, legacy flags, ownership hints).
     *
     * @return array<string, mixed>
     */
    abstract protected function inspect_entity(int $id): array;

    /**
     * @return array<string, mixed>
     */
    final public function inspect(int $id): array {
        return $this->inspect_entity($id);
    }

    /**
     * Normalized delete-readiness view on top of {@see inspect()}.
     *
     * @return array<string, mixed>
     */
    public function delete_readiness(int $id): array {
        $snapshot = $this->inspect($id);

        return [
            'entity_id'   => $id,
            'inspector'   => static::class,
            'snapshot'    => $snapshot,
            'has_blockers_for_strict_delete' => $this->has_strict_delete_blockers($snapshot),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    protected function has_strict_delete_blockers(array $snapshot): bool {
        $templates = (int) ( $snapshot['templates_count'] ?? 0 );
        $orders    = (int) ( $snapshot['orders_count'] ?? 0 );
        $ts        = (int) ( $snapshot['templates_servico_only'] ?? 0 );
        $os        = (int) ( $snapshot['orders_servico_only'] ?? 0 );

        return ($templates + $orders + $ts + $os) > 0;
    }
}
