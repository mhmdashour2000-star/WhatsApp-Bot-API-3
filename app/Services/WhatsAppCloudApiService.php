<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudApiService
{
    protected ?string $token;
    protected ?string $phoneNumberId;
    protected string $apiVersion;

    public function __construct()
    {
        $this->token = config('services.whatsapp.token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->apiVersion = config('services.whatsapp.api_version', 'v21.0');
    }

    /**
     * Send plain text message in Arabic.
     */
    public function sendTextMessage(string $to, string $message): bool
    {
        if (empty($this->token) || empty($this->phoneNumberId)) {
            Log::info("[WhatsApp Simulated Send] To: {$to} | Message: {$message}");
            return true;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $response = Http::withToken($this->token)->post($url, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->cleanPhoneNumber($to),
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ]);

        if (!$response->successful()) {
            Log::error("[WhatsApp API Error] " . $response->body());
            return false;
        }

        return true;
    }

    /**
     * Clean phone number to E.164 digits without plus.
     */
    public function cleanPhoneNumber(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }
}
