<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// CORS preflight handler for all /api/* endpoints.
// Allowed websites are set in .env (CORS_ALLOWED_ORIGINS), see App\Libraries\Cors.
$routes->options('api/(:any)', static function () {
    $response = service('response')->setStatusCode(204);
    $response->setHeader('Access-Control-Max-Age', '600');
    return \App\Libraries\Cors::apply($response, service('request')->getHeaderLine('Origin'));
});

// ---------- PUBLIC (no login needed) ----------
$routes->post('api/auth/login', 'Api\AuthController::login');
$routes->post('api/auth/forgot-password', 'Api\AuthController::forgotPassword');
$routes->post('api/auth/verify-otp', 'Api\AuthController::verifyOtp');
$routes->post('api/auth/reset-password', 'Api\AuthController::resetPassword');

// ---------- EMAIL OTP FOR REGISTRATION (Admin/Staff only) ----------
$routes->post('api/email/send-otp', 'Api\EmailVerificationController::sendOtp', ['filter' => 'role:Admin,Staff']);
$routes->post('api/email/verify-otp', 'Api\EmailVerificationController::verifyOtp', ['filter' => 'role:Admin,Staff']);

// ---------- PATIENTS ----------
$routes->post('api/patients', 'Api\PatientController::register', ['filter' => 'role:Admin,Staff']);
$routes->get('api/patients', 'Api\PatientController::index', ['filter' => 'role:Admin,Staff,Doctor']);
$routes->get('api/patients/(:num)', 'Api\PatientController::show/$1', ['filter' => 'role:Admin,Staff,Doctor']);
$routes->delete('api/patients/(:num)', 'Api\PatientController::delete/$1', ['filter' => 'role:Admin']);
$routes->put('api/patients/(:num)/disease', 'Api\PatientController::updateDisease/$1', ['filter' => 'role:Doctor']);
$routes->get('api/patients/me', 'Api\PatientController::me', ['filter' => 'role:Patient']);

// ---------- FACE VERIFICATION (Admin/Staff, only for patients of their own center) ----------
$routes->get('api/patients/(:num)/face', 'Api\FaceController::status/$1', ['filter' => 'role:Admin,Staff']);
$routes->post('api/patients/(:num)/face', 'Api\FaceController::enroll/$1', ['filter' => 'role:Admin,Staff']);
$routes->post('api/patients/(:num)/face/verify', 'Api\FaceController::verify/$1', ['filter' => 'role:Admin,Staff']);
$routes->post('api/patients/(:num)/face/override', 'Api\FaceController::override/$1', ['filter' => 'role:Admin,Staff']);
$routes->delete('api/patients/(:num)/face', 'Api\FaceController::delete/$1', ['filter' => 'role:Admin']);
$routes->get('api/face/enabled', 'Api\FaceController::enabled', ['filter' => 'role:Admin,Staff']);
$routes->post('api/face/identify', 'Api\FaceController::identify', ['filter' => 'role:Admin,Staff']);

// ---------- APPOINTMENTS ----------
$routes->get('api/appointments', 'Api\AppointmentController::index', ['filter' => 'role:Admin,Staff,Doctor,Patient']);
$routes->post('api/appointments', 'Api\AppointmentController::create', ['filter' => 'role:Admin,Staff,Doctor,Patient']);
$routes->put('api/appointments/(:num)/status', 'Api\AppointmentController::updateStatus/$1', ['filter' => 'role:Admin,Staff,Doctor']);
$routes->delete('api/appointments/(:num)', 'Api\AppointmentController::delete/$1', ['filter' => 'role:Admin,Staff']);

// ---------- PRESCRIPTIONS ----------
$routes->get('api/prescriptions', 'Api\PrescriptionController::index', ['filter' => 'role:Admin,Staff,Doctor,Patient']);
$routes->post('api/prescriptions', 'Api\PrescriptionController::create', ['filter' => 'role:Doctor']);

// ---------- VISITS / QUEUE ----------
$routes->get('api/visits', 'Api\VisitController::index', ['filter' => 'role:Admin,Staff,Doctor,Patient']);
$routes->get('api/visits/(:num)', 'Api\VisitController::show/$1', ['filter' => 'role:Admin,Staff,Doctor,Patient']);
$routes->post('api/visits/check-in', 'Api\VisitController::checkIn', ['filter' => 'role:Admin,Staff']);
$routes->post('api/visits/call-next', 'Api\VisitController::callNext', ['filter' => 'role:Admin,Staff']);
$routes->put('api/visits/(:num)/status', 'Api\VisitController::updateStatus/$1', ['filter' => 'role:Admin,Staff,Doctor']);

// ---------- MEDICAL RECORDS ----------
$routes->get('api/medical-records', 'Api\MedicalRecordController::index', ['filter' => 'role:Admin,Staff,Doctor,Patient']);
$routes->get('api/medical-records/(:num)', 'Api\MedicalRecordController::show/$1', ['filter' => 'role:Admin,Staff,Doctor,Patient']);
$routes->post('api/medical-records', 'Api\MedicalRecordController::create', ['filter' => 'role:Doctor']);

// ---------- QR CODES ----------
$routes->get('api/qr-codes', 'Api\QrCodeController::index', ['filter' => 'role:Admin']);
$routes->post('api/qr-codes/generate', 'Api\QrCodeController::generate', ['filter' => 'role:Admin']);
$routes->get('api/qr-codes/lookup/(:any)', 'Api\QrCodeController::lookup/$1', ['filter' => 'role:Admin,Staff']);

// ---------- DISEASE STATS / OUTBREAK WARNING (any logged-in user, totals only) ----------
$routes->get('api/disease-stats', 'Api\DiseaseStatsController::index', ['filter' => 'role']);

// ---------- MASTER ADMIN SUMMARY (counts only, no patient data) ----------
$routes->get('api/admin/summary', 'Api\AdminSummaryController::index', ['filter' => 'role:Master Admin']);

// ---------- HEALTH CENTERS ----------
$routes->get('api/health-centers', 'Api\HealthCenterController::index', ['filter' => 'role']);
$routes->post('api/health-centers', 'Api\HealthCenterController::create', ['filter' => 'role:Master Admin']);

// ---------- USER MANAGEMENT (Master Admin = all centers, Admin = own center) + ADMIN LOGS + MASTER ADMIN BACKUP ----------
$routes->get('api/users', 'Api\UserController::index', ['filter' => 'role:Master Admin,Admin,Staff']);
$routes->post('api/users', 'Api\UserController::create', ['filter' => 'role:Master Admin,Admin']);
$routes->put('api/users/(:num)/status', 'Api\UserController::updateStatus/$1', ['filter' => 'role:Master Admin,Admin']);
$routes->delete('api/users/(:num)', 'Api\UserController::delete/$1', ['filter' => 'role:Master Admin,Admin']);
$routes->put('api/users/(:num)/restore', 'Api\UserController::restore/$1', ['filter' => 'role:Master Admin,Admin']);
$routes->get('api/roles', 'Api\RoleController::index', ['filter' => 'role:Master Admin,Admin']);
$routes->get('api/activity-logs', 'Api\ActivityLogController::index', ['filter' => 'role:Admin']);
$routes->get('api/backup/export', 'Api\BackupController::export', ['filter' => 'role:Master Admin,Admin,strict']);
$routes->post('api/backup/restore', 'Api\BackupController::restore', ['filter' => 'role:Master Admin,Admin,strict']);

// ---------- SETTINGS ----------
$routes->get('api/settings', 'Api\SettingsController::index', ['filter' => 'role']);
$routes->put('api/settings', 'Api\SettingsController::update', ['filter' => 'role:Master Admin,strict']);

// ---------- PROFILE (any logged-in user) ----------
$routes->put('api/profile', 'Api\ProfileController::updateProfile', ['filter' => 'role']);
$routes->put('api/profile/password', 'Api\ProfileController::changePassword', ['filter' => 'role']);