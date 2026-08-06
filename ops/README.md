# TDEChat — production safety net

Backups, a health watchdog, and log rotation. TDEChat is the **only** store for
every conversation and transcript, so these are not optional on a live box.

Assumes the app is at `/var/www/tdechat` (override with `TDECHAT_DIR`).

| File | What it does |
|------|--------------|
| `backup-db.sh` | Daily `pg_dump` → `/var/backups/tdechat`, integrity-checked, pruned, optional off-box copy |
| `restore-db.sh` | Restore a dump (into prod or a throwaway DB for drills) |
| `healthcheck.sh` | Alerts if a service is down or the queue is failing jobs |
| `systemd/*` | Timers that run the two scripts |

---

## 1. Log rotation (do this first — it's just `.env`)
Stop `storage/logs/laravel.log` growing forever by using the daily channel:

```dotenv
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=info
```
Then `php backend/artisan config:cache && sudo systemctl restart php8.4-fpm`.
(nginx/apache and php-fpm logs already rotate via their own package logrotate.)

## 2. Backups
```bash
# needs the postgres client
sudo apt install -y postgresql-client

# install the timer
sudo cp /var/www/tdechat/ops/systemd/tdechat-backup.{service,timer} /etc/systemd/system/
# add an OFF-BOX target (a backup on the same box is only half a backup):
sudo systemctl edit tdechat-backup.service     # add:  [Service]\nEnvironment=TDECHAT_BACKUP_RSYNC=user@host:/backups/tdechat
sudo systemctl daemon-reload
sudo systemctl enable --now tdechat-backup.timer

# run one now to prove it works
sudo systemctl start tdechat-backup.service
journalctl -u tdechat-backup.service -n 20 --no-pager
ls -lh /var/backups/tdechat
```

## 3. Test the restore (the part everyone skips)
A backup you have never restored is not a backup. Drill it into a throwaway DB:
```bash
# create a scratch DB on the same server, then:
sudo TDECHAT_DIR=/var/www/tdechat /var/www/tdechat/ops/restore-db.sh \
     /var/backups/tdechat/tdechat_YYYY-MM-DD_HHMMSS.dump tdechat_restore_test
# point a temporary .env DB_DATABASE at tdechat_restore_test and confirm the app boots.
```
Do this at least once now, and after any major change. Delete the scratch DB when done.

## 4. Health watchdog
```bash
sudo cp /var/www/tdechat/ops/systemd/tdechat-healthcheck.{service,timer} /etc/systemd/system/
# set an alert channel (Slack/Teams webhook or email):
sudo systemctl edit tdechat-healthcheck.service   # add:  [Service]\nEnvironment=TDECHAT_ALERT_WEBHOOK=https://hooks.slack.com/services/...
sudo systemctl daemon-reload
sudo systemctl enable --now tdechat-healthcheck.timer

# force a check
sudo systemctl start tdechat-healthcheck.service
journalctl -u tdechat-healthcheck.service -n 20 --no-pager
```
It checks `php8.4-fpm` + the three `tdechat-*` workers and the `failed_jobs` count.
Override the watched units per box with `TDECHAT_SERVICES` if they're named differently.

## Verify the timers
```bash
systemctl list-timers 'tdechat-*'
```

## Notes
- The backup runs as **root** on purpose: dumps contain all PII, so they live in a
  `700` root-only directory. Keep the off-box target locked down the same way.
- Retention/anonymisation of the *data itself* is separate — that's the System
  Settings → Data retention feature, not these backups.
