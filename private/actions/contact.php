<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/site-services.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_home('error');
}

if (trim((string) ($_POST['website'] ?? '')) !== '') {
    redirect_home('success');
}

$submission = [
    'name' => trim((string) ($_POST['name'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'phone' => trim((string) ($_POST['phone'] ?? '')),
    'audience' => trim((string) ($_POST['audience'] ?? '')),
    'service' => trim((string) ($_POST['service'] ?? '')),
    'message' => sanitize_multiline((string) ($_POST['message'] ?? '')),
    'privacyConfirmation' => trim((string) ($_POST['privacy_confirmation'] ?? '')),
    'captchaAnswer' => trim((string) ($_POST['captcha_answer'] ?? '')),
    'formRenderedAt' => (int) ($_POST['form_rendered_at'] ?? 0),
    'formToken' => trim((string) ($_POST['form_token'] ?? '')),
];

$validation = validate_contact_submission($siteConfig, $submission);

if (!$validation['valid']) {
    store_contact_form_flash(contact_submission_values($submission), $validation['errors']);
    redirect_home('error');
}

$mailResult = send_contact_mail($siteConfig, $submission);

redirect_home(match (true) {
    $mailResult['ownerSent'] && $mailResult['customerSent'] => 'success',
    $mailResult['ownerSent'] => 'partial',
    default => 'error',
});
