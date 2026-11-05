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
 * Implementation of the WhatsApp conversation flow for clinic booking.
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

        // Load or create session
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $cleanPhone],
            ['current_state' => 'MAIN_MENU', 'state_payload' => []]
        );

        $payload = $session->state_payload ?? [];

        // Session timeout – reset after 15 minutes of inactivity
        if ($session->last_interaction_at && Carbon::parse($session->last_interaction_at)->diffInMinutes(Carbon::now()) > 15) {
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }

        // Show main menu on greeting or initial empty message
        if ($session->current_state === 'MAIN_MENU' && (empty($text) || in_array(strtolower($text), ['hi', 'hello', 'مرحبا', 'start', 'hey']))) {
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }

        // Update interaction timestamp
        $session->update(['last_interaction_at' => Carbon::now()]);

        // Global flow cancel/restart via button IDs and common keywords
        if (in_array(strtolower($text), ['flow_cancel', 'cancel_booking', 'flow_restart', 'main_menu', 'cancel', '0', 'الغاء', 'إلغاء', 'خروج', 'menu', 'قائمة', 'ابدأ'])) {
            // If there's a holding appointment, cancel it safely without touching confirmed appointments
            if (!empty($payload['appointment_id'])) {
                $holding = Appointment::find($payload['appointment_id']);
                if ($holding && $holding->status === 'holding') {
                    $holding->update([
                        'status' => 'cancelled_by_patient',
                        'cancellation_reason' => 'Flow cancelled by user',
                        'cancelled_at' => Carbon::now(),
                    ]);
                }
            }
            $this->resetToMainMenu($session, $cleanPhone);
            return;
        }

        $state = $session->current_state;

        switch ($state) {
            case 'MAIN_MENU':
                $this->handleMainMenu($session, $cleanPhone, $text);
                break;
            case 'SELECTING_PATIENT':
                $this->handleSelectPatient($session, $cleanPhone, $text, $payload);
                break;
            case 'AWAITING_NEW_PATIENT_NAME':
                $this->handleNewPatientName($session, $cleanPhone, $text, $payload);
                break;
            case 'AWAITING_KVKK_CONSENT':
                $this->handleKvkkConsent($session, $cleanPhone, $text, $payload);
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

    /**
     * Display Main Menu with 3 separate interactive reply buttons in Arabic.
     */
    public function resetToMainMenu(WhatsappSession $session, string $phone): void
    {
        $session->update([
            'current_state' => 'MAIN_MENU',
            'state_payload' => [],
            'last_interaction_at' => Carbon::now(),
        ]);

        $body = "مرحباً بكم في عيادة الشفاء الطبية.\n\nتتيح لكم هذه الخدمة إدارة مواعيدكم الطبية بكل سهولة. يرجى اختيار إحدى الخدمات التالية:";

        $buttons = [
            [
                'id' => 'main_new_appointment',
                'title' => 'حجز موعد جديد',
                'type' => 'reply',
                'reply' => ['id' => 'main_new_appointment', 'title' => 'حجز موعد جديد'],
            ],
            [
                'id' => 'main_view_appointments',
                'title' => 'استعراض المواعيد',
                'type' => 'reply',
                'reply' => ['id' => 'main_view_appointments', 'title' => 'استعراض المواعيد'],
            ],
            [
                'id' => 'main_cancel_appointments',
                'title' => 'إلغاء موعد نشط',
                'type' => 'reply',
                'reply' => ['id' => 'main_cancel_appointments', 'title' => 'إلغاء موعد نشط'],
            ],
        ];

        $this->apiService->sendButtonMessage($phone, $body, $buttons);
    }

    /**
     * Handle Main Menu actions.
     */
    protected function handleMainMenu(WhatsappSession $session, string $phone, string $choice): void
    {
        if (in_array($choice, ['1', 'main_new_appointment', 'main_menu_book', 'حجز موعد جديد'])) {
            $this->initiateBookingFlow($session, $phone);
        } elseif (in_array($choice, ['2', 'main_view_appointments', 'main_menu_view', 'استعراض المواعيد'])) {
            $this->showUpcomingAppointments($session, $phone);
        } elseif (in_array($choice, ['3', 'main_cancel_appointments', 'main_menu_cancel', 'إلغاء موعد نشط'])) {
            $this->initiateCancellationFlow($session, $phone);
        } else {
            $this->apiService->sendTextMessage($phone, "يرجى اختيار أحد الخيارات المتاحة بالضغط على الزر المطلوب أدناه.");
            $this->resetToMainMenu($session, $phone);
        }
    }

    /**
     * Retrieve the single most recently registered or booked patient for this phone number.
     */
    protected function getLatestPatientForPhone(string $phone): ?Patient
    {
        $patients = Patient::where('primary_phone', $phone)->get();
        if ($patients->isEmpty()) {
            return null;
        }

        if ($patients->count() === 1) {
            return $patients->first();
        }

        // Check if any patient has recent appointments
        $latestWithAppt = Patient::where('primary_phone', $phone)
            ->whereHas('appointments')
            ->get()
            ->sortByDesc(function ($p) {
                return $p->appointments()->max('id') ?? 0;
            })
            ->first();

        $latestRegistered = Patient::where('primary_phone', $phone)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($latestWithAppt && $latestRegistered && $latestWithAppt->id !== $latestRegistered->id) {
            $latestApptDate = $latestWithAppt->appointments()->latest('id')->first()?->created_at;
            if ($latestApptDate && $latestApptDate->gt($latestRegistered->updated_at)) {
                return $latestWithAppt;
            }
        }

        return $latestRegistered;
    }

    /**
     * Step 1: Patient Selection.
     * Shows only the single latest patient (if exists), Register New Patient, and Cancel button.
     */
    protected function initiateBookingFlow(WhatsappSession $session, string $phone): void
    {
        $latestPatient = $this->getLatestPatientForPhone($phone);

        $session->update([
            'current_state' => 'SELECTING_PATIENT',
            'state_payload' => [
                'latest_patient_id' => $latestPatient?->id,
            ],
            'last_interaction_at' => Carbon::now(),
        ]);

        $buttons = [];
        if ($latestPatient) {
            $buttons[] = [
                'id' => 'patient_latest',
                'title' => $latestPatient->full_name,
                'type' => 'reply',
                'reply' => ['id' => 'patient_latest', 'title' => $latestPatient->full_name],
            ];
        }

        $buttons[] = [
            'id' => 'patient_new',
            'title' => 'تسجيل مريض جديد',
            'type' => 'reply',
            'reply' => ['id' => 'patient_new', 'title' => 'تسجيل مريض جديد'],
        ];

        $buttons[] = [
            'id' => 'flow_cancel',
            'title' => 'إلغاء والعودة',
            'type' => 'reply',
            'reply' => ['id' => 'flow_cancel', 'title' => 'إلغاء والعودة'],
        ];

        $this->apiService->sendButtonMessage($phone, "يرجى اختيار المريض:", $buttons);
    }

    /**
     * Handle Patient Selection choices.
     */
    protected function handleSelectPatient(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $latestPatientId = $payload['latest_patient_id'] ?? null;

        // Choose latest patient
        $isLatestChosen = ($choice === 'patient_latest') ||
            ($latestPatientId && $choice === 'patient_' . $latestPatientId) ||
            ($latestPatientId && $choice === '1');

        if ($isLatestChosen && $latestPatientId) {
            $patient = Patient::find($latestPatientId);
            if ($patient && $patient->is_blocked) {
                $this->apiService->sendTextMessage($phone, "عذراً، هذا الحساب محظور من الحجز. يرجى التواصل مع إدارة العيادة.");
                $this->resetToMainMenu($session, $phone);
                return;
            }

            // Explicit consent is required for every booking, including existing patients
            $session->update([
                'current_state' => 'AWAITING_KVKK_CONSENT',
                'state_payload' => ['patient_id' => $patient->id],
            ]);
            $this->sendKvkkConsentMessage($session, $phone, ['patient_id' => $patient->id]);
            return;
        }

        // Choose to register new patient
        $isNewPatientChosen = ($choice === 'patient_new' || $choice === 'new_patient' || $choice === 'تسجيل مريض جديد') ||
            (!$latestPatientId && $choice === '1') ||
            ($latestPatientId && $choice === '2');

        if ($isNewPatientChosen) {
            $session->update([
                'current_state' => 'AWAITING_NEW_PATIENT_NAME',
                'state_payload' => [],
            ]);
            $this->apiService->sendTextMessage($phone, "يرجى إدخال الاسم الكامل للمريض:");
            return;
        }

        // Invalid choice – re-display patient selection
        $this->initiateBookingFlow($session, $phone);
    }

    /**
     * Handle New Patient Name input.
     */
    protected function handleNewPatientName(WhatsappSession $session, string $phone, string $name, array $payload): void
    {
        $trimmedName = trim($name);
        if (mb_strlen($trimmedName) < 2) {
            $this->apiService->sendTextMessage($phone, "يرجى إدخال اسم صحيح للمريض (حرفان على الأقل):");
            return;
        }

        // Check if patient already exists for this phone number to avoid unnecessary duplicates
        $patient = Patient::where('primary_phone', $phone)
            ->whereRaw('LOWER(TRIM(full_name)) = ?', [mb_strtolower($trimmedName)])
            ->first();

        if (!$patient) {
            $patient = Patient::create([
                'primary_phone' => $phone,
                'full_name' => $trimmedName,
                'kvkk_consent_accepted' => false,
            ]);
        }

        // Touch to ensure this patient is the latest registered
        $patient->touch();

        // Require explicit consent before advancing to clinic selection
        $session->update([
            'current_state' => 'AWAITING_KVKK_CONSENT',
            'state_payload' => [
                'patient_id' => $patient->id,
            ],
        ]);

        $this->sendKvkkConsentMessage($session, $phone, ['patient_id' => $patient->id]);
    }

    /**
     * Send explicit consent message informing the patient of storage/processing outside Turkey.
     * Uses exactly two reply buttons: موافق and لا أوافق.
     */
    protected function sendKvkkConsentMessage(WhatsappSession $session, string $phone, array $payload): void
    {
        $body = "نحيطكم علماً بأنه سيتم تخزين ومعالجة بياناتكم الشخصية والطبية على خوادم خارج تركيا لغرض إدارة ومتابعة حجز المواعيد الطبية وفقاً لسياسة حماية البيانات الشخصية (KVKK).\n\nهل توافق على تخزين ومعالجة معلوماتك الشخصية والطبية خارج تركيا؟";

        $buttons = [
            [
                'id' => 'kvkk_yes',
                'title' => 'موافق',
                'type' => 'reply',
                'reply' => ['id' => 'kvkk_yes', 'title' => 'موافق'],
            ],
            [
                'id' => 'kvkk_no',
                'title' => 'لا أوافق',
                'type' => 'reply',
                'reply' => ['id' => 'kvkk_no', 'title' => 'لا أوافق'],
            ],
        ];

        $this->apiService->sendButtonMessage($phone, $body, $buttons);
    }

    /**
     * Handle explicit consent response for medical data storage outside Turkey.
     */
    protected function handleKvkkConsent(WhatsappSession $session, string $phone, string $text, array $payload): void
    {
        if (in_array(strtolower($text), ['kvkk_yes', 'موافق', 'نعم', 'yes', '1'])) {
            $payload = array_merge($payload, ['kvkk_accepted' => true]);
            $patientId = $payload['patient_id'] ?? null;

            if ($patientId) {
                $patient = Patient::find($patientId);
                if ($patient) {
                    $patient->update([
                        'kvkk_consent_accepted' => true,
                        'kvkk_consent_date' => Carbon::now(),
                    ]);
                }

                $session->update([
                    'current_state' => 'SELECTING_SPECIALTY',
                    'state_payload' => $payload,
                ]);

                $this->displaySpecialties($phone, $session, $patientId);
            } else {
                $session->update([
                    'current_state' => 'AWAITING_NEW_PATIENT_NAME',
                    'state_payload' => $payload,
                ]);
                $this->apiService->sendTextMessage($phone, "يرجى إدخال الاسم الكامل للمريض:");
            }
        } elseif (in_array(strtolower($text), ['kvkk_no', 'لا أوافق', 'لا', 'no', '2'])) {
            // Refusal: release any temporary slot hold, clear booking state, and return to main menu
            if (!empty($payload['appointment_id'])) {
                $holding = Appointment::find($payload['appointment_id']);
                if ($holding && $holding->status === 'holding') {
                    $holding->update([
                        'status' => 'cancelled_by_patient',
                        'cancellation_reason' => 'رفض المريض الموافقة على تخزين ومعالجة البيانات خارج تركيا',
                        'cancelled_at' => Carbon::now(),
                    ]);
                }
            }

            $session->update([
                'current_state' => 'MAIN_MENU',
                'state_payload' => [],
            ]);

            $this->apiService->sendTextMessage($phone, "تم إلغاء عملية الحجز نظراً لعدم الموافقة على تخزين ومعالجة البيانات الطبية خارج تركيا.");
            $this->resetToMainMenu($session, $phone);
        } else {
            // Re-prompt consent message
            $this->sendKvkkConsentMessage($session, $phone, $payload);
        }
    }

    /**
     * Step 2: Clinic Selection.
     * Displays clinics as a WhatsApp interactive list, with a separate cancel button outside.
     */
    protected function displaySpecialties(string $phone, WhatsappSession $session, int $patientId): void
    {
        $specialties = Specialty::where('is_active', true)->get();
        if ($specialties->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "لا تتوفر عيادات متاحة للحجز حالياً.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $session->update([
            'current_state' => 'SELECTING_SPECIALTY',
            'state_payload' => array_merge($session->state_payload ?? [], [
                'patient_id' => $patientId,
                'specialty_ids' => $specialties->pluck('id')->toArray(),
            ]),
        ]);

        $rows = [];
        foreach ($specialties as $spec) {
            $rows[] = ['id' => 'clinic_' . $spec->id, 'title' => $spec->name];
        }

        $sections = [['title' => 'العيادات المتاحة', 'rows' => $rows]];
        $this->apiService->sendListMessage($phone, "يرجى اختيار العيادة:", "اختيار العيادة", $sections);

        // Separate cancel button outside the list
        $this->sendSeparateCancelButton($phone);
    }

    /**
     * Handle Clinic Selection.
     */
    protected function handleSelectSpecialty(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $specialtyIds = $payload['specialty_ids'] ?? [];
        $selectedId = null;

        if (Str::startsWith($choice, 'clinic_')) {
            $selectedId = (int) substr($choice, 7);
        } elseif (Str::startsWith($choice, 'specialty_')) {
            $selectedId = (int) substr($choice, 10);
        } else {
            $index = ((int) $choice) - 1;
            if (isset($specialtyIds[$index])) {
                $selectedId = $specialtyIds[$index];
            }
        }

        if (!$selectedId || !in_array($selectedId, $specialtyIds)) {
            $this->apiService->sendTextMessage($phone, "اختيار غير صحيح. يرجى اختيار العيادة من القائمة.");
            $this->displaySpecialties($phone, $session, $payload['patient_id']);
            return;
        }

        $specialtyId = $selectedId;
        $doctors = Doctor::where('specialty_id', $specialtyId)->where('is_active', true)->get();
        if ($doctors->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "لا يوجد أطباء متاحون في هذه العيادة حالياً.");
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
            $title = $doc->title ? " ({$doc->title})" : '';
            $rows[] = ['id' => 'doctor_' . $doc->id, 'title' => trim("{$doc->full_name}{$title}")];
        }

        $sections = [['title' => 'الأطباء المتاحون', 'rows' => $rows]];
        $this->apiService->sendListMessage($phone, "يرجى اختيار الطبيب:", "اختيار الطبيب", $sections);

        // Separate cancel button outside the list
        $this->sendSeparateCancelButton($phone);
    }

    /**
     * Handle Doctor Selection.
     */
    protected function handleSelectDoctor(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $doctorIds = $payload['doctor_ids'] ?? [];
        $selectedId = null;

        if (Str::startsWith($choice, 'doctor_')) {
            $selectedId = (int) substr($choice, 7);
        } else {
            $index = ((int) $choice) - 1;
            if (isset($doctorIds[$index])) {
                $selectedId = $doctorIds[$index];
            }
        }

        if (!$selectedId || !in_array($selectedId, $doctorIds)) {
            $this->apiService->sendTextMessage($phone, "اختيار غير صحيح. يرجى اختيار الطبيب من القائمة.");
            return;
        }

        $doctorId = $selectedId;
        $doctor = Doctor::find($doctorId);
        $availableDates = $this->bookingService->getAvailableDates($doctor, 8);

        if ($availableDates->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "لا تتوفر مواعيد متاحة لهذا الطبيب خلال الأيام القادمة.");
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
        foreach ($availableDates->take(8) as $d) {
            $rows[] = ['id' => 'date_' . $d['date'], 'title' => "{$d['formatted']} ({$d['day_name']})"];
        }

        $sections = [['title' => 'التواريخ المتاحة', 'rows' => $rows]];
        $this->apiService->sendListMessage($phone, "يرجى اختيار تاريخ الموعد المناسب:", "اختيار التاريخ", $sections);

        // Separate cancel button outside the list
        $this->sendSeparateCancelButton($phone);
    }

    /**
     * Handle Date Selection.
     */
    protected function handleSelectDate(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $availableDates = $payload['available_dates'] ?? [];
        $selectedDate = null;

        if (Str::startsWith($choice, 'date_')) {
            $selectedDate = substr($choice, 5);
        } else {
            $index = ((int) $choice) - 1;
            if (isset($availableDates[$index])) {
                $selectedDate = $availableDates[$index]['date'];
            }
        }

        if (!$selectedDate) {
            $this->apiService->sendTextMessage($phone, "اختيار غير صحيح. يرجى اختيار التاريخ من القائمة.");
            return;
        }

        $doctor = Doctor::find($payload['doctor_id']);
        $slots = $this->bookingService->getAvailableSlots($doctor, $selectedDate);

        if ($slots->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "جميع مواعيد هذا التاريخ محجوزة بالكامل. يرجى اختيار تاريخ آخر.");
            return;
        }

        $slotsArray = $slots->toArray();

        $session->update([
            'current_state' => 'SELECTING_SLOT',
            'state_payload' => array_merge($payload, [
                'selected_date' => $selectedDate,
                'available_slots' => $slotsArray,
                'slot_page' => 1,
            ]),
        ]);

        $this->displaySlotsList($phone, $selectedDate, $slotsArray, 1);
    }

    /**
     * Display Available Time Slots list and separate cancel button.
     */
    protected function displaySlotsList(string $phone, string $selectedDate, array $slotsArray, int $page): void
    {
        $totalSlots = count($slotsArray);
        // Fixed capacity per page: 8 slots, leaving room for prev/next navigation rows (total rows <= 10)
        $perPage = 8;
        $offset = ($page - 1) * $perPage;

        $pageSlots = array_slice($slotsArray, $offset, $perPage, true);
        $rows = [];
        foreach ($pageSlots as $idx => $s) {
            $rows[] = ['id' => 'slot_' . $idx, 'title' => $s['display']];
        }

        if ($page > 1) {
            $rows[] = ['id' => 'slot_page_prev', 'title' => 'السابق'];
        }
        if ($offset + count($pageSlots) < $totalSlots) {
            $rows[] = ['id' => 'slot_page_next', 'title' => 'التالي'];
        }

        $sections = [['title' => 'الأوقات المتاحة', 'rows' => $rows]];
        $this->apiService->sendListMessage($phone, "يرجى اختيار وقت الموعد المتاح:", "اختيار الوقت", $sections);

        // Separate cancel button outside the list
        $this->sendSeparateCancelButton($phone);
    }

    /**
     * Handle Slot Selection.
     */
    protected function handleSelectSlot(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $slots = $payload['available_slots'] ?? [];

        // Handle pagination actions
        if ($choice === 'slot_page_next' || $choice === 'slot_page_prev') {
            $currentPage = $payload['slot_page'] ?? 1;
            $newPage = $choice === 'slot_page_next' ? $currentPage + 1 : max(1, $currentPage - 1);
            $payload['slot_page'] = $newPage;
            $session->update(['state_payload' => $payload]);

            $this->displaySlotsList($phone, $payload['selected_date'], $slots, $newPage);
            return;
        }

        $index = null;
        if (Str::startsWith($choice, 'slot_')) {
            $index = (int) substr($choice, 5);
        } else {
            $index = ((int) $choice) - 1;
        }

        if (!isset($slots[$index])) {
            $this->apiService->sendTextMessage($phone, "الوقت المختار غير صحيح. يرجى اختيار الوقت من القائمة.");
            return;
        }

        $slot = $slots[$index];
        $doctor = Doctor::find($payload['doctor_id']);
        $patient = Patient::find($payload['patient_id']);

        // Release any existing temporary holding appointment before holding a new slot
        if (!empty($payload['appointment_id'])) {
            $existingHold = Appointment::find($payload['appointment_id']);
            if ($existingHold && $existingHold->status === 'holding') {
                $existingHold->update([
                    'status' => 'cancelled_by_patient',
                    'cancellation_reason' => 'User selected a different slot',
                    'cancelled_at' => Carbon::now(),
                ]);
            }
        }

        try {
            // Revalidate slot and create temporary reservation
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

            $clinicName = $doctor->specialty?->name ?? ClinicSetting::get('clinic_name', 'عيادة الشفاء الطبية');

            $reviewMsg = "تفاصيل الموعد\n\n" .
                "المريض: {$patient->full_name}\n" .
                "العيادة: {$clinicName}\n" .
                "الطبيب: {$doctor->full_name}\n" .
                "التاريخ: {$payload['selected_date']}\n" .
                "الوقت: {$slot['display']}\n\n" .
                "يرجى مراجعة تفاصيل الموعد أعلاه.\n\n" .
                "هل ترغب في تأكيد الحجز؟";

            $buttons = [
                [
                    'id' => 'booking_yes',
                    'title' => 'نعم',
                    'type' => 'reply',
                    'reply' => ['id' => 'booking_yes', 'title' => 'نعم'],
                ],
                [
                    'id' => 'booking_no',
                    'title' => 'لا',
                    'type' => 'reply',
                    'reply' => ['id' => 'booking_no', 'title' => 'لا'],
                ],
            ];

            $this->apiService->sendButtonMessage($phone, $reviewMsg, $buttons);

        } catch (Throwable $e) {
            $this->apiService->sendTextMessage($phone, $e->getMessage());
            // Re-fetch slots and redisplay
            $availableSlots = $this->bookingService->getAvailableSlots($doctor, $payload['selected_date']);
            $slotsArray = $availableSlots->toArray();
            $payload['available_slots'] = $slotsArray;
            $session->update(['state_payload' => $payload]);
            $this->displaySlotsList($phone, $payload['selected_date'], $slotsArray, 1);
        }
    }

    /**
     * Handle Appointment Review and Confirmation.
     */
    protected function handleConfirmBooking(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $apptId = $payload['appointment_id'] ?? null;
        $appointment = Appointment::find($apptId);

        // Disallow selecting another slot while a pending confirmation is active
        if (Str::startsWith($choice, 'slot_')) {
            $this->apiService->sendTextMessage($phone, "لديك حجز معلق بانتظار التأكيد. يرجى الضغط على نعم للتأكيد أو لا للإلغاء.");
            $buttons = [
                ['id' => 'booking_yes', 'title' => 'نعم', 'type' => 'reply', 'reply' => ['id' => 'booking_yes', 'title' => 'نعم']],
                ['id' => 'booking_no', 'title' => 'لا', 'type' => 'reply', 'reply' => ['id' => 'booking_no', 'title' => 'لا']],
            ];
            $this->apiService->sendButtonMessage($phone, "هل ترغب في تأكيد حجز الموعد؟", $buttons);
            return;
        }

        // If user presses No
        if (in_array(strtolower($choice), ['booking_no', 'confirm_no', 'no', '2', 'لا'])) {
            if ($appointment && $appointment->status === 'holding') {
                $appointment->update([
                    'status' => 'cancelled_by_patient',
                    'cancellation_reason' => 'User rejected booking confirmation',
                    'cancelled_at' => Carbon::now(),
                ]);
            }
            $session->update([
                'current_state' => 'MAIN_MENU',
                'state_payload' => [],
            ]);
            $this->resetToMainMenu($session, $phone);
            return;
        }

        // If user presses Yes
        if (in_array(strtolower($choice), ['booking_yes', 'confirm_yes', 'yes', '1', 'نعم'])) {
            // Idempotency: if already confirmed (repeated webhook delivery), send confirmation and reset
            if ($appointment && $appointment->status === 'confirmed') {
                $this->sendConfirmationSuccessMessage($phone, $appointment, $payload['slot_display'] ?? null);
                $session->update([
                    'current_state' => 'MAIN_MENU',
                    'state_payload' => [],
                ]);
                $this->resetToMainMenu($session, $phone);
                return;
            }

            if (!$appointment || $appointment->status !== 'holding') {
                $this->apiService->sendTextMessage($phone, "لم يعد الحجز معلقاً. يرجى بدء حجز جديد.");
                $this->resetToMainMenu($session, $phone);
                return;
            }

            // Check if holding reservation has expired
            if ($appointment->holding_expires_at && Carbon::now()->gt($appointment->holding_expires_at)) {
                $appointment->update([
                    'status' => 'cancelled_by_clinic',
                    'cancellation_reason' => 'Reservation expired',
                    'cancelled_at' => Carbon::now(),
                ]);
                $this->apiService->sendTextMessage($phone, "انتهت صلاحية الحجز المؤقت. يرجى اختيار وقت متاح مجدداً.");
                // Return to slot selection
                $this->handleSelectDate($session, $phone, 'date_' . $payload['selected_date'], $payload);
                return;
            }

            try {
                // Confirm the exact pending reservation
                $confirmed = $this->bookingService->confirmAppointment($appointment);
                $confirmed->update([
                    'kvkk_consent_accepted' => true,
                    'kvkk_consent_date' => Carbon::now(),
                ]);

                // Update patient touched timestamp
                $confirmed->patient?->touch();

                // Send clean professional confirmation message in Arabic
                $this->sendConfirmationSuccessMessage($phone, $confirmed, $payload['slot_display'] ?? null);

                // Clear pending booking state and automatically display main menu
                $session->update([
                    'current_state' => 'MAIN_MENU',
                    'state_payload' => [],
                ]);
                $this->resetToMainMenu($session, $phone);

            } catch (Throwable $e) {
                $this->apiService->sendTextMessage($phone, $e->getMessage());
                $this->resetToMainMenu($session, $phone);
            }
            return;
        }

        // Any other unrecognized input: re-prompt confirmation without cancelling
        $buttons = [
            ['id' => 'booking_yes', 'title' => 'نعم', 'type' => 'reply', 'reply' => ['id' => 'booking_yes', 'title' => 'نعم']],
            ['id' => 'booking_no', 'title' => 'لا', 'type' => 'reply', 'reply' => ['id' => 'booking_no', 'title' => 'لا']],
        ];
        $this->apiService->sendButtonMessage($phone, "يرجى مراجعة تفاصيل الموعد واختيار نعم للمتابعة أو لا للإلغاء:", $buttons);
    }

    /**
     * Send final confirmation message in clean, professional plain Arabic text.
     */
    protected function sendConfirmationSuccessMessage(string $phone, Appointment $appointment, ?string $slotDisplay = null): void
    {
        $clinicName = $appointment->doctor->specialty?->name ?? ClinicSetting::get('clinic_name', 'عيادة الشفاء الطبية');
        $timeDisplay = $slotDisplay ?: (substr($appointment->start_time, 0, 5) . ' - ' . substr($appointment->end_time, 0, 5));
        $dateDisplay = Carbon::parse($appointment->appointment_date)->format('Y-m-d');

        $msg = "تم تأكيد موعدك بنجاح.\n\n" .
            "تفاصيل الموعد\n\n" .
            "المريض: {$appointment->patient->full_name}\n" .
            "العيادة: {$clinicName}\n" .
            "الطبيب: {$appointment->doctor->full_name}\n" .
            "التاريخ: {$dateDisplay}\n" .
            "الوقت: {$timeDisplay}\n\n" .
            "شكراً لاختياركم عيادة الشفاء الطبية.";

        $this->apiService->sendTextMessage($phone, $msg);
    }

    /**
     * Send separate cancel button below a list message in Arabic.
     */
    protected function sendSeparateCancelButton(string $phone): void
    {
        $buttons = [
            [
                'id' => 'flow_cancel',
                'title' => 'إلغاء والعودة',
                'type' => 'reply',
                'reply' => ['id' => 'flow_cancel', 'title' => 'إلغاء والعودة'],
            ],
        ];

        $this->apiService->sendButtonMessage($phone, "للإلغاء والعودة إلى القائمة الرئيسية:", $buttons);
    }

    /**
     * Show upcoming confirmed appointments in Arabic.
     */
    protected function showUpcomingAppointments(WhatsappSession $session, string $phone): void
    {
        $patientIds = Patient::where('primary_phone', $phone)->pluck('id');
        $appointments = Appointment::with(['doctor.specialty', 'patient'])
            ->whereIn('patient_id', $patientIds)
            ->where('appointment_date', '>=', Carbon::today()->toDateString())
            ->where('status', 'confirmed')
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->get();

        if ($appointments->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "لا توجد لديك أي مواعيد مؤكدة قادمة.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $msg = "المواعيد القادمة\n\n";
        foreach ($appointments as $idx => $a) {
            $num = $idx + 1;
            $clinicName = $a->doctor->specialty?->name ?? ClinicSetting::get('clinic_name', 'عيادة الشفاء الطبية');
            $msg .= "{$num}. الرقم المرجعي: {$a->reference_code}\n" .
                "المريض: {$a->patient->full_name}\n" .
                "العيادة: {$clinicName}\n" .
                "الطبيب: {$a->doctor->full_name}\n" .
                "التاريخ: {$a->appointment_date->format('Y-m-d')}\n" .
                "الوقت: " . substr($a->start_time, 0, 5) . " - " . substr($a->end_time, 0, 5) . "\n\n";
        }

        $this->apiService->sendTextMessage($phone, trim($msg));
        $this->resetToMainMenu($session, $phone);
    }

    /**
     * Step: Cancellation Flow in Arabic.
     */
    protected function initiateCancellationFlow(WhatsappSession $session, string $phone): void
    {
        $patientIds = Patient::where('primary_phone', $phone)->pluck('id');
        $appointments = Appointment::with(['doctor.specialty', 'patient'])
            ->whereIn('patient_id', $patientIds)
            ->where('appointment_date', '>=', Carbon::today()->toDateString())
            ->where('status', 'confirmed')
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->get();

        if ($appointments->isEmpty()) {
            $this->apiService->sendTextMessage($phone, "لا توجد لديك مواعيد نشطة يمكن إلغاؤها.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $session->update([
            'current_state' => 'SELECTING_CANCEL_APPT',
            'state_payload' => [
                'cancellable_ids' => $appointments->pluck('id')->toArray(),
            ],
        ]);

        $msg = "يرجى الرد برقم الموعد الذي ترغب في إلغائه:\n\n";
        foreach ($appointments as $idx => $a) {
            $num = $idx + 1;
            $msg .= "{$num}. المرجع: [{$a->reference_code}] مع د. {$a->doctor->full_name} بتاريخ {$a->appointment_date->format('Y-m-d')} الساعة " . substr($a->start_time, 0, 5) . "\n";
        }

        $this->apiService->sendTextMessage($phone, trim($msg));
    }

    /**
     * Handle Cancel Appointment Choice in Arabic.
     */
    protected function handleSelectCancelAppointment(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $cancellableIds = $payload['cancellable_ids'] ?? [];
        $index = ((int) $choice) - 1;

        if (!isset($cancellableIds[$index])) {
            $this->apiService->sendTextMessage($phone, "اختيار غير صحيح. يرجى إرسال رقم الموعد المراد إلغاؤه من القائمة.");
            return;
        }

        $appointment = Appointment::find($cancellableIds[$index]);
        if (!$appointment) {
            $this->apiService->sendTextMessage($phone, "لم يتم العثور على الموعد. يرجى المحاولة مرة أخرى.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $session->update([
            'current_state' => 'CONFIRMING_CANCELLATION',
            'state_payload' => ['appointment_id' => $appointment->id],
        ]);

        $confirmMsg = "هل أنت متأكد من رغبتك في إلغاء الموعد [{$appointment->reference_code}] مع د. {$appointment->doctor->full_name} بتاريخ {$appointment->appointment_date->format('Y-m-d')}؟";
        $buttons = [
            ['id' => 'cancel_yes', 'title' => 'نعم', 'type' => 'reply', 'reply' => ['id' => 'cancel_yes', 'title' => 'نعم']],
            ['id' => 'cancel_no', 'title' => 'لا', 'type' => 'reply', 'reply' => ['id' => 'cancel_no', 'title' => 'لا']],
        ];

        $this->apiService->sendButtonMessage($phone, $confirmMsg, $buttons);
    }

    /**
     * Handle Confirmation of Cancellation in Arabic.
     */
    protected function handleConfirmCancellation(WhatsappSession $session, string $phone, string $choice, array $payload): void
    {
        $appointmentId = $payload['appointment_id'] ?? null;
        $appointment = Appointment::find($appointmentId);

        if (!$appointment) {
            $this->apiService->sendTextMessage($phone, "لم يتم العثور على الموعد أو تم حذفه مسبقاً.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $patient = $appointment->patient;
        if (!$patient || $patient->primary_phone !== $phone) {
            $this->apiService->sendTextMessage($phone, "غير مصرح لك بإلغاء هذا الموعد.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $nonCancellable = ['cancelled_by_patient', 'cancelled_by_clinic', 'completed'];
        if (in_array($appointment->status, $nonCancellable)) {
            $this->apiService->sendTextMessage($phone, "لا يمكن إلغاء هذا الموعد حالياً.");
            $this->resetToMainMenu($session, $phone);
            return;
        }

        if (in_array(strtolower($choice), ['cancel_yes', 'yes', '1', 'نعم'])) {
            try {
                $this->bookingService->cancelAppointment($appointment, 'إلغاء عبر بوت الواتساب بناءً على طلب المريض', 'patient');
                $msg = "تم إلغاء موعدك [{$appointment->reference_code}] مع د. {$appointment->doctor->full_name} بتاريخ {$appointment->appointment_date->format('Y-m-d')} الساعة " . substr($appointment->start_time, 0, 5) . " بنجاح.";
                $this->apiService->sendTextMessage($phone, $msg);
            } catch (Throwable $e) {
                $this->apiService->sendTextMessage($phone, "تعذر إلغاء الموعد: " . $e->getMessage());
            }
            $this->resetToMainMenu($session, $phone);
            return;
        }

        $this->apiService->sendTextMessage($phone, "تم التراجع عن الإلغاء. العودة إلى القائمة الرئيسية.");
        $this->resetToMainMenu($session, $phone);
    }
}
