<?php
/**
 * NumVault - Service Catalog & Routing Manager
 * Coordinates service listings, country bindings, and server pricing hierarchies
 */

declare(strict_types=1);

namespace App\Services;

use PDO;

class ServiceManager {
    /**
     * Get all active services with minimal available price
     */
    public static function getActiveServices(): array {
        $pdo = get_db();
        $stmt = $pdo->query("
            SELECT s.*, 
                   COUNT(srv.id) AS active_servers_count,
                   MIN(srv.selling_price) AS min_price
            FROM services s
            LEFT JOIN servers srv ON srv.service_id = s.id AND srv.is_enabled = 1
            WHERE s.is_active = 1
            GROUP BY s.id
            ORDER BY s.sort_order ASC, s.name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all active countries
     */
    public static function getActiveCountries(): array {
        $pdo = get_db();
        $stmt = $pdo->query("
            SELECT * FROM countries 
            WHERE is_active = 1 
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get available server routes for a specific service and country
     */
    public static function getAvailableServers(int $serviceId, int $countryId): array {
        $pdo = get_db();
        $stmt = $pdo->prepare("
            SELECT srv.*, p.name AS provider_name, p.is_enabled AS provider_enabled
            FROM servers srv
            JOIN providers p ON p.id = srv.provider_id
            WHERE srv.service_id = ? 
              AND srv.country_id = ? 
              AND srv.is_enabled = 1 
              AND p.is_enabled = 1
            ORDER BY srv.selling_price ASC
        ");
        $stmt->execute([$serviceId, $countryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Apply bulk margin adjustment to all or filtered server routes
     */
    public static function applyBulkMargin(float $percentage, ?int $providerId = null): int {
        $pdo = get_db();
        $multiplier = 1.0 + ($percentage / 100.0);

        if ($providerId !== null && $providerId > 0) {
            $stmt = $pdo->prepare("
                UPDATE servers 
                SET selling_price = ROUND(cost_price * ?, 2),
                    updated_at = NOW()
                WHERE provider_id = ? AND cost_price > 0
            ");
            $stmt->execute([$multiplier, $providerId]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE servers 
                SET selling_price = ROUND(cost_price * ?, 2),
                    updated_at = NOW()
                WHERE cost_price > 0
            ");
            $stmt->execute([$multiplier]);
        }

        return $stmt->rowCount();
    }
}
