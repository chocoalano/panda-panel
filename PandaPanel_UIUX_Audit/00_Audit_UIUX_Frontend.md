# Audit UI/UX Frontend PandaPanel

Tanggal: 9 September 2026. Cakupan: audit source frontend Vue dan spesifikasi perbaikan; tidak mengubah implementasi aplikasi.

**Kesimpulan:** PandaPanel sudah memiliki fondasi shadcn-vue/Reka UI, struktur halaman yang modular, dan token light/dark. Namun, dukungan tema belum konsisten dari konfigurasi panel sampai komponen yang tampil. Prioritas perancangan adalah memperbaiki pasangan warna, penerapan tema kustom, aksesibilitas formulir dan navigasi, serta perilaku layout pada layar kecil. Mengganti seluruh komponen tidak diperlukan.

Dokumen pendamping:

- [Prompt perancangan shadcn-vue](01_Prompt_Perancangan_Shadcn_Vue.md): master prompt dan prompt per area, untuk menghasilkan spesifikasi desain berikutnya.
- [Inventaris seluruh komponen Vue](02_Inventaris_Vue.md): daftar file dan tingkat pemeriksaan; menjadi dasar checklist komponen.

## 1. Metode dan batas kepastian

Audit menginventarisasi **271 file `.vue` di `resources/js`**, terdiri dari 148 primitive dalam 29 keluarga UI dan 123 komponen/halaman lainnya. Seluruh file masuk pemindaian source; pembacaan mendalam difokuskan pada tema, wrapper, template interaktif, dan jalur yang menjadi bukti temuan. Inventaris penuh tidak berarti setiap kombinasi props dan state sudah diuji.

Pemetaan memakai codebase-memory pada proyek `panda-panel`, generasi `2026-09-09T08:53:10Z`, mode `fast`, tingkat bukti Verify. Pencarian modul Vue menghasilkan 279 modul tanpa halaman hasil lanjutan: 271 aplikasi, 7 fallback host, dan 1 fixture pengujian. Coverage diperiksa untuk seluruh 271 file aplikasi dan 12 file pendukung. CSS memiliki partial parsing pada baris 5–8, yang diperiksa langsung. Scope frontend juga menunjukkan pengecualian berkas test/deklarasi; tidak dipakai untuk menyimpulkan kelengkapan pengujian.

Audit ini **bukan audit visual browser atau sertifikasi WCAG**. Tidak ada screenshot aplikasi host, pengukuran computed style, sesi screen reader, atau pengujian touch yang dijalankan. Angka kontras di bawah dihitung dari nilai warna source, dengan asumsi warna opak dan tanpa override host. Dampak yang bergantung konfigurasi diberi pemicu spesifik.

Perilaku index pada temuan U12 juga dikonfirmasi melalui diagnostik kecil dengan `renderList` dari Vue yang terpasang: tiga iterasi menghasilkan index 0/1/2, sehingga ekspresi `labels[index - 1]` menghasilkan kosong/Jan/Feb untuk data Jan/Feb/Mar. Diagnostik ini tidak merender layar aplikasi.

Keterangan bukti:

- **Source:** perilaku atau struktur terlihat langsung dalam source yang dirujuk.
- **Hitungan:** perhitungan luminansi dari warna yang didefinisikan; bukan sampling screenshot.
- **Risiko:** membutuhkan kondisi tertentu atau verifikasi aplikasi host/browser.

Prioritas:

- **P1:** mendahulukan keterbacaan, ketepatan informasi, akses fitur, atau keberhasilan alur utama.
- **P2:** memperbaiki konsistensi, efisiensi, feedback, dan kenyamanan penggunaan.

## 2. Fondasi yang layak dipertahankan

| Fondasi | Nilai untuk rancangan berikutnya |
| --- | --- |
| Vue 3, TypeScript, Inertia, Tailwind 4, Reka UI, CVA | Komponen dasar sudah tersedia lokal. Selaraskan sistem desain dengan stack ini; hindari migrasi framework tanpa kebutuhan. |
| Token semantic di `panda-panel.css` | Mayoritas primitive dapat berubah tema melalui satu sumber warna. |
| `palette.ts` | Badge, ikon, dan selected control sudah berbagi vocabulary neutral/success/warning/danger/info. |
| Tabel dan kartu | `DataTableGrid` menggunakan metadata record yang sama; perubahan desain dapat menjaga query, selection, dan action yang ada. |
| Form tabs dan wizard | Sudah ada mekanisme menunjukkan bagian yang memiliki error. Perlu dilengkapi asosiasi pesan dan pemindahan fokus. |
| Modal berbasis Reka | Sudah menyediakan fondasi dialog, heading, close, dan pengaturan interaksi; jangan menggantinya dengan overlay manual tanpa alasan. |
| Skeleton dan empty state | `LoadingState` memakai `aria-busy`; `EmptyState` menyediakan heading, deskripsi, dan slot tindakan. |
| Shell responsif | Gutter bertahap, sidebar mobile melalui Sheet, serta frozen-column styling sudah dipikirkan. Perbaikan perlu menjaga perilaku tersebut. |
| Inisialisasi appearance | Stub Blade memiliki script awal sebelum stylesheet; perlindungan terhadap kilatan tema sudah ada untuk jalur instalasi tersebut. |

## 3. Temuan warna dan light/dark mode

### T01 — P1 — Palet dark dari konfigurasi panel belum diterapkan

**Bukti Source:** [usePanelStyling.ts:41](../resources/js/panel/composables/usePanelStyling.ts#L41) hanya melakukan iterasi `theme.light`. [SidebarPanelLayout.vue:58](../resources/js/panel/layouts/SidebarPanelLayout.vue#L58) dan [HeaderPanelLayout.vue:73](../resources/js/panel/layouts/HeaderPanelLayout.vue#L73) memasangnya sebagai inline style pada shell.

**Pemicu:** panel mengirim `theme.light` dan `theme.dark`, lalu pengguna memilih dark. Warna light yang dipasang lokal tetap berlaku; perubahan `.dark` di ancestor tidak memilih nilai dark dari konfigurasi tersebut. Dark default global tetap dapat bekerja pada properti yang tidak dioverride.

**Rekomendasi:** satu resolusi tema aktif untuk Light/Dark/System; tentukan fallback token secara eksplisit, lalu terapkan palet hasil resolusi ke seluruh permukaan panel. Uji panel yang hanya mengatur sebagian warna dan perpindahan antar-panel.

### T02 — P1 — Ikon branding sidebar dapat putih di atas putih pada dark mode

**Bukti Source + Hitungan:** [panda-panel.css:156](../resources/css/panda-panel.css#L156) mengatur `--sidebar-primary` dan `--sidebar-primary-foreground` menjadi putih. Keduanya dipakai bersama oleh [PanelSidebar.vue:76](../resources/js/panel/components/PanelSidebar.vue#L76) dan [PanelSwitcher.vue:87](../resources/js/panel/components/PanelSwitcher.vue#L87). Kontras **1:1**.

**Pemicu:** dark mode dengan ikon SVG yang mengikuti `currentColor`, tanpa override host. Ikon kehilangan pembeda dari latarnya. Logo bitmap memiliki perilaku berbeda, sehingga tidak semua logo otomatis gagal.

**Rekomendasi:** tetapkan pasangan surface/foreground yang kontras; uji ikon, logo light/dark, keadaan collapsed, dan panel switcher.

### T03 — P1 — Token sidebar yang dikirim berbeda dari token yang dibaca utility

**Bukti Source:** [panda-panel.css:54](../resources/css/panda-panel.css#L54) memetakan `bg-sidebar` ke `--sidebar-background`. [PanelTheme.php:50](../src/Support/PanelTheme.php#L50) mengizinkan konfigurasi `sidebar`, sedangkan composable menulisnya menjadi `--sidebar`.

**Pemicu:** konfigurasi mengubah `sidebar` dengan harapan mengganti permukaan sidebar. Utility tetap membaca nama lain.

**Rekomendasi:** sepakati satu token canonical dan alias kompatibilitas bila diperlukan. Dokumentasikan dampak API tema; ini membutuhkan keselarasan kontrak PHP–frontend pada implementasi berikutnya.

### T04 — P1 — Tema panel berisiko tidak menjangkau portal

**Bukti Source + Risiko:** tema dipasang di shell, sedangkan [DialogContent.vue:33](../resources/js/components/ui/dialog/DialogContent.vue#L33), [PopoverContent.vue:31](../resources/js/components/ui/popover/PopoverContent.vue#L31), Select, DropdownMenu, Tooltip, dan Sheet memakai portal tanpa target lokal. Implementasi Reka yang terpasang memilih `body` ketika target/config provider tidak diberikan. Pemindaian frontend paket tidak menemukan pengaturan `teleportTo`/ConfigProvider.

**Pemicu:** tema kustom hanya ada pada shell dan host menggunakan target portal default. Overlay tetap mengikuti `.dark` global, tetapi dapat kehilangan **warna kustom panel**, sehingga tombol/menu/dialog berbeda dari halaman. Sidebar mobile juga memakai Sheet.

**Rekomendasi:** rancang cakupan tema untuk portal, toast, dan overlay bertingkat. Pilih container bertema atau propagasi token aktif dengan mempertimbangkan clipping, stacking, dan isolasi antar-panel. Verifikasi di aplikasi host karena provider host dapat mengubah hasil.

### T05 — P1 — Teks putih pada destructive light tidak mencapai kontras teks normal

**Bukti Hitungan:** [panda-panel.css:107](../resources/css/panda-panel.css#L107) mendefinisikan destructive `hsl(0 84.2% 60.2%)`. [button/index.ts:14](../resources/js/components/ui/button/index.ts#L14), [badge/index.ts:16](../resources/js/components/ui/badge/index.ts#L16), dan [PanelNotifications.vue:232](../resources/js/panel/components/PanelNotifications.vue#L232) memasangkan background destructive dengan putih. Rasio default opak sekitar **3,76:1**, di bawah 4,5:1 untuk teks normal.

**Rekomendasi:** pilih destructive surface dan foreground berpasangan. Pisahkan kebutuhan warna teks error dari filled destructive action bila satu token tidak memenuhi keduanya. Hitung kembali hover dan opacity; jangan menyimpulkan dark gagal dengan angka light karena destructive primitive mempunyai override opacity dark.

### T06 — P2 — Muted text memenuhi kontras pada putih, tetapi tidak pada muted surface tertentu

**Bukti Hitungan:** `--muted-foreground` light pada putih sekitar **4,74:1**, tetapi pada `--muted` sekitar **4,35:1**. Pasangan tersebut digunakan pada neutral badge di [palette.ts:16](../resources/js/panel/palette.ts#L16) dan badge tabs di [FormTabs.vue:99](../resources/js/panel/forms/FormTabs.vue#L99).

**Rekomendasi:** validasi pasangan aktual, termasuk badge 12px dan caption. Opacity tambahan pada teks penting harus dihitung terhadap hasil komposit, bukan warna asal.

### T07 — P2 — System mode dapat mengubah CSS tanpa menyegarkan logo reaktif

**Bukti Source:** [useAppearance.ts:67](../resources/js/composables/useAppearance.ts#L67) menangani perubahan OS dengan `updateTheme`; [useAppearance.ts:99](../resources/js/composables/useAppearance.ts#L99) menghitung `resolvedAppearance` dari ref appearance dan pembacaan `matchMedia` nonreaktif. [usePanelBranding.ts:46](../resources/js/panel/composables/usePanelBranding.ts#L46) bergantung pada computed tersebut.

**Pemicu:** mode System tetap aktif, OS berpindah light↔dark setelah computed dievaluasi. Kelas HTML berubah, tetapi tidak ada pembaruan dependency reaktif untuk memicu pemilihan logo kembali.

**Rekomendasi:** sistem appearance harus menyediakan resolved mode reaktif yang sama untuk CSS, logo, ikon, chart, dan toast. Uji pergantian OS saat halaman tetap terbuka.

### T08 — P2 — Penerapan semantic color belum menyeluruh

**Bukti Source:** [AppearanceTabs.vue:21](../resources/js/components/AppearanceTabs.vue#L21) memakai neutral/white/black langsung; [ChartWidget.vue:56](../resources/js/panel/widgets/ChartWidget.vue#L56) memakai warna seri tetap; [Login.vue:52](../resources/js/pages/panel/auth/Login.vue#L52) memakai emerald tanpa pasangan dark, sementara Profile memiliki pasangan dark.

**Rekomendasi:** pindahkan warna UI bermakna ke token/palette terpusat. Warna tetap bukan otomatis bug: scrim hitam transparan, warna yang sedang dipilih pengguna, dan konten gambar dapat menjadi pengecualian terdokumentasi. Untuk grafik, gunakan palette kategori dengan pasangan per tema dan pembeda selain warna.

### T09 — P2 — Identifikasi batas input dan native controls perlu diperkuat

**Bukti Hitungan + Risiko:** `--input` terhadap background default sekitar **1,26:1** pada light dan **1,31:1** pada dark. [Input.vue:27](../resources/js/components/ui/input/Input.vue#L27) menggunakan border tersebut dan permukaan transparan/opacity. Ini berisiko jika batas menjadi petunjuk utama bahwa area dapat diisi. Bukan semua border dekoratif wajib 3:1.

Tidak ada deklarasi `color-scheme` pada CSS/composable/stub yang dipindai; native select/time/file controls tetap perlu diperiksa di browser/OS sasaran.

**Rekomendasi:** bedakan `border` dekoratif dari batas kontrol yang harus dikenali; sediakan token `input` yang cukup jelas dan sinkronkan native color scheme dengan resolved appearance.

## 4. Temuan aksesibilitas, alur, dan responsivitas

| ID | Prioritas & bukti | Temuan, dampak, dan arah perancangan |
| --- | --- | --- |
| U01 | P1 · Source | **Helper/error belum terhubung programatis ke field.** [FieldWrapper.vue:31](../resources/js/panel/forms/fields/FieldWrapper.vue#L31) menampilkan helper dan InputError tanpa ID; [TextInputField.vue:31](../resources/js/panel/forms/fields/TextInputField.vue#L31) memiliki `aria-invalid` tetapi tidak `aria-describedby`. Auth/settings juga menampilkan InputError terpisah. Sediakan kontrak ID label/helper/error serta state invalid/required yang diteruskan ke kontrol. |
| U02 | P1 · Source | **ID field berulang dalam repeater.** [RepeaterField.vue:212](../resources/js/panel/forms/fields/RepeaterField.vue#L212) meneruskan node schema yang sama pada setiap item; [TextInputField.vue:25](../resources/js/panel/forms/fields/TextInputField.vue#L25) mengambil ID hanya dari `field.name`. Dua item dengan field bernama sama menghasilkan ID ganda dan label dapat menunjuk item lain. Gunakan namespace instance/form/item untuk ID DOM tanpa mengubah key payload bisnis. |
| U03 | P2 · Source | **Submit gagal belum mengarahkan fokus.** [FormRenderer.vue:421](../resources/js/panel/forms/FormRenderer.vue#L421) dan [:444](../resources/js/panel/forms/FormRenderer.vue#L444) hanya menyimpan error; tidak ada error summary/fokus field pertama pada handler ini. Pada form panjang pengguna dapat mengira tombol tidak bekerja. Rancang ringkasan error, pembukaan tab/section terkait, dan fokus yang tidak tertutup sticky footer. |
| U04 | P2 · Source | **Tabs belum memiliki perilaku keyboard sesuai pola tab.** [FormTabs.vue:74](../resources/js/panel/forms/FormTabs.vue#L74) dan [InfolistTabs.vue:45](../resources/js/panel/infolists/InfolistTabs.vue#L45) memiliki role/selected/controls, tetapi perubahan hanya melalui click; tidak ada roving tabindex atau arrow handling. ID juga hanya memakai key tab. Gunakan Tabs berbasis Reka dengan ID per instance, fokus keyboard, dan penanda error lebih dari warna. |
| U05 | P2 · Source | **Kontrol appearance tidak mengumumkan pilihan aktif.** [AppearanceTabs.vue:23](../resources/js/components/AppearanceTabs.vue#L23) hanya mengganti class. Rancang sebagai RadioGroup/ToggleGroup satu pilihan dengan label grup dan state terpilih yang terbaca, bukan sekadar tampak. |
| U06 | P1 · Source | **Tombol tampilkan password dilewati Tab.** [PasswordInput.vue:44](../resources/js/components/PasswordInput.vue#L44) memasang `tabindex=-1`. Pertahankan label yang sudah ada, masukkan tombol ke urutan keyboard, tampilkan fokus dan status show/hide. |
| U07 | P1 · Source | **Resend kode email tidak mempunyai countdown lokal.** [EmailCode.vue:28](../resources/js/pages/panel/auth/EmailCode.vue#L28) menerima `retryAfter`; [:77](../resources/js/pages/panel/auth/EmailCode.vue#L77) langsung menggunakannya untuk disabled. Script tidak mengurangi nilai. Jika prop positif tidak diperbarui dari luar, tombol tetap nonaktif setelah waktu tunggu nyata lewat. Rancang countdown berdasarkan deadline, pemulihan setelah tab kembali aktif, dan state resend sukses/gagal. |
| U08 | P1 · Source + Risiko | **Right cluster bar menekan konten mobile.** [PanelClusterBar.vue:30](../resources/js/panel/components/PanelClusterBar.vue#L30) memakai `w-56 shrink-0`, dan [SidebarPanelLayout.vue:97](../resources/js/panel/layouts/SidebarPanelLayout.vue#L97) mempertahankan flex horizontal tanpa breakpoint; pola serupa ada di HeaderPanelLayout. Jika right-bar aktif di layar kecil, sebagian besar lebar habis untuk navigasi. Rancang pindah menjadi menu/section di atas konten pada viewport sempit. |
| U09 | P1 · Source | **Navigasi anak tidak dirender dalam header layout.** [HeaderPanelLayout.vue:67](../resources/js/panel/layouts/HeaderPanelLayout.vue#L67) hanya mengambil `group.items`, lalu [:108](../resources/js/panel/layouts/HeaderPanelLayout.vue#L108) merender Link top-level tanpa children. Data navigasi dengan anak kehilangan jalur penemuan fitur melalui header. Tentukan DropdownMenu/NavigationMenu atau Sheet mobile yang tetap menampilkan seluruh tujuan yang diizinkan. |
| U10 | P2 · Source | **Search memiliki keyboard handler tetapi semantik dan scrolling belum lengkap.** [PanelSearch.vue:259](../resources/js/panel/components/PanelSearch.vue#L259) memakai placeholder tanpa label input eksplisit; active result tidak dihubungkan melalui combobox/listbox/active descendant, dan [:64](../resources/js/panel/components/PanelSearch.vue#L64) hanya mengubah index. Rancang Command/Combobox lengkap, label, pengumuman jumlah hasil, dan scroll hasil aktif ke area terlihat. |
| U11 | P2 · Source | **Pencarian gagal tercampur dengan kosong atau data lama.** [PanelSearch.vue:158](../resources/js/panel/components/PanelSearch.vue#L158) mengubah response non-OK menjadi daftar kosong; catch tidak menampilkan failed state. [SelectField.vue:153](../resources/js/panel/forms/fields/SelectField.vue#L153) mempertahankan hasil lama saat gagal tanpa penjelasan. Bedakan idle/loading/empty/error/stale dan berikan retry yang mempertahankan query. |
| U12 | P1 · Source | **Label dan hit area grafik bergeser satu kategori.** [ChartWidget.vue:619](../resources/js/panel/widgets/ChartWidget.vue#L619) memakai `(_, index)` lalu `index - 1`; hal serupa pada [:643](../resources/js/panel/widgets/ChartWidget.vue#L643). Index kedua Vue sudah berbasis nol. Contoh label Jan/Feb/Mar menjadi kosong/Jan/Feb, dan kategori akhir kehilangan hit area yang benar. Selaraskan label, posisi titik, tooltip, dan focus target; validasi dataset satu dan banyak titik. |
| U13 | P2 · Source | **Grafik masih bergantung warna dan tooltip visual.** [ChartWidget.vue:487](../resources/js/panel/widgets/ChartWidget.vue#L487) hanya memberikan label generik jenis chart; hit area role button tidak menyertakan nilai seri dalam accessible name. Rancang ringkasan bermakna, alternatif tabel data, tooltip keyboard/touch, penanda seri, dan fokus terlihat. Warna kategori tidak harus diartikan sebagai baik/buruk. |
| U14 | P1 · Source | **Search header tabel tidak mengikuti posisi actions.** Header utama mendukung `before_columns` di [DataTable.vue:374](../resources/js/panel/tables/DataTable.vue#L374), tetapi search row selalu menaruh actions kosong di akhir pada [:498](../resources/js/panel/tables/DataTable.vue#L498). Ketika column search dan actions-before aktif, filter tampak di bawah kolom yang salah. Semua baris tabel harus berbagi urutan kolom aktual. |
| U15 | P2 · Source | **Reorder tabel hanya drag; arah sort belum diumumkan.** [DataTable.vue:577](../resources/js/panel/tables/DataTable.vue#L577) menyediakan drag handle tanpa jalur pindah via keyboard; header [:405](../resources/js/panel/tables/DataTable.vue#L405) mengganti ikon sort, tetapi tidak menulis `aria-sort`. Rancang tindakan naik/turun yang dapat diakses serta pengumuman posisi dan arah sort. |
| U16 | P2 · Source + Risiko | **Action row berpotensi meluap.** [FormRenderer.vue:510](../resources/js/panel/forms/FormRenderer.vue#L510) memakai flex tanpa wrap untuk Save/Create another/Cancel. [PageHeader.vue:24](../resources/js/panel/components/PageHeader.vue#L24) memotong judul dan action row [:37](../resources/js/panel/components/PageHeader.vue#L37) tidak wrap. Rancang stacking/wrap dan overflow menu berdasarkan prioritas; uji label Indonesia yang panjang, 320px, dan zoom. |
| U17 | P2 · Source | **Rich editor menghilangkan outline tanpa pengganti lokal.** [RichEditorField.vue:160](../resources/js/panel/forms/fields/RichEditorField.vue#L160) memakai `outline-none`; wrapper tidak menyediakan focus-within ring. Rancang fokus editor dan toolbar yang jelas, termasuk selected formatting state. |
| U18 | P2 · Source + Risiko | **Target kecil pada beberapa kontrol ikon.** Close default [DialogContent.vue:49](../resources/js/components/ui/dialog/DialogContent.vue#L49) tidak memiliki padding/min-size, dan Switch default hanya sekitar 18,4px tinggi. Ukuran DOM final dan spacing perlu diperiksa. Tetapkan target desain sentuh 44px, dengan kontrol desktop padat tetap memenuhi minimum/pengecualian WCAG; jangan menyamakan ukuran gambar ikon dengan area klik. |
| U19 | P2 · Source | **Microcopy belum satu bahasa.** [DataTablePagination.vue:64](../resources/js/panel/tables/DataTablePagination.vue#L64) dan [:80](../resources/js/panel/tables/DataTablePagination.vue#L80) memakai `/ page` dan `Page … of`; [SelectField.vue:208](../resources/js/panel/forms/fields/SelectField.vue#L208) menulis placeholder Inggris, FileUploadField juga menulis error Inggris. Rancang key terjemahan untuk label visual, pesan error, dan accessible name. |
| U20 | P2 · Source + Risiko | **Tren naik selalu hijau, turun selalu merah.** [StatsWidget.vue:77](../resources/js/panel/widgets/StatsWidget.vue#L77) memetakan warna hanya dari arah. Untuk biaya, keluhan, atau error rate, kenaikan justru buruk. Pisahkan arah perubahan dari makna baik/buruk/netral dalam spesifikasi; tandai kebutuhan metadata tambahan bila belum tersedia. |

## 5. Batas integrasi dan risiko desain tambahan

- **Kontrak token belum lengkap untuk kustomisasi semua permukaan.** Allowlist [PanelTheme.php:36](../src/Support/PanelTheme.php#L36) tidak mencakup seluruh token CSS seperti card/popover/input/chart dan semua pasangan sidebar. Jangan mengirim token baru melalui API tersebut tanpa memeriksa kontraknya. Sebagian dapat didefinisikan melalui stylesheet terlebih dahulu pada implementasi nanti.
- **Auth menggunakan global theme.** [PanelAuthLayout.vue:34](../resources/js/panel/layouts/PanelAuthLayout.vue#L34) tidak memasang `themeStyle`. Tetapkan apakah identitas warna panel harus konsisten sebelum dan sesudah login; jangan menganggap auth sudah memakai palet kustom panel.
- **Editor prose bergantung style host.** RichEditor/MarkdownEditor memakai `prose` dan `dark:prose-invert`, tetapi package.json dan stylesheet paket yang dibaca tidak mengaktifkan typography plugin. Perlu kontrak styling konten heading/link/list/code yang eksplisit; host bisa menyediakan style tersebut.
- **Gerak belum menjadi kebijakan desain.** Ada transisi, skeleton, spinner, dan efek hover mengangkat card. File sumber paket yang dipindai tidak mendefinisikan kebijakan reduced motion. Bedakan animasi dekoratif dari indikator proses yang tetap harus dapat dipahami.
- **Fallback host bukan jaminan UI produksi.** `frontend/host` berisi seam untuk instalasi/build. User menu, passkey, dan two-factor pada aplikasi host perlu audit runtime tersendiri; stub tidak diperlakukan sebagai layar produksi yang telah lulus audit.
- **Ekstensi kustom belum dapat dijamin.** Registry fields/widgets/hooks/columns/shell milik aplikasi konsumen harus mengikuti kontrak tema dan aksesibilitas yang sama. Daftar 271 file tidak mencakup ekstensi yang belum ada di repository ini.

## 6. Arah visual yang direkomendasikan

Usulan awal: dashboard operasional yang tenang, netral, dan padat secara terukur; satu aksen utama untuk CTA dan pilihan aktif. Gunakan slate netral dengan indigo sebagai **kandidat**, bukan identitas merek yang sudah disetujui. Warna status tetap dibedakan dari warna kategori grafik.

| Aspek | Spesifikasi rancangan awal |
| --- | --- |
| Hierarki | Satu judul utama; deskripsi ringkas; satu tindakan primer per konteks. Metadata pendukung tidak bersaing dengan nama record dan status. |
| Typography | Pertahankan Instrument Sans/system fallback; body 14–16px, label 14px, metadata minimum target 12px, judul halaman 20–24px. Angka tabular untuk nominal dan statistik. |
| Spacing | Basis 4px; jarak dalam komponen 8–16px; kelompok 16–24px; section besar 24–32px. |
| Shape | Radius konsisten: input/button 6–8px; card/dialog 8–12px. Elevasi terutama untuk overlay, tidak semua card perlu shadow. |
| Layout | Mobile satu kolom; gutter 16px lalu 24px. Sidebar menjadi Sheet; right-bar pindah ke navigasi ringkas. Lebar form disesuaikan isi, tidak selalu full-width. |
| Data density | Comfortable sebagai default; compact opsional untuk tabel kerja. Gunakan row spacing dan alignment yang konsisten. |
| Interaksi | Focus, hover, selected, disabled, readonly, loading, error, dan success harus punya definisi terpisah. Selected tidak hanya dibedakan dengan hue. |

Rancangan token mengikuti pasangan semantic surface/foreground dan override per tema; ini sejalan dengan [panduan theming shadcn-vue](https://www.shadcn-vue.com/docs/theming). Komponen yang membaca token tidak perlu menerima class warna per halaman.

### Kandidat palette, bukan perubahan kode

| Token / pasangan | Light | Dark |
| --- | --- | --- |
| background / foreground | `#F8FAFC` / `#0F172A` | `#0B1120` / `#E2E8F0` |
| card / card-foreground | `#FFFFFF` / `#0F172A` | `#111827` / `#E2E8F0` |
| popover / popover-foreground | `#FFFFFF` / `#0F172A` | `#111827` / `#E2E8F0` |
| primary / primary-foreground | `#4F46E5` / `#FFFFFF` | `#A5B4FC` / `#1E1B4B` |
| secondary / secondary-foreground | `#F1F5F9` / `#0F172A` | `#1E293B` / `#E2E8F0` |
| muted / muted-foreground | `#F1F5F9` / `#475569` | `#1E293B` / `#CBD5E1` |
| accent / accent-foreground | `#EEF2FF` / `#3730A3` | `#1E1B4B` / `#C7D2FE` |
| destructive / destructive-foreground | `#B91C1C` / `#FFFFFF` | `#FCA5A5` / `#450A0A` |
| border dekoratif | `#E2E8F0` | `#283548` |
| input boundary | `#64748B` | `#64748B` |
| ring | `#4F46E5` | `#A5B4FC` |
| sidebar / sidebar-foreground | `#FFFFFF` / `#0F172A` | `#111827` / `#E2E8F0` |
| sidebar-primary / foreground | Sama dengan pasangan primary | Sama dengan pasangan primary |
| sidebar-accent / foreground | Sama dengan pasangan accent | Sama dengan pasangan accent |
| sidebar-border / sidebar-ring | Sama dengan border / ring | Sama dengan border / ring |
| success-subtle / success-text | `#ECFDF5` / `#166534` | `#052E16` / `#86EFAC` |
| warning-subtle / warning-text | `#FFFBEB` / `#92400E` | `#451A03` / `#FCD34D` |
| danger-subtle / danger-text | `#FEF2F2` / `#991B1B` | `#450A0A` / `#FCA5A5` |
| info-subtle / info-text | `#EFF6FF` / `#1E40AF` | `#172554` / `#93C5FD` |
| chart-1 | `#4F46E5` | `#A5B4FC` |
| chart-2 | `#0369A1` | `#7DD3FC` |
| chart-3 | `#047857` | `#6EE7B7` |
| chart-4 | `#B45309` | `#FCD34D` |
| chart-5 | `#BE185D` | `#F9A8D4` |

Token status di atas merupakan usulan tambahan, bukan token yang sudah tersedia melalui API PanelTheme. Danger subtle dipisahkan dari filled destructive; neutral badge menggunakan muted yang sudah dihitung. Kandidat chart harus diuji pada latar aktual, antar-area yang bersentuhan, dan kondisi gangguan persepsi warna; warna tersebut tidak otomatis membuktikan seri dapat dibedakan. Tambahkan marker/dash/label.

### Perhitungan kandidat pasangan utama

| Pasangan opak | Light | Dark |
| --- | ---: | ---: |
| Teks / background | 17,06:1 | 15,27:1 |
| Teks / card | 17,85:1 | 14,39:1 |
| Muted text / muted surface | 6,92:1 | 9,85:1 |
| Primary foreground / primary | 6,29:1 | 8,02:1 |
| Accent foreground / accent | 8,88:1 | 10,72:1 |
| Destructive foreground / destructive | 6,47:1 | 8,51:1 |
| Success text / subtle | 6,77:1 | 10,62:1 |
| Warning text / subtle | 6,84:1 | 10,39:1 |
| Info text / subtle | 8,01:1 | 8,15:1 |
| Input boundary / card | 4,76:1 | 3,73:1 |

Metode: konversi sRGB ke luminansi relatif, lalu `(Llebih-terang + 0,05) / (Llebih-gelap + 0,05)`. Angka ditampilkan dengan pembulatan; evaluasi ambang memakai nilai sebelum pembulatan. Ini belum memvalidasi hover, transparansi, disabled, custom brand, overlay, gradient, atau seluruh kombinasi komponen.

## 7. Kriteria penerimaan untuk seluruh komponen

| Dimensi | Kriteria yang harus dibuktikan pada tahap implementasi berikutnya |
| --- | --- |
| Tema | Light, Dark, System; perubahan manual; reload; perubahan OS saat halaman terbuka; perpindahan panel; tema parsial; logo dan portal konsisten. |
| Kontras | Teks normal minimal 4,5:1; teks besar sesuai definisi WCAG minimal 3:1. Status penting tidak mengandalkan warna saja. [WCAG contrast minimum](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html). |
| Kontrol dan fokus | Petunjuk visual kontrol/state yang diperlukan untuk identifikasi minimal 3:1 terhadap warna yang bersebelahan. Tidak diterapkan membabi buta ke border dekoratif. [WCAG non-text contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html). |
| Keyboard | Seluruh fungsi tersedia tanpa pointer; urutan masuk akal; dialog mengembalikan fokus; fokus tidak seluruhnya tertutup sticky layer. [WCAG focus not obscured](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html). |
| Target interaksi | Minimum AA 24×24 CSS px atau memenuhi pengecualian/spacing yang berlaku; target desain touch 44×44px. Jangan menyebut 44px sebagai kewajiban AA universal. [WCAG target size](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html). |
| Tabs/search | Ikuti pola keyboard dan hubungan ARIA yang sesuai, bukan role saja. [APG Tabs](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/) dan [APG Combobox](https://www.w3.org/WAI/ARIA/apg/patterns/combobox/). |
| Responsif | Periksa 320, 375, 768, 1024, dan 1440px serta zoom 200%/400%. Tidak ada horizontal scroll halaman karena toolbar/form; tabel data boleh punya area scroll lokal ketika diperlukan. |
| Form | Label/helper/error saling terhubung; ID unik per instance; required/readonly/disabled jelas; error fokus; tidak kehilangan input; state upload dan validasi dapat dipahami. |
| Data | Label grafik/tooltip tepat; sort/filter/selection jelas; kosong ≠ gagal ≠ loading; tabel dan kartu mempertahankan konteks query. |
| Konten | String Indonesia/Inggris terlokalisasi; label panjang, nama record panjang, angka besar, nilai nol/null, dan status campuran tetap terbaca. |
| Ekstensi | Custom fields/widgets/hooks/columns/shell, host components, toast, dan nested portal ikut matrix tema dan keyboard. |

Setiap komponen pada inventaris harus mempunyai baris pemeriksaan state yang relevan: default, hover, focus-visible, active/selected, disabled, readonly, loading, empty, error, success, dan overflow. Tandai state yang tidak relevan sebagai N/A dengan alasan, jangan otomatis dianggap lulus.

## 8. Urutan perancangan dan verifikasi

1. **Fondasi tema:** T01–T09, kontrak token, portal scope, appearance, branding, dan contrast sheet.
2. **Alur utama yang terhambat:** U01–U02, U06–U09, U12, U14; selesaikan struktur interaksi sebelum dekorasi.
3. **Pola komponen:** shell, table toolbar, form field, tabs/wizard, action dialog, notification, dan widget.
4. **Konsistensi visual:** typography, spacing, density, elevation, microcopy, serta seluruh state.
5. **Verifikasi runtime host:** screenshot per tema/viewport/state, keyboard dan screen reader, measured contrast, error/slow network, dan ekstensi kustom.

Laporan ini selesai pada tahap audit source dan penyusunan arahan desain. Tidak ada klaim seluruh komponen sudah mendukung light/dark secara sempurna. Tidak ada source aplikasi, dependency, endpoint, atau konfigurasi yang diubah; tidak menjalankan build/test aplikasi karena hasil kerja saat ini berupa dokumentasi.
