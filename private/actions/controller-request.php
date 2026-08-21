<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/site-services.php';

function redirect_controller_configurator(string $status = ''): never
{
    $query = $status !== '' ? '?config=' . urlencode($status) : '';
    header('Location: /controller-service-telfs' . $query . '#konfigurator', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect_controller_configurator();
}

if (trim((string) ($_POST['website'] ?? '')) !== '') {
    redirect_controller_configurator('success');
}

$values = controller_submission_values($_POST);
$selection = build_controller_selection($_POST);
$firstName = trim((string) $values['firstName']);
$lastName = trim((string) $values['lastName']);
$preferredContact = in_array($values['preferredContact'], ['email', 'phone'], true)
    ? (string) $values['preferredContact']
    : 'email';

$submission = [
    'name' => trim($firstName . ' ' . $lastName),
    'email' => trim((string) $values['email']),
    'phone' => trim((string) $values['phone']),
    'address' => trim((string) $values['address']),
    'postalCode' => trim((string) $values['postalCode']),
    'city' => trim((string) $values['city']),
    'audience' => 'Gaming-Hardware',
    'service' => 'Controller-Upgrade-Konfiguration',
    'message' => (string) ($selection['message'] ?? 'Controller-Upgrade-Anfrage'),
    'privacyConfirmation' => trim((string) $values['privacyConfirmation']),
    'captchaAnswer' => trim((string) ($_POST['captcha_answer'] ?? '')),
    'formRenderedAt' => (int) ($_POST['form_rendered_at'] ?? 0),
    'formToken' => trim((string) ($_POST['form_token'] ?? '')),
    'preferredContact' => $preferredContact,
];

$validation = validate_contact_submission($siteConfig, $submission);
$errors = array_values(array_unique(array_merge(
    is_array($selection['errors'] ?? null) ? $selection['errors'] : [],
    is_array($validation['errors'] ?? null) ? $validation['errors'] : [],
    $firstName === '' ? ['firstName'] : [],
    $lastName === '' ? ['lastName'] : []
)));

if (($selection['valid'] ?? false) !== true || ($validation['valid'] ?? false) !== true || $errors !== []) {
    store_controller_form_flash($values, $errors);
    redirect_controller_configurator('incomplete');
}

$selection['message'] .= "\nBevorzugte Rückmeldung: " . ($preferredContact === 'phone' ? 'Telefon' : 'E-Mail') . '.';
$controllerRequestId = bin2hex(random_bytes(16));
$requestId = bin2hex(random_bytes(16));
$dolibarrResult = create_dolibarr_controller_proposal(
    $siteConfig,
    $submission,
    $selection,
    $controllerRequestId,
    $requestId
);

if (($dolibarrResult['ok'] ?? false) !== true) {
    store_controller_form_flash($values, [], [
        'requestId' => $requestId,
    ]);
    redirect_controller_configurator('erp_error');
}

redirect_controller_configurator('success');
