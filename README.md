# TwilioChristmasPanel (FPP v9.x)

Display incoming Twilio SMS messages on an FPP PixelOverlay/Matrix/P5 panel with optional profanity filtering and message queueing.

## Requirements
- Falcon Player (FPP) v9.x
- Twilio phone number with SMS webhooks enabled
- Network reachability from Twilio to your FPP instance

## Install
1. Copy the plugin files to your FPP plugins directory: `/home/fpp/media/plugins/TwilioChristmasPanel`.
2. Ensure it is owned by the fpp user: `sudo chown -R fpp:fpp /home/fpp/media/plugins/TwilioChristmasPanel`.
3. Restart FPPD (UI button or `/opt/fpp/scripts/stop && /opt/fpp/scripts/start`).
4. Open FPP UI → Content Setup → Plugins → Twilio Christmas Panel to configure.

## Configure the plugin
On the plugin settings page:
- **Twilio Auth Token**: paste the auth token from your Twilio console (used for webhook signature validation).
- **Twilio From Number**: your Twilio phone number (for reference only).
- **P5 Panel Target Name**: the PixelOverlay/Matrix target name (e.g., `MatrixPanel1`).
- **Enable profanity filtering**: toggle API + local regex filtering.
- **Profanity API Endpoint**: defaults to `https://www.purgomalum.com/service/json`.
- **Scroll Speed**: delay (ms) between pixel shifts; lower is faster.
- Save settings.
- Use **Send Test Message to Panel** to verify output.
- Use **Clear Queue** to empty pending messages.

## Twilio webhook setup
1. In Twilio console, open your phone number → Messaging → A MESSAGE COMES IN.
2. Set the webhook to:
   - `https://<your-fpp-host-or-ip>/plugin.php?plugin=TwilioChristmasPanel&command=twilioWebhook`
   - or `https://<your-fpp-host-or-ip>/plugin/TwilioChristmasPanel/twilioWebhook`
3. Method: `POST`.
4. Save changes.

Signature validation uses `X-Twilio-Signature` with your configured auth token. If the token is missing or the signature fails, the request is rejected.

## How it works
1. Webhook receives SMS `Body` and `From`.
2. Profanity filter (API first, local regex fallback) runs; if ≥70% of words are flagged, the message is blocked and replaced.
3. Prepends `Merry Christmas: ` to the cleaned text.
4. Enqueues the message in `data/TwilioChristmasPanel.json`.
5. Sends each queued message to the panel via `/api/command/PixelOverlayText` (scrolling left).

## API routes (GET/POST)
- `/plugin.php?plugin=TwilioChristmasPanel&command=getQueue`
- `/plugin.php?plugin=TwilioChristmasPanel&command=clearQueue`
- `/plugin.php?plugin=TwilioChristmasPanel&command=addTestMessage&message=Hello`
- Direct form: `/plugin/TwilioChristmasPanel/getQueue`, etc.

## Logs
- Main plugin log: `/home/fpp/logs/TwilioChristmasPanel.log`
- Also mirrored entries in `/home/fpp/logs/fppd.log`

## Testing checklist
- Use the settings page “Send Test Message to Panel” to confirm PixelOverlay output.
- Send an SMS to your Twilio number; verify it scrolls on the panel and appears in the log.
- Check `getQueue` API for pending items if messages do not display.

## Troubleshooting
- If messages fail to display, confirm the panel target name matches your PixelOverlay target.
- If signature validation fails, verify the Twilio Auth Token matches the number’s token in Twilio console.
- If the profanity API is unreachable, the plugin falls back to a local regex censor; adjust the endpoint or disable filtering if needed.
