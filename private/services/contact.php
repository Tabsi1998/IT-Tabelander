<?php
declare(strict_types=1);

function redirect_home(string $status): never
{
    header('Location: /?contact=' . urlencode($status) . '#kontakt', true, 303);
    exit;
}

function sanitize_multiline(string $value): string
{
    $clean = trim(str_replace(["\r\n", "\r"], "\n", $value));
    $clean = preg_replace('/\n{3,}/', "\n\n", $clean) ?? $clean;

    return trim($clean);
}

function ensure_contact_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }

    session_name('ittabelander_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function store_contact_form_flash(array $values, array $errors = [], array $meta = []): void
{
    ensure_contact_session();

    $_SESSION['contact_form']['flash'] = [
        'values' => $values,
        'errors' => array_values(array_unique($errors)),
        'meta' => $meta,
    ];
}

function consume_contact_form_flash(): array
{
    ensure_contact_session();

    $flash = $_SESSION['contact_form']['flash'] ?? [];
    unset($_SESSION['contact_form']['flash']);

    return is_array($flash) ? $flash : [];
}

function contact_submission_values(array $submission): array
{
    return [
        'name' => trim((string) ($submission['name'] ?? '')),
        'email' => trim((string) ($submission['email'] ?? '')),
        'phone' => trim((string) ($submission['phone'] ?? '')),
        'audience' => trim((string) ($submission['audience'] ?? '')),
        'service' => trim((string) ($submission['service'] ?? '')),
        'message' => trim((string) ($submission['message'] ?? '')),
        'privacyConfirmation' => trim((string) ($submission['privacyConfirmation'] ?? '')),
    ];
}

function contact_error_message(array $errors): string
{
    $fieldLabels = [
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'audience' => 'Anliegen',
        'service' => 'Leistung',
        'message' => 'Nachricht mit mindestens 12 Zeichen',
    ];

    if (in_array('privacyConfirmation', $errors, true)) {
        return 'Bitte bestätigen Sie die Datenschutzerklärung, bevor die Anfrage gesendet wird.';
    }

    if (in_array('captcha', $errors, true)) {
        return 'Die Sicherheitsfrage wurde nicht korrekt beantwortet. Bitte prüfen Sie die Eingabe.';
    }

    if (in_array('token', $errors, true) || in_array('timing', $errors, true)) {
        return 'Das Formular wurde ungültig übermittelt. Bitte laden Sie die Seite kurz neu und senden Sie die Anfrage erneut.';
    }

    $missingFields = array_values(array_intersect_key($fieldLabels, array_flip($errors)));

    if ($missingFields !== []) {
        return 'Bitte prüfen Sie folgende Angaben: ' . implode(', ', $missingFields) . '.';
    }

    return 'Bitte prüfen Sie die Pflichtfelder und die E-Mail-Adresse. Die Anfrage konnte noch nicht übermittelt werden.';
}

function build_contact_form_view_model(array $siteConfig): array
{
    ensure_contact_session();

    $security = $siteConfig['security'] ?? [];
    $captchaConfig = $security['captcha'] ?? [];
    $captchaEnabled = (bool) ($captchaConfig['enabled'] ?? false);
    $captchaQuestion = '';

    if ($captchaEnabled) {
        $captcha = $_SESSION['contact_form']['captcha'] ?? null;

        if (!is_array($captcha) || empty($captcha['question']) || !isset($captcha['answer'])) {
            $left = random_int(2, 9);
            $right = random_int(1, 9);
            $captcha = [
                'question' => sprintf('Bitte lösen: %d + %d', $left, $right),
                'answer' => (string) ($left + $right),
                'generatedAt' => time(),
            ];
            $_SESSION['contact_form']['captcha'] = $captcha;
        }

        $captchaQuestion = (string) $captcha['question'];
    }

    if (empty($_SESSION['contact_form']['token'])) {
        $_SESSION['contact_form']['token'] = bin2hex(random_bytes(16));
    }

    return [
        'captchaEnabled' => $captchaEnabled,
        'captchaLabel' => (string) ($captchaConfig['label'] ?? 'Sicherheitsfrage'),
        'captchaQuestion' => $captchaQuestion,
        'formToken' => (string) $_SESSION['contact_form']['token'],
        'renderedAt' => time(),
    ];
}

function validate_contact_submission(array $siteConfig, array $submission): array
{
    ensure_contact_session();

    $messageLength = function_exists('mb_strlen')
        ? mb_strlen((string) ($submission['message'] ?? ''))
        : strlen((string) ($submission['message'] ?? ''));

    $errors = [];

    if (trim((string) ($submission['name'] ?? '')) === '') {
        $errors[] = 'name';
    }

    if (!filter_var((string) ($submission['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'email';
    }

    if (trim((string) ($submission['audience'] ?? '')) === '') {
        $errors[] = 'audience';
    }

    if (trim((string) ($submission['service'] ?? '')) === '') {
        $errors[] = 'service';
    }

    if ($messageLength < 12) {
        $errors[] = 'message';
    }

    if (($siteConfig['security']['privacyConsentRequired'] ?? true) && ($submission['privacyConfirmation'] ?? '') !== '1') {
        $errors[] = 'privacyConfirmation';
    }

    $minFormSeconds = max(0, (int) ($siteConfig['security']['minFormSeconds'] ?? 0));
    $renderedAt = (int) ($submission['formRenderedAt'] ?? 0);

    if ($renderedAt <= 0 || (time() - $renderedAt) < $minFormSeconds) {
        $errors[] = 'timing';
    }

    $sessionToken = (string) ($_SESSION['contact_form']['token'] ?? '');
    $formToken = trim((string) ($submission['formToken'] ?? ''));

    if ($sessionToken === '' || !hash_equals($sessionToken, $formToken)) {
        $errors[] = 'token';
    }

    $captchaConfig = $siteConfig['security']['captcha'] ?? [];
    if ((bool) ($captchaConfig['enabled'] ?? false)) {
        $expectedAnswer = (string) ($_SESSION['contact_form']['captcha']['answer'] ?? '');
        $submittedAnswer = trim((string) ($submission['captchaAnswer'] ?? ''));

        if ($expectedAnswer === '' || $submittedAnswer === '' || !hash_equals($expectedAnswer, $submittedAnswer)) {
            $errors[] = 'captcha';
        }
    }

    if ($errors === []) {
        unset($_SESSION['contact_form']);
    }

    return [
        'valid' => $errors === [],
        'errors' => $errors,
    ];
}
