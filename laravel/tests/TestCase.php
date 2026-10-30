<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function createNotificationTables(): void
    {
        foreach ([
            '2026_10_20_170939_create_system_notifications_table.php',
            '2026_10_20_172530_add_personal_folders_to_system_notifications.php',
            '2026_10_20_175848_add_expiry_to_system_notifications.php',
            '2026_10_21_052602_add_activity_details_to_system_notifications.php',
            '2026_10_21_060157_add_bell_dismissed_at_to_system_notifications.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    protected function useIsolatedMysql(): void
    {
        $database = env('SECURITY_TEST_DATABASE');
        if (! is_string($database) || $database === '') {
            $this->markTestSkipped('Define SECURITY_TEST_DATABASE con una base MySQL proportsa_test_* aislada.');
        }
        if (! app()->environment('testing') || ! preg_match('/^proportsa_test_[a-z0-9_]+$/', $database)) {
            throw new RuntimeException('Las pruebas MySQL solo pueden usar una base proportsa_test_* en testing.');
        }
        config(['database.default' => 'mysql', 'database.connections.mysql.url' => null,
            'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => $database, 'database.connections.mysql.username' => 'root',
            'database.connections.mysql.password' => '', 'mail.default' => 'array', 'queue.default' => 'null']);
        DB::purge('mysql');
    }
}
