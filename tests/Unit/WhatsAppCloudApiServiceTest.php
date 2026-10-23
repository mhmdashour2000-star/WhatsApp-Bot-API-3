<?php

namespace Tests\Unit;

use App\Services\WhatsAppCloudApiService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCloudApiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.whatsapp.token', 'test_fake_token_123');
        Config::set('services.whatsapp.phone_number_id', '123456789');
        Config::set('services.whatsapp.api_version', 'v21.0');
    }

    public function test_send_text_message_structure(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.HBg...']]], 200),
        ]);

        $service = new WhatsAppCloudApiService();
        $result = $service->sendTextMessage('+90 555 123 4567', 'مرحباً بك');

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return $request->url() === 'https://graph.facebook.com/v21.0/123456789/messages'
                && $data['messaging_product'] === 'whatsapp'
                && $data['recipient_type'] === 'individual'
                && $data['to'] === '905551234567'
                && $data['type'] === 'text'
                && $data['text']['body'] === 'مرحباً بك';
        });
    }

    public function test_send_button_message_structure_and_limits(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.HBg...']]], 200),
        ]);

        $service = new WhatsAppCloudApiService();
        $buttons = [
            ['id' => 'btn_1', 'title' => 'حجز موعد جديد'],
            ['id' => 'btn_2', 'title' => 'استعراض مواعيدي'],
            ['id' => 'btn_3', 'title' => 'إلغاء موعد قائم'],
            ['id' => 'btn_4_ignored', 'title' => 'زر إضافي'], // Should be sliced to max 3
        ];

        $result = $service->sendButtonMessage(
            '905551234567',
            'اختر الخدمة المطلوبة:',
            $buttons,
            'عيادة الشفاء',
            'قائمة الخدمات'
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $interactive = $data['interactive'];
            $actionButtons = $interactive['action']['buttons'];

            return $data['type'] === 'interactive'
                && $interactive['type'] === 'button'
                && $interactive['body']['text'] === 'اختر الخدمة المطلوبة:'
                && $interactive['header']['text'] === 'عيادة الشفاء'
                && $interactive['footer']['text'] === 'قائمة الخدمات'
                && count($actionButtons) === 3
                && $actionButtons[0]['reply']['id'] === 'btn_1'
                && $actionButtons[0]['reply']['title'] === 'حجز موعد جديد'
                && $actionButtons[2]['reply']['id'] === 'btn_3';
        });
    }

    public function test_send_list_message_structure_and_limits(): void
    {
        Http::fake([
            'https://graph.facebook.com/v21.0/123456789/messages' => Http::response(['messages' => [['id' => 'wamid.HBg...']]], 200),
        ]);

        $service = new WhatsAppCloudApiService();
        $sections = [
            [
                'title' => 'التخصصات المتاحة',
                'rows' => [
                    ['id' => 'spec_1', 'title' => 'الطب العام', 'description' => 'كشف عام وباطنية'],
                    ['id' => 'spec_2', 'title' => 'طب الأسنان', 'description' => 'علاج وتجميل'],
                ],
            ],
        ];

        $result = $service->sendListMessage(
            '905551234567',
            'يرجى اختيار التخصص الطبي:',
            'عرض التخصصات',
            $sections,
            'العيادة التخصصية',
            'اختر من القائمة'
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $interactive = $data['interactive'];
            $sections = $interactive['action']['sections'];

            return $data['type'] === 'interactive'
                && $interactive['type'] === 'list'
                && $interactive['body']['text'] === 'يرجى اختيار التخصص الطبي:'
                && $interactive['action']['button'] === 'عرض التخصصات'
                && count($sections) === 1
                && count($sections[0]['rows']) === 2
                && $sections[0]['rows'][0]['id'] === 'spec_1'
                && $sections[0]['rows'][0]['title'] === 'الطب العام'
                && $sections[0]['rows'][0]['description'] === 'كشف عام وباطنية';
        });
    }
}
