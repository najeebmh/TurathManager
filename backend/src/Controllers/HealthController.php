<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Support\Migrator;

/**
 * فحص صحة النظام دون كشف أي أسرار.
 */
final class HealthController extends Controller
{
    public function show(Request $r): Response
    {
        $db = ['status' => 'down'];
        $status = 200;
        try {
            $pdo = $this->app->db();
            $pdo->query('SELECT 1')->fetchColumn();
            $schema = (new Migrator($pdo, $this->app->config['paths']['migrations']))->status();
            $db = [
                'status' => 'up',
                'schema_version' => $schema['current'],
                'pending_migrations' => count($schema['pending']),
            ];
        } catch (\Throwable $e) {
            $this->app->logger->error('Health check DB failure', ['exception' => get_class($e)]);
            $status = 503;
        }
        return \TurathManager\Core\Response::json([
            'app' => $this->app->config['app']['name'],
            'status' => $status === 200 ? 'ok' : 'degraded',
            'time' => $this->app->clock->now()->format('Y-m-d\TH:i:s\Z'),
            'timezone' => $this->app->config['app']['timezone'],
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'database' => $db,
        ], $status);
    }
}
