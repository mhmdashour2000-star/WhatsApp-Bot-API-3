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
     * Send interactive quick reply button message (up to 3 buttons).
     * WhatsApp Cloud API limits:
     * - buttons: maximum 3 buttons
     * - button id: maximum 256 characters
     * - button title: maximum 20 characters
     * - body text: maximum 1024 characters
     * - header text: maximum 60 characters
     * - footer text: maximum 60 characters
     *
     * @param array<int, array{id: string, title: string}> $buttons
     */
    public function sendButtonMessage(string $to, string $bodyText, array $buttons, ?string $header = null, ?string $footer = null): bool
    {
        $actionButtons = [];
        foreach (array_slice($buttons, 0, 3) as $btn) {
    // Accept both flat buttons and WhatsApp-style nested reply buttons.
    $reply = $btn['reply'] ?? $btn;

    if (!isset($reply['id'], $reply['title'])) {
        throw new \InvalidArgumentException(
            'Each button must contain an id and title.'
        );
    }

    $actionButtons[] = [
        'type' => 'reply',
        'reply' => [
            'id' => (string) mb_substr((string) $reply['id'], 0, 256, 'UTF-8'),
            'title' => (string) mb_substr((string) $reply['title'], 0, 20, 'UTF-8'),
        ],
    ];
}

        $interactive = [
            'type' => 'button',
            'body' => ['text' => (string) mb_substr($bodyText, 0, 1024, 'UTF-8')],
            'action' => ['buttons' => $actionButtons],
        ];

        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => (string) mb_substr($header, 0, 60, 'UTF-8')];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => (string) mb_substr($footer, 0, 60, 'UTF-8')];
        }

        return $this->sendInteractivePayload($to, $interactive);
    }

    /**
     * Send interactive list message.
     * WhatsApp Cloud API limits:
     * - list button label: maximum 20 characters
     * - total rows across all sections: maximum 10 rows
     * - row id: maximum 200 characters
     * - row title: maximum 24 characters
     * - row description: maximum 72 characters
     *
     * @param array<int, array{title: string, rows: array<int, array{id: string, title: string, description?: string}>}> $sections
     */
    public function sendListMessage(string $to, string $bodyText, string $buttonText, array $sections, ?string $header = null, ?string $footer = null): bool
    {
        $totalRows = 0;
        $sanitizedSections = [];

        foreach ($sections as $section) {
            $sectionRows = [];
            foreach ($section['rows'] ?? [] as $row) {
                if ($totalRows >= 10) {
                    break 2;
                }
                $formattedRow = [
                    'id' => (string) mb_substr($row['id'], 0, 200, 'UTF-8'),
                    'title' => (string) mb_substr($row['title'], 0, 24, 'UTF-8'),
                ];
                if (!empty($row['description'])) {
                    $formattedRow['description'] = (string) mb_substr($row['description'], 0, 72, 'UTF-8');
                }
                $sectionRows[] = $formattedRow;
                $totalRows++;
            }

            if (!empty($sectionRows)) {
                $sanitizedSections[] = [
                    'title' => (string) mb_substr($section['title'] ?? 'خيارات', 0, 24, 'UTF-8'),
                    'rows' => $sectionRows,
                ];
            }
        }

        $interactive = [
            'type' => 'list',
            'body' => ['text' => (string) mb_substr($bodyText, 0, 1024, 'UTF-8')],
            'action' => [
                'button' => (string) mb_substr($buttonText, 0, 20, 'UTF-8'),
                'sections' => $sanitizedSections,
            ],
        ];

        if ($header) {
            $interactive['header'] = ['type' => 'text', 'text' => (string) mb_substr($header, 0, 60, 'UTF-8')];
        }
        if ($footer) {
            $interactive['footer'] = ['text' => (string) mb_substr($footer, 0, 60, 'UTF-8')];
        }

        return $this->sendInteractivePayload($to, $interactive);
    }

    /**
     * Helper to send interactive payload to WhatsApp Cloud API.
     */
    protected function sendInteractivePayload(string $to, array $interactive): bool
    {
        if (empty($this->token) || empty($this->phoneNumberId)) {
            Log::info("[WhatsApp Simulated Interactive Send] To: {$to} | Payload: " . json_encode($interactive, JSON_UNESCAPED_UNICODE));
            return true;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $response = Http::withToken($this->token)->post($url, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->cleanPhoneNumber($to),
            'type' => 'interactive',
            'interactive' => $interactive,
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
