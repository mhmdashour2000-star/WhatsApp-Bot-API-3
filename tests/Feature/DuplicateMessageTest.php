<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\WhatsappSession;

class DuplicateMessageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_process_the_same_whatsapp_message_twice()
    {
        $this->withoutMiddleware();
        Config::set('services.whatsapp.app_secret', null);
        // Prepare a fake webhook payload (simplified) with a unique message id
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.HBgMNTU5...123',
                            'from' => '1234567890',
                            'type' => 'text',
                            'text' => ['body' => 'حجز موعد'],
                        ]],
                    ],
                ]],
            ]],
        ];

        // First request should be processed and create a session record
        $response1 = $this->postJson('/api/v1/whatsapp/webhook', $payload);
        $response1->assertStatus(200);
        $this->assertDatabaseHas('whatsapp_sessions', [
            'phone_number' => '1234567890',
            'last_message_id' => 'wamid.HBgMNTU5...123',
        ]);

        // Second request with the same message id should be ignored
        $response2 = $this->postJson('/api/v1/whatsapp/webhook', $payload);
        $response2->assertStatus(200);

        // Ensure that no additional session rows were created (still only one)
        $this->assertEquals(1, WhatsappSession::where('phone_number', '1234567890')->count());
        // Verify session state unchanged after duplicate
        $sessionDup = WhatsappSession::where('phone_number', '1234567890')->first();
        $this->assertEquals('MAIN_MENU', $sessionDup->current_state);
        $this->assertEquals('wamid.HBgMNTU5...123', $sessionDup->last_message_id);

        // New distinct message should advance the flow
        $payload2 = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'id' => 'wamid.HBgMNTU5...124',
                            'from' => '1234567890',
                            'type' => 'text',
                            'text' => ['body' => '1'], // choose option 1 from main menu
                        ]],
                    ],
                ]],
            ]],
        ];
        $response2b = $this->postJson('/api/v1/whatsapp/webhook', $payload2);
        $response2b->assertStatus(200);
        $session2 = WhatsappSession::where('phone_number', '1234567890')->first();
        $this->assertEquals('AWAITING_KVKK_CONSENT', $session2->current_state);
        $this->assertEquals('wamid.HBgMNTU5...124', $session2->last_message_id);
    }
}
