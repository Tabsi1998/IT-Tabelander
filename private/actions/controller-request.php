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

ensure_contact_session();
$sessionToken = (string) ($_SESSION['contact_form']['token'] ?? '');
$formToken = trim((string) ($_POST['form_token'] ?? ''));

if ($sessionToken === '' || $formToken === '' || !hash_equals($sessionToken, $formToken)) {
    redirect_controller_configurator('invalid');
}

$selection = build_controller_selection($_POST);

if (!$selection['valid']) {
    redirect_controller_configurator('incomplete');
}

$controllerRequestId = store_controller_request($selection);

store_contact_form_flash([
    'name' => '',
    'email' => '',
    'phone' => '',
    'audience' => 'Gaming-Hardware',
    'service' => 'Konsole & Controller',
    'message' => (string) $selection['message'],
    'privacyConfirmation' => '',
    'controllerRequestId' => $controllerRequestId,
]);

header('Location: /?controller=ready#kontakt', true, 303);
exit;
