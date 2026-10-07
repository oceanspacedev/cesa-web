# Queue Worker Setup

Panduan ini dipakai untuk menjalankan queue worker yang aman untuk notifikasi email dan WhatsApp lintas plugin:

- `form-transfer`
- `exit-clearance`
- `rekrutmen`

Queue yang dipakai produksi:

- `default` untuk job umum
- `rekrutmen-ai` untuk screening CV (biasanya digabung dengan `default`)
- `notifications` untuk email notification
- `whatsapp` untuk WhatsApp notification
- `cesa-exports` untuk export Filament (exit clearance, dll)

App production path: `/var/www/web-cesa-new`.

Worker systemd system (`web-cesa-queue@.service`) harus memakai path itu.
Kalau unit masih mengarah ke `/var/www/web-cesa` lama, jalankan:

```bash
bash scripts/retarget-system-queue-workers.sh
```

User systemd (akun `developer`, linger enabled) juga menjalankan worker di `~/.config/systemd/user/web-cesa-new-*.service`.

## Rekomendasi Worker

Gunakan jumlah process berikut:

- `default` + `rekrutmen-ai`: `numprocs=2`
- `notifications`: `numprocs=1`
- `whatsapp`: `numprocs=1`
- `cesa-exports`: `numprocs=1`

Alasan:

- queue `default` boleh lebih longgar karena tidak semua job adalah outbound notification
- queue `notifications` sengaja dibatasi agar email tidak blast
- queue `whatsapp` sengaja dibatasi agar pengiriman ke provider tidak terlalu agresif
- queue `cesa-exports` harus punya worker sendiri; kalau tidak, export Filament akan menumpuk

## `.env`

Gunakan konfigurasi berikut:

```env
QUEUE_CONNECTION=database
CACHE_STORE=database

FORM_TRANSFER_NOTIFICATION_QUEUE=notifications
EXIT_CLEARANCE_NOTIFICATION_QUEUE=notifications
REKRUTMEN_NOTIFICATION_QUEUE=notifications

NOTIFICATION_MAIL_THROTTLE_ENABLED=true
NOTIFICATION_MAIL_THROTTLE_MIN_INTERVAL=2
NOTIFICATION_MAIL_THROTTLE_MAX_INTERVAL=5
NOTIFICATION_MAIL_THROTTLE_KEY=global

WHATSAPP_API_ENDPOINT=https://waghub.mekayastudio.com
WHATSAPP_API_KEY=your-token

WHATSAPP_COUNTRY_CODE=62
WHATSAPP_QUEUE=whatsapp
WHATSAPP_CONNECTION=null
WHATSAPP_TIMEOUT=10
WHATSAPP_TRIES=3
WHATSAPP_BACKOFF=10,30,60
WHATSAPP_THROTTLE_ENABLED=true
WHATSAPP_THROTTLE_MIN_INTERVAL=5
WHATSAPP_THROTTLE_MAX_INTERVAL=10
WHATSAPP_THROTTLE_KEY=global
```

## Manual Worker Commands

Jalankan worker berikut bila tidak memakai Supervisor:

```bash
php artisan queue:work database --queue=rekrutmen-ai,default --sleep=1 --tries=3 --backoff=5 --timeout=120 --max-time=3600

php artisan queue:work database --queue=notifications --sleep=1 --tries=3 --backoff=10 --timeout=120 --max-time=3600

php artisan queue:work database --queue=whatsapp --sleep=1 --tries=3 --backoff=10 --timeout=120 --max-time=3600

php artisan queue:work database --queue=cesa-exports --sleep=1 --tries=3 --backoff=5 --timeout=300 --max-time=3600
```

## Supervisor Example

Contoh konfigurasi Supervisor:

```ini
[program:web-cesa-default]
command=php /var/www/web-cesa-new/artisan queue:work database --queue=rekrutmen-ai,default --sleep=1 --tries=3 --backoff=5 --timeout=120 --max-time=3600
directory=/var/www/web-cesa-new
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile=/var/log/supervisor/web-cesa-default.log

[program:web-cesa-notifications]
command=php /var/www/web-cesa-new/artisan queue:work database --queue=notifications --sleep=1 --tries=3 --backoff=10 --timeout=120 --max-time=3600
directory=/var/www/web-cesa-new
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile=/var/log/supervisor/web-cesa-notifications.log

[program:web-cesa-whatsapp]
command=php /var/www/web-cesa-new/artisan queue:work database --queue=whatsapp --sleep=1 --tries=3 --backoff=10 --timeout=120 --max-time=3600
directory=/var/www/web-cesa-new
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile=/var/log/supervisor/web-cesa-whatsapp.log

[program:web-cesa-exports]
command=php /var/www/web-cesa-new/artisan queue:work database --queue=cesa-exports --sleep=1 --tries=3 --backoff=5 --timeout=300 --max-time=3600
directory=/var/www/web-cesa-new
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
numprocs=1
process_name=%(program_name)s_%(process_num)02d
redirect_stderr=true
stdout_logfile=/var/log/supervisor/web-cesa-exports.log
```

## Apply Changes

Setelah update `.env` atau Supervisor, jalankan:

```bash
php artisan config:cache
php artisan queue:restart
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart web-cesa-default:*
sudo supervisorctl restart web-cesa-notifications:*
sudo supervisorctl restart web-cesa-whatsapp:*
```

## Important Notes

- `CACHE_STORE` harus shared antar worker atau antar server
- `database` aman dipakai, `redis` lebih baik
- jangan gunakan cache `file` bila worker berjalan di banyak process atau banyak server
- throttle global email dan WhatsApp mengandalkan shared cache
- queue `notifications` dan `whatsapp` sebaiknya tetap satu process agar ritme kirim tetap konservatif

## Safer Option

Kalau provider WhatsApp atau SMTP Anda sensitif, gunakan interval yang lebih konservatif:

```env
NOTIFICATION_MAIL_THROTTLE_MIN_INTERVAL=3
NOTIFICATION_MAIL_THROTTLE_MAX_INTERVAL=6
WHATSAPP_THROTTLE_MIN_INTERVAL=10
WHATSAPP_THROTTLE_MAX_INTERVAL=15
```
