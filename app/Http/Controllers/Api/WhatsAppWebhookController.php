<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use App\Models\WhatsappSession;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        protected WhatsAppFlowService $flowService
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
                    $messageId = $message['id'] ?? null;

                    // Load or create session for this phone number
                    $session = WhatsappSession::firstOrCreate(
                        ['phone_number' => $from],
                        ['current_state' => 'MAIN_MENU', 'state_payload' => []]
                    );

                    // If a message ID is present, ensure it is processed exactly once using a status column.
                    if ($messageId) {
                        $existing = DB::table('processed_whatsapp_messages')
                            ->where('message_id', $messageId)
                            ->first();

                        if ($existing) {
                            if ($existing->status === 'processing') {
                                // Another request is already handling this message.
                                continue;
                            }
                            if ($existing->status === 'processed') {
                                // Message has already been processed successfully.
                                continue;
                            }
                            // Status is 'failed' – allow a retry by marking it as processing again.
                            DB::table('processed_whatsapp_messages')
                                ->where('message_id', $messageId)
                                ->update([
                                    'status' => 'processing',
                                    'processed_at' => now(),
                                ]);
                        } else {
                            // No record yet – create one with processing status.
                            DB::table('processed_whatsapp_messages')
                                ->insert([
                                    'message_id' => $messageId,
                                    'status' => 'processing',
                                    'processed_at' => now(),
                                ]);
                        }
                    }

                    if ($from && $type === 'text') {
                        $textBody = $message['text']['body'] ?? '';
                        try {
                            $this->flowService->handle($from, $textBody);
                            // On success, mark the message as processed and store last_message_id.
                            if ($messageId) {
                                DB::table('processed_whatsapp_messages')
                                    ->where('message_id', $messageId)
                                    ->update([
                                        'status' => 'processed',
                                        'processed_at' => now(),
                                    ]);
                                $session->update(['last_message_id' => $messageId]);
                            }
                        } catch (\Throwable $e) {
                            Log::error('[WhatsApp Bot Error] ' . $e->getMessage(), [
                                'from' => $from,
                                'text' => $textBody,
                                'trace' => $e->getTraceAsString(),
                            ]);
                            // Mark processing as failed to allow future retry.
                            if ($messageId) {
                                DB::table('processed_whatsapp_messages')
                                    ->where('message_id', $messageId)
                                    ->update([
                                        'status' => 'failed',
                                        'processed_at' => now(),
                                    ]);
                            }
                        }
                    } elseif ($from && $type === 'interactive') {
                        $interactive = $message['interactive'] ?? [];
                        $subtype = $interactive['type'] ?? '';
                        $payloadId = '';
                        if ($subtype === 'button_reply') {
                            $payloadId = $interactive['button_reply']['id'] ?? '';
                        } elseif ($subtype === 'list_reply') {
                            $payloadId = $interactive['list_reply']['id'] ?? '';
                        }
                        if ($payloadId !== '') {
                            try {
                                $this->flowService->handle($from, $payloadId);
                                // On success, update processed status and last_message_id.
                                if ($messageId) {
                                    DB::table('processed_whatsapp_messages')
                                        ->where('message_id', $messageId)
                                        ->update([
                                            'status' => 'processed',
                                            'processed_at' => now(),
                                        ]);
                                    DB::table('whatsapp_sessions')
                                        ->where('phone_number', $from)
                                        ->update(['last_message_id' => $messageId]);
                                }
                            } catch (\Throwable $e) {
                                Log::error('[WhatsApp Bot Error] ' . $e->getMessage(), [
                                    'from' => $from,
                                    'interactive_id' => $payloadId,
                                    'trace' => $e->getTraceAsString(),
                                ]);
                                // Mark as failed to enable retry.
                                if ($messageId) {
                                    DB::table('processed_whatsapp_messages')
                                        ->where('message_id', $messageId)
                                        ->update([
                                            'status' => 'failed',
                                            'processed_at' => now(),
                                        ]);
                                }
                            }
                        }
                    }
                    
                }
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}
