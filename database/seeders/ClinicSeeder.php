<?php

namespace Database\Seeders;

use App\Models\ClinicSetting;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Specialty;
use Illuminate\Database\Seeder;

class ClinicSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Clinic Basic Settings
        $settings = [
            [
                'setting_key' => 'clinic_name',
                'setting_value' => 'عيادة الشفاء التخصصية',
                'data_type' => 'string',
                'description' => 'اسم العيادة الرسمي'
            ],
            [
                'setting_key' => 'cancellation_min_hours_before',
                'setting_value' => '4',
                'data_type' => 'integer',
                'description' => 'الحد الأدنى لعدد الساعات المسموح بها للإلغاء قبل الموعد'
            ],
            [
                'setting_key' => 'slot_holding_minutes',
                'setting_value' => '10',
                'data_type' => 'integer',
                'description' => 'مدة حجز المقعد المؤقت حتى يؤكد المريض'
            ],
            [
                'setting_key' => 'max_no_shows_before_block',
                'setting_value' => '2',
                'data_type' => 'integer',
                'description' => 'عدد مرات الغياب بدون عذر قبل حظر الحجز التلقائي'
            ],
            [
                'setting_key' => 'kvkk_clarification_url',
                'setting_value' => 'https://clinic.example.com/privacy-kvkk',
                'data_type' => 'string',
                'description' => 'رابط نص الإفصاح وسياسة الخصوصية KVKK'
            ]
        ];

        foreach ($settings as $setting) {
            ClinicSetting::updateOrCreate(['setting_key' => $setting['setting_key']], $setting);
        }

        // 2. Sample Specialties
        $general = Specialty::updateOrCreate(
            ['code' => 'GEN_MED'],
            ['name' => 'الطب العام والباطنية', 'description' => 'فحوصات عامة واستشارات باطنية', 'is_active' => true]
        );

        $dental = Specialty::updateOrCreate(
            ['code' => 'DENTAL'],
            ['name' => 'طب وجراحة الأسنان', 'description' => 'معالجة وجراحة وتجميل الأسنان', 'is_active' => true]
        );

        // 3. Sample Doctors
        $doc1 = Doctor::updateOrCreate(
            ['email' => 'dr.ahmad@clinic.local'],
            [
                'specialty_id' => $general->id,
                'full_name' => 'د. أحمد خليل',
                'title' => 'أخصائي باطنية',
                'phone' => '05550001122',
                'slot_duration_minutes' => 30,
                'is_active' => true
            ]
        );

        $doc2 = Doctor::updateOrCreate(
            ['email' => 'dr.nour@clinic.local'],
            [
                'specialty_id' => $dental->id,
                'full_name' => 'د. نور العلي',
                'title' => 'طبيبة أسنان',
                'phone' => '05550003344',
                'slot_duration_minutes' => 30,
                'is_active' => true
            ]
        );

        // 4. Doctor Weekly Schedules (Sunday to Thursday, 09:00 to 17:00, break 13:00 to 14:00)
        // 0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu
        foreach ([$doc1, $doc2] as $doc) {
            for ($day = 0; $day <= 4; $day++) {
                DoctorSchedule::updateOrCreate(
                    ['doctor_id' => $doc->id, 'day_of_week' => $day],
                    [
                        'start_time' => '09:00:00',
                        'end_time' => '17:00:00',
                        'break_start' => '13:00:00',
                        'break_end' => '14:00:00',
                        'is_working_day' => true
                    ]
                );
            }
        }
    }
}
