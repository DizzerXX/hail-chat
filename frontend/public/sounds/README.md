# Notification sounds

The agent dashboard plays these when an idle prompt fires. Drop your audio files
here with the exact names below (they are served at `/sounds/<name>`).

Selectable notification sounds (admin picks one on **Admin → Chat settings**),
listed in `backend/config/chat.php` under `notification_sounds`:

- notification-1.mp3
- notification-2.mp3
- notification-3.mp3
- notification-4.mp3
- notification-5.mp3
- notification-6.mp3
- notification-7.mp3
- notification-8.mp3
- notification-9.mp3

Fixed customer-idle warning alert (not selectable):

- warning.mp3

Missing files simply play nothing — the feature still works. To add or rename
options, edit `notification_sounds` in `backend/config/chat.php` and place the
matching file here.
