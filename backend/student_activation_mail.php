<?php
require_once __DIR__ . '/config.php';

function deliverStudentActivationEmails(array $invitations): array
{
    $apiKey = env('RESEND_API_KEY', '');
    $from = env('ACTIVATION_FROM_EMAIL', '');
    $baseUrl = rtrim(env('APP_BASE_URL', ''), '/');
    $failed = 0;

    if ($invitations === []) {
        return ['sent' => 0, 'failed' => 0];
    }

    $baseParts = parse_url($baseUrl);
    $validBaseUrl = is_array($baseParts)
        && in_array(strtolower((string)($baseParts['scheme'] ?? '')), ['https', 'http'], true)
        && !empty($baseParts['host'])
        && (strtolower((string)$baseParts['scheme']) === 'https'
            || in_array(strtolower((string)$baseParts['host']), ['localhost', '127.0.0.1'], true));

    if ($apiKey === '' || $from === '' || !$validBaseUrl || !function_exists('curl_init')) {
        error_log('Student activation email delivery is not configured or cURL is unavailable.');
        return ['sent' => 0, 'failed' => count($invitations)];
    }

    $sent = 0;
    foreach (array_chunk($invitations, 100) as $batchIndex => $batch) {
        $emails = [];
        foreach ($batch as $invitation) {
            $name = htmlspecialchars($invitation['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $activationUrl = $baseUrl . '/frontend/index.html#activate=' . rawurlencode($invitation['token']);
            $emails[] = [
                'from' => $from,
                'to' => [$invitation['email']],
                'subject' => 'Activate your NMIMS Hostel Leave Portal account',
                'html' => '<p>Hello ' . $name . ',</p>'
                    . '<p>Your student account is ready. Use the secure link below to set your password:</p>'
                    . '<p><a href="' . htmlspecialchars($activationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Activate my account</a></p>'
                    . '<p>This link expires in 24 hours and can only be used once. If you did not expect this email, you can ignore it.</p>',
                'text' => "Hello {$invitation['name']},\n\nYour student account is ready. Open this secure link to set your password:\n"
                    . $activationUrl
                    . "\n\nThis link expires in 24 hours and can only be used once. If you did not expect this email, you can ignore it.",
            ];
        }

        $curl = curl_init('https://api.resend.com/emails/batch');
        if ($curl === false) {
            $failed += count($batch);
            error_log('Unable to initialize student activation email request.');
            continue;
        }
        $payload = json_encode($emails, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($payload)) {
            curl_close($curl);
            $failed += count($batch);
            error_log('Unable to encode student activation email batch.');
            continue;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $batchSent = $status >= 200 && $status < 300
            && is_array($response)
            && isset($response['data'])
            && is_array($response['data'])
            && count($response['data']) === count($batch);

        if ($batchSent) {
            $sent += count($batch);
        } else {
            $failed += count($batch);
            error_log('Student activation email batch failed (HTTP ' . $status . '). ' . ($curlError !== '' ? 'Transport error.' : 'Provider rejected or returned an incomplete batch.'));
        }

        if ($batchIndex < (int)ceil(count($invitations) / 100) - 1) {
            usleep(600000);
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}
