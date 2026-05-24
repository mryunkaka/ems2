# Firebase Realtime Chat Setup

Widget live chat global dan counter visitor online memakai Firebase Realtime Database + Firebase Anonymous Auth.
File setup yang sama juga dipakai untuk fitur live music global.

## Alasan teknis

- Cocok untuk shared hosting/cPanel karena koneksi realtime tidak dibebankan ke PHP server.
- Presence online didukung native lewat `onDisconnect()`.
- Bisa menampilkan jumlah tab/browser yang sedang membuka website secara realtime.

## Langkah setup

1. Buat project di Firebase Console.
2. Tambahkan Web App dan salin konfigurasi Firebase.
3. Aktifkan `Authentication -> Sign-in method -> Anonymous`.
4. Buat `Realtime Database`.
5. Isi variabel berikut di `.env`:

```env
FIREBASE_API_KEY=...
FIREBASE_AUTH_DOMAIN=your-project.firebaseapp.com
FIREBASE_DATABASE_URL=https://your-project-default-rtdb.asia-southeast1.firebasedatabase.app
FIREBASE_PROJECT_ID=your-project
FIREBASE_APP_ID=...
FIREBASE_MESSAGING_SENDER_ID=...
FIREBASE_MEASUREMENT_ID=
FIREBASE_PRESENCE_PATH=ems_presence/live_visitors
FIREBASE_CHAT_ROOM_PATH=ems_live_chat/global_room/messages
FIREBASE_CHAT_MAX_MESSAGES=40
FIREBASE_MUSIC_QUEUE_PATH=ems_live_music/global_room/queue
FIREBASE_MUSIC_STATE_PATH=ems_live_music/global_room/state
FIREBASE_MUSIC_QUEUE_MAX_ITEMS=25
```

## Rules yang disarankan

Atur Realtime Database Rules seperti ini:

```json
{
  "rules": {
    "ems_presence": {
      ".read": "auth != null",
      ".write": "auth != null",
      "$group": {
        "$sessionId": {
          ".validate": "newData.hasChildren(['authUid','senderId','name','pageTitle','pagePath'])"
        }
      }
    },
    "ems_live_chat": {
      ".read": "auth != null",
      ".write": "auth != null",
      "$room": {
        "$channel": {
          "$messageId": {
            ".validate": "newData.hasChildren(['authUid','senderId','senderName','text']) && newData.child('text').isString() && newData.child('text').val().length <= 500"
          }
        }
      }
    },
    "ems_live_music": {
      ".read": "auth != null",
      ".write": "auth != null",
      "$room": {
        "queue": {
          "$trackId": {
            ".validate": "newData.hasChildren(['authUid','addedByName','sourceType','playbackType','title','url'])"
          }
        },
        "state": {
          ".validate": "newData.val() == null || newData.hasChildren(['queueId','title','sourceType','playbackType','status'])"
        }
      }
    }
  }
}
```

## Catatan perilaku

- Counter online menghitung sesi/tab aktif, bukan deduplikasi per manusia.
- Jika satu user membuka 3 tab, counter akan naik 3.
- Chat muncul global di layout footer selama konfigurasi Firebase aktif.
- Live music sinkron saat ini aman untuk `audio direct` dan `YouTube`.
- Link `Spotify` atau `TikTok` disimpan sebagai antrian referensi, bukan ekstraksi audio otomatis.
