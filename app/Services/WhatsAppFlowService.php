<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\WhatsappSession;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fresh implementation of the WhatsApp conversation flow.
 * Mirrors the original WhatsAppBotConversationService but is isolated under a new class name.
 */
class WhatsAppFlowService
{
    public function __construct(
        protected WhatsAppCloudApiService $apiService,
        protected AppointmentBookingService $bookingService
    ) {}

    /**
     * Entry point for incoming messages.
     */
    public function handle(string $fromPhone, string $messageBody): void
    {
        $cleanPhone = $this->apiService->cleanPhoneNumber($fromPhone);
        $text = trim($messageBody);

        // Load or create a session
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $cleanPhone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );
        // Initialize payload for the session
        $payload = $session->state_payload ?? [];

        // Session timeout – reset after 15 minutes of inactivity
        if ($session->last_interaction_at && Carbon::parse($session->last_interaction_at)->diffInMinutes(Carbon::now()) > 15) {
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }



        // Show main menu immediately on first interaction (no previous state payload)
        if ($session->current_state === 'MAIN_MENU' && empty($payload) && $session->last_interaction_at === null) {
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }

        $state = $session->current_state;

        // Update interaction timestamp for active session
        $session->update(['last_interaction_at' => Carbon::now()]);
        // Global flow cancel/restart via button IDs and common keywords
        if (in_array(strtolower($text), ['flow_cancel', 'flow_restart', '0', 'الغاء', 'إلغاء', 'خروج', 'menu', 'قائمة', 'ابدأ'])) {
            // If there's a holding appointment, cancel it safely
            if (!empty($payload['appointment_id'])) {
                $holding = Appointment::find($payload['appointment_id']);
                if ($holding && $holding->status === 'holding') {
                    $holding->update(['status' => 'cancelled_by_clinic', 'cancellation_reason' => 'Flow cancelled by user', 'cancelled_at' => Carbon::now()]);
                }
            }
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }
        switch ($state) {
            case 'MAIN_MENU':
                $this->handleMainMenu($session, $cleanPhone, $text);
                break;
            case 'AWAITING_KVKK_CONSENT':
                $this->handleKvkkConsent($session, $cleanPhone, $text, $payload);
                break;
            case 'AWAITING_NEW_PATIENT_NAME':
                $this->handleNewPatientName($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_PATIENT':
                $this->handleSelectPatient($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_SPECIALTY':
                $this->handleSelectSpecialty($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_DOCTOR':
                $this->handleSelectDoctor($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_DATE':
                $this->handleSelectDate($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_SLOT':
                $this->handleSelectSlot($session, $cleanPhone, $text, $payload);
                break;
            case 'CONFIRMING_BOOKING':
                $this->handleConfirmBooking($session, $cleanPhone, $text, $payload);
                break;
            case 'SELECTING_CANCEL_APPT':
                $this->handleSelectCancelAppointment($session, $cleanPhone, $text, $payload);
                break;
            case 'CONFIRMING_CANCELLATION':
                $this->handleConfirmCancellation($session, $cleanPhone, $text, $payload);
                break;
            default:
                $this->resetToMainMenu($session, $cleanPhone);
        }
    }

    // ---------------------------------------------------------------------
    // Helper methods – copied verbatim from the original service
    // ---------------------------------------------------------------------

    protected function resetToMainMenu(WhatsappSession $session, string $phone): void
    {
        $clinicName = ClinicSetting::get('clinic_name', 'عيادة الشفاء التخصصية');
        $session->update([
            'current_state' => 'MAIN_MENU',
            'state_payload' => [],
            'last_interaction_at' => Carbon::now(),
        ]);

        $buttons = [
            ['type' => 'reply', 'reply' => ['id' => 'main_menu_book', 'title' => '📅 حجز موعد جديد']],
            ['type' => 'reply', 'reply' => ['id' => 'main_menu_view', 'title' => '👀 استعراض مواعيدي القادمة']],
            ['type' => 'reply', 'reply' => ['id' => 'main_menu_cancel', 'title' => '❌ إلغاء موعد قائم']],
        ];
        $header = "أهلاً بك في {$clinicName} 🏥";
        $body = "يرجى اختيار الخدمة المطلوبة:";
        $this->apiService->sendButtonMessage($phone, $body, $buttons, $header);
    }

    protected function handleMainMenu(WhatsappSession $session, string $phone, string $choice): void
    {
        if (in_array($choice, ['1', 'main_menu_book'])) {
            $this->initiateBookingFlow($session, $phone);
        } elseif (in_array($choice, ['2', 'main_menu_view'])) {
            $this->showUpcomingAppointments($session, $phone);
        } elseif (in_array($choice, ['3', 'main_menu_cancel'])) {
            $this->initiateCancellationFlow($session, $phone);
        } else {
            $this->apiService->sendTextMessage($phone, "عذراً، الخيار غير صحيح. يرجى إرسال 1 لحجز موعد، أو 2 لاستعراض المواعيد، أو 3 للإلغاء.");
        }
    }

    protected function initiateBookingFlow(WhatsappSession $session, string $phone): void
    {
        $patients = Patient::where('primary_phone', $phone)->get();
        if ($patients->isEmpty()) {
            $session->update([
                'current_state' => 'AWAITING_KVKK_CONSENT',
                'state_payload' => [],
            ]);
            $this->sendKvkkConsentMessage($session, $phone, []);
            return;
        }

        if ($patients->count() === 1) {
            $patient = $patients->first();
            $session->update([
                'current_state' => 'SELECTING_PATIENT',
                'state_payload' => ['patient_id' => $patient->id, 'single_existing' => true],
            ]);
            $msg = "مرحباً بك مجدداً!\n" .
                "هل ترغب بالحجز للمريض:\n" .
                "👤 *{$patient->full_name}*\n\n" .
                "1️⃣ متابعة الحجز بهذا الاسم\n" .
                "2️⃣ حجز لشخص آخر برقمك (فرد من العائلة)";
            $this->apiService->sendTextMessage($phone, $msg);
            return;
        }

        $session->update([
            'current_state' => 'SELECTING_PATIENT',
            'state_payload' => ['patients_list' => $patients->pluck('id')->toArray()],
        ]);

        $rows = [];
        foreach ($patients as $p) {
            $rows[] = ['id' => 'patient_' . $p->id, 'title' => $p->full_name];
        }
        $rows[] = ['id' => 'new_patient', 'title' => 'إضافة مريض جديد'];
        $sections = [['title' => 'المرضى', 'rows' => $rows]];
        $body = "اختر المريض من القائمة:";
        $buttonText = "اختر المريض";
        $this->apiService->sendListMessage($phone, $body, $buttonText, $sections);
    }

    protected function handleKvkkConsent(WhatsappSession $session, string $phone, string $text, array $payload): void
    {
        if ($text === '1' || $text === 'kvkk_yes') {
            $payload = array_merge($payload, ['kvkk_accepted' => true]);
            if (isset($payload['patient_id'])) {
                $patient = Patient::find($payload['patient_id']);
                if ($patient && $patient->is_blocked) {
                    $this->apiService->sendTextMessage($phone, "عذراً، لا يمكن حجز موعد لأن حساب المريض محظور. يرجى التواصل مع العيادة.");
                    $this->resetToMainMenu($session, $phone);
                    return;
                }
                $session->update([
                    'current_state' => 'SELECTING_SPECIALTY',
                    'state_payload' => $payload,
                ]);
                $this->displaySpecialties($phone, $session, $payload['patient_id']);
            } else {
                $session->update([
                    'current_state' => 'AWAITING_NEW_PATIENT_NAME',
                    'state_payload' => $payload,
                ]);
                $this->apiService->sendTextMessage($phone, "شكراً لموافقتك. يرجى الآن كتابة *الاسم الثلاثي* للمريض:");
            }
        } else {
            $this->apiService->sendTextMessage($phone, "تم إلغاء العملية بناءً على رغبتك. يمكنك بدء المحادثة في أي وقت.");
            $this->resetToMainMenu($session, $phone);
        }
    }

    protected function handleNewPatientName(WhatsappSession $session, string $phone, string $name, array $payload): void
    {
        if (strlen($name) < 3) {
            $this->apiService->sendTextMessage($phone, "يرجى كتابة الاسم بشكل صحيح (3 أحرف على الأقل):");
            return;
        }
        $patient = Patient::create([
            'primary_phone' => $phone,
            'full_name' => $name,
            'kvkk_consent_accepted' => true,
            'kvkk_consent_date' => Carbon::now(),
        ]);
        $session->update([
            'current_state' => 'SELECTING_SPECIALTY',
            'state_payload' => ['patient_id' => $patient->id],
        ]);
        $this->displaySpecialties($phone, $session, $patient->id);
    }

    protected function handleSelectPatient(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        // If a single existing patient was pre‑selected, we need to let the user choose between using it or adding a new one
        if (!empty($payload['single_existing'])) {
            if ($choice === '1' || $choice === 'use_existing') {
                $patientId = $payload['patient_id'];
                $session->update([
                    'current_state' => 'AWAITING_KVKK_CONSENT',
                    'state_payload' => ['patient_id' => $patientId],
                ]);
                $this->sendKvkkConsentMessage($session, $phone, ['patient_id' => $patientId]);
                return;
            }
            if ($choice === '2' || $choice === 'new_patient') {
                $session->update([
                    'current_state' => 'AWAITING_NEW_PATIENT_NAME',
                    'state_payload' => [],
                ]);
                $this->apiService->sendTextMessage($phone, "يرجى كتابة الاسم الثلاثي للشخص الجديد:");
                return;
            }
            $this->apiService->sendTextMessage($phone, "إدخال غير صالح. الرجاء اختيار 1 أو 2.");
            return;
        }

        $patientId = $payload['patient_id'] ?? null;
        if ($patientId) {
            $session->update([
                'current_state' => 'AWAITING_KVKK_CONSENT',
                'state_payload' => ['patient_id' => $patientId],
            ]);
            $this->sendKvkkConsentMessage($session, $phone, ['patient_id' => $patientId]);
            return;
        }

        if (Str::startsWith($choice, 'patient_')) {
            $selectedId = (int) substr($choice, 8);
            $session->update([
                'current_state' => 'AWAITING_KVKK_CONSENT',
                'state_payload' => ['patient_id' => $selectedId],
            ]);
            $this->sendKvkkConsentMessage($session, $phone, ['patient_id' => $selectedId]);
            return;
        }
        if ($choice === 'new_patient') {
            $session->update([
                'current_state' => 'AWAITING_NEW_PATIENT_NAME',
                'state_payload' => [],
            ]);
            $this->apiService->sendTextMessage($phone, "يرجى كتابة الاسم الثلاثي للشخص الجديد:");
            return;
        }

        $patientIds = $payload['patients_list'] ?? [];
        $index = ((int) $choice) - 1;
        if ($index >= 0 && $index < count($patientIds)) {
            $selectedId = $patientIds[$index];
            $session->update([
                'current_state' => 'AWAITING_KVKK_CONSENT',
                'state_payload' => ['patient_id' => $selectedId],
            ]);
            $this->sendKvkkConsentMessage($session, $phone, ['patient_id' => $selectedId]);
        } elseif ($index === count($patientIds)) {
            $session->update([
                'current_state' => 'AWAITING_NEW_PATIENT_NAME',
                'state_payload' => [],
            ]);
            $this->apiService->sendTextMessage($phone, "يرجى كتابة الاسم الثلاثي للشخص الجديد:");
        } else {
            $this->apiService->sendTextMessage($phone, "رقم غير صحيح، يرجى اختيار أحد الأرقام الموضحة أعلاه.");
        }
    }

    protected function displaySpecialties(string $phone, WhatsappSession $session, int $patientId): void
    {
        $specialties = Specialty::where('is_active', true)->get();
        if ($specialties->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "عذراً، لا توجد تخصصات متاحة للحجز حالياً.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        $session->update([
            'current_state' => 'SELECTING_SPECIALTY',
            'state_payload' => [
                'patient_id' => $patientId,
                'specialty_ids' => $specialties->pluck('id')->toArray(),
            ],
        ]);
        $rows = [];
        foreach ($specialties as $spec) {
            $rows[] = ['id' => 'specialty_' . $spec->id, 'title' => $spec->name];
        }
        $sections = [['title' => 'التخصصات', 'rows' => $rows]];
        $body = "اختر التخصص من القائمة:";
        $buttonText = "اختر التخصص";
        $this->apiService->sendListMessage($phone, $body, $buttonText, $sections);
    }

    protected function handleSelectSpecialty(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $specialtyIds = $payload['specialty_ids'] ?? [];
        if (Str::startsWith($choice, 'specialty_')) {
            $selectedId = (int) substr($choice, 10);
            $index = array_search($selectedId, $specialtyIds);
        } else {
            $index = ((int) $choice) - 1;
        }
        if (!isset($specialtyIds[$index])) {
            $this->apiService->sendTextMessage($phone, "اختيار غير صحيح. يرجى اختيار رقم من القائمة.");
            return;
        }
        $specialtyId = $specialtyIds[$index];
        $doctors = Doctor::where('specialty_id', $specialtyId)->where('is_active', true)->get();
        if ($doctors->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "عذراً، لا يوجد أطباء متاحين في هذا التخصص حالياً.");
            $this->displaySpecialties($phone, $session, $payload['patient_id']);
            return;
        }
        $session->update([
            'current_state' => 'SELECTING_DOCTOR',
            'state_payload' => array_merge($payload, [
                'specialty_id' => $specialtyId,
                'doctor_ids' => $doctors->pluck('id')->toArray(),
            ]),
        ]);
        $rows = [];
        foreach ($doctors as $doc) {
            $title = $doc->title ? "({$doc->title})" : '';
            $rows[] = ['id' => 'doctor_' . $doc->id, 'title' => trim("{$doc->full_name} {$title}")];
        }
        $sections = [['title' => 'الأطباء', 'rows' => $rows]];
        $body = "اختر الطبيب من القائمة:";
        $buttonText = "اختر الطبيب";
        $this->apiService->sendListMessage($phone, $body, $buttonText, $sections);
    }

    protected function handleSelectDoctor(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $doctorIds = $payload['doctor_ids'] ?? [];
        if (Str::startsWith($choice, 'doctor_')) {
            $selectedId = (int) substr($choice, 7);
            $index = array_search($selectedId, $doctorIds);
        } else {
            $index = ((int) $choice) - 1;
        }
        if (!isset($doctorIds[$index])) {
            $this->apiService->sendTextMessage($phone, "اختيار غير صحيح. يرجى اختيار رقم الطبيب من القائمة.");
            return;
        }
        $doctorId = $doctorIds[$index];
        $doctor = Doctor::find($doctorId);
        $availableDates = $this->bookingService->getAvailableDates($doctor, 10);
        if ($availableDates->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "عذراً، لا توجد مواعيد متاحة لهذا الطبيب خلال الأيام القادمة.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        $session->update([
            'current_state' => 'SELECTING_DATE',
            'state_payload' => array_merge($payload, [
                'doctor_id' => $doctorId,
                'available_dates' => $availableDates->toArray(),
            ]),
        ]);
        $rows = [];
        foreach ($availableDates as $d) {
            $rows[] = ['id' => 'date_' . $d['date'], 'title' => "{$d['day_name']} ({$d['date']})"];
        }
        $sections = [['title' => 'التواريخ المتاحة', 'rows' => $rows]];
        $body = "اختر التاريخ المناسب للموعد:";
        $buttonText = "اختر التاريخ";
        $this->apiService->sendListMessage($phone, $body, $buttonText, $sections);
    }

    protected function handleSelectDate(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $availableDates = $payload['available_dates'] ?? [];
        if (Str::startsWith($choice, 'date_')) {
            $selectedDate = substr($choice, 5);
        } else {
            $index = ((int) $choice) - 1;
            if (!isset($availableDates[$index])) {
                $this->apiService->sendTextMessage($phone, "تاريخ غير صحيح. يرجى اختيار رقم اليوم من القائمة.");
                return;
            }
            $selectedDate = $availableDates[$index]['date'];
        }
        $doctor = Doctor::find($payload['doctor_id']);
        $slots = $this->bookingService->getAvailableSlots($doctor, $selectedDate);
        if ($slots->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "عذراً، حُجزت جميع مواعيد هذا اليوم. يرجى اختيار تاريخ آخر.");
            return;
        }
        $session->update([
            'current_state' => 'SELECTING_SLOT',
            'state_payload' => array_merge($payload, [
                'selected_date' => $selectedDate,
                'available_slots' => $slots->toArray(),
            ]),
        ]);
        $rows = [];
        foreach ($slots as $idx => $s) {
            $rows[] = ['id' => 'slot_' . $idx, 'title' => $s['display']];
        }
        $sections = [['title' => 'الأوقات المتاحة', 'rows' => $rows]];
        $body = "اختر الوقت المناسب ليوم {$selectedDate}:";
        $buttonText = "اختر الوقت";
        $this->apiService->sendListMessage($phone, $body, $buttonText, $sections);
    }

    protected function handleSelectSlot(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $slots = $payload['available_slots'] ?? [];
        if (Str::startsWith($choice, 'slot_')) {
            $index = (int) substr($choice, 5);
        } else {
            $index = ((int) $choice) - 1;
        }
        if (!isset($slots[$index])) {
            $this->apiService->sendTextMessage($phone, "الوقت المختار غير صحيح. أرسل رقم الوقت من القائمة أعلاه.");
            return;
        }
        if (!isset($slots[$index])) {
            $this->apiService->sendTextMessage($phone, "الوقت المختار غير صالح. يرجى اختيار وقت من القائمة عبر الزر.");
            return;
        }
        $slot = $slots[$index];
        $doctor = Doctor::find($payload['doctor_id']);
        $patient = Patient::find($payload['patient_id']);
        // Ensure the slot identifier exists and is valid
        if (!isset($payload['available_slots'][$index])) {
            $this->apiService->sendTextMessage($phone, "الوقت المختار غير موجود. أعد اختيار الوقت.");
            return;
        }
        try {
            // Hold the selected slot for the patient
            $holdingAppt = $this->bookingService->holdSlot(
                $patient,
                $doctor,
                $payload['selected_date'],
                $slot['start_time'],
                $slot['end_time'],
                'whatsapp'
            );
            $session->update([
                'current_state' => 'CONFIRMING_BOOKING',
                'state_payload' => array_merge($payload, [
                    'appointment_id' => $holdingAppt->id,
                    'slot_display' => $slot['display'],
                ]),
            ]);
            $holdMin = ClinicSetting::get('slot_holding_minutes', 10);
            $msg = "⏳ تفاصيل الموعد المقترح:\n" .
                "👤 المريض: *{$patient->full_name}*\n" .
                "👨‍⚕️ الطبيب: *{$doctor->full_name}*\n" .
                "📅 التاريخ: *{$payload['selected_date']}*\n" .
                "⏰ الوقت: *{$slot['display']}*\n\n" .
                "⚠️ الموعد محجوز لك مؤقتاً لمدة ({$holdMin}) دقائق.\n" .
                "لتأكيد الحجز نهائياً أرسل:\n✅ نعم\n❌ لا";
            $buttons = [
                ['type' => 'reply', 'reply' => ['id' => 'confirm_yes', 'title' => '✅ نعم']],
                ['type' => 'reply', 'reply' => ['id' => 'confirm_no', 'title' => '❌ لا']],
            ];
            // Slots list is sent as a button message; the $msg is the body text
            $this->apiService->sendButtonMessage($phone, $msg, $buttons); // correct order already matches signature
        } catch (Throwable $e) {
            $this->apiService->sendTextMessage($phone, "⚠️ " . $e->getMessage());
            $this->handleSelectDate($session, $phone, ((int) array_search($payload['selected_date'], array_column($payload['available_dates'], 'date'))) + 1, $payload);
        }
    }

    protected function handleConfirmBooking(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $apptId = $payload['appointment_id'] ?? null;
        $appointment = Appointment::find($apptId);
        if (!$appointment) {
            $this->apiService->sendTextMessage($phone, "حدث خطأ في استرجاع الموعد. يرجى البدء من جديد.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        // Verify that the holding appointment belongs to this session's patient and doctor
        if ($appointment->status !== 'holding') {
            $this->apiService->sendTextMessage($phone, "الموعد لا يُعد في حالة حجز مؤقتة. يرجى البدء من جديد.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        if ($choice === '1' || $choice === 'confirm_yes') {
            try {
                $doctor = Doctor::find($payload['doctor_id']);
                $slot = $payload['slot_display'];
                $availableSlots = $this->bookingService->getAvailableSlots($doctor, $payload['selected_date']);
                $slotStillAvailable = collect($availableSlots)->contains(fn($s) => $s['display'] === $slot);
                if (!$slotStillAvailable) {
                    $this->handleSelectDate($session, $phone, (string) array_search($payload['selected_date'], array_column($payload['available_dates'], 'date')) + 1, $payload);
                    return;
                }
                $confirmed = $this->bookingService->confirmAppointment($appointment);
                if (!empty($payload['kvkk_accepted'])) {
                    $confirmed->update([
                        'kvkk_consent_accepted' => true,
                        'kvkk_consent_date' => Carbon::now(),
                    ]);
                }
                $msg = "✅ *تم تأكيد حجز موعدك بنجاح!*\n\n" .
                    "🏷️ رقم الحجز المرجعي: *{$confirmed->reference_code}*\n" .
                    "👤 المريض: {$confirmed->patient->full_name}\n" .
                    "👨‍⚕️ الطبيب: {$confirmed->doctor->full_name}\n" .
                    "📅 التاريخ: {$confirmed->appointment_date->format('Y-m-d')}\n" .
                    "⏰ الوقت: {$payload['slot_display']}\n\n" .
                    "📍 يرجى الحضور قبل الموعد بـ 10 دقائق.\n" .
                    "نتمنى لكم دوام الصحة والعافية! 🌸";
                $this->apiService->sendTextMessage($phone, $msg);
                $this->resetToMainMenu($session, $phone);
            } catch (Throwable $e) {
                $this->apiService->sendTextMessage($phone, "⚠️ " . $e->getMessage());
                $this->resetToMainMenu($session, $phone);
            }
        } else {
            // User rejected the booking, release the holding slot safely
            $appointment->update([
                'status' => 'cancelled_by_patient',
                'cancellation_reason' => 'User rejected booking confirmation',
                'cancelled_at' => Carbon::now(),
            ]);
            $this->apiService->sendTextMessage($phone, "تم إلغاء طلب الحجز وإتاحة الموعد للآخرين.");
            $this->resetToMainMenu($session, $phone);
        }
    }

    protected function showUpcomingAppointments(WhatsappSession $session, string $phone): void
    {
        $patientIds = Patient::where('primary_phone', $phone)->pluck('id');
        $appointments = Appointment::with(['doctor', 'patient'])
            ->whereIn('patient_id', $patientIds)
            ->where('appointment_date', '>=', Carbon::today()->toDateString())
            ->where('status', 'confirmed')
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->get();
        if ($appointments->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "لا توجد لديك أي مواعيد قادمة مؤكدة حالياً.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        $msg = "📋 مواعيدك القادمة المؤكدة:\n\n";
        foreach ($appointments as $idx => $a) {
            $num = $idx + 1;
            $msg .= "{$num}️⃣ *{$a->reference_code}*\n" .
                "👤 المريض: {$a->patient->full_name}\n" .
                "👨‍⚕️ الطبيب: {$a->doctor->full_name}\n" .
                "📅 التاريخ: {$a->appointment_date->format('Y-m-d')}\n" .
                "⏰ الوقت: " . substr($a->start_time, 0, 5) . "\n\n";
        }
        $this->apiService->sendTextMessage($phone, $msg);
        $this->resetToMainMenu($session, $phone);
    }

    protected function initiateCancellationFlow(WhatsappSession $session, string $phone): void
    {
        $patientIds = Patient::where('primary_phone', $phone)->pluck('id');
        $appointments = Appointment::with(['doctor', 'patient'])
            ->whereIn('patient_id', $patientIds)
            ->where('appointment_date', '>=', Carbon::today()->toDateString())
            ->where('status', 'confirmed')
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->get();
        if ($appointments->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "لا توجد لديك مواعيد مؤكدة يمكن إلغاؤها حالياً.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        $session->update([
            'current_state' => 'SELECTING_CANCEL_APPT',
            'state_payload' => [
                'cancellable_ids' => $appointments->pluck('id')->toArray(),
            ],
        ]);
        $msg = "اختر الموعد الذي ترغب بإلغائه بإرسال رقمه:\n";
        foreach ($appointments as $idx => $a) {
            $num = $idx + 1;
            $msg .= "{$num}️⃣ الموعد: [{$a->reference_code}] مع {$a->doctor->full_name} بتاريخ {$a->appointment_date->format('Y-m-d')} الساعة " . substr($a->start_time, 0, 5) . "\n";
        }
        $this->apiService->sendTextMessage($phone, $msg);
    }

    protected function handleSelectCancelAppointment(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $cancellableIds = $payload['cancellable_ids'] ?? [];
        $index = ((int) $choice) - 1;
        if (!isset($cancellableIds[$index])) {
            $this->apiService->sendTextMessage($phone, "رقم غير صحيح. أرسل رقم الموعد المراد إلغاؤه من القائمة.");
            return;
        }
        $appointment = Appointment::find($cancellableIds[$index]);
        if (!$appointment) {
            $this->apiService->sendTextMessage($phone, "الموعد غير موجود. حاول مرة أخرى.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        $session->update([
            'current_state' => 'CONFIRMING_CANCELLATION',
            'state_payload' => ['appointment_id' => $appointment->id],
        ]);
        $confirmMsg = "هل تريد إلغاء الموعد [{$appointment->reference_code}] مع {$appointment->doctor->full_name} في {$appointment->appointment_date->format('Y-m-d')}؟";
        $buttons = [
            ['type' => 'reply', 'reply' => ['id' => 'cancel_yes', 'title' => '✅ نعم']],
            ['type' => 'reply', 'reply' => ['id' => 'cancel_no', 'title' => '❌ لا']],
        ];
        // Confirmation of cancellation uses button message with proper order
        $this->apiService->sendButtonMessage($phone, $confirmMsg, $buttons); // correct order
    }

    protected function handleConfirmCancellation(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $appointmentId = $payload['appointment_id'] ?? null;
        $appointment = Appointment::find($appointmentId);
        if (!$appointment) {
            $this->apiService->sendTextMessage($phone, "الموعد غير موجود أو تم حذفه مسبقاً.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        $patient = $appointment->patient;
        if (!$patient || $patient->primary_phone !== $phone) {
            $this->apiService->sendTextMessage($phone, "ليس لديك صلاحية إلغاء هذا الموعد.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        $nonCancellable = ['cancelled_by_patient', 'cancelled_by_clinic', 'completed'];
        if (in_array($appointment->status, $nonCancellable)) {
            $this->apiService->sendTextMessage($phone, "لا يمكن إلغاء الموعد لأنه غير قابل للإلغاء حالياً.");
            $this->resetToMainMenu($session, $phone);
            return;
        }
        if (in_array(strtolower($choice), ['cancel_yes', 'yes', '1'])) {
            try {
                $this->bookingService->cancelAppointment($appointment, 'إلغاء من المريض عبر واتساب', 'patient');
                $msg = "✅ تم إلغاء الموعد [{$appointment->reference_code}] مع {$appointment->doctor->full_name} في {$appointment->appointment_date->format('Y-m-d')} الساعة " . substr($appointment->start_time, 0, 5) . ".";
                $this->apiService->sendTextMessage($phone, $msg);
            } catch (Throwable $e) {
                $this->apiService->sendTextMessage($phone, "⚠️ لا يمكن إلغاء الموعد: " . $e->getMessage());
            }
            $this->resetToMainMenu($session, $phone);
            return;
        }
        $this->apiService->sendTextMessage($phone, "تم إلغاء طلب الإلغاء. عودة للقائمة الرئيسية.");
        $this->resetToMainMenu($session, $phone);
    }

    protected function sendKvkkConsentMessage(WhatsappSession $session, string $phone, array $payload): void
    {
        $header = '⚖️ موافقة KVKK';
        $body = "لتأكيد حجزك، يرجى موافقتك على سياسة حماية البيانات (KVKK).\n" .
            "اضغط ✅ للموافقة أو ❌ للرفض.";
        $buttons = [
            ['type' => 'reply', 'reply' => ['id' => 'kvkk_yes', 'title' => '✅ نعم']],
            ['type' => 'reply', 'reply' => ['id' => 'kvkk_no', 'title' => '❌ لا']],
        ];
        $this->apiService->sendButtonMessage($phone, $body, $buttons, $header); // correct order
    }
}
?>
