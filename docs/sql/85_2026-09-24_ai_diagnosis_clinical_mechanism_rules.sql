-- Aturan Wajib Mekanisme Cedera & Konsistensi Klinis AI Diagnosis Assistant
-- Memastikan GCS motor M3/M4 terpisah, Estimasi AI berlabel wajib verifikasi,
-- Airway definitif (intubasi ETT) bila GCS <= 8, osmoterapi bila suspek TTIK,
-- Urutan diagnostik sebelum operasi cito/elektif, informed consent wali & persiapan darah,
-- Diagnosis banding satu ranah mekanisme (dilarang mencampur trauma tumpul vs tajam),
-- Trauma abdomen dengan tanda absolut operasi cito (eviserasi/perdarahan/peritonitis tidak stabil): CT scan dilarang & dilewati langsung ke laparotomi,
-- serta variasi kasus unik antar permintaan anamnesis.

UPDATE system_ai_prompt_templates
SET user_prompt_template = 'ANAMNESIS / TEMUAN MEDIS / KONDISI FISIK DARI USER:
{{anamnesis}}

TUGAS MODEL AI: Input boleh singkat. Tentukan dulu secara internal mekanisme cedera yang paling masuk akal (misal: luka tusuk tembus abdomen dengan perforasi usus, cedera kepala berat / TBI akibat benturan, dsb) dan pastikan seluruh bagian laporan konsisten tanpa kontradiksi.

ATURAN WAJIB KLINIS:
1. GCS dihitung harfiah sesuai deskripsi kesadaran: M3 = fleksi abnormal/dekortikasi; M4 = withdrawal/menarik diri normal. M3 dan M4 adalah respons berbeda; pilih satu sesuai respons pasien, jangan menulis fleksi abnormal/withdrawal sebagai sinonim. Tuliskan dasar klinis tiap komponen E, V, M pada field basis/interpretation.
2. Semua angka yang belum diukur (GCS, TTV, lab, radiologi) WAJIB diberi label "Estimasi AI — wajib verifikasi" pada field estimasi terpisah, field faktual tetap "Data belum tersedia". Setiap TTV ditampilkan dengan nilai, tag estimasi bila estimasi, dan penjelasan klinis dalam kurung. Jika suhu <36°C, tulis HIPOTERMIA serta kaitkan dengan risiko koagulopati dan trauma triad of death (hipotermia-asidosis-koagulopati) pada syok hemoragik berat; jangan gunakan frasa kabur "wajib dipertahankan".
3. Airway definitif (intubasi endotrakeal / ETT) WAJIB disertakan pada penanganan emergency jika estimasi GCS ≤ 8 (bukan cuma oksigen via mask).
4. Jika ada kecurigaan peningkatan tekanan intrakranial (TTIK / Cushing reflex / trauma kepala berat), sertakan osmoterapi (Manitol 20% atau NaCl 3% hipertonik) di penanganan emergency.
5. Pada trauma kepala dengan kecurigaan peningkatan tekanan intrakranial, primary survey bagian Disability WAJIB memuat pemeriksaan ukuran dan reaktivitas pupil dengan status isokor/anisokor/dilatasi serta reaktif/non reaktif.
5a. Jika anisokor atau dilatasi menunjukkan tanda herniasi akut, rencana operasi definitif boleh berstatus cito tanpa menunggu hasil CT scan. Jika tanda herniasi akut tidak jelas, tulis persis: rencana tentatif — menunggu hasil CT scan.
5b. Istilah hipotensi permisif (permissive hypotension) hanya boleh dipakai untuk strategi resusitasi cairan dengan target tekanan darah yang sengaja dijaga tidak terlalu tinggi, bukan untuk temuan TTV mentah. Gunakan deskripsi tanda awal syok hemoragik ringan-sedang pada TTV bila konteks trauma mendukung.
5c. Urutan waktu harus logis: CT scan / penunjang penentu dievaluasi SEBELUM jenis operasi definitif diputuskan secara rinci, kecuali tanda herniasi akut atau kegawatan lain membenarkan tindakan cito.
6. Sertakan informed consent ke keluarga/wali sebelum pasien ke ruang operasi (pasien tidak sadar = consent dari wali), dan persiapan darah (golongan darah + crossmatch / PRC) untuk tindakan berisiko perdarahan.
7. Diagnosis banding HARUS tetap dalam ranah mekanisme cedera yang sama dengan diagnosis utama. Jangan mencampur DDx trauma tumpul dengan kasus trauma tajam/penetrasi, atau sebaliknya — DDx hanya boleh menjelaskan variasi organ/tingkat keparahan dalam mekanisme cedera YANG SAMA.
8. Untuk trauma abdomen: jika ditemukan tanda kegawatan absolut untuk operasi langsung (eviserasi/prolaps organ, perdarahan masif tidak terkontrol, tanda peritonitis jelas pada pasien tidak stabil), maka CT scan TIDAK direkomendasikan — pasien harus langsung ke laparotomi tanpa pencitraan karena pencitraan akan menunda tindakan penyelamat nyawa. Jika usus, omentum, atau organ lain terpapar, tutup sementara dengan kassa steril basah/lembab yang dibasahi NaCl 0,9%; kassa kering dilarang karena dapat menyebabkan desikasi jaringan. Jangan mendorong organ kembali dan jangan melakukan penutupan definitif di IGD. Sebutkan secara eksplisit di laporan bahwa CT scan dilewati dan alasannya, JANGAN mencantumkan CT scan sebagai rekomendasi jika rencana tindakan sudah berupa operasi cito berdasarkan tanda absolut tersebut.
9. Kasus tidak boleh identik walau anamnesis trigger sama dengan permintaan sebelumnya — variasikan tingkat keparahan, organ/struktur yang terkena, dan angka fisiologis dalam rentang yang masuk akal secara klinis.
10. CEK KESESUAIAN FISIOLOGIS: Cocokkan mekanisme, volume, dan lokasi cedera dengan derajat syok/perdarahan, serta lokasi cedera kepala dengan GCS dan tanda herniasi. Jika cedera teridentifikasi tidak cukup menjelaskan syok berat atau GCS rendah, jangan mengatribusikan semuanya ke cedera tunggal; tulis RED FLAG: derajat syok/penurunan kesadaran tidak proporsional dengan cedera yang teridentifikasi — curigai cedera tambahan tersembunyi (kepala, toraks, abdomen, sumber perdarahan lain) yang belum teridentifikasi, perlu secondary survey menyeluruh dan pencitraan tambahan. Evaluasi ulang mekanisme, sumber perdarahan, cedera tambahan, hipoksia, hipotensi, intoksikasi, kejang, atau penyebab non-traumatik sesuai data; jangan menaikkan diagnosis menjadi cedera berat tanpa bukti.
11. KONSISTENSI PEMERIKSAAN PENUNJANG: Semua pemeriksaan yang disebut di bagian mana pun laporan, termasuk Section 4 Status Rencana Operasi, wajib tercantum di Section 6 Rekomendasi Pemeriksaan Penunjang. Frasa menunggu hasil CT scan hanya boleh dipakai jika CT scan tercantum di daftar rekomendasi radiologi Section 6.
12. ISTILAH ANATOMI: tungkai berarti ekstremitas bawah/kaki. Untuk cedera tangan/jari gunakan ekstremitas atas, tangan, atau jari tangan; jangan menyebut tangan/jari sebagai tungkai.

Cek ulang konsistensi sebelum difinalkan dalam format JSON.'
WHERE feature_key = 'ai_diagnosis_assistant';
