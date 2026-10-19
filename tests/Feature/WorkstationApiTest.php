<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\ClinicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkstationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $secretary;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClinicSeeder::class);

        $this->secretary = User::factory()->create([
            'email' => 'secretary@clinic.local',
            'password' => bcrypt('password123'),
        ]);

        $this->token = $this->secretary->createToken('test_workstation')->plainTextToken;
    }

    public function test_can_login_via_workstation_api(): void
    {
        $response = $this->postJson('/api/v1/workstation/login', [
            'email' => 'secretary@clinic.local',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_can_fetch_daily_appointments(): void
    {
        $doctor = Doctor::first();
        $patient = Patient::create([
            'primary_phone' => '905551234567',
            'full_name' => 'سامي خالد',
        ]);

        Appointment::create([
            'reference_code' => 'AP-TEST001',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => now()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'confirmed',
            'version' => 1,
            'source' => 'whatsapp',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/v1/workstation/appointments?date=' . now()->toDateString());

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 1);
    }

    public function test_optimistic_concurrency_lock_detects_conflict(): void
    {
        $doctor = Doctor::first();
        $patient = Patient::create([
            'primary_phone' => '905551234567',
            'full_name' => 'سامي خالد',
        ]);

        $appt = Appointment::create([
            'reference_code' => 'AP-TEST002',
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'appointment_date' => now()->toDateString(),
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'status' => 'confirmed',
            'version' => 2, // Server version is 2
            'source' => 'whatsapp',
        ]);

        // Attempt update with stale version 1
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->patchJson("/api/v1/workstation/appointments/{$appt->id}/status", [
                'status' => 'completed',
                'attended' => true,
                'client_version' => 1, // Stale version!
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('conflict', true);
    }
}
