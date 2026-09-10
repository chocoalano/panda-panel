# Audit Ulang Input Tanggal dan Waktu

Tanggal: 10 September 2026. Snapshot source: commit `f33f9ef`; working tree bersih sebelum audit.

Saat pemeriksaan akhir terdapat edit lokal terpisah pada `DateField.vue`: class picker berubah dari `w-full max-w-60` menjadi `w-full`. Edit tersebut dipertahankan dan diperiksa lewat diff; pemakaian `PanelDatePicker` serta kesimpulan audit temporal tidak berubah.

**Status: belum memenuhi ketentuan. Ada 3 lokasi renderer aktif yang menghasilkan input temporal native: 2 literal dan 1 melalui binding dinamis.** Audit ini menghasilkan dokumentasi; implementasi belum diubah.

Ketentuan pengguna: seluruh pemilih tanggal/waktu harus menggunakan komponen yang sudah tersedia. Larangan diterapkan pada `input[type=date]`, `input[type=time]`, `input[type=datetime]`, dan `input[type=datetime-local]`, termasuk yang dibungkus `<Input>` atau dihasilkan melalui binding dinamis.

Larangan mengenai kontrol DOM, bukan nama metadata. `field.type = 'date'`, aturan validasi `date`, dan class PHP `DateTimePicker` tetap diperlukan dan bukan pelanggaran.

## 1. Pelanggaran aktif

| ID | Lokasi | Pelanggaran | Pengganti dari komponen yang tersedia |
| --- | --- | --- | --- |
| DT01 | [TimeField.vue:33](../resources/js/panel/forms/fields/TimeField.vue#L33) | `<Input type="time">` menghasilkan input waktu native. | Susun `Select`, `SelectTrigger`, `SelectContent`, dan `SelectItem` untuk jam/menit/detik di dalam `TimeField` existing; `Popover`/`Button` bila diperlukan. |
| DT02 | [DateTimeField.vue:34](../resources/js/panel/forms/fields/DateTimeField.vue#L34) | `<Input type="datetime-local">` menghasilkan input datetime native. | Gunakan `PanelDatePicker` serta Select waktu di dalam `DateTimeField` existing. |
| DT03 | [DataTableQueryBuilder.vue:178](../resources/js/panel/tables/DataTableQueryBuilder.vue#L178) | `<Input :type="inputTypeFor(rule)">` menghasilkan date native untuk constraint tanggal yang membutuhkan nilai. | Cabang date memakai `PanelDatePicker`; cabang text/number tetap menggunakan `Input`. |

Ketiganya merupakan prioritas utama untuk memenuhi kebijakan baru. Mengubah class, menyembunyikan ikon native, atau membungkus input dengan Popover belum memenuhi ketentuan jika input temporal native tetap dirender.

### DT01 dan DT02: memakai komponen Input tetap menghasilkan input native

Primitive [Input.vue:23](../resources/js/components/ui/input/Input.vue#L23) merender `<input>`. Atribut `type` diteruskan ke root tersebut melalui attribute fallthrough. Nama komponen shadcn tidak mengganti mekanisme pemilih tanggal/waktu milik browser.

[TimeField.vue:34](../resources/js/panel/forms/fields/TimeField.vue#L34) menggunakan `step` untuk detik dan mengubah string kosong menjadi `null`. [DateTimeField.vue:35](../resources/js/panel/forms/fields/DateTimeField.vue#L35) juga mempertahankan detik, min/max, disabled, invalid, serta translasi separator `T`/spasi. Semua kontrak ini harus dipertahankan saat mengganti kontrol.

Jalur pemilihan renderer ada pada [FormField.vue:160](../resources/js/panel/forms/FormField.vue#L160) untuk datetime dan [:168](../resources/js/panel/forms/FormField.vue#L168) untuk time. Dampaknya mengikuti penggunaan schema field tersebut, termasuk ketika form yang sama dipakai pada halaman atau dialog.

### DT03: input date tidak terlihat dalam pencarian atribut literal

Alur yang diverifikasi dari source:

1. [DateConstraint.php:13](../src/Tables/Filters/Constraints/DateConstraint.php#L13) mengembalikan `'date'`.
2. [Constraint.php:142](../src/Tables/Filters/Constraints/Constraint.php#L142) mengirimkannya sebagai metadata `input`.
3. [DataTableQueryBuilder.vue:65](../resources/js/panel/tables/DataTableQueryBuilder.vue#L65) meneruskan nilai tersebut melalui `inputTypeFor`; hanya `'none'` yang diubah menjadi `'text'`.
4. [DataTableQueryBuilder.vue:175](../resources/js/panel/tables/DataTableQueryBuilder.vue#L175) merender Input ketika `needsValue(rule)` benar dan memasang type dinamis.

Operator tanggal yang membutuhkan nilai menghasilkan input native. Operator tanpa nilai, seperti `IsBlank`/`IsFilled`, tidak merender kontrol ini. Karena itu, hasil pencarian `type="date"` saja tidak dapat membuktikan kepatuhan.

Penggantian perlu membedakan renderer berdasarkan semantic input type. Metadata `'date'`, operator, dan kontrak `QueryBuilderRule` tidak perlu dihapus. Nilai kosong picker harus tetap mengikuti kontrak `string | null`, dan perpindahan operator tidak boleh menyisakan nilai yang tidak relevan.

## 2. Bagian yang sudah sesuai

| Area | Hasil pemeriksaan |
| --- | --- |
| [DateField.vue:25](../resources/js/panel/forms/fields/DateField.vue#L25) | Sudah menggunakan `PanelDatePicker`, termasuk min/max, disabled, invalid, dan clearable berdasarkan required. |
| [DataTableFilters.vue:226](../resources/js/panel/tables/DataTableFilters.vue#L226) dan [:239](../resources/js/panel/tables/DataTableFilters.vue#L239) | Filter tanggal dari/sampai sudah memakai `PanelDatePicker`, dengan batas yang mengikuti nilai pasangannya. |
| [PanelDatePicker.vue:140](../resources/js/panel/components/PanelDatePicker.vue#L140) | Implementasi nyata memakai `Popover` + `Button` + `Calendar`, bukan input date native. |
| [PanelDatePicker.vue:29](../resources/js/panel/components/PanelDatePicker.vue#L29) | Teks `<input type="date">` hanya komentar penjelasan kontrol lama yang digantikan; bukan penggunaan aktif. |
| Calendar month/year navigation | Memakai komponen `NativeSelect` existing. Elemen `<select>` bukan input temporal yang dilarang. |
| Kolom/infolist dan metadata tanggal | Menampilkan nilai tanggal, mendefinisikan schema, atau memvalidasi tanggal tidak sama dengan membuat input native. |

Tidak ditemukan atribut literal aktif `type="date"` atau `type="datetime"` pada template yang dipindai. **Date belum bersih**, karena DT03 menghasilkan `type="date"` secara dinamis.

### Pemeriksaan seluruh binding `:type` yang ditemukan

| Binding | Sumber nilai | Penilaian |
| --- | --- | --- |
| [DataTableQueryBuilder.vue:178](../resources/js/panel/tables/DataTableQueryBuilder.vue#L178) | `inputTypeFor(rule)` dapat bernilai date | Pelanggaran DT03. |
| [TextInputField.vue:28](../resources/js/panel/forms/fields/TextInputField.vue#L28) | Kontrak [form.ts:105](../resources/js/panel/types/form.ts#L105) text/email; serializer [TextInput.php:79](../src/Forms/Components/TextInput.php#L79) sesuai | Tidak menghasilkan temporal input melalui kontrak bawaan yang diperiksa. |
| [DataTableCell.vue:195](../resources/js/panel/tables/DataTableCell.vue#L195) | Kontrak [table.ts:119](../resources/js/panel/types/table.ts#L119) text/number; serializer [TextInputColumn.php:69](../src/Tables/Columns/TextInputColumn.php#L69) sesuai | Tidak menghasilkan temporal input melalui kontrak bawaan yang diperiksa. |
| [PasswordInput.vue:45](../resources/js/components/PasswordInput.vue#L45) | `showPassword ? 'text' : 'password'` | Ekspresi bawaan tidak menghasilkan temporal input. Komponen juga meneruskan `$attrs`; override oleh host perlu dijaga dalam pengujian DOM. |

Generic Input dan custom widget/hook dapat menerima atribut atau implementasi dari aplikasi konsumen. Audit tidak menjamin komponen eksternal yang belum ada di checkout.

## 3. Reuse komponen yang tersedia

**Date picker nonnative siap pakai sudah tersedia. Kontrol Vue time/datetime nonnative siap pakai belum ditemukan dalam scope komponen package yang diperiksa.** `TimePicker.php` dan `DateTimePicker.php` adalah definisi schema server, bukan komponen interaksi Vue.

Ketentuan pengguna dapat dipenuhi dengan mengubah komposisi renderer existing: date memakai `PanelDatePicker`; time memakai Select jam 00–23, menit 00–59, dan detik 00–59 ketika `seconds=true`; datetime menggabungkan keduanya. Tidak perlu library picker baru. Mengganti menjadi text input bebas tanpa komponen pemilih belum memenuhi tujuan konsistensi interaksi ini.

Kontrak yang wajib dipertahankan:

- **Date:** `YYYY-MM-DD | null`; jangan mengubah tanggal kalender menjadi timestamp UTC.
- **Time:** `HH:mm` atau `HH:mm:ss`. [TimePicker.php:34](../src/Forms/Components/TimePicker.php#L34) memvalidasi format tersebut. Jangan menghilangkan detik atau mengisi `00:00` otomatis saat pengguna belum memilih.
- **Datetime:** Carbon dari [DateTimePicker.php:53](../src/Forms/Components/DateTimePicker.php#L53) dapat tiba dengan separator `T`; renderer sekarang mengirim separator spasi. Dukung nilai tersimpan dan waktu lokal, tanpa `toISOString()` yang dapat menggeser tanggal/jam.
- **Draft belum lengkap:** tanggal saja atau waktu saja perlu ditangani sebagai draft/validasi yang eksplisit; jangan emit nilai seolah lengkap dengan bagian waktu yang dibuat tanpa keputusan UX.
- **Batas datetime penuh:** [PanelDatePicker.vue:83](../resources/js/panel/components/PanelDatePicker.vue#L83) hanya mengambil 10 karakter pertama. Meneruskan min/max ke kalender tidak membatasi jam pada tanggal batas. Untuk minimum `2026-09-10 09:30`, tanggal 10 September boleh dipilih, tetapi waktu 08:00 harus ditolak/tidak tersedia.
- **Perubahan bagian:** mengganti tanggal mempertahankan waktu yang masih valid; jika menjadi di luar batas, berikan feedback. Clear mengembalikan `null`, bukan string parsial atau epoch.
- **Integrasi:** pertahankan field name, emit, payload, operator, validasi server, seconds, disabled/error/required, reset, dan draft filter yang sudah ada.

## 4. Temuan pendukung

**DT04 — Helper/error belum diteruskan ke trigger date picker.** [DateField.vue:27](../resources/js/panel/forms/fields/DateField.vue#L27) memasang `aria-describedby` pada PanelDatePicker, tetapi komponen memiliki root `<div>` pada [PanelDatePicker.vue:139](../resources/js/panel/components/PanelDatePicker.vue#L139) dan tidak meneruskannya ke Button pada baris 143–148. Atribut jatuh ke wrapper, bukan elemen fokus. Ini bukan tambahan pelanggaran native input, tetapi perlu diperbaiki saat reuse: hubungkan helper/error ke trigger/select terkait, beri label setiap bagian waktu dan ID unik per instance/repeater.

**DT05 — Dokumentasi masih mengarahkan ke kontrol native.** [docs/forms/fields/date.md:36](../docs/forms/fields/date.md#L36) masih menyatakan DatePicker memakai input date; baris 37–38 menguraikan input datetime-local/time. Penjelasan di baris 110, [docs/forms/hydration.md:42](../docs/forms/hydration.md#L42), dan [DateTimePicker.php:75](../src/Forms/Components/DateTimePicker.php#L75) juga bergantung pada istilah renderer native. Dokumentasi/komentar bukan penggunaan aktif, tetapi perlu diselaraskan bersama implementasi agar integrator tidak mengembalikannya. Kontrak nilai tetap harus dijelaskan.

## 5. Kriteria penerimaan perbaikan

1. Tidak ada DOM `input[type=date]`, `input[type=time]`, `input[type=datetime]`, atau `input[type=datetime-local]` yang dibuat renderer temporal package, termasuk hidden input dan portal.
2. Pemeriksaan meliputi atribut literal, `:type`, serta spread/fallthrough yang memengaruhi type; komentar dan semantic metadata tidak dihitung sebagai pelanggaran.
3. Date form/filter/query-builder memakai picker existing. Query-builder text/number dan operator tanpa nilai tetap bekerja.
4. TimeField/DateTimeField memakai komposisi komponen existing; tidak mengandalkan UI pemilih waktu browser.
5. Round-trip null, midnight, akhir hari, detik, separator `T`/spasi, dan min/max tanggal serta jam tetap benar.
6. Label/helper/error terhubung ke kontrol fokus; ID unik, disabled/invalid jelas, serta seluruh interaksi tersedia melalui keyboard.
7. Calendar, Select, Popover, dan error state mengikuti token light/dark yang sama. Uji perubahan tema saat picker terbuka, termasuk tema kustom dan portal.
8. Pada viewport sempit kelompok tanggal/waktu wrap/stack tanpa kehilangan label dan kontrol.
9. Uji di form halaman, repeater, dialog/slide-over, date-range filter, dan query builder. Custom component host mengikuti ketentuan yang sama.
10. Dokumentasi field dan komentar kontrak diselaraskan dengan renderer final.

Guard regresi yang disarankan pada tahap implementasi: pemindaian AST ditambah assertion DOM terhadap selector terlarang ketika renderer temporal dan rule date dipasang. Tes DOM penting karena DT03 lolos dari pencarian literal. Tambahkan pengujian round-trip serta batas waktu, bukan hanya nama komponen pengganti.

## 6. Cakupan dan batas bukti

| Area template Vue | Jumlah |
| --- | ---: |
| `resources/js` | 274 |
| `frontend/host` | 7 |
| `frontend/browser` | 7 |
| `examples` | 13 |
| `tests` | 1 |
| **Total** | **302** |

Template diperiksa memakai parser SFC dan parser DOM Vue yang terpasang. Pemindaian akhir selesai tanpa parse error: dua atribut temporal literal dan empat binding `:type`. Keempat binding ditelusuri ke sumber nilainya, menghasilkan satu tambahan pelanggaran date dinamis. Spread pada Input/custom component ditinjau sebagai batas integrasi.

Penelusuran source tambahan mencakup `resources`, `frontend`, `stubs`, `src`, `examples`, dan `tests`, termasuk Blade/HTML serta konstruksi input melalui script. Dokumentasi tanggal diperiksa terpisah. Dependency, `.git`, dan artefak build tidak dihitung sebagai source UI package; aplikasi host eksternal tidak diaudit.

Index codebase-memory diperbarui karena generasi sebelumnya sudah berbeda dari beberapa file. Generasi audit: `2026-09-10T04:05:24Z`, mode fast, scope kontrol temporal. Pencarian relevan dan scope coverage dipaginasi sampai selesai. Source kode yang dikutip cocok dengan metadata; docs/examples/fixtures dan test yang dikecualikan index diperiksa langsung dari filesystem. Relasi template yang tidak lengkap di graph dilengkapi pembacaan source; trace kosong tidak dijadikan bukti tidak ada pemanggil.

Audit tidak menjalankan aplikasi host, screenshot, atau suite test/build. Kesimpulan berdasarkan source dan diagnostik parser; visual light/dark dan runtime keyboard masih perlu dibuktikan pada implementasi. Pekerjaan audit ini hanya mengubah dokumentasi, tanpa mengubah source aplikasi, dependency, route, atau schema.

Kembali ke [audit UI/UX sebelumnya](00_Audit_UIUX_Frontend.md) atau [prompt perancangan](01_Prompt_Perancangan_Shadcn_Vue.md). Audit ini menambahkan ketentuan temporal dan tidak menyatakan semua temuan historis masih berlaku pada snapshot terbaru.
