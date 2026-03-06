function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");

  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }

  return outputArray;
}

async function initPush() {
  if (!("serviceWorker" in navigator)) {
    alert("Browser tidak mendukung push notification");
    return;
  }

  if (!("PushManager" in window)) {
    alert("PushManager tidak tersedia");
    return;
  }

  const permission = await Notification.requestPermission();
  if (permission !== "granted") {
    alert("Izin notifikasi ditolak");
    return;
  }

  console.log("Permission:", Notification.permission);

  // 1️⃣ Register SW
  await navigator.serviceWorker.register("/sw.js");

  // 2️⃣ Tunggu sampai AKTIF
  const registration = await navigator.serviceWorker.ready;
  console.log("SW active:", registration.active);

  // 3️⃣ Subscribe
  const subscription = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(PUSH_PUBLIC_KEY),
  });

  console.log("Subscription:", subscription);

  // 4️⃣ Simpan ke server
  await fetch("/actions/save_push_subscription.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(subscription.toJSON()),
  });

  // Tampilkan indikator notif aktif
  const indicator = document.querySelector(".notif-indicator");
  if (indicator) {
    indicator.classList.remove("hidden");
  }

  alert("Notifikasi berhasil diaktifkan");
}
