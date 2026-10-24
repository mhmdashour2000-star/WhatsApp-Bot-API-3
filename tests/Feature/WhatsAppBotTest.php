<?php

namespace Tests\Feature;

use App\Models\ClinicSetting;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\WhatsappSession;
use Database\Seeders\ClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Config::set('services.whatsapp.app_secret', null);
        $this->seed(ClinicSeeder::class);
    }

    public function test_meta_webhook_challenge_verification(): void
    {
        $verifyToken = config('services.whatsapp.verify_token');

        $response = $this->get('/api/v1/whatsapp/webhook?' . http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => $verifyToken,
            'hub_challenge' => '1234567890',
        ]));

        $response->assertStatus(200);
        $this->assertEquals('1234567890', $response->getContent());
    }

    public function test_new_user_initiates_kvkk_consent_flow(): void
    {
        $payload = [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'messages' => [
                                    [
                                        'from' => '905559876543',
                                        'type' => 'text',
                                        'text' => ['body' => 'مرحبا'],
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/whatsapp/webhook', $payload);
        $this->assertEquals(200, $response->status());

        // Session created in MAIN_MENU
        $session = WhatsappSession::where('phone_number', '905559876543')->first();
        $this->assertNotNull($session);
        $this->assertEquals('MAIN_MENU', $session->current_state);

        // Send '1' to book
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] = '1';
        $this->postJson('/api/v1/whatsapp/webhook', $payload);

        $session->refresh();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session->current_state);
    }
}
