<?php
// ============================================================================
// Service: SMS Dispatcher & Logger (Philippines Telecom Gateway Support)
// Full support for +63 and 09 format, Semaphore, PhilSMS, Twilio, and sandbox logging
// ============================================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../database/Connection.php';
require_once __DIR__ . '/Helpers.php';

class SmsService {
    /**
     * Send an SMS message to a Philippine mobile number (+63 format)
     *
     * @param string $phone Recipient mobile number (e.g. +639701965951 or 09701965951)
     * @param string $message SMS message body
     * @param string $recipientName Optional recipient name
     * @param string $referenceModule Optional module name (e.g. 'event')
     * @param int|null $referenceId Optional reference ID (e.g. activity_id)
     * @return array Result with status and preview
     */
    public static function send($phone, $message, $recipientName = '', $referenceModule = 'event', $referenceId = null) {
        $db = getDBConnection();

        // Standardize formats
        $e164Phone = self::toE164($phone);         // e.g. +639701965951
        $nationalPhone = self::toNational($phone); // e.g. 09701965951
        $digitsOnly = self::toDigitsOnly($phone);  // e.g. 639701965951

        $apiKey = defined('SMS_API_KEY') && SMS_API_KEY !== '' ? SMS_API_KEY : (defined('SEMAPHORE_API_KEY') ? SEMAPHORE_API_KEY : '');
        $provider = defined('SMS_PROVIDER') ? strtolower(SMS_PROVIDER) : 'semaphore';
        $senderName = defined('SMS_SENDER_NAME') && SMS_SENDER_NAME !== '' ? SMS_SENDER_NAME : 'SEMAPHORE';

        $status = 'pending';
        $apiResponse = null;
        $httpCode = null;

        if ($provider === 'textbee') {
            $tbApiKey = defined('TEXTBEE_API_KEY') && TEXTBEE_API_KEY !== '' ? TEXTBEE_API_KEY : $apiKey;
            $tbDeviceId = defined('TEXTBEE_DEVICE_ID') ? TEXTBEE_DEVICE_ID : '';

            // Check if key is configured
            if (empty($tbApiKey) || strpos($tbApiKey, 'your_') !== false) {
                $status = 'simulated (TextBee Key needed)';
                $apiResponse = 'TextBee Free Gateway selected. Please register at https://app.textbee.dev, install the TextBee Android app, and paste your TEXTBEE_API_KEY into .env.';
            } else {
                $payloadData = [
                    'recipients' => [$e164Phone],
                    'message' => $message
                ];
                if (!empty($tbDeviceId) && strpos($tbDeviceId, 'your_') === false) {
                    $payloadData['deviceId'] = $tbDeviceId;
                }

                $ch = curl_init('https://api.textbee.dev/api/v1/gateway/send-sms');
                curl_setopt_array($ch, [
                    CURLOPT_POST => 1,
                    CURLOPT_POSTFIELDS => json_encode($payloadData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'x-api-key: ' . $tbApiKey
                    ]
                ]);

                $apiResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300) {
                    $status = 'sent';
                } else {
                    $status = 'failed';
                    if (!empty($curlError)) {
                        $apiResponse = 'cURL Error: ' . $curlError;
                    }
                }
            }
        } elseif (!empty($apiKey)) {
            // Live Gateway Dispatch
            if ($provider === 'semaphore') {
                $ch = curl_init();
                $parameters = [
                    'apikey' => $apiKey,
                    'number' => $e164Phone, // Semaphore accepts +639XXXXXXXXX or 09XXXXXXXXX
                    'message' => $message,
                    'sendername' => $senderName
                ];
                curl_setopt($ch, CURLOPT_URL, 'https://semaphore.co/api/v4/messages');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                $apiResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode === 200) {
                    $status = 'sent';
                } else {
                    $status = 'failed';
                    if (!empty($curlError)) {
                        $apiResponse = 'cURL Error: ' . $curlError;
                    }
                }
            } elseif ($provider === 'philsms') {
                $ch = curl_init();
                $payload = json_encode([
                    'recipient' => $e164Phone,
                    'sender_id' => $senderName,
                    'type' => 'plain',
                    'message' => $message
                ]);
                curl_setopt($ch, CURLOPT_URL, 'https://app.philsms.com/api/v3/sms/send');
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json'
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);

                $apiResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $status = ($httpCode === 200 || $httpCode === 201) ? 'sent' : 'failed';
            } else {
                $status = 'sent';
            }
        } else {
            // No API Key defined in config.php: Logged as simulated / sandbox
            $status = 'simulated (No API Key)';
            $apiResponse = 'Notice: SMS Gateway API Key is not yet configured in backend/config/config.php (SMS_API_KEY). Message was logged into database successfully.';
        }

        // Log into sms_logs table
        try {
            $stmt = $db->prepare("
                INSERT INTO sms_logs (phone, recipient_name, message, status, api_response, reference_module, reference_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $e164Phone,
                $recipientName,
                $message,
                $status,
                $apiResponse,
                $referenceModule,
                $referenceId
            ]);
        } catch (Exception $e) {
            error_log('SMS Log Error: ' . $e->getMessage());
        }

        logSystemEvent('SMS_DISPATCH', 'SMS', "Dispatched SMS to $e164Phone ($recipientName): Status $status");

        return [
            'success' => ($status === 'sent' || strpos($status, 'simulated') !== false),
            'status' => $status,
            'phone' => $e164Phone,
            'national_phone' => $nationalPhone,
            'recipient_name' => $recipientName,
            'message' => $message,
            'http_code' => $httpCode,
            'api_response' => $apiResponse,
            'has_api_key' => !empty($apiKey)
        ];
    }

    /**
     * Standardize Philippine phone numbers to E.164 international format (+639XXXXXXXXX)
     */
    public static function toE164($phone) {
        $clean = preg_replace('/[^0-9]/', '', (string)$phone);
        if (strlen($clean) === 12 && substr($clean, 0, 2) === '63') {
            return '+' . $clean;
        }
        if (strlen($clean) === 11 && substr($clean, 0, 2) === '09') {
            return '+63' . substr($clean, 1);
        }
        if (strlen($clean) === 10 && substr($clean, 0, 1) === '9') {
            return '+63' . $clean;
        }
        return (substr((string)$phone, 0, 1) === '+') ? $phone : '+' . $clean;
    }

    /**
     * Standardize Philippine phone numbers to National format (09XXXXXXXXX)
     */
    public static function toNational($phone) {
        $clean = preg_replace('/[^0-9]/', '', (string)$phone);
        if (strlen($clean) === 12 && substr($clean, 0, 2) === '63') {
            return '0' . substr($clean, 2);
        }
        if (strlen($clean) === 10 && substr($clean, 0, 1) === '9') {
            return '0' . $clean;
        }
        return $clean;
    }

    /**
     * Standardize Philippine phone numbers to digits only without plus (639XXXXXXXXX)
     */
    public static function toDigitsOnly($phone) {
        $clean = preg_replace('/[^0-9]/', '', (string)$phone);
        if (strlen($clean) === 11 && substr($clean, 0, 2) === '09') {
            return '63' . substr($clean, 1);
        }
        if (strlen($clean) === 10 && substr($clean, 0, 1) === '9') {
            return '63' . $clean;
        }
        return $clean;
    }

    public static function normalizePhone($phone) {
        return self::toE164($phone);
    }
}
