# Workstation Integration Guide: Microsoft Access & Microsoft Excel
**Target:** Clinic Secretary Desktop Interface  
**API Protocol:** REST (HTTPS / JSON)  
**Security:** Bearer Token (Laravel Sanctum)  
**Concurrency Protection:** Optimistic Locking (`version` matching)

---

## 1. Overview & Connection Principles

1. **Hostinger / Local Server is Single Source of Truth:**
   Microsoft Access and Excel files act strictly as presentation interfaces. All queries and status updates are sent through the Laravel REST API.
2. **Optimistic Locking Guard:**
   Whenever an appointment is loaded into Excel or Access, cache its `version`. When updating attendance (`attended`) or marking a no-show (`no_show`), send the `client_version`. If a patient cancelled via WhatsApp in the meantime, the API returns `409 Conflict`, prompting the secretary to refresh and avoiding data overwrite.

---

## 2. API Endpoints Reference

Base URL (Local): `http://localhost:8000/api/v1/workstation`  
Base URL (Hostinger): `https://your-domain.com/api/v1/workstation`

### 2.1 Authentication: Login & Token Generation
- **Endpoint:** `POST /login`
- **Headers:** `Content-Type: application/json`, `Accept: application/json`
- **Request Body:**
```json
{
  "email": "secretary@clinic.local",
  "password": "your_secure_password",
  "device_name": "secretary_access_pc"
}
```
- **Response (200 OK):**
```json
{
  "success": true,
  "token": "1|qW8eR9...YOUR_SANCTUM_TOKEN...",
  "user": { "id": 1, "name": "Secretary Maryam", "email": "secretary@clinic.local" }
}
```

---

### 2.2 Appointments: Fetch Daily Schedule (For Excel & Access Grid)
- **Endpoint:** `GET /appointments?date=YYYY-MM-DD`
- **Headers:** `Authorization: Bearer <TOKEN>`, `Accept: application/json`
- **Optional Query Params:** `&doctor_id=1`, `&status=confirmed`
- **Response (200 OK):**
```json
{
  "success": true,
  "date": "2026-10-20",
  "count": 1,
  "data": [
    {
      "id": 15,
      "reference_code": "AP-8B9X21ZA",
      "patient_id": 4,
      "patient_name": "أحمد محمود",
      "patient_phone": "905551234567",
      "doctor_id": 1,
      "doctor_name": "د. أحمد خليل",
      "specialty_name": "الطب العام والباطنية",
      "appointment_date": "2026-10-20",
      "start_time": "10:00:00",
      "end_time": "10:30:00",
      "status": "confirmed",
      "attended": false,
      "version": 1,
      "total_paid": "0.00"
    }
  ]
}
```

---

### 2.3 Appointments: Update Status & Attendance (With Optimistic Lock)
- **Endpoint:** `PATCH /appointments/{id}/status`
- **Headers:** `Authorization: Bearer <TOKEN>`, `Content-Type: application/json`, `Accept: application/json`
- **Request Body:**
```json
{
  "status": "completed",
  "attended": true,
  "client_version": 1
}
```
- **Success (200 OK):**
```json
{
  "success": true,
  "message": "تم تحديث حالة الموعد بنجاح",
  "new_version": 2
}
```
- **Conflict Response (409 Conflict):**
```json
{
  "success": false,
  "conflict": true,
  "message": "تعارض في التعديل: تم تعديل هذا الموعد مسبقاً من مصدر آخر. يرجى تحديث البيانات والمحاولة مجدداً.",
  "current_version": 2,
  "current_status": "cancelled_by_patient"
}
```

---

### 2.4 Appointments: Record Payment
- **Endpoint:** `POST /appointments/{id}/payments`
- **Headers:** `Authorization: Bearer <TOKEN>`, `Content-Type: application/json`
- **Request Body:**
```json
{
  "amount": 500.00,
  "currency": "TRY",
  "payment_method": "credit_card",
  "receipt_reference": "REC-2026-1092",
  "notes": "دفعة كشفية استشارة"
}
```

---

### 2.5 Patients: Fast Search & Archive
- **Endpoint:** `GET /patients/search?q=0555` (or patient name)
- **Endpoint:** `GET /patients/{id}` (full appointment history)
- **Endpoint:** `PATCH /patients/{id}/status` (set `is_blocked` = true/false)

---

## 3. Ready-to-Use VBA Module for Access & Excel

Add this module (`mod_ClinicApi`) in Microsoft Access or Excel VBA editor (`Alt + F11`):

```vba
' ==============================================================================
' Module: mod_ClinicApi
' Description: Secure REST API Client for Clinic Server with Bearer Authentication
' ==============================================================================
Option Explicit

Private Const API_BASE_URL As String = "http://localhost:8000/api/v1/workstation"
Private strAuthToken As String

' 1. Log In and Store Token in Memory
Public Function ApiLogin(strEmail As String, strPassword As String) As Boolean
    Dim http As Object
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    
    Dim strPayload As String
    strPayload = "{""email"":""" & strEmail & """,""password"":""" & strPassword & """}"
    
    http.Open "POST", API_BASE_URL & "/login", False
    http.setRequestHeader "Content-Type", "application/json"
    http.setRequestHeader "Accept", "application/json"
    http.send strPayload
    
    If http.Status = 200 Then
        Dim resp As String
        resp = http.responseText
        Dim posToken As Long
        posToken = InStr(resp, """token"":""")
        If posToken > 0 Then
            Dim tokenStart As Long: tokenStart = posToken + 9
            Dim tokenEnd As Long: tokenEnd = InStr(tokenStart, resp, """")
            strAuthToken = Mid(resp, tokenStart, tokenEnd - tokenStart)
            ApiLogin = True
            MsgBox "تم تسجيل الدخول بنجاح!", vbInformation, "نجاح"
            Exit Function
        End If
    End If
    
    MsgBox "فشل تسجيل الدخول. رمز الخطأ: " & http.Status & vbCrLf & http.responseText, vbCritical, "خطأ"
    ApiLogin = False
End Function

' 2. Fetch Daily Appointments (JSON String)
Public Function FetchDailyAppointments(strDate As String) As String
    If Len(strAuthToken) = 0 Then
        MsgBox "يجب تسجيل الدخول أولاً!", vbExclamation
        Exit Function
    End If

    Dim http As Object
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    
    http.Open "GET", API_BASE_URL & "/appointments?date=" & strDate, False
    http.setRequestHeader "Authorization", "Bearer " & strAuthToken
    http.setRequestHeader "Accept", "application/json"
    http.send
    
    If http.Status = 200 Then
        FetchDailyAppointments = http.responseText
    Else
        MsgBox "خطأ في جلب البيانات: " & http.Status, vbCritical
        FetchDailyAppointments = ""
    End If
End Function

' 3. Update Attendance Status with Optimistic Concurrency Check
Public Function UpdateAttendanceStatus(lngApptId As Long, strStatus As String, bAttended As Boolean, lngClientVersion As Long) As Boolean
    If Len(strAuthToken) = 0 Then
        MsgBox "يجب تسجيل الدخول أولاً!", vbExclamation
        Exit Function
    End If

    Dim http As Object
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    
    Dim strPayload As String
    strPayload = "{""status"":""" & strStatus & """,""attended"":" & IIf(bAttended, "true", "false") & ",""client_version"":" & lngClientVersion & "}"
    
    http.Open "PATCH", API_BASE_URL & "/appointments/" & lngApptId & "/status", False
    http.setRequestHeader "Authorization", "Bearer " & strAuthToken
    http.setRequestHeader "Content-Type", "application/json"
    http.setRequestHeader "Accept", "application/json"
    http.send strPayload
    
    If http.Status = 200 Then
        MsgBox "تم حفظ التعديل بنجاح في السيرفر الرئيسي!", vbInformation
        UpdateAttendanceStatus = True
    ElseIf http.Status = 409 Then
        MsgBox "⚠️ تنبيه تعارض: تم تعديل أو إلغاء هذا الموعد من طرف الواتساب أو مستخدم آخر!" & vbCrLf & "يرجى الضغط على زر التحديث لإعادة قراءة الحالة من السيرفر.", vbExclamation, "تعارض في التعديل"
        UpdateAttendanceStatus = False
    Else
        MsgBox "حدث خطأ غير متوقع: " & http.Status & vbCrLf & http.responseText, vbCritical
        UpdateAttendanceStatus = False
    End If
End Function
```
