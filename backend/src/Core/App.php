<?php

declare(strict_types=1);

namespace TurathManager\Core;

use PDO;
use TurathManager\Auth\AuthService;
use TurathManager\Auth\CurrentUser;

/**
 * نواة التطبيق: تحمل الإعدادات والاتصال والخدمات وتعالج الطلبات.
 */
final class App
{
    private ?PDO $pdo = null;
    /** @var array<class-string,object> */
    private array $services = [];
    public readonly Logger $logger;
    public readonly Clock $clock;
    public readonly Validator $validator;
    public readonly Router $router;

    /** @param array<string,mixed> $config */
    public function __construct(public readonly array $config)
    {
        $this->logger = new Logger($config['paths']['logs']);
        $this->clock = new Clock($config['app']['timezone']);
        $this->validator = new Validator($this->clock, $config['app']['default_country_code']);
        $this->router = new Router();
        (require dirname(__DIR__, 2) . '/routes.php')($this->router);
    }

    public function db(): PDO
    {
        return $this->pdo ??= Database::connect($this->config['db']);
    }

    public function setDb(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function service(string $class): object
    {
        if (!isset($this->services[$class]) && $class === \TurathManager\Support\HttpClient::class) {
            $this->services[$class] = new \TurathManager\Support\CurlHttpClient();
        }
        return $this->services[$class] ??= new $class($this);
    }

    /** @param object $instance */
    public function bind(string $class, object $instance): void
    {
        $this->services[$class] = $instance;
    }

    public function isProduction(): bool
    {
        return $this->config['app']['env'] === 'production';
    }

    public function handle(Request $request): Response
    {
        $origin = $request->header('origin');
        try {
            if ($request->method === 'OPTIONS') {
                $response = Response::noContent();
            } else {
                $match = $this->router->match($request->method, $request->path);
                $request->attributes['params'] = $match['params'];
                $route = $match['route'];

                if ($route['access'] !== Router::PUBLIC) {
                    $user = $this->service(AuthService::class)->authenticate($request);
                    if ($route['access'] !== Router::AUTH && !$user->can($route['access'])) {
                        throw HttpException::forbidden();
                    }
                    $request->attributes['user'] = $user;
                }

                [$class, $method] = $route['handler'];
                $replay = $this->idempotencyLookup($request);
                if ($replay !== null) {
                    $response = $replay;
                } else {
                    $controller = new $class($this);
                    $response = $controller->$method($request);
                    $this->idempotencyStore($request, $response);
                }
            }
        } catch (HttpException $e) {
            $response = Response::json(['error' => [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'details' => $e->details ?: null,
            ]], $e->status);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'path' => $request->path,
            ]);
            $message = 'حدث خطأ داخلي في الخادم';
            if ($this->config['app']['debug'] && !$this->isProduction()) {
                $message .= ': ' . Logger::redactString($e->getMessage());
            }
            $response = Response::json(['error' => ['code' => 'server_error', 'message' => $message]], 500);
        }

        $response->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => $response->headers['Cache-Control'] ?? 'no-store',
        ];
        if ($origin !== null && in_array($origin, $this->config['app']['cors_origins'], true)) {
            $response->headers['Access-Control-Allow-Origin'] = $origin;
            $response->headers['Vary'] = 'Origin';
            $response->headers['Access-Control-Allow-Headers'] = 'Authorization, Content-Type, Idempotency-Key, X-Device-Id';
            $response->headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, DELETE, OPTIONS';
            $response->headers['Access-Control-Max-Age'] = '600';
        }
        return $response;
    }

    /** مفتاح Idempotency-Key للطلبات المعدِّلة: إعادة نفس الطلب تعيد الاستجابة المخزنة دون تنفيذ مكرر */
    private function idempotencyLookup(Request $request): ?Response
    {
        $key = $request->header('idempotency-key');
        $user = $request->attributes['user'] ?? null;
        if ($key === null || !$user instanceof CurrentUser || !in_array($request->method, ['POST', 'PUT'], true) || $request->path === '/sync/push') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{8,100}$/', $key)) {
            throw HttpException::badRequest('مفتاح Idempotency-Key غير صالح');
        }
        $st = $this->db()->prepare('SELECT method, path, response_status, response_body FROM idempotency_keys WHERE user_id = ? AND idem_key = ?');
        $st->execute([$user->id, $key]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        if ($row['method'] !== $request->method || $row['path'] !== $request->path) {
            throw new HttpException(422, 'مفتاح Idempotency-Key مستخدم لطلب مختلف', 'idempotency_mismatch');
        }
        $resp = Response::json(json_decode($row['response_body'], true), (int) $row['response_status']);
        $resp->headers['Idempotent-Replayed'] = 'true';
        return $resp;
    }

    private function idempotencyStore(Request $request, Response $response): void
    {
        $key = $request->header('idempotency-key');
        $user = $request->attributes['user'] ?? null;
        if ($key === null || !$user instanceof CurrentUser || !in_array($request->method, ['POST', 'PUT'], true) || $request->path === '/sync/push'
            || $response->status < 200 || $response->status >= 300 || $response->raw !== null || $response->filePath !== null) {
            return;
        }
        $this->db()->prepare('INSERT IGNORE INTO idempotency_keys (idem_key, user_id, method, path, response_status, response_body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$key, $user->id, $request->method, $request->path, $response->status, json_encode($response->data, JSON_UNESCAPED_UNICODE), $this->clock->nowDb()]);
    }

    public static function user(Request $request): CurrentUser
    {
        $u = $request->attributes['user'] ?? null;
        if (!$u instanceof CurrentUser) {
            throw HttpException::unauthorized();
        }
        return $u;
    }
}
