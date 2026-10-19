<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppBotConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppBotConversationService $botService
    ) {}

    /**
     * Meta Webhook Verification (GET request with hub_challenge).
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expectedVerifyToken = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $expectedVerifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Unauthorized', 403);
    }

    /**
     * Handle incoming WhatsApp Webhook Events (POST).
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Signature Verification (if APP_SECRET configured)
        $appSecret = config('services.whatsapp.app_secret');
        if (!empty($appSecret)) {
            $signature = $request->header('X-Hub-Signature-256');
            if (!$signature) {
                return response()->json(['error' => 'Missing signature'], 401);
            }

            $expectedSig = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);
            if (!hash_equals($expectedSig, $signature)) {
                Log::warning('[WhatsApp Webhook] Invalid Signature');
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->all();

        // 2. Extract incoming text messages
        $entries = $payload['entry'] ?? [];
        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];

                foreach ($messages as $message) {
                    $from = $message['from'] ?? null;
                    $type = $message['type'] ?? null;

                    if ($from && $type === 'text') {
                        $textBody = $message['text']['body'] ?? '';
                        try {
                            $this->botService->handleIncomingMessage($from, $textBody);
                        } catch (\Throwable $e) {
                            Log::error('[WhatsApp Bot Error] ' . $e->getMessage(), [
                                'from' => $from,
                                'text' => $textBody,
                                'trace' => $e->getTraceAsString(),
                            ]);
                        }
                    }
                }
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}
