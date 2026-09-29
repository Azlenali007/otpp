<?php
/**
 * NumVault - Database Namespace Adapter
 */

declare(strict_types=1);

namespace App\Database;

use App\Core\Database as CoreDatabase;
use PDO;

class Database {
    public static function getConnection(): PDO {
        return CoreDatabase::getConnection();
    }

    public static function transaction(callable $callback): mixed {
        return CoreDatabase::transaction($callback);
    }
}
