# Components (UI Only)

Komponen di folder ini adalah partial PHP yang sifatnya presentasional. Tujuannya mengurangi duplikasi markup dan memastikan UI konsisten.

Aturan:
- Jangan taruh query DB, validasi bisnis, atau perubahan flow di sini.
- Komponen boleh menerima `$props` sederhana dan slot `callable` untuk body/actions.

Cara pakai (opsional):
```php
require_once __DIR__ . '/../assets/design/ui/component.php';

ems_component('ui/card', [
  'title' => 'Judul',
  'body' => function () {
    echo '<p class="meta-text">Isi card</p>';
  },
]);
```

