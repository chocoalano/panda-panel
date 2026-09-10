# Prompt Perancangan UI/UX PandaPanel — shadcn-vue

Dokumen ini merupakan keluaran audit, bukan instruksi yang sudah dieksekusi untuk mengubah aplikasi. Gunakan master prompt bersama [laporan audit](00_Audit_UIUX_Frontend.md) dan [inventaris Vue](02_Inventaris_Vue.md). Prompt tambahan memperinci area tertentu setelah fondasi desain ditetapkan.

Semua prompt di bawah meminta **rancangan dan spesifikasi**, bukan perubahan kode. Implementasi merupakan tahap terpisah setelah ada instruksi untuk mengerjakannya.

Ketentuan tambahan 10 September 2026: seluruh pemilih tanggal/waktu harus memakai komponen yang sudah ada. Lihat [audit khusus input temporal](03_Audit_Input_Tanggal_Waktu.md). Larangan mencakup `input[type=date]`, `input[type=time]`, `input[type=datetime]`, dan `input[type=datetime-local]`, termasuk hasil `<Input :type>` dinamis.

## A. Master prompt — siap disalin

```text
Bertindaklah sebagai Senior Product Designer dan UI/UX Architect yang memahami
dashboard operasional, Vue 3, shadcn-vue, Reka UI, dan aksesibilitas web.

TUGAS
Susun rancangan UI/UX lengkap untuk PandaPanel berdasarkan source frontend dan:
- PandaPanel_UIUX_Audit/00_Audit_UIUX_Frontend.md
- PandaPanel_UIUX_Audit/02_Inventaris_Vue.md
- PandaPanel_UIUX_Audit/03_Audit_Input_Tanggal_Waktu.md

Pada tahap ini hasilkan dokumen perancangan, wireframe tekstual, spesifikasi
komponen, dan acceptance criteria. Jangan mengubah source, dependency, route,
schema, atau perilaku bisnis. Jangan mengklaim prototipe atau screenshot telah
dibuat bila hanya menghasilkan teks.

KONTEKS PRODUK
PandaPanel adalah paket panel admin dengan frontend yang dipublikasikan ke
aplikasi host. UI membaca metadata server melalui Inertia; komponen kustom
dapat ditambahkan melalui registry. Repository sudah memiliki primitive lokal
di resources/js/components/ui berbasis Reka UI/CVA. Gunakan fondasi tersebut.

Inventaris saat audit: 271 Vue aplikasi; 148 primitive dalam 29 keluarga,
123 komponen/halaman lainnya. Fallback frontend/host tidak otomatis mewakili
komponen produksi konsumen. Validasi angka/source jika repository berubah.

TUJUAN PENGGUNA
1. Menemukan halaman, panel, tenant, dan record dengan cepat.
2. Membaca tabel dan status tanpa kelelahan visual.
3. Mengisi form panjang dengan yakin, memahami error, dan menyimpan tanpa
   kehilangan konteks.
4. Memahami hasil tindakan, proses async, dan data dashboard secara tepat.
5. Mendapat kualitas interaksi yang setara pada light/dark, desktop/mobile,
   keyboard, touch, dan teknologi bantu.

PRINSIP VISUAL
- Gaya dashboard operasional yang bersih, tenang, dan konsisten.
- Gunakan satu aksen utama, hierarchy jelas, dan ruang kosong yang terukur.
- Slate netral + indigo boleh menjadi kandidat awal; nyatakan sebagai asumsi
  desain yang dapat disesuaikan, bukan identitas merek yang telah disetujui.
- Warna status membawa makna; warna chart kategori membedakan seri.
- Hindari banyak aksen dekoratif, card bertumpuk, shadow di semua permukaan,
  dan teks kecil/opacity rendah untuk informasi penting.
- Pertahankan Instrument Sans/system fallback kecuali ada alasan yang kuat.
- Body 14–16px, label 14px, metadata target minimal 12px, judul 20–24px.
- Spacing berbasis 4px; tetapkan padding, gap, radius, dan density secara jelas.
- Tampilkan satu CTA primer per konteks, kelompokkan tindakan sekunder,
  dan bedakan tindakan destruktif tanpa mendominasi halaman.

DESIGN SYSTEM DAN TEMA — WAJIB
1. Definisikan token Light dan Dark untuk:
   background/foreground, card/card-foreground, popover/popover-foreground,
   primary/primary-foreground, secondary/secondary-foreground,
   muted/muted-foreground, accent/accent-foreground,
   destructive/destructive-foreground, border, input, ring,
   seluruh pasangan sidebar, chart-1..chart-5, serta status semantic.
2. Bedakan surface dekoratif, border pemisah, boundary kontrol, dan focus ring.
3. Untuk success/warning/danger/info/neutral, definisikan foreground, subtle
   background, border, ikon, dan selected treatment. Jangan hanya nama warna.
4. Pakai kandidat palet dalam laporan audit sebagai titik awal. Berikan tabel
   nilai token, contoh pemakaian, pasangan foreground, dan hasil kontras.
5. Teks normal minimal 4,5:1; teks besar sesuai WCAG minimal 3:1. Indikator
   kontrol/state yang diperlukan harus terbaca terhadap warna di sebelahnya.
   Periksa opacity/hover/selected pada warna komposit aktual. Jangan menganggap
   satu rasio token sudah membuktikan seluruh komponen lulus.
6. Gunakan semantic utility yang membaca CSS variables. Warna literal hanya
   untuk pengecualian yang dijelaskan, misalnya scrim, gambar, atau swatch
   warna yang sedang dipilih pengguna. Jangan membentuk class Tailwind dengan
   interpolasi nama warna runtime.
7. Rancang satu resolved appearance reaktif untuk Light/Dark/System, persistence,
   first paint, perpindahan OS, branding, chart, toast, dan native color scheme.
   Jangan menambahkan sistem preferensi tema kedua yang saling bersaing.
8. Tentukan precedence token global, tema panel aktif, dan fallback parsial.
   Hindari kebocoran warna saat pindah panel/tenant.
9. Tema harus menjangkau Dialog, Sheet, Select, DropdownMenu, Popover, Tooltip,
   Sonner, nested overlay, sidebar mobile, auth, dan custom components.
   Bahas target portal, inheritance, stacking, clipping, dan lifecycle.
10. Petakan token yang sudah didukung API PanelTheme dan yang masih membutuhkan
    kontrak tambahan. Jangan menganggap card/popover/status/chart baru dapat
    langsung dikirim melalui allowlist server saat ini.

TEMUAN AUDIT YANG HARUS DITANGANI DALAM RANCANGAN
- T01–T04: dark palette, pasangan sidebar putih/putih, alias sidebar, portal.
- T05–T09: destructive/muted contrast, reactive system mode, semantic color,
  batas input dan native controls.
- U01–U07: hubungan label/helper/error, ID repeater, error focus, tabs,
  appearance selection, password toggle, countdown resend.
- U08–U11: right-bar mobile, children header navigation, search accessibility
  dan pemisahan failed/empty/stale.
- U12–U15: ketepatan label chart, akses data chart, urutan search header,
  reorder keyboard dan aria-sort.
- U16–U20: action wrapping, focus editor, target sentuh, i18n, makna tren.
Sertakan matriks ID temuan → keputusan desain → komponen → kriteria penerimaan.

CAKUPAN PERANCANGAN
A. Primitive: seluruh 29 keluarga pada inventaris. Bedakan primitive,
   composition/pattern, dan halaman. Pertahankan forwarding attrs/props/emits.
B. Shell: sidebar/header/blank/auth, breadcrumb, page header, nested navigation,
   panel/tenant/locale switcher, search, notification, account menu, cluster,
   subnavigation, render hook, dan pilihan appearance.
C. Halaman: Dashboard/Page; Login/Register/ForgotPassword/ResetPassword/
   VerifyEmail/EmailCode; Appearance/Profile/Security; Index/Create/Edit/View/
   ManageRelated/Integrations.
D. Data: toolbar, tabs, filter aktif, deferred filter, query builder, sort,
   visibility/order column, tabel, kartu, pagination, bulk action, row action,
   editable cell, frozen columns, grouping, summaries, empty/loading/error.
E. Form: semua field dan layout dalam inventaris; field dasar dan kompleks,
   section/grid/tabs/wizard, relationship, repeater, builder, upload, editors,
   callout, custom schema, sticky actions, dan validation feedback.
F. Detail/relasi: infolist, nested detail, badges, copy/link, relation manager,
   inline/slide-over/modal form, dan action confirmation.
G. Widget: KPI/stat, chart, table, filter, polling/deferred/loading, fallback,
   custom widget, dan empty/error state.

PERILAKU KOMPONEN — WAJIB
Untuk setiap pattern, jelaskan:
- Tujuan pengguna dan informasi utama.
- Struktur/anatomi dan primitive yang digunakan.
- Props/metadata yang dibutuhkan; bedakan tersedia sekarang vs usulan baru.
- Token, typography, spacing, alignment, ukuran dan density.
- State default/hover/focus/active/selected/disabled/readonly/loading/empty/
  error/success/overflow yang relevan. Tandai N/A dengan alasan.
- Light dan Dark serta pengecualian System/brand.
- Keyboard, accessible name, role/state, target klik dan fokus.
- Perilaku mobile, tablet, desktop, long content, dan zoom.
- Acceptance criteria yang dapat diamati dan skenario edge case.

RESPONSIVE
- Gunakan viewport evaluasi 320, 375, 768, 1024, dan 1440px.
- Mobile satu kolom, gutter 16px; desktop gutter 24px sesuai konteks.
- Sidebar menjadi Sheet; right cluster tidak menyisakan konten sempit.
- Action toolbar wrap/stack atau menu prioritas; jangan memotong CTA utama.
- Tabel data boleh scroll lokal dengan petunjuk overflow; jangan memaksa semua
  tabel menjadi kartu bila perbandingan antar-kolom penting.
- Kartu memakai label detail; filter/query tidak hilang saat berganti layout.
- Dialog lebar kecil boleh menjadi sheet/full-screen; header/footer penting
  tetap dapat dijangkau ketika keyboard perangkat terbuka.
- Uji viewport sempit, zoom 200%/400%, teks panjang, dan nilai besar.

AKSESIBILITAS
- Gunakan primitive Reka/shadcn yang tepat; ARIA bukan pengganti perilaku.
- Semua fungsi bisa dioperasikan keyboard. Fokus terlihat dan tidak tertutup.
- Label/helper/error terkait ID unik; validasi mengarahkan ke sumber masalah.
- Disabled dan readonly mempunyai makna berbeda; jangan menghapus informasi
  yang masih dibutuhkan hanya karena kontrol tidak bisa diedit.
- Target desain touch 44px; audit minimum AA 24px beserta pengecualiannya.
- State tidak dibedakan hanya warna. Gunakan ikon, teks, check, atau bentuk.
- Sediakan informasi data chart dalam bentuk yang dapat dibaca teknologi bantu.
- Hormati reduced motion. Feedback proses tetap tersedia ketika animasi dikurangi.
- Pilih live-region secara proporsional; hindari seluruh layar menjadi alert.

BATAS ARSITEKTUR
- Dilarang menghasilkan input native type=date/time/datetime/datetime-local,
  termasuk melalui wrapper Input, binding dinamis, atau elemen hidden. Gunakan
  PanelDatePicker existing untuk date; susun waktu dari Select dan primitive
  existing di TimeField/DateTimeField. Query-builder date juga wajib memakai
  picker existing. Pertahankan null, seconds, batas datetime lengkap, format
  waktu lokal, dan light/dark. Metadata type=date/time/datetime tetap boleh
  digunakan untuk memilih renderer; bukan atribut input DOM.
- Pertahankan contract Inertia, metadata PHP, query URL, izin tindakan,
  event/props, form values, registries, i18n, dan host publishing seam.
- Jangan mengarang endpoint, fitur bisnis, atau data baru agar mockup terlihat lengkap.
- Komponen shadcn/Reka tambahan boleh diusulkan dengan alasan; jangan menjalankan
  CLI yang menimpa primitive lokal pada tahap perancangan ini.
- Card, menu, button, dialog yang sudah ada harus dinilai untuk reuse dahulu.
- Jelaskan gap host/custom component sebagai pekerjaan verifikasi, bukan lulus.

FORMAT HASIL
1. Ringkasan masalah pengguna dan keputusan desain utama.
2. Peta halaman dan alur tugas utama.
3. Design foundations: token light/dark, contrast sheet, type/spacing/radius/
   elevation/density/motion dan aturan pemakaian.
4. Component/pattern catalog lengkap dengan state matrix.
5. Spesifikasi per halaman, dengan wireframe tekstual desktop dan mobile.
6. Kontrak tema/portal serta mapping ke file yang ada.
7. Matriks penanganan temuan audit dan urutan implementasi yang disarankan.
8. Checklist penerimaan per komponen, viewport, mode dan state.
9. Asumsi, keputusan yang masih terbuka, dan bukti yang masih perlu diuji runtime.

KRITERIA SELESAI
Tidak ada kelompok komponen dalam inventaris yang terlewat tanpa alasan.
Semua pattern mempunyai rancangan Light dan Dark, state interaktif, responsive,
aksesibilitas, dan acceptance criteria. Bedakan rekomendasi desain, bukti source,
hasil pengukuran, dan hal yang belum diverifikasi. Hasil harus cukup rinci untuk
ditinjau desainer dan diterjemahkan developer tanpa menebak perilaku penting.
```

## B. Prompt pendalaman per area

Gunakan konteks master prompt dan referensi audit pada setiap prompt berikut. Urutannya membentuk satu sistem desain; jangan menghasilkan palet berbeda untuk tiap area.

### 1. Fondasi warna, typography, dan appearance

```text
Susun spesifikasi design system PandaPanel, perancangan saja. Baca temuan
T01–T09 dan batas kontrak API tema pada laporan audit.

Periksa:
resources/css/panda-panel.css
resources/js/panel/palette.ts
resources/js/panel/composables/usePanelStyling.ts
resources/js/composables/useAppearance.ts
resources/js/panel/composables/usePanelBranding.ts
resources/js/components/AppearanceTabs.vue
resources/js/components/ui/{button,badge,alert,dialog,sheet,popover,select,
dropdown-menu,tooltip,sonner,sidebar}
src/Support/PanelTheme.php sebagai referensi kontrak, bukan target perubahan.

Hasilkan:
1. Tabel token lengkap, alias canonical, pair foreground/background, dan
   fallback light/dark untuk partial panel theme.
2. Pemisahan status subtle/solid/text/border dengan danger alias yang konsisten
   dengan destructive. Jelaskan keputusan warna untuk warning dan neutral.
3. Palette chart 5 seri per tema; gunakan marker/dash/label agar warna bukan
   satu-satunya pembeda. Tinjau kontras terhadap card, grid, dan antar-area.
4. Contrast sheet: normal, hover, pressed, selected, helper, placeholder,
   invalid, unread badge, tooltip, dan disabled sebagai evaluasi terpisah.
5. Model theme inheritance shell/auth/mobile sidebar/portal/toast/custom slot.
   Tentukan cara mencegah warna panel lama tertinggal setelah navigasi.
6. State machine Light/Dark/System, startup, persistence, perubahan OS,
   logo fallback, native color-scheme, dan reduced motion.
7. Skala typography, spacing, radius, elevation, density, dan focus treatment.

Jangan mengganti token dengan class warna acak pada setiap komponen. Tandai
token baru yang belum didukung allowlist. Sertakan langkah pembuktian visual
dan runtime; jangan mengklaim seluruh state sudah lolos hanya dari hex dasar.
```

### 2. Shell, navigasi, search, dan notification

```text
Rancang shell PandaPanel dengan fondasi token yang telah ditentukan. Jangan
ubah source. Tangani U08–U11 dan seluruh perilaku tema portal.

Cakupan: SidebarPanelLayout, HeaderPanelLayout, PanelSidebar, PanelHeader,
PanelNavigation/Item, PanelClusterBar, PanelSubNavigation, PanelBreadcrumb,
PageHeader, PanelSwitcher, PanelTenantSwitcher, PanelLocaleSwitcher,
PanelSearch, PanelNotifications, NavUser, dan render hooks.

Buat wireframe desktop/mobile yang menjelaskan:
- Posisi brand, trigger sidebar, breadcrumb, global search, notification,
  tenant/panel switcher, appearance, dan user menu.
- Hierarki nested menu, active ancestor, expanded/collapsed, label panjang,
  overflow header, dan menu mobile yang mempertahankan tujuan anak.
- Right cluster menjadi navigasi ringkas pada layar sempit.
- Search sebagai Command/Combobox berlabel: idle, min-query, loading,
  no result, results grouped, error, retry, stale; Arrow/Enter/Escape,
  focus return, active descendant, scroll aktif dan jumlah hasil.
- Notification unread/read, jumlah terbaca, mark one/all, loading/error/empty,
  waktu, tindakan lanjutan, dan target sentuh. Warna badge sesuai contrast sheet.
- Page header dengan satu CTA primer; tindakan tambahan wrap/menu;
  judul record penting dapat dibaca penuh.
- Skip navigation/main landmark, urutan fokus dan keadaan permission terbatas.

Petakan ke Sidebar/Sheet/NavigationMenu/DropdownMenu/Breadcrumb/Button/
Dialog/Command atau primitive lain yang tepat. Tandai komponen baru yang
belum ada lokal. Hasilkan state matrix dan acceptance criteria per pattern,
termasuk navigasi antar-panel dengan warna dan logo berbeda.
```

### 3. Tabel, kartu, filter, dan tindakan massal

```text
Rancang pengalaman membaca dan mengolah data PandaPanel tanpa mengubah query,
endpoint, perizinan, atau metadata server. Tangani U14–U15, U19, dan isu warna.

Cakupan semua panel/tables/*.vue serta halaman resources/Index.vue.

Definisikan:
1. Toolbar: search, filter, filter aktif/chip, clear, column manager, sort,
   table/grid toggle, refresh bila tersedia, dan tindakan utama. Tentukan
   prioritas desktop/mobile dan behavior deferred Apply/Reset.
2. Filter: label, operator, value, query-builder grouping, kondisi kosong,
   validasi, loading options, failed fetch, dan keyboard.
3. Tabel: alignment teks/angka/tanggal, row height comfortable/compact,
   header sort + aria-sort, per-column search, zebra/hover/selected yang
   halus, checkbox partial selection, editable cell dan feedback gagal/sukses.
4. Frozen columns: plate opak pada default/hover/selected, indikator batas,
   horizontal scroll lokal, narrow-screen policy, dan row action placement.
   Main header, search header, body, summaries harus memiliki urutan sama.
5. Reorder: drag dan alternatif keyboard/aksi naik-turun; pengumuman posisi.
6. Bulk action: jumlah record dan cakupan selection harus jujur; jangan
   mengklaim semua hasil dipilih jika implementasi hanya memilih satu halaman.
7. Pagination: rentang hasil, halaman sekarang, ukuran halaman, disabled,
   empty state dan seluruh microcopy lokal.
8. Cards: title/image/status/detail/action; tidak kehilangan filter, query,
   selection dan summary yang didukung. Jelaskan capability tabel yang memang
   tidak relevan di grid, bukan membuat kontrol palsu.
9. State awal kosong, hasil filter kosong, loading awal, background refresh,
   error, permission-limited, data panjang/null, dan koneksi lambat.

Hasilkan wireframe list/grid desktop/mobile, anatomi komponen, token/state
matrix, mapping file, dan acceptance criteria termasuk table dengan actions
before_columns + per-column search. Tidak membuat backend atau fitur baru.
```

### 4. Form lengkap, field kompleks, tabs, dan wizard

```text
Rancang sistem formulir PandaPanel berdasarkan semua panel/forms/*.vue dan
panel/forms/fields/*.vue, serta Create/Edit. Perancangan saja. Tangani
U01–U04, U11, U16–U19.

Kontrak Field wajib mencakup:
- Label, required, optional, helper, value, placeholder, suffix/prefix bila
  didukung, disabled, readonly, invalid, error message, dan loading.
- ID unik per form/field/repeater item; ID DOM tidak mengubah nama payload.
- Hubungan label/input/helper/error, error summary, fokus error pertama,
  pembukaan section/tab yang relevan dan pending validation.

Rancang setiap kelompok:
A. Text/password/number/textarea/date/datetime/time/color/slider.
   Date menggunakan PanelDatePicker existing; time/datetime menggunakan
   komposisi Select dan picker existing. Jangan menghasilkan input native
   type=date/time/datetime/datetime-local, termasuk dalam wrapper atau portal.
B. Checkbox/toggle/radio/checkbox-list/toggle-buttons.
C. Select single/multiple/searchable/dependent: selection chips, clear jika
   diizinkan, no options, remote loading/error/retry, dan stale option.
D. Tags/key-value: tambah/hapus, keyboard, nilai duplikat, label tombol hapus.
E. File upload: batas format/ukuran/jumlah yang tersedia pada metadata,
   memilih file, preview, status per file, gagal/retry/remove, dan efek terhadap
   tombol submit. Jangan menggambar persentase progres palsu bila API hanya
   menyediakan proses indeterminate; tandai gap kontraknya.
F. Rich/Markdown/Code editor: toolbar, selected formatting, focus, preview,
   typography konten light/dark, overflow code, dan akses keyboard.
G. Repeater/builder: identitas item stabil, error dalam item collapsed,
   tambah/duplikasi hanya jika tersedia, move/remove, focus setelah perubahan,
   min/max item, empty state dan konten panjang.
H. Section/grid/tabs/wizard/relationship/callout/custom schema: hierarchy,
   container width, progress, back/next, validation, state tersimpan dan error.

Tetapkan kapan memakai satu kolom, dua kolom, inline label, dan section.
Sticky action harus wrap/stack di mobile, tidak menutupi field/fokus, dan
membedakan Save, Save & create another, Cancel serta unsaved changes.

Hasilkan field anatomy, matrix seluruh field type, wireframe form sederhana
dan kompleks desktop/mobile, aturan keyboard, mapping primitive/file,
acceptance criteria dan gap metadata. Jangan merombak form engine pada tahap ini.
```

### 5. Dialog, action, detail, dan relation manager

```text
Rancang pola tindakan PandaPanel menggunakan actions, relations, infolists,
View, ManageRelated, Integrations, dan PanelRecordLayout. Perancangan saja.

Bedakan tindakan langsung, navigasi, konfirmasi destruktif, dialog dengan form,
custom content dan slide-over. Setiap desain harus mempertahankan action
authorization, nama event, konteks record dan submit contract yang tersedia.

Spesifikasikan:
- Heading tindakan, record yang terdampak, konsekuensi, primary/cancel label,
  pending state, duplicate-submit prevention, success/error dan pemulihan.
- Focus awal yang sesuai risiko, trap/return focus, Escape/outside behavior
  berdasarkan metadata, nested modal, close button dan data belum tersimpan.
- Content panjang: scroll body, header/footer, keyboard mobile, safe area,
  viewport kecil, kontras overlay dan pewarisan tema portal.
- Detail record: group, label/value, format angka/tanggal, placeholder null,
  badge status, helper, copy feedback, link, teks panjang dan custom entry.
- Relation manager: tabs/list, search, selection, empty/loading/error,
  attach/detach/create/edit sesuai fitur tersedia, konteks induk dan pivot.
- Integrations: hirarki informasi koneksi/aksi yang benar-benar tersedia,
  state configured/unconfigured/working/error hanya sesuai metadata.

Gunakan Card/Separator/Tabs/Badge/Button/Dialog/AlertDialog/Sheet yang sesuai;
tandai primitive tambahan bila belum ada. Hasilkan state matrix, wireframe,
behavior specification, mapping source dan acceptance criteria dua tema.
```

### 6. Dashboard dan widget

```text
Rancang dashboard PandaPanel dan seluruh widgets/*.vue. Perancangan saja;
jaga bentuk data dan kontrak custom widget. Tangani U12–U13 dan U20.

Definisikan:
- Urutan KPI berdasarkan kepentingan, label/nilai/unit/periode/pembanding,
  grid responsif, digit besar, nol/null, serta loading/empty/failed/stale.
- Arah tren dipisahkan dari makna positif/negatif. Untuk biaya atau error,
  kenaikan bisa buruk; jika metadata belum mendukung, nyatakan kebutuhan
  tambahan atau gunakan treatment netral yang jujur.
- Chart palette Light/Dark yang terpisah dari status; legend, marker/dash,
  grid, axes, unit, tooltip, empty state, title dan description.
- Ketepatan label/index/tooltip. Skenario 1 titik, banyak titik, banyak seri,
  nol, negatif, stacked, gap/null sesuai data yang diterima, dan nilai besar.
- Alternatif data tekstual/tabel, navigasi fokus yang efisien, tooltip
  keyboard/touch, pembatasan clipping dan label kategori panjang.
- Table widget, widget filter, polling/deferred, retry, waktu pembaruan bila
  tersedia, dan custom/fallback widget yang tetap mengikuti sistem tema.
- Klik pada KPI hanya bila ada tujuan; hindari affordance seolah interaktif
  pada card yang tidak memiliki action.

Hasilkan wireframe dashboard desktop/mobile, specification KPI/chart/table,
state matrix, token usage, mapping file, dan acceptance criteria. Jangan
menambah angka/periode/progres fiktif untuk membuat rancangan terlihat lengkap.
```

### 7. Auth, profile, security, dan appearance

```text
Rancang auth/settings PandaPanel: Login, Register, ForgotPassword,
ResetPassword, VerifyEmail, EmailCode, Profile, Security, Appearance,
PasswordInput dan komponen pendukung. Perancangan saja. Tangani U01, U05–U07.

Tentukan:
- Konsistensi brand light/dark dan theme scope pada guest maupun authenticated.
- Satu tindakan utama, helper yang relevan, label permanen, autocomplete,
  show/hide password via keyboard, submit/loading/server error dan feedback.
- Kode email: input/paste yang mudah, penerima disamarkan, error yang jelas,
  countdown yang mengikuti waktu nyata, resend cooldown/sukses/gagal, dan
  state setelah tab browser kembali aktif. Jangan mengubah kebijakan auth.
- Pilihan Light/Dark/System yang accessible, selected state jelas, persistence,
  preview jika berguna, perubahan OS, dan native controls.
- Profile/security form grouping, status tersimpan, konfirmasi tindakan
  sensitif, recovery information dan focus/error behavior.
- Passkey/two-factor/user menu host: pisahkan kontrak panel dari komponen
  aplikasi host yang harus diperiksa; jangan menganggap stub sudah memadai.
- Mobile keyboard, panjang pesan Indonesia, target touch, fokus dialog,
  error association dan success color yang konsisten.

Hasilkan wireframe tiap alur utama, state diagram tekstual, microcopy yang
terlokalisasi, token usage, mapping file dan acceptance criteria dua tema.
```

### 8. Review kelengkapan rancangan dan rencana pengujian

```text
Audit dokumen rancangan yang sudah dihasilkan terhadap inventaris 271 Vue.
Lakukan review dokumen/source; jangan mengubah implementasi atau menyatakan
pengujian runtime telah lulus bila belum dijalankan.

Buat matriks dengan kolom:
file/pattern | skenario | light | dark | system-change | keyboard |
mobile | contrast | error/empty/loading | host dependency | bukti/status.

Status yang diperbolehkan: dirancang, diverifikasi source, diverifikasi runtime
dengan bukti, belum diverifikasi, atau N/A dengan alasan. Inventaris atau
build yang berhasil tidak boleh dianggap bukti lulus aksesibilitas.

Periksa:
1. Seluruh keluarga primitive dan komposisi aplikasi sudah dipetakan.
2. Semua T01–T09/U01–U20 mempunyai keputusan dan acceptance criteria.
3. Tema panel parsial/kustom, nested portal, mobile sidebar, toast, auth,
   logo, perpindahan panel dan System OS change ikut rencana verifikasi.
4. Keyboard-only: menu, tabs, search, select, form, upload, reorder, chart,
   modal, password toggle, dan focus return.
5. Viewport 320/375/768/1024/1440; zoom 200%/400%; label panjang, data besar,
   nol/null, error jaringan, loading lama, dan permission terbatas.
6. Contrast per pasangan/state aktual, termasuk opacity, invalid,
   selected, placeholder, tooltip dan frozen cells.
7. Snapshot/screenshots masa implementasi: list/grid, long form + errors,
   auth, dashboard, sidebar collapsed/mobile, search, notifications,
   nested dialog dan custom theme pada kedua mode.
8. Pengujian aplikasi yang relevan mengikuti script repository. Lint/typecheck/
   build membantu integrasi tetapi tidak menggantikan pemeriksaan UI di browser.

Hasilkan gap list berprioritas, ketergantungan host/backend yang konkret,
urutan implementasi kecil dan dapat ditinjau, serta checklist sign-off desain.
```

## C. Bentuk keluaran yang diharapkan dari setiap prompt

| Bagian keluaran | Detail minimum |
| --- | --- |
| Masalah pengguna | Situasi konkret, hambatan, hasil yang diinginkan. |
| Keputusan desain | Layout, hierarchy, primitive, alasan, dan alternatif hanya bila ada tradeoff nyata. |
| Anatomy | Elemen, urutan, ukuran, spacing, token, dan responsive behavior. |
| State | Kondisi masuk, tampilan, interaksi yang tersedia, feedback, dan cara keluar/pulih. |
| Aksesibilitas | Nama, role/state, relasi ID, keyboard, fokus, target, dan pembeda selain warna. |
| Pemetaan source | File existing yang dipakai, primitive baru yang diusulkan, dan batas kontrak. |
| Acceptance criteria | Skenario Given/When/Then yang dapat diperiksa, untuk light dan dark. |
| Batas bukti | Source vs desain vs hasil runtime; keputusan host/brand yang belum tersedia. |

Contoh tingkat ketelitian acceptance criteria:

```text
Given panel A memiliki primary light/dark berbeda dan pengguna memilih System,
when OS berubah ke dark saat dialog konfirmasi sedang terbuka,
then shell, dialog, primary button, logo, dan toast mengikuti resolved dark
yang sama, teks tetap terbaca, serta data form/fokus tidak hilang.

Given dua item repeater mempunyai field berlabel Nama,
when pengguna mengaktifkan label Nama pada item kedua,
then hanya input item kedua menerima fokus dan error yang dibaca terkait
item tersebut; seluruh ID DOM unik tanpa mengubah nama payload bisnis.

Given tabel memakai record actions sebelum kolom dan column search aktif,
when pengguna melihat header dan baris pencarian,
then setiap search berada di bawah kolom yang benar pada light dan dark,
termasuk ketika kolom dibekukan, diubah visibilitasnya, atau diurut ulang.
```

Spesifikasi semantic token mengacu pada [shadcn-vue theming](https://www.shadcn-vue.com/docs/theming); pilihan Light/Dark/System dapat dibandingkan dengan [panduan dark mode](https://www.shadcn-vue.com/docs/dark-mode/vite), dengan tetap mempertahankan composable appearance proyek sebagai sumber utama. Kriteria aksesibilitas dan referensi W3C tersedia pada bagian 7 laporan audit.
