<?php

namespace App\Http\Controllers\Api\Debug;

use App\Http\Controllers\Controller;
use App\Support\DebugPanel;
use App\Support\EmailSwitch;
use App\Support\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The Debug page's Health section.
 *
 *   GET  debug/health            every check, each ok / warn / fail, with when
 *                                its evidence is from (App\Support\SystemHealth)
 *   POST debug/health/test-mail  one plain e-mail to the signed-in super
 *                                admin's own address, to prove mail delivers
 *
 * Reading changes nothing, so it is not audited. The test e-mail is, as
 * `debug.health.test_mail`: it is the one thing here that leaves the server.
 */
class HealthController extends Controller
{
    public function show(SystemHealth $health): JsonResponse
    {
        return response()->json(['data' => [
            'checked_at' => now()->toIso8601String(),
            'checks' => $health->checks(),
        ]]);
    }

    public function testMail(Request $request): JsonResponse
    {
        $user = $request->user();
        $mailer = (string) config('mail.default');

        try {
            // Sent now, not queued: the button is asking whether mail works
            // this minute, and a queued message would only prove the queue.
            Mail::mailer($mailer)->raw(
                "This is a test e-mail from BizTrack's Debug page, sent at ".now()->setTimezone('Asia/Manila')->format('g:i A, F j')
                .". If it reached you, this server's mail is working.",
                fn ($message) => $message->to($user->email)->subject('BizTrack test e-mail'),
            );
            $result = EmailSwitch::on()
                ? ['ok' => true, 'message' => "Sent to {$user->email}. Check that inbox (and its spam folder)."]
                : ['ok' => false, 'message' => "Not sent: the mail driver is {$mailer}, so it went to ".($mailer === 'log' ? 'the log' : 'nowhere').' instead of to an inbox.'];
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => 'The mail server refused it: '.Str::limit($e->getMessage(), 200)];
        }

        DebugPanel::audit('health.test_mail', [], ['to' => $user->email, 'mailer' => $mailer, 'ok' => $result['ok']], actorId: $user->id);

        return response()->json(['data' => $result]);
    }
}
