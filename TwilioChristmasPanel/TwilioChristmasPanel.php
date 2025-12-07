<?php

// Main logic for the TwilioChristmasPanel FPP plugin.

define('TWCP_PLUGIN_NAME', 'TwilioChristmasPanel');
define('TWCP_PLUGIN_PATH', __DIR__);
define('TWCP_DATA_DIR', TWCP_PLUGIN_PATH . '/data');
define('TWCP_QUEUE_FILE', TWCP_DATA_DIR . '/TwilioChristmasPanel.json');
define('TWCP_SETTINGS_FILE', TWCP_DATA_DIR . '/settings.json');
define('TWCP_LOG_FILE', '/home/fpp/logs/TwilioChristmasPanel.log');

class TwilioChristmasPanel
{
    public static function getSettings()
    {
        self::ensureDataDirectory();
        if (!file_exists(TWCP_SETTINGS_FILE)) {
            return self::defaultSettings();
        }

        $contents = file_get_contents(TWCP_SETTINGS_FILE);
        if ($contents === false) {
            return self::defaultSettings();
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return self::defaultSettings();
        }

        return array_merge(self::defaultSettings(), $decoded);
    }

    public static function saveSettings($settings)
    {
        self::ensureDataDirectory();
        $merged = array_merge(self::defaultSettings(), $settings);
        file_put_contents(TWCP_SETTINGS_FILE, json_encode($merged, JSON_PRETTY_PRINT));
        self::logMessage('Settings saved');
    }

    public static function handleCommand($command)
    {
        $command = strtolower(trim($command));
        switch ($command) {
            case 'twiliowebhook':
                return self::handleWebhook();
            case 'clearqueue':
                self::clearQueue();
                self::respondJson(array('status' => 'ok', 'cleared' => true));
                return;
            case 'addtestmessage':
                $msg = isset($_REQUEST['message']) ? $_REQUEST['message'] : 'Test message from TwilioChristmasPanel';
                self::addTestMessage($msg);
                self::respondJson(array('status' => 'ok', 'added' => $msg));
                return;
            case 'getqueue':
                $queue = self::getQueueData();
                self::respondJson($queue);
                return;
            default:
                http_response_code(400);
                echo 'Unknown command';
                return;
        }
    }

    public static function handleWebhook()
    {
        self::logMessage('Incoming Twilio webhook received');
        $settings = self::getSettings();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            self::logMessage('Webhook rejected: non-POST method');
            return;
        }

        if (empty($settings['twilioAuthToken'])) {
            http_response_code(500);
            echo 'Twilio auth token not configured';
            self::logMessage('Webhook rejected: Twilio auth token missing');
            return;
        }

        $providedSignature = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE']) ? $_SERVER['HTTP_X_TWILIO_SIGNATURE'] : '';
        if (!self::validateTwilioSignature($settings['twilioAuthToken'], $_POST, $providedSignature)) {
            http_response_code(403);
            echo 'Invalid signature';
            self::logMessage('Webhook rejected: invalid Twilio signature');
            return;
        }

        $body = isset($_POST['Body']) ? trim($_POST['Body']) : '';
        $from = isset($_POST['From']) ? $_POST['From'] : '';

        if ($body === '') {
            http_response_code(400);
            echo 'Empty message';
            self::logMessage('Webhook rejected: empty Body');
            return;
        }

        $blocked = false;
        $cleaned = self::filterMessage($body, $settings, $blocked);
        $finalText = $blocked
            ? 'Merry Christmas: [Message blocked due to inappropriate content]'
            : 'Merry Christmas: ' . $cleaned;

        $payload = array(
            'id' => uniqid('msg_', true),
            'from' => $from,
            'raw' => $body,
            'text' => $finalText,
            'receivedAt' => time(),
        );

        self::enqueueMessage($payload);
        self::dispatchQueue($settings);

        header('Content-Type: text/xml');
        $response = '<Response><Message>Thanks! Your Christmas message will be displayed shortly.</Message></Response>';
        echo $response;
        self::logMessage('Webhook processed for sender ' . $from);
    }

    public static function clearQueue()
    {
        self::saveQueue(array('queue' => array(), 'lastDisplayed' => null));
        self::logMessage('Queue cleared via API');
    }

    public static function addTestMessage($text)
    {
        $finalText = 'Merry Christmas: ' . $text;
        $payload = array(
            'id' => uniqid('test_', true),
            'from' => 'Test',
            'raw' => $text,
            'text' => $finalText,
            'receivedAt' => time(),
        );
        self::enqueueMessage($payload);
        self::dispatchQueue(self::getSettings());
        self::logMessage('Test message added: ' . $text);
    }

    public static function getQueueData()
    {
        $data = self::loadQueue();
        return array(
            'queueLength' => count($data['queue']),
            'queue' => $data['queue'],
            'lastDisplayed' => isset($data['lastDisplayed']) ? $data['lastDisplayed'] : null,
        );
    }

    private static function filterMessage($message, $settings, &$blocked)
    {
        $blocked = false;
        $stats = self::profanityStats($message);
        $ratio = ($stats['total'] > 0) ? ($stats['flagged'] / $stats['total']) : 0;

        if ($ratio >= 0.7) {
            $blocked = true;
            self::logMessage('Message blocked due to high profanity ratio');
            return $message;
        }

        if (isset($settings['profanityFilterEnabled']) && !$settings['profanityFilterEnabled']) {
            return $message;
        }

        $apiEndpoint = isset($settings['profanityApiEndpoint']) && $settings['profanityApiEndpoint'] !== ''
            ? $settings['profanityApiEndpoint']
            : 'https://www.purgomalum.com/service/json';

        $filtered = self::callProfanityApi($message, $apiEndpoint);
        if ($filtered !== null) {
            return $filtered;
        }

        $filtered = self::applyLocalCensor($message, $stats);
        self::logMessage('Used local profanity censor fallback');
        return $filtered;
    }

    private static function callProfanityApi($message, $endpoint)
    {
        $url = rtrim($endpoint, '/') . '?text=' . urlencode($message);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $result = curl_exec($ch);
        if ($result === false) {
            self::logMessage('Profanity API call failed: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            self::logMessage('Profanity API HTTP error: ' . $code);
            return null;
        }

        $json = json_decode($result, true);
        if (is_array($json) && isset($json['result'])) {
            return $json['result'];
        }

        // Some APIs return plain text instead of JSON.
        if (!empty($result)) {
            return trim($result);
        }

        return null;
    }

    private static function applyLocalCensor($message, &$stats)
    {
        $badWords = self::profanityWordList();
        $pattern = '/\b(' . implode('|', array_map('preg_quote', $badWords)) . ')\b/i';
        $stats = self::profanityStats($message);
        return preg_replace($pattern, '***', $message);
    }

    private static function profanityStats($message)
    {
        $badWords = self::profanityWordList();
        $words = preg_split('/\s+/', strtolower($message));
        $flagged = 0;
        $total = 0;

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $total++;
            foreach ($badWords as $bad) {
                if (strpos($word, $bad) !== false) {
                    $flagged++;
                    break;
                }
            }
        }

        return array('flagged' => $flagged, 'total' => $total);
    }

    private static function profanityWordList()
    {
        // Minimal list for fallback censoring.
        return array(
            'damn', 'hell', 'shit', 'fuck', 'bitch', 'bastard', 'asshole', 'crap'
        );
    }

    private static function enqueueMessage($payload)
    {
        self::withQueue(function (&$data) use ($payload) {
            if (!isset($data['queue']) || !is_array($data['queue'])) {
                $data['queue'] = array();
            }
            $data['queue'][] = $payload;
        });
        self::logMessage('Message queued: ' . $payload['id']);
    }

    private static function dispatchQueue($settings)
    {
        $speed = isset($settings['scrollSpeed']) ? intval($settings['scrollSpeed']) : 60;
        $speed = ($speed <= 0) ? 60 : $speed;

        self::withQueue(function (&$data) use ($speed, $settings) {
            if (!isset($data['queue']) || count($data['queue']) === 0) {
                return;
            }

            while (count($data['queue']) > 0) {
                $message = array_shift($data['queue']);
                $ok = self::sendToPanel($message['text'], $settings);
                $data['lastDisplayed'] = $message;

                if (!$ok) {
                    // Put message back at the front if sending failed.
                    array_unshift($data['queue'], $message);
                    self::logMessage('Send failed, message returned to queue: ' . $message['id'], 'ERROR');
                    break;
                }

                // Approximate pause to allow text to scroll before showing the next message.
                $pause = self::calculateDisplayPause($message['text'], $speed);
                sleep($pause);
            }
        });
    }

    private static function calculateDisplayPause($text, $speed)
    {
        $lengthFactor = max(2, ceil(strlen($text) / 15));
        $speedFactor = max(1, min(10, intval($speed / 10)));
        return max(2, min(20, $lengthFactor + (10 - $speedFactor)));
    }

    private static function sendToPanel($text, $settings)
    {
        $panelName = isset($settings['panelName']) && $settings['panelName'] !== '' ? $settings['panelName'] : 'MatrixPanel1';
        $delay = isset($settings['scrollSpeed']) ? intval($settings['scrollSpeed']) : 60;
        if ($delay < 5) {
            $delay = 5;
        }

        $payload = array(
            'target' => $panelName,
            'text' => $text,
            'color' => '#00FF00',
            'font' => '6x10',
            'antialias' => true,
            'centerText' => true,
            'backgroundColor' => '#000000',
            'direction' => 'left',
            'repeat' => 1,
            'delay' => $delay
        );

        $ch = curl_init('http://localhost/api/command/PixelOverlayText');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $response = curl_exec($ch);
        if ($response === false) {
            self::logMessage('Error sending to panel: ' . curl_error($ch), 'ERROR');
            curl_close($ch);
            return false;
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            self::logMessage('Sent to panel "' . $panelName . '": ' . $text);
            return true;
        }

        self::logMessage('Panel API responded with HTTP ' . $code . ' body: ' . $response, 'ERROR');
        return false;
    }

    private static function validateTwilioSignature($authToken, $params, $providedSignature)
    {
        if ($providedSignature === '') {
            return false;
        }

        $url = self::getRequestUrl();
        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key . $value;
        }

        $computed = base64_encode(hash_hmac('sha1', $data, $authToken, true));
        return hash_equals($computed, $providedSignature);
    }

    private static function getRequestUrl()
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        return $scheme . '://' . $host . $uri;
    }

    private static function loadQueue()
    {
        self::ensureDataDirectory();
        if (!file_exists(TWCP_QUEUE_FILE)) {
            return array('queue' => array(), 'lastDisplayed' => null);
        }

        $contents = file_get_contents(TWCP_QUEUE_FILE);
        if ($contents === false) {
            return array('queue' => array(), 'lastDisplayed' => null);
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return array('queue' => array(), 'lastDisplayed' => null);
        }

        if (!isset($decoded['queue']) || !is_array($decoded['queue'])) {
            $decoded['queue'] = array();
        }

        return $decoded;
    }

    private static function saveQueue($data)
    {
        self::ensureDataDirectory();
        file_put_contents(TWCP_QUEUE_FILE, json_encode($data, JSON_PRETTY_PRINT));
    }

    private static function withQueue($callback)
    {
        self::ensureDataDirectory();
        $fh = fopen(TWCP_QUEUE_FILE, 'c+');
        if (!$fh) {
            self::logMessage('Unable to open queue file', 'ERROR');
            return;
        }

        flock($fh, LOCK_EX);
        $size = filesize(TWCP_QUEUE_FILE);
        $contents = $size > 0 ? fread($fh, $size) : '';
        $data = $contents ? json_decode($contents, true) : null;
        if (!is_array($data)) {
            $data = array('queue' => array(), 'lastDisplayed' => null);
        }

        $callback($data);

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private static function ensureDataDirectory()
    {
        if (!is_dir(TWCP_DATA_DIR)) {
            mkdir(TWCP_DATA_DIR, 0775, true);
        }

        $logDir = dirname(TWCP_LOG_FILE);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
    }

    private static function defaultSettings()
    {
        return array(
            'twilioAuthToken' => '',
            'twilioFromNumber' => '',
            'panelName' => 'MatrixPanel1',
            'profanityFilterEnabled' => true,
            'profanityApiEndpoint' => 'https://www.purgomalum.com/service/json',
            'scrollSpeed' => 60
        );
    }

    public static function logMessage($message, $level = 'INFO')
    {
        self::ensureDataDirectory();
        $line = sprintf("[%s] [%s] %s\n", date('c'), strtoupper($level), $message);
        file_put_contents(TWCP_LOG_FILE, $line, FILE_APPEND);
        // Mirror important entries to the main FPP log for visibility.
        error_log($line, 3, '/home/fpp/logs/fppd.log');
    }

    private static function respondJson($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}

// Allow CLI dispatch for manual queue processing if desired.
if (php_sapi_name() === 'cli' && isset($argv[1]) && strtolower($argv[1]) === 'dispatch') {
    TwilioChristmasPanel::dispatchQueue(TwilioChristmasPanel::getSettings());
    exit(0);
}
