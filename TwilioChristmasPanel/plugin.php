<?php

require_once __DIR__ . '/TwilioChristmasPanel.php';

function GetPluginInfo()
{
    return array(
        'name' => 'TwilioChristmasPanel',
        'displayName' => 'Twilio Christmas Panel',
        'author' => 'OpenAI Codex',
        'email' => '',
        'homepage' => 'https://example.com',
        'version' => '1.0.0',
        'requires' => '9.0',
        'hasSettings' => true
    );
}

function GetPluginMetaData()
{
    return array(
        'name' => 'TwilioChristmasPanel',
        'displayName' => 'Twilio Christmas Panel',
        'version' => '1.0.0',
        'author' => 'OpenAI Codex',
        'homepage' => 'https://example.com'
    );
}

function GetPluginInfoFromJSON()
{
    $json = file_get_contents(__DIR__ . '/pluginInfo.json');
    return json_decode($json, true);
}

// Main command dispatcher for plugin HTTP routes:
//   /plugin.php?plugin=TwilioChristmasPanel&command=twilioWebhook
//   /plugin.php?plugin=TwilioChristmasPanel&command=clearQueue
//   /plugin.php?plugin=TwilioChristmasPanel&command=addTestMessage
//   /plugin.php?plugin=TwilioChristmasPanel&command=getQueue
function ProcessCommand($command)
{
    TwilioChristmasPanel::handleCommand($command);
}

// Optional hook so other FPP components can push data to this plugin.
function ProcessInput($type, $data)
{
    if (strtolower($type) === 'twiliowebhook') {
        TwilioChristmasPanel::handleCommand('twiliowebhook');
    }
}

// Notification hook kept for completeness; currently unused.
function ProcessNotification($notification, $data)
{
    // No notifications handled at this time.
}

// Allow direct access via /plugin/TwilioChristmasPanel/<command>
// in addition to the standard /plugin.php?plugin=TwilioChristmasPanel&command=<command>.
if (php_sapi_name() !== 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    $cmd = '';

    if (isset($_GET['command'])) {
        $cmd = $_GET['command'];
    } elseif (isset($_SERVER['REQUEST_URI'])) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $parts = explode('/', trim($path, '/'));
        // Expecting /plugin/TwilioChristmasPanel/{command}
        $pluginIndex = array_search('TwilioChristmasPanel', $parts, true);
        if ($pluginIndex !== false && isset($parts[$pluginIndex + 1])) {
            $cmd = $parts[$pluginIndex + 1];
        }
    }

    if ($cmd !== '') {
        ProcessCommand($cmd);
    } else {
        http_response_code(400);
        echo 'Missing command';
    }
    exit;
}
