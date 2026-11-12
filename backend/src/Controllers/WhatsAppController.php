<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Core\Validator;
use TurathManager\Services\WhatsAppService;

final class WhatsAppController extends Controller
{
    public function settings(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(WhatsAppService::class)->publicSettings()]);
    }

    public function save(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(WhatsAppService::class)->saveSettings($r->body, $this->user($r), $r)]);
    }

    public function test(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(WhatsAppService::class)->testConnection($this->user($r), $r)]);
    }

    public function syncTemplates(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(WhatsAppService::class)->syncTemplates($this->user($r))]);
    }

    /** إرسال رسالة اختبار فعلية إلى رقم مسموح؛ النتيجة تعكس رد Meta الحقيقي */
    public function sendTest(Request $r): Response
    {
        $to = Validator::normalizePhone((string) ($r->body['to'] ?? ''), $this->app->config['app']['default_country_code']);
        if ($to === null) {
            throw HttpException::validation(['to' => 'رقم غير صالح']);
        }
        $template = (string) ($r->body['template'] ?? 'hello_world');
        if (!preg_match('/^[a-z0-9_]+$/', $template)) {
            throw HttpException::validation(['template' => 'اسم قالب غير صالح']);
        }
        $lang = (string) ($r->body['language'] ?? ($template === 'hello_world' ? 'en_US' : 'ar'));
        $params = array_map('strval', (array) ($r->body['params'] ?? []));
        $res = $this->app->service(WhatsAppService::class)->sendTemplate($to, $template, $lang, $params);
        $this->app->service(\TurathManager\Support\Audit::class)->log($this->user($r)->id, 'whatsapp.test_message', 'whatsapp_settings', '1', ['ok' => $res['ok'], 'template' => $template], $r);
        unset($res['response']);
        return Response::json(['data' => $res], $res['ok'] ? 200 : 502);
    }

    public function verify(Request $r): Response
    {
        $challenge = $this->app->service(WhatsAppService::class)->verifyWebhook($r->query);
        if ($challenge === null) {
            throw HttpException::forbidden('رمز التحقق غير صحيح');
        }
        return new Response(200, null, ['Content-Type' => 'text/plain; charset=utf-8'], $challenge);
    }

    public function webhook(Request $r): Response
    {
        $ok = $this->app->service(WhatsAppService::class)->handleWebhook($r->rawBody, $r->header('x-hub-signature-256'));
        if (!$ok) {
            throw HttpException::forbidden('توقيع غير صالح');
        }
        return Response::json(['received' => true]);
    }
}
