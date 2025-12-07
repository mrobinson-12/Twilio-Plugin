<?php
require_once __DIR__ . '/TwilioChristmasPanel.php';

$settings = TwilioChristmasPanel::getSettings();
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_settings'])) {
        $settings['twilioAuthToken'] = isset($_POST['twilioAuthToken']) ? trim($_POST['twilioAuthToken']) : '';
        $settings['twilioFromNumber'] = isset($_POST['twilioFromNumber']) ? trim($_POST['twilioFromNumber']) : '';
        $settings['panelName'] = isset($_POST['panelName']) ? trim($_POST['panelName']) : 'MatrixPanel1';
        $settings['profanityFilterEnabled'] = isset($_POST['profanityFilterEnabled']) ? true : false;
        $settings['profanityApiEndpoint'] = isset($_POST['profanityApiEndpoint']) ? trim($_POST['profanityApiEndpoint']) : 'https://www.purgomalum.com/service/json';
        $settings['scrollSpeed'] = isset($_POST['scrollSpeed']) ? intval($_POST['scrollSpeed']) : 60;

        TwilioChristmasPanel::saveSettings($settings);
        $status = 'Settings saved';
    }

    if (isset($_POST['send_test'])) {
        $testText = isset($_POST['testMessage']) && $_POST['testMessage'] !== '' ? $_POST['testMessage'] : 'This is a test message';
        TwilioChristmasPanel::addTestMessage($testText);
        $status = 'Test message sent';
    }

    if (isset($_POST['clear_queue'])) {
        TwilioChristmasPanel::clearQueue();
        $status = 'Queue cleared';
    }

    $settings = TwilioChristmasPanel::getSettings();
}

$queueInfo = TwilioChristmasPanel::getQueueData();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Twilio Christmas Panel Settings</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 720px; margin: 0 auto; }
        .card { border: 1px solid #ccc; padding: 16px; margin-bottom: 18px; border-radius: 8px; }
        .card h2 { margin-top: 0; }
        label { display: block; margin-bottom: 6px; font-weight: bold; }
        input[type="text"], input[type="password"], input[type="number"] { width: 100%; padding: 8px; margin-bottom: 10px; }
        .row { display: flex; gap: 16px; }
        .row .col { flex: 1; }
        .status { padding: 10px; background: #e8f5e9; border: 1px solid #c8e6c9; color: #256029; border-radius: 6px; margin-bottom: 12px; }
        .button { padding: 8px 12px; margin-right: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Twilio Christmas Panel</h1>
        <?php if ($status !== ''): ?>
            <div class="status"><?php echo htmlspecialchars($status); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Plugin Settings</h2>
            <form method="post">
                <label for="twilioAuthToken">Twilio Auth Token</label>
                <input type="password" id="twilioAuthToken" name="twilioAuthToken" value="<?php echo htmlspecialchars($settings['twilioAuthToken']); ?>" required>

                <label for="twilioFromNumber">Twilio From Number</label>
                <input type="text" id="twilioFromNumber" name="twilioFromNumber" value="<?php echo htmlspecialchars($settings['twilioFromNumber']); ?>" placeholder="+15551234567">

                <label for="panelName">P5 Panel Target Name</label>
                <input type="text" id="panelName" name="panelName" value="<?php echo htmlspecialchars($settings['panelName']); ?>" placeholder="MatrixPanel1">

                <label>
                    <input type="checkbox" name="profanityFilterEnabled" <?php echo $settings['profanityFilterEnabled'] ? 'checked' : ''; ?>>
                    Enable profanity filtering
                </label>

                <label for="profanityApiEndpoint">Profanity API Endpoint</label>
                <input type="text" id="profanityApiEndpoint" name="profanityApiEndpoint" value="<?php echo htmlspecialchars($settings['profanityApiEndpoint']); ?>">

                <label for="scrollSpeed">Scroll Speed (delay in ms between shifts; lower is faster)</label>
                <input type="number" id="scrollSpeed" name="scrollSpeed" min="5" max="500" value="<?php echo htmlspecialchars($settings['scrollSpeed']); ?>">

                <button class="button" type="submit" name="save_settings">Save Settings</button>
            </form>
        </div>

        <div class="card">
            <h2>Testing Tools</h2>
            <form method="post">
                <label for="testMessage">Send Test Message to Panel</label>
                <input type="text" id="testMessage" name="testMessage" value="Happy Holidays from FPP!">
                <button class="button" type="submit" name="send_test">Send Test Message</button>
            </form>
        </div>

        <div class="card">
            <h2>Queue Management</h2>
            <p>Queued messages: <?php echo intval($queueInfo['queueLength']); ?></p>
            <?php if (!empty($queueInfo['queue'])): ?>
                <ul>
                    <?php foreach ($queueInfo['queue'] as $item): ?>
                        <li><?php echo htmlspecialchars($item['text']); ?> (from <?php echo htmlspecialchars($item['from']); ?>)</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post">
                <button class="button" type="submit" name="clear_queue">Clear Queue</button>
            </form>
        </div>

        <div class="card">
            <h2>Webhook URL</h2>
            <p>Configure Twilio to POST SMS webhooks to:</p>
            <code><?php echo htmlspecialchars((!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/plugin.php?plugin=TwilioChristmasPanel&command=twilioWebhook'); ?></code>
        </div>
    </div>
</body>
</html>
