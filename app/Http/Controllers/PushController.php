<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PushSubscription;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PushController extends Controller
{
    public function subscribe(Request $request)
    {
        $validated = $request->validate([
            'endpoint'        => 'required|string',
            'keys.p256dh'     => 'required|string',
            'keys.auth'       => 'required|string',
        ]);

        PushSubscription::updateOrCreate(
            [
                'user_id'  => auth()->id(),
                'endpoint' => $validated['endpoint'],
            ],
            [
                'p256dh' => $validated['keys']['p256dh'],
                'auth'   => $validated['keys']['auth'],
            ]
        );

        return response()->json(['success' => true]);
    }

    public function unsubscribe(Request $request)
    {
        PushSubscription::where('user_id', auth()->id())
            ->where('endpoint', $request->endpoint)
            ->delete();

        return response()->json(['success' => true]);
    }

    public static function sendToUser(int $userId, string $title, string $body, string $url = '/')
    {
        $subscriptions = PushSubscription::where('user_id', $userId)->get();
        if ($subscriptions->isEmpty()) return;

        $auth = [
            'VAPID' => [
                'subject'    => config('services.vapid.subject'),
                'publicKey'  => config('services.vapid.public_key'),
                'privateKey' => config('services.vapid.private_key'),
            ],
        ];

        $webPush = new WebPush($auth);
        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint'        => $sub->endpoint,
                    'keys'            => [
                        'p256dh' => $sub->p256dh,
                        'auth'   => $sub->auth,
                    ],
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                \Log::error('[Push] FAILED', [
                    'endpoint' => $report->getEndpoint(),
                    'reason'   => $report->getReason(),
                    'expired'  => $report->isSubscriptionExpired(),
                ]);
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint', $report->getEndpoint())->delete();
                }
            } else {
                \Log::info('[Push] Sent OK', ['endpoint' => substr($report->getEndpoint(), 0, 60)]);
            }
        }
    }
}
