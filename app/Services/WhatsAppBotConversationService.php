<?php

namespace App\Services;

class WhatsAppBotConversationService
{
    protected WhatsAppFlowService $flowService;

    public function __construct(
        protected WhatsAppCloudApiService $apiService,
        protected AppointmentBookingService $bookingService,
        ?WhatsAppFlowService $flowService = null
    ) {
        $this->flowService = $flowService ?? new WhatsAppFlowService($apiService, $bookingService);
    }

    /**
     * Process incoming message from patient phone number.
     */
    public function handleIncomingMessage(string $fromPhone, string $messageBody): void
    {
        $this->flowService->handle($fromPhone, $messageBody);
    }
}
