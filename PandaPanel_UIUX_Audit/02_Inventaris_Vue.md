# Inventaris Vue untuk Audit UI/UX PandaPanel

Tanggal snapshot: 9 September 2026. Root: `panda-panel/resources/js` pada repository paket.

Inventaris ini mencakup **271 file aplikasi**. Status pemeriksaan tidak menyatakan komponen sudah lulus light/dark atau aksesibilitas.

- **Pemindaian source**: masuk inventaris dan pemindaian isi untuk pola warna, ARIA, motion, serta struktur; belum berarti seluruh perilakunya diperiksa manual.
- **Pemeriksaan terarah**: bagian source/template terkait juga dibaca atau ditelusuri untuk temuan/landasan audit. Bukan pengujian semua cabang props.
- **Runtime**: belum diverifikasi untuk seluruh baris; tahap lanjutan harus mengisi light, dark, keyboard, viewport, dan state sesuai prompt.

Checksum SHA-256 pendek dan jumlah baris membantu mengenali perubahan source setelah audit. Nomor baris laporan berlaku pada snapshot ini.

## Ringkasan cakupan

| Kelompok | Jumlah Vue |
| --- | ---: |
| Primitive UI | 148 |
| Komponen bersama | 11 |
| Halaman panel | 17 |
| Actions | 4 |
| Shell dan komponen panel | 19 |
| Forms dan fields | 37 |
| Infolists | 4 |
| Layouts | 5 |
| Relations | 3 |
| Tables | 13 |
| Widgets | 10 |
| **Total** | **271** |

Keluarga primitive: alert, avatar, badge, breadcrumb, button, calendar, card, checkbox, collapsible, dialog, dropdown-menu, input, input-otp, label, native-select, navigation-menu, popover, radio-group, select, separator, sheet, sidebar, skeleton, sonner, spinner, switch, table, textarea, tooltip.

Graph menemukan tambahan 7 Vue fallback di `frontend/host/components` dan 1 test fixture `tests/Fixtures/Panel/Plugins/stubs/Gauge.vue`. Berkas tersebut bukan bagian dari hitungan 271 UI aplikasi. Komponen host dan ekstensi kustom konsumen perlu evaluasi tersendiri.

Coverage graph generasi `2026-09-09T08:53:10Z` diperiksa untuk 271 Vue berikut: tidak ada gap yang tercatat dan metadata cocok pada saat pemeriksaan. Ini sinyal best-effort, bukan pembuktian kelengkapan parser/relasi template.

## Primitive UI

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [components/ui/alert/Alert.vue](../resources/js/components/ui/alert/Alert.vue) | 21 | `061849c47f6c` | Pemindaian source |
| [components/ui/alert/AlertDescription.vue](../resources/js/components/ui/alert/AlertDescription.vue) | 17 | `cf248b304a48` | Pemindaian source |
| [components/ui/alert/AlertTitle.vue](../resources/js/components/ui/alert/AlertTitle.vue) | 17 | `06a19c1ffb9c` | Pemindaian source |
| [components/ui/avatar/Avatar.vue](../resources/js/components/ui/avatar/Avatar.vue) | 18 | `42e6a82dafaf` | Pemindaian source |
| [components/ui/avatar/AvatarFallback.vue](../resources/js/components/ui/avatar/AvatarFallback.vue) | 21 | `eaaf5f988b8b` | Pemindaian source |
| [components/ui/avatar/AvatarImage.vue](../resources/js/components/ui/avatar/AvatarImage.vue) | 16 | `28c533de9f0a` | Pemindaian source |
| [components/ui/badge/Badge.vue](../resources/js/components/ui/badge/Badge.vue) | 26 | `248ec80e93ea` | Pemindaian source |
| [components/ui/breadcrumb/Breadcrumb.vue](../resources/js/components/ui/breadcrumb/Breadcrumb.vue) | 20 | `829f0b22cafb` | Pemindaian source |
| [components/ui/breadcrumb/BreadcrumbEllipsis.vue](../resources/js/components/ui/breadcrumb/BreadcrumbEllipsis.vue) | 26 | `c169f6476316` | Pemindaian source |
| [components/ui/breadcrumb/BreadcrumbItem.vue](../resources/js/components/ui/breadcrumb/BreadcrumbItem.vue) | 17 | `9c25b7bec572` | Pemindaian source |
| [components/ui/breadcrumb/BreadcrumbLink.vue](../resources/js/components/ui/breadcrumb/BreadcrumbLink.vue) | 21 | `3104a45b2ac5` | Pemindaian source |
| [components/ui/breadcrumb/BreadcrumbList.vue](../resources/js/components/ui/breadcrumb/BreadcrumbList.vue) | 17 | `1f23da90687d` | Pemindaian source |
| [components/ui/breadcrumb/BreadcrumbPage.vue](../resources/js/components/ui/breadcrumb/BreadcrumbPage.vue) | 20 | `6d41c1b212ea` | Pemindaian source |
| [components/ui/breadcrumb/BreadcrumbSeparator.vue](../resources/js/components/ui/breadcrumb/BreadcrumbSeparator.vue) | 22 | `7e59e930240f` | Pemindaian source |
| [components/ui/button/Button.vue](../resources/js/components/ui/button/Button.vue) | 31 | `7e4d659bcbdf` | Pemeriksaan terarah |
| [components/ui/calendar/Calendar.vue](../resources/js/components/ui/calendar/Calendar.vue) | 162 | `6e9cd9c35e12` | Pemindaian source |
| [components/ui/calendar/CalendarCell.vue](../resources/js/components/ui/calendar/CalendarCell.vue) | 23 | `abfabe72a58f` | Pemindaian source |
| [components/ui/calendar/CalendarCellTrigger.vue](../resources/js/components/ui/calendar/CalendarCellTrigger.vue) | 39 | `76458d2b3968` | Pemindaian source |
| [components/ui/calendar/CalendarGrid.vue](../resources/js/components/ui/calendar/CalendarGrid.vue) | 23 | `2fe95b7cdf5f` | Pemindaian source |
| [components/ui/calendar/CalendarGridBody.vue](../resources/js/components/ui/calendar/CalendarGridBody.vue) | 15 | `d4a93114ad51` | Pemindaian source |
| [components/ui/calendar/CalendarGridHead.vue](../resources/js/components/ui/calendar/CalendarGridHead.vue) | 16 | `74748a3def79` | Pemindaian source |
| [components/ui/calendar/CalendarGridRow.vue](../resources/js/components/ui/calendar/CalendarGridRow.vue) | 22 | `667dff7ed17c` | Pemindaian source |
| [components/ui/calendar/CalendarHeadCell.vue](../resources/js/components/ui/calendar/CalendarHeadCell.vue) | 23 | `afe00708acae` | Pemindaian source |
| [components/ui/calendar/CalendarHeader.vue](../resources/js/components/ui/calendar/CalendarHeader.vue) | 23 | `40a65b7e8050` | Pemindaian source |
| [components/ui/calendar/CalendarHeading.vue](../resources/js/components/ui/calendar/CalendarHeading.vue) | 30 | `b28611f722a4` | Pemindaian source |
| [components/ui/calendar/CalendarNextButton.vue](../resources/js/components/ui/calendar/CalendarNextButton.vue) | 31 | `6efaf0610595` | Pemindaian source |
| [components/ui/calendar/CalendarPrevButton.vue](../resources/js/components/ui/calendar/CalendarPrevButton.vue) | 31 | `23ea17c839d8` | Pemindaian source |
| [components/ui/card/Card.vue](../resources/js/components/ui/card/Card.vue) | 22 | `f792a0180d90` | Pemindaian source |
| [components/ui/card/CardAction.vue](../resources/js/components/ui/card/CardAction.vue) | 17 | `96b31cf6e550` | Pemindaian source |
| [components/ui/card/CardContent.vue](../resources/js/components/ui/card/CardContent.vue) | 17 | `c0dc7b82968f` | Pemindaian source |
| [components/ui/card/CardDescription.vue](../resources/js/components/ui/card/CardDescription.vue) | 17 | `b83461e0570a` | Pemindaian source |
| [components/ui/card/CardFooter.vue](../resources/js/components/ui/card/CardFooter.vue) | 17 | `8ca42fe06a37` | Pemindaian source |
| [components/ui/card/CardHeader.vue](../resources/js/components/ui/card/CardHeader.vue) | 17 | `eb9540756f3f` | Pemindaian source |
| [components/ui/card/CardTitle.vue](../resources/js/components/ui/card/CardTitle.vue) | 17 | `be1c63549fbb` | Pemindaian source |
| [components/ui/checkbox/Checkbox.vue](../resources/js/components/ui/checkbox/Checkbox.vue) | 35 | `0175a05cb328` | Pemindaian source |
| [components/ui/collapsible/Collapsible.vue](../resources/js/components/ui/collapsible/Collapsible.vue) | 19 | `406994e9c3a4` | Pemindaian source |
| [components/ui/collapsible/CollapsibleContent.vue](../resources/js/components/ui/collapsible/CollapsibleContent.vue) | 15 | `2181117891ba` | Pemindaian source |
| [components/ui/collapsible/CollapsibleTrigger.vue](../resources/js/components/ui/collapsible/CollapsibleTrigger.vue) | 15 | `4f8db2f40161` | Pemindaian source |
| [components/ui/dialog/Dialog.vue](../resources/js/components/ui/dialog/Dialog.vue) | 19 | `e42a0978a57d` | Pemindaian source |
| [components/ui/dialog/DialogClose.vue](../resources/js/components/ui/dialog/DialogClose.vue) | 15 | `bd8526926a98` | Pemindaian source |
| [components/ui/dialog/DialogContent.vue](../resources/js/components/ui/dialog/DialogContent.vue) | 56 | `5a4568e662e7` | Pemeriksaan terarah |
| [components/ui/dialog/DialogDescription.vue](../resources/js/components/ui/dialog/DialogDescription.vue) | 23 | `5f1233f040ed` | Pemindaian source |
| [components/ui/dialog/DialogFooter.vue](../resources/js/components/ui/dialog/DialogFooter.vue) | 30 | `767c7f2c43e9` | Pemindaian source |
| [components/ui/dialog/DialogHeader.vue](../resources/js/components/ui/dialog/DialogHeader.vue) | 17 | `4fb8d0113230` | Pemindaian source |
| [components/ui/dialog/DialogOverlay.vue](../resources/js/components/ui/dialog/DialogOverlay.vue) | 21 | `96fb4ff64453` | Pemeriksaan terarah |
| [components/ui/dialog/DialogScrollContent.vue](../resources/js/components/ui/dialog/DialogScrollContent.vue) | 62 | `13fcd7845541` | Pemeriksaan terarah |
| [components/ui/dialog/DialogTitle.vue](../resources/js/components/ui/dialog/DialogTitle.vue) | 23 | `f64ee2c63657` | Pemindaian source |
| [components/ui/dialog/DialogTrigger.vue](../resources/js/components/ui/dialog/DialogTrigger.vue) | 15 | `6458b0ad97b9` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenu.vue](../resources/js/components/ui/dropdown-menu/DropdownMenu.vue) | 19 | `ad0759b7b6d5` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuCheckboxItem.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuCheckboxItem.vue) | 39 | `62a0c836ba0b` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuContent.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuContent.vue) | 39 | `52ac6984ea00` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuGroup.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuGroup.vue) | 15 | `24fd1c9c8a0e` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuItem.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuItem.vue) | 31 | `b5c832288a19` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuLabel.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuLabel.vue) | 23 | `4158b4ec3f7c` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuRadioGroup.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuRadioGroup.vue) | 21 | `864ff5483045` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuRadioItem.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuRadioItem.vue) | 40 | `87c9b2a76fca` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuSeparator.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuSeparator.vue) | 23 | `03b94000ea1d` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuShortcut.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuShortcut.vue) | 17 | `6556695dce57` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuSub.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuSub.vue) | 18 | `6aa6307d0cf7` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuSubContent.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuSubContent.vue) | 27 | `d47ce7df6a56` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuSubTrigger.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuSubTrigger.vue) | 31 | `b92465ce5d9e` | Pemindaian source |
| [components/ui/dropdown-menu/DropdownMenuTrigger.vue](../resources/js/components/ui/dropdown-menu/DropdownMenuTrigger.vue) | 17 | `052aeba71e7a` | Pemindaian source |
| [components/ui/input/Input.vue](../resources/js/components/ui/input/Input.vue) | 33 | `7a55fe41c84a` | Pemeriksaan terarah |
| [components/ui/input-otp/InputOTP.vue](../resources/js/components/ui/input-otp/InputOTP.vue) | 28 | `befb2017b8d6` | Pemindaian source |
| [components/ui/input-otp/InputOTPGroup.vue](../resources/js/components/ui/input-otp/InputOTPGroup.vue) | 22 | `d5419502f35e` | Pemindaian source |
| [components/ui/input-otp/InputOTPSeparator.vue](../resources/js/components/ui/input-otp/InputOTPSeparator.vue) | 21 | `d9c9b53e526d` | Pemindaian source |
| [components/ui/input-otp/InputOTPSlot.vue](../resources/js/components/ui/input-otp/InputOTPSlot.vue) | 32 | `7a162d1316ec` | Pemindaian source |
| [components/ui/label/Label.vue](../resources/js/components/ui/label/Label.vue) | 26 | `6bb2e95050b0` | Pemindaian source |
| [components/ui/native-select/NativeSelect.vue](../resources/js/components/ui/native-select/NativeSelect.vue) | 50 | `0b192c830695` | Pemeriksaan terarah |
| [components/ui/native-select/NativeSelectOptGroup.vue](../resources/js/components/ui/native-select/NativeSelectOptGroup.vue) | 12 | `dbd5bd69ac29` | Pemindaian source |
| [components/ui/native-select/NativeSelectOption.vue](../resources/js/components/ui/native-select/NativeSelectOption.vue) | 12 | `2249a52c546d` | Pemindaian source |
| [components/ui/navigation-menu/NavigationMenu.vue](../resources/js/components/ui/navigation-menu/NavigationMenu.vue) | 35 | `eeac6b720971` | Pemindaian source |
| [components/ui/navigation-menu/NavigationMenuContent.vue](../resources/js/components/ui/navigation-menu/NavigationMenuContent.vue) | 31 | `948beb4a178a` | Pemindaian source |
| [components/ui/navigation-menu/NavigationMenuIndicator.vue](../resources/js/components/ui/navigation-menu/NavigationMenuIndicator.vue) | 23 | `cc67ffdebb64` | Pemindaian source |
| [components/ui/navigation-menu/NavigationMenuItem.vue](../resources/js/components/ui/navigation-menu/NavigationMenuItem.vue) | 21 | `90eced2bc651` | Pemindaian source |
| [components/ui/navigation-menu/NavigationMenuLink.vue](../resources/js/components/ui/navigation-menu/NavigationMenuLink.vue) | 26 | `f888ee221f15` | Pemindaian source |
| [components/ui/navigation-menu/NavigationMenuList.vue](../resources/js/components/ui/navigation-menu/NavigationMenuList.vue) | 28 | `7f647a236cdf` | Pemindaian source |
| [components/ui/navigation-menu/NavigationMenuTrigger.vue](../resources/js/components/ui/navigation-menu/NavigationMenuTrigger.vue) | 32 | `fd9245dced60` | Pemindaian source |
| [components/ui/navigation-menu/NavigationMenuViewport.vue](../resources/js/components/ui/navigation-menu/NavigationMenuViewport.vue) | 31 | `8e282d06c09e` | Pemindaian source |
| [components/ui/popover/Popover.vue](../resources/js/components/ui/popover/Popover.vue) | 19 | `2acd4ebc20f7` | Pemindaian source |
| [components/ui/popover/PopoverAnchor.vue](../resources/js/components/ui/popover/PopoverAnchor.vue) | 15 | `8e6f46b87044` | Pemindaian source |
| [components/ui/popover/PopoverContent.vue](../resources/js/components/ui/popover/PopoverContent.vue) | 45 | `0b49d4821446` | Pemeriksaan terarah |
| [components/ui/popover/PopoverTrigger.vue](../resources/js/components/ui/popover/PopoverTrigger.vue) | 15 | `3ce37a26b38d` | Pemindaian source |
| [components/ui/radio-group/RadioGroup.vue](../resources/js/components/ui/radio-group/RadioGroup.vue) | 25 | `1ce372ee7b1d` | Pemindaian source |
| [components/ui/radio-group/RadioGroupItem.vue](../resources/js/components/ui/radio-group/RadioGroupItem.vue) | 40 | `0b93c82580f3` | Pemindaian source |
| [components/ui/select/Select.vue](../resources/js/components/ui/select/Select.vue) | 19 | `0a100251a7ad` | Pemindaian source |
| [components/ui/select/SelectContent.vue](../resources/js/components/ui/select/SelectContent.vue) | 51 | `00a098d8de47` | Pemindaian source |
| [components/ui/select/SelectGroup.vue](../resources/js/components/ui/select/SelectGroup.vue) | 15 | `d75787597511` | Pemindaian source |
| [components/ui/select/SelectItem.vue](../resources/js/components/ui/select/SelectItem.vue) | 44 | `2ab515d9b9ba` | Pemindaian source |
| [components/ui/select/SelectItemText.vue](../resources/js/components/ui/select/SelectItemText.vue) | 15 | `76ad26caa4ad` | Pemindaian source |
| [components/ui/select/SelectLabel.vue](../resources/js/components/ui/select/SelectLabel.vue) | 17 | `84b0cac44a84` | Pemindaian source |
| [components/ui/select/SelectScrollDownButton.vue](../resources/js/components/ui/select/SelectScrollDownButton.vue) | 26 | `f2482084f7a3` | Pemindaian source |
| [components/ui/select/SelectScrollUpButton.vue](../resources/js/components/ui/select/SelectScrollUpButton.vue) | 26 | `e5d43cce73a0` | Pemindaian source |
| [components/ui/select/SelectSeparator.vue](../resources/js/components/ui/select/SelectSeparator.vue) | 19 | `f887abb6d269` | Pemindaian source |
| [components/ui/select/SelectTrigger.vue](../resources/js/components/ui/select/SelectTrigger.vue) | 33 | `b71e531649bb` | Pemindaian source |
| [components/ui/select/SelectValue.vue](../resources/js/components/ui/select/SelectValue.vue) | 15 | `ab6a9b14358e` | Pemindaian source |
| [components/ui/separator/Separator.vue](../resources/js/components/ui/separator/Separator.vue) | 29 | `8a90c981f04c` | Pemindaian source |
| [components/ui/sheet/Sheet.vue](../resources/js/components/ui/sheet/Sheet.vue) | 19 | `765fc0405d3b` | Pemindaian source |
| [components/ui/sheet/SheetClose.vue](../resources/js/components/ui/sheet/SheetClose.vue) | 15 | `a9ae9e32f815` | Pemindaian source |
| [components/ui/sheet/SheetContent.vue](../resources/js/components/ui/sheet/SheetContent.vue) | 65 | `18b8ec3adf7a` | Pemindaian source |
| [components/ui/sheet/SheetDescription.vue](../resources/js/components/ui/sheet/SheetDescription.vue) | 21 | `a99550b6cc36` | Pemindaian source |
| [components/ui/sheet/SheetFooter.vue](../resources/js/components/ui/sheet/SheetFooter.vue) | 16 | `6043806cee7d` | Pemindaian source |
| [components/ui/sheet/SheetHeader.vue](../resources/js/components/ui/sheet/SheetHeader.vue) | 15 | `f7fa221e5ba3` | Pemindaian source |
| [components/ui/sheet/SheetOverlay.vue](../resources/js/components/ui/sheet/SheetOverlay.vue) | 21 | `fbfcdfbf3815` | Pemindaian source |
| [components/ui/sheet/SheetTitle.vue](../resources/js/components/ui/sheet/SheetTitle.vue) | 21 | `25952291a202` | Pemindaian source |
| [components/ui/sheet/SheetTrigger.vue](../resources/js/components/ui/sheet/SheetTrigger.vue) | 15 | `cac73faa1a4a` | Pemindaian source |
| [components/ui/sidebar/Sidebar.vue](../resources/js/components/ui/sidebar/Sidebar.vue) | 99 | `d867a210270e` | Pemeriksaan terarah |
| [components/ui/sidebar/SidebarContent.vue](../resources/js/components/ui/sidebar/SidebarContent.vue) | 18 | `dabc8723fa5d` | Pemindaian source |
| [components/ui/sidebar/SidebarFooter.vue](../resources/js/components/ui/sidebar/SidebarFooter.vue) | 18 | `98c866da9f2a` | Pemindaian source |
| [components/ui/sidebar/SidebarGroup.vue](../resources/js/components/ui/sidebar/SidebarGroup.vue) | 18 | `48a74838ff74` | Pemindaian source |
| [components/ui/sidebar/SidebarGroupAction.vue](../resources/js/components/ui/sidebar/SidebarGroupAction.vue) | 27 | `d29a6fc9822d` | Pemindaian source |
| [components/ui/sidebar/SidebarGroupContent.vue](../resources/js/components/ui/sidebar/SidebarGroupContent.vue) | 18 | `689768308567` | Pemindaian source |
| [components/ui/sidebar/SidebarGroupLabel.vue](../resources/js/components/ui/sidebar/SidebarGroupLabel.vue) | 25 | `171714a885e2` | Pemindaian source |
| [components/ui/sidebar/SidebarHeader.vue](../resources/js/components/ui/sidebar/SidebarHeader.vue) | 18 | `2155513d69de` | Pemindaian source |
| [components/ui/sidebar/SidebarInput.vue](../resources/js/components/ui/sidebar/SidebarInput.vue) | 22 | `56a15ca69108` | Pemindaian source |
| [components/ui/sidebar/SidebarInset.vue](../resources/js/components/ui/sidebar/SidebarInset.vue) | 21 | `f6bfc89eb688` | Pemindaian source |
| [components/ui/sidebar/SidebarMenu.vue](../resources/js/components/ui/sidebar/SidebarMenu.vue) | 18 | `66b27c6752b3` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuAction.vue](../resources/js/components/ui/sidebar/SidebarMenuAction.vue) | 35 | `f7888134ddc7` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuBadge.vue](../resources/js/components/ui/sidebar/SidebarMenuBadge.vue) | 26 | `e3f23b6d4cae` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuButton.vue](../resources/js/components/ui/sidebar/SidebarMenuButton.vue) | 48 | `320a4e3f0263` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuButtonChild.vue](../resources/js/components/ui/sidebar/SidebarMenuButtonChild.vue) | 36 | `ae12036d28cb` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuItem.vue](../resources/js/components/ui/sidebar/SidebarMenuItem.vue) | 18 | `b008a19ad8ae` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuSkeleton.vue](../resources/js/components/ui/sidebar/SidebarMenuSkeleton.vue) | 35 | `7b7e0720057c` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuSub.vue](../resources/js/components/ui/sidebar/SidebarMenuSub.vue) | 22 | `d2a4d61aa61d` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuSubButton.vue](../resources/js/components/ui/sidebar/SidebarMenuSubButton.vue) | 36 | `085aee55f1ef` | Pemindaian source |
| [components/ui/sidebar/SidebarMenuSubItem.vue](../resources/js/components/ui/sidebar/SidebarMenuSubItem.vue) | 18 | `6463a58a0d47` | Pemindaian source |
| [components/ui/sidebar/SidebarProvider.vue](../resources/js/components/ui/sidebar/SidebarProvider.vue) | 82 | `09dde4175613` | Pemindaian source |
| [components/ui/sidebar/SidebarRail.vue](../resources/js/components/ui/sidebar/SidebarRail.vue) | 36 | `8759eae2f290` | Pemindaian source |
| [components/ui/sidebar/SidebarSeparator.vue](../resources/js/components/ui/sidebar/SidebarSeparator.vue) | 19 | `7642e81ed9b5` | Pemindaian source |
| [components/ui/sidebar/SidebarTrigger.vue](../resources/js/components/ui/sidebar/SidebarTrigger.vue) | 31 | `14a79dc2981d` | Pemindaian source |
| [components/ui/skeleton/Skeleton.vue](../resources/js/components/ui/skeleton/Skeleton.vue) | 17 | `c48185bdb2db` | Pemindaian source |
| [components/ui/sonner/Sonner.vue](../resources/js/components/ui/sonner/Sonner.vue) | 44 | `f6d118fb3539` | Pemeriksaan terarah |
| [components/ui/spinner/Spinner.vue](../resources/js/components/ui/spinner/Spinner.vue) | 20 | `9c56278f72fd` | Pemindaian source |
| [components/ui/switch/Switch.vue](../resources/js/components/ui/switch/Switch.vue) | 38 | `fa34a3e41056` | Pemeriksaan terarah |
| [components/ui/table/Table.vue](../resources/js/components/ui/table/Table.vue) | 16 | `6f2598364646` | Pemindaian source |
| [components/ui/table/TableBody.vue](../resources/js/components/ui/table/TableBody.vue) | 17 | `5dded3bc5292` | Pemindaian source |
| [components/ui/table/TableCaption.vue](../resources/js/components/ui/table/TableCaption.vue) | 17 | `a41eb7261757` | Pemindaian source |
| [components/ui/table/TableCell.vue](../resources/js/components/ui/table/TableCell.vue) | 22 | `cd3798e65d53` | Pemindaian source |
| [components/ui/table/TableEmpty.vue](../resources/js/components/ui/table/TableEmpty.vue) | 34 | `cacf4996d740` | Pemindaian source |
| [components/ui/table/TableFooter.vue](../resources/js/components/ui/table/TableFooter.vue) | 17 | `84aafcde0a7f` | Pemindaian source |
| [components/ui/table/TableHead.vue](../resources/js/components/ui/table/TableHead.vue) | 17 | `fc9398ef6d3b` | Pemindaian source |
| [components/ui/table/TableHeader.vue](../resources/js/components/ui/table/TableHeader.vue) | 17 | `d94790397845` | Pemindaian source |
| [components/ui/table/TableRow.vue](../resources/js/components/ui/table/TableRow.vue) | 28 | `8f02fed76aac` | Pemindaian source |
| [components/ui/textarea/Textarea.vue](../resources/js/components/ui/textarea/Textarea.vue) | 28 | `7dca60ac7225` | Pemindaian source |
| [components/ui/tooltip/Tooltip.vue](../resources/js/components/ui/tooltip/Tooltip.vue) | 19 | `674df497bf24` | Pemindaian source |
| [components/ui/tooltip/TooltipContent.vue](../resources/js/components/ui/tooltip/TooltipContent.vue) | 34 | `5e57428318b8` | Pemeriksaan terarah |
| [components/ui/tooltip/TooltipProvider.vue](../resources/js/components/ui/tooltip/TooltipProvider.vue) | 14 | `80df75297065` | Pemindaian source |
| [components/ui/tooltip/TooltipTrigger.vue](../resources/js/components/ui/tooltip/TooltipTrigger.vue) | 15 | `c904e72f5f86` | Pemindaian source |

## Komponen bersama

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [components/AppContent.vue](../resources/js/components/AppContent.vue) | 28 | `9eaa7239b773` | Pemindaian source |
| [components/AppShell.vue](../resources/js/components/AppShell.vue) | 24 | `39c6fadcc5cc` | Pemindaian source |
| [components/AppearanceTabs.vue](../resources/js/components/AppearanceTabs.vue) | 38 | `afb8169d5a65` | Pemeriksaan terarah |
| [components/DeleteUser.vue](../resources/js/components/DeleteUser.vue) | 116 | `f785892ff462` | Pemindaian source |
| [components/InputError.vue](../resources/js/components/InputError.vue) | 13 | `fc320547bd8d` | Pemeriksaan terarah |
| [components/ManagePasskeys.vue](../resources/js/components/ManagePasskeys.vue) | 68 | `71a613acc84f` | Pemindaian source |
| [components/ManageTwoFactor.vue](../resources/js/components/ManageTwoFactor.vue) | 92 | `59506225ead4` | Pemindaian source |
| [components/NavUser.vue](../resources/js/components/NavUser.vue) | 55 | `62a58d535854` | Pemindaian source |
| [components/PasskeyVerify.vue](../resources/js/components/PasskeyVerify.vue) | 77 | `9cc7ab80f121` | Pemindaian source |
| [components/PasswordInput.vue](../resources/js/components/PasswordInput.vue) | 51 | `4aee19e7dc6c` | Pemeriksaan terarah |
| [components/TextLink.vue](../resources/js/components/TextLink.vue) | 25 | `304301c8164d` | Pemindaian source |

## Halaman panel

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [pages/panel/Dashboard.vue](../resources/js/pages/panel/Dashboard.vue) | 62 | `b0a9012a87e1` | Pemindaian source |
| [pages/panel/Page.vue](../resources/js/pages/panel/Page.vue) | 57 | `a8280d7adbda` | Pemindaian source |
| [pages/panel/auth/EmailCode.vue](../resources/js/pages/panel/auth/EmailCode.vue) | 87 | `8ac2ac2589ad` | Pemeriksaan terarah |
| [pages/panel/auth/ForgotPassword.vue](../resources/js/pages/panel/auth/ForgotPassword.vue) | 69 | `c41b81c4b8fd` | Pemindaian source |
| [pages/panel/auth/Login.vue](../resources/js/pages/panel/auth/Login.vue) | 121 | `4f6133774508` | Pemeriksaan terarah |
| [pages/panel/auth/Register.vue](../resources/js/pages/panel/auth/Register.vue) | 104 | `e7e558ecbd9f` | Pemindaian source |
| [pages/panel/auth/ResetPassword.vue](../resources/js/pages/panel/auth/ResetPassword.vue) | 83 | `e0c3fd315f8f` | Pemindaian source |
| [pages/panel/auth/VerifyEmail.vue](../resources/js/pages/panel/auth/VerifyEmail.vue) | 56 | `e0b150595bf0` | Pemindaian source |
| [pages/panel/resources/Create.vue](../resources/js/pages/panel/resources/Create.vue) | 81 | `3250e7565d05` | Pemindaian source |
| [pages/panel/resources/Edit.vue](../resources/js/pages/panel/resources/Edit.vue) | 94 | `ac8d29b5cdaa` | Pemindaian source |
| [pages/panel/resources/Index.vue](../resources/js/pages/panel/resources/Index.vue) | 313 | `4b636e339e5e` | Pemindaian source |
| [pages/panel/resources/Integrations.vue](../resources/js/pages/panel/resources/Integrations.vue) | 706 | `8a16e9c68a5f` | Pemindaian source |
| [pages/panel/resources/ManageRelated.vue](../resources/js/pages/panel/resources/ManageRelated.vue) | 60 | `83b6d0709c19` | Pemindaian source |
| [pages/panel/resources/View.vue](../resources/js/pages/panel/resources/View.vue) | 124 | `84f105d05c01` | Pemindaian source |
| [pages/panel/settings/Appearance.vue](../resources/js/pages/panel/settings/Appearance.vue) | 28 | `193df64152e2` | Pemindaian source |
| [pages/panel/settings/Profile.vue](../resources/js/pages/panel/settings/Profile.vue) | 117 | `2b94f3d7d6b7` | Pemeriksaan terarah |
| [pages/panel/settings/Security.vue](../resources/js/pages/panel/settings/Security.vue) | 163 | `163cdb81c81b` | Pemindaian source |

## Actions

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [panel/actions/ActionButton.vue](../resources/js/panel/actions/ActionButton.vue) | 43 | `404f82caeef5` | Pemindaian source |
| [panel/actions/ActionDialog.vue](../resources/js/panel/actions/ActionDialog.vue) | 62 | `cc7feec199a9` | Pemindaian source |
| [panel/actions/ActionGroup.vue](../resources/js/panel/actions/ActionGroup.vue) | 55 | `a2c6fe66c8f7` | Pemindaian source |
| [panel/actions/ActionModal.vue](../resources/js/panel/actions/ActionModal.vue) | 388 | `b3857b1e14c2` | Pemeriksaan terarah |

## Shell dan komponen panel

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [panel/components/DashboardGuide.vue](../resources/js/panel/components/DashboardGuide.vue) | 146 | `3431c0e605cb` | Pemindaian source |
| [panel/components/EmptyState.vue](../resources/js/panel/components/EmptyState.vue) | 34 | `850f9b3479e5` | Pemeriksaan terarah |
| [panel/components/LoadingState.vue](../resources/js/panel/components/LoadingState.vue) | 39 | `c1d6f77c645e` | Pemeriksaan terarah |
| [panel/components/PageHeader.vue](../resources/js/panel/components/PageHeader.vue) | 41 | `0b64e1804cc7` | Pemeriksaan terarah |
| [panel/components/PanelBreadcrumb.vue](../resources/js/panel/components/PanelBreadcrumb.vue) | 39 | `f86328aaf975` | Pemindaian source |
| [panel/components/PanelClusterBar.vue](../resources/js/panel/components/PanelClusterBar.vue) | 68 | `088573c168c6` | Pemeriksaan terarah |
| [panel/components/PanelDatePicker.vue](../resources/js/panel/components/PanelDatePicker.vue) | 188 | `4e53c8ab8769` | Pemindaian source |
| [panel/components/PanelHeader.vue](../resources/js/panel/components/PanelHeader.vue) | 101 | `83c2e207340f` | Pemindaian source |
| [panel/components/PanelLocaleSwitcher.vue](../resources/js/panel/components/PanelLocaleSwitcher.vue) | 88 | `f4baa4486ba2` | Pemindaian source |
| [panel/components/PanelNavigation.vue](../resources/js/panel/components/PanelNavigation.vue) | 73 | `84b74e77daf1` | Pemindaian source |
| [panel/components/PanelNavigationItem.vue](../resources/js/panel/components/PanelNavigationItem.vue) | 97 | `9218b97985cd` | Pemindaian source |
| [panel/components/PanelNotifications.vue](../resources/js/panel/components/PanelNotifications.vue) | 357 | `2c379504ba72` | Pemeriksaan terarah |
| [panel/components/PanelRecordLayout.vue](../resources/js/panel/components/PanelRecordLayout.vue) | 54 | `ca52c9022f41` | Pemindaian source |
| [panel/components/PanelRenderHook.vue](../resources/js/panel/components/PanelRenderHook.vue) | 56 | `e788b3b1be04` | Pemindaian source |
| [panel/components/PanelSearch.vue](../resources/js/panel/components/PanelSearch.vue) | 328 | `9a24dcba817c` | Pemeriksaan terarah |
| [panel/components/PanelSidebar.vue](../resources/js/panel/components/PanelSidebar.vue) | 118 | `7d1452784011` | Pemeriksaan terarah |
| [panel/components/PanelSubNavigation.vue](../resources/js/panel/components/PanelSubNavigation.vue) | 65 | `7ab92ac544f3` | Pemindaian source |
| [panel/components/PanelSwitcher.vue](../resources/js/panel/components/PanelSwitcher.vue) | 113 | `8670504a582f` | Pemeriksaan terarah |
| [panel/components/PanelTenantSwitcher.vue](../resources/js/panel/components/PanelTenantSwitcher.vue) | 107 | `88c1af0e00d9` | Pemindaian source |

## Forms dan fields

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [panel/forms/FormCallout.vue](../resources/js/panel/forms/FormCallout.vue) | 69 | `16666a94341e` | Pemindaian source |
| [panel/forms/FormComponentRenderer.vue](../resources/js/panel/forms/FormComponentRenderer.vue) | 125 | `ae14110a6e30` | Pemeriksaan terarah |
| [panel/forms/FormCustomComponent.vue](../resources/js/panel/forms/FormCustomComponent.vue) | 62 | `a251afeb8c5e` | Pemindaian source |
| [panel/forms/FormEmptyState.vue](../resources/js/panel/forms/FormEmptyState.vue) | 27 | `96319c9c82fc` | Pemindaian source |
| [panel/forms/FormField.vue](../resources/js/panel/forms/FormField.vue) | 295 | `bc06db0fb302` | Pemeriksaan terarah |
| [panel/forms/FormGrid.vue](../resources/js/panel/forms/FormGrid.vue) | 50 | `e7f3aaea0117` | Pemindaian source |
| [panel/forms/FormPrime.vue](../resources/js/panel/forms/FormPrime.vue) | 66 | `d89ef7d4db87` | Pemindaian source |
| [panel/forms/FormRelationship.vue](../resources/js/panel/forms/FormRelationship.vue) | 48 | `cc275a783336` | Pemindaian source |
| [panel/forms/FormRenderer.vue](../resources/js/panel/forms/FormRenderer.vue) | 541 | `7cf942d3cdb1` | Pemeriksaan terarah |
| [panel/forms/FormSection.vue](../resources/js/panel/forms/FormSection.vue) | 77 | `a49c03bf9a97` | Pemindaian source |
| [panel/forms/FormTabs.vue](../resources/js/panel/forms/FormTabs.vue) | 130 | `872765cf1525` | Pemeriksaan terarah |
| [panel/forms/FormWizard.vue](../resources/js/panel/forms/FormWizard.vue) | 240 | `1bc923f58591` | Pemeriksaan terarah |
| [panel/forms/fields/BuilderField.vue](../resources/js/panel/forms/fields/BuilderField.vue) | 307 | `0e7c72ea6f1b` | Pemeriksaan terarah |
| [panel/forms/fields/CheckboxField.vue](../resources/js/panel/forms/fields/CheckboxField.vue) | 34 | `aae1852fa6b2` | Pemindaian source |
| [panel/forms/fields/CheckboxListField.vue](../resources/js/panel/forms/fields/CheckboxListField.vue) | 130 | `42c8a31cf538` | Pemindaian source |
| [panel/forms/fields/CodeEditorField.vue](../resources/js/panel/forms/fields/CodeEditorField.vue) | 130 | `1158db79128c` | Pemindaian source |
| [panel/forms/fields/ColorPickerField.vue](../resources/js/panel/forms/fields/ColorPickerField.vue) | 88 | `533c0f48f34e` | Pemindaian source |
| [panel/forms/fields/CustomFieldRenderer.vue](../resources/js/panel/forms/fields/CustomFieldRenderer.vue) | 58 | `945cd9a9290e` | Pemindaian source |
| [panel/forms/fields/DateField.vue](../resources/js/panel/forms/fields/DateField.vue) | 37 | `27799506773d` | Pemindaian source |
| [panel/forms/fields/DateTimeField.vue](../resources/js/panel/forms/fields/DateTimeField.vue) | 54 | `7c533ada18d2` | Pemindaian source |
| [panel/forms/fields/FieldWrapper.vue](../resources/js/panel/forms/fields/FieldWrapper.vue) | 66 | `7e8ec50cbb71` | Pemeriksaan terarah |
| [panel/forms/fields/FileUploadField.vue](../resources/js/panel/forms/fields/FileUploadField.vue) | 216 | `a9388d436ac0` | Pemeriksaan terarah |
| [panel/forms/fields/KeyValueField.vue](../resources/js/panel/forms/fields/KeyValueField.vue) | 157 | `80285300b96d` | Pemindaian source |
| [panel/forms/fields/MarkdownEditorField.vue](../resources/js/panel/forms/fields/MarkdownEditorField.vue) | 176 | `6d3e41916eb8` | Pemindaian source |
| [panel/forms/fields/NumberField.vue](../resources/js/panel/forms/fields/NumberField.vue) | 43 | `5d5ca0518a83` | Pemindaian source |
| [panel/forms/fields/PasswordField.vue](../resources/js/panel/forms/fields/PasswordField.vue) | 74 | `b08288aaf351` | Pemindaian source |
| [panel/forms/fields/RadioField.vue](../resources/js/panel/forms/fields/RadioField.vue) | 72 | `2bbf513362ef` | Pemindaian source |
| [panel/forms/fields/RepeaterField.vue](../resources/js/panel/forms/fields/RepeaterField.vue) | 239 | `89884143ab36` | Pemeriksaan terarah |
| [panel/forms/fields/RichEditorField.vue](../resources/js/panel/forms/fields/RichEditorField.vue) | 167 | `88c80af95903` | Pemeriksaan terarah |
| [panel/forms/fields/SelectField.vue](../resources/js/panel/forms/fields/SelectField.vue) | 298 | `afda80b5d57f` | Pemeriksaan terarah |
| [panel/forms/fields/SliderField.vue](../resources/js/panel/forms/fields/SliderField.vue) | 62 | `556235e0576e` | Pemindaian source |
| [panel/forms/fields/TagsInputField.vue](../resources/js/panel/forms/fields/TagsInputField.vue) | 144 | `ec902a3e69e9` | Pemindaian source |
| [panel/forms/fields/TextInputField.vue](../resources/js/panel/forms/fields/TextInputField.vue) | 37 | `7a992f2ee263` | Pemeriksaan terarah |
| [panel/forms/fields/TextareaField.vue](../resources/js/panel/forms/fields/TextareaField.vue) | 37 | `09e80383392d` | Pemindaian source |
| [panel/forms/fields/TimeField.vue](../resources/js/panel/forms/fields/TimeField.vue) | 45 | `40ae0aabc2bd` | Pemindaian source |
| [panel/forms/fields/ToggleButtonsField.vue](../resources/js/panel/forms/fields/ToggleButtonsField.vue) | 101 | `203fa60b592a` | Pemindaian source |
| [panel/forms/fields/ToggleField.vue](../resources/js/panel/forms/fields/ToggleField.vue) | 34 | `71df2c9d431c` | Pemindaian source |

## Infolists

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [panel/infolists/InfolistEntry.vue](../resources/js/panel/infolists/InfolistEntry.vue) | 276 | `4799886b3139` | Pemeriksaan terarah |
| [panel/infolists/InfolistNode.vue](../resources/js/panel/infolists/InfolistNode.vue) | 109 | `5277c6c8b336` | Pemindaian source |
| [panel/infolists/InfolistRenderer.vue](../resources/js/panel/infolists/InfolistRenderer.vue) | 88 | `52f6964c1432` | Pemindaian source |
| [panel/infolists/InfolistTabs.vue](../resources/js/panel/infolists/InfolistTabs.vue) | 95 | `55d46b60c746` | Pemeriksaan terarah |

## Layouts

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [panel/layouts/HeaderPanelLayout.vue](../resources/js/panel/layouts/HeaderPanelLayout.vue) | 175 | `7116d0401e1f` | Pemeriksaan terarah |
| [panel/layouts/PanelAuthLayout.vue](../resources/js/panel/layouts/PanelAuthLayout.vue) | 70 | `04ce21df076a` | Pemeriksaan terarah |
| [panel/layouts/PanelBlankLayout.vue](../resources/js/panel/layouts/PanelBlankLayout.vue) | 20 | `56293d4110fa` | Pemindaian source |
| [panel/layouts/PanelLayout.vue](../resources/js/panel/layouts/PanelLayout.vue) | 60 | `8653ac2b4db7` | Pemindaian source |
| [panel/layouts/SidebarPanelLayout.vue](../resources/js/panel/layouts/SidebarPanelLayout.vue) | 113 | `12d7355d2399` | Pemeriksaan terarah |

## Relations

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [panel/relations/RelationFormDialog.vue](../resources/js/panel/relations/RelationFormDialog.vue) | 184 | `778868aedd2d` | Pemeriksaan terarah |
| [panel/relations/RelationManagerList.vue](../resources/js/panel/relations/RelationManagerList.vue) | 29 | `84b39a41943b` | Pemindaian source |
| [panel/relations/RelationManagerPanel.vue](../resources/js/panel/relations/RelationManagerPanel.vue) | 217 | `4b077477bc9d` | Pemindaian source |

## Tables

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [panel/tables/DataTable.vue](../resources/js/panel/tables/DataTable.vue) | 834 | `893a940e6b5a` | Pemeriksaan terarah |
| [panel/tables/DataTableBulkActions.vue](../resources/js/panel/tables/DataTableBulkActions.vue) | 57 | `421d37c049ac` | Pemeriksaan terarah |
| [panel/tables/DataTableCard.vue](../resources/js/panel/tables/DataTableCard.vue) | 188 | `a07f720c3302` | Pemeriksaan terarah |
| [panel/tables/DataTableCell.vue](../resources/js/panel/tables/DataTableCell.vue) | 240 | `631214a3b564` | Pemindaian source |
| [panel/tables/DataTableColumnManager.vue](../resources/js/panel/tables/DataTableColumnManager.vue) | 287 | `576298b96943` | Pemindaian source |
| [panel/tables/DataTableFilters.vue](../resources/js/panel/tables/DataTableFilters.vue) | 251 | `a7c131f471a6` | Pemindaian source |
| [panel/tables/DataTableGrid.vue](../resources/js/panel/tables/DataTableGrid.vue) | 257 | `aeacd51a16a7` | Pemeriksaan terarah |
| [panel/tables/DataTableLayoutToggle.vue](../resources/js/panel/tables/DataTableLayoutToggle.vue) | 67 | `a6580ba738b3` | Pemindaian source |
| [panel/tables/DataTablePagination.vue](../resources/js/panel/tables/DataTablePagination.vue) | 95 | `8aa5dc0281fe` | Pemeriksaan terarah |
| [panel/tables/DataTableQueryBuilder.vue](../resources/js/panel/tables/DataTableQueryBuilder.vue) | 209 | `3f6bdb3dea00` | Pemindaian source |
| [panel/tables/DataTableSortMenu.vue](../resources/js/panel/tables/DataTableSortMenu.vue) | 99 | `600cc827e7d2` | Pemindaian source |
| [panel/tables/DataTableTabs.vue](../resources/js/panel/tables/DataTableTabs.vue) | 59 | `344cce63b487` | Pemindaian source |
| [panel/tables/DataTableToolbar.vue](../resources/js/panel/tables/DataTableToolbar.vue) | 389 | `73d776a96ee8` | Pemeriksaan terarah |

## Widgets

| File | Baris | SHA-256 pendek | Pemeriksaan |
| --- | ---: | --- | --- |
| [panel/widgets/ChartWidget.vue](../resources/js/panel/widgets/ChartWidget.vue) | 653 | `5b9ab7361378` | Pemeriksaan terarah |
| [panel/widgets/CustomWidget.vue](../resources/js/panel/widgets/CustomWidget.vue) | 25 | `abb44c5f1bab` | Pemindaian source |
| [panel/widgets/PageWidgets.vue](../resources/js/panel/widgets/PageWidgets.vue) | 34 | `4f50d57e5a33` | Pemindaian source |
| [panel/widgets/StatsWidget.vue](../resources/js/panel/widgets/StatsWidget.vue) | 264 | `c6a7d16eaf36` | Pemeriksaan terarah |
| [panel/widgets/TableWidget.vue](../resources/js/panel/widgets/TableWidget.vue) | 266 | `7b18b05bed65` | Pemindaian source |
| [panel/widgets/WidgetFallback.vue](../resources/js/panel/widgets/WidgetFallback.vue) | 32 | `800759cf0ed0` | Pemindaian source |
| [panel/widgets/WidgetFilters.vue](../resources/js/panel/widgets/WidgetFilters.vue) | 139 | `009fdaf298b8` | Pemindaian source |
| [panel/widgets/WidgetGrid.vue](../resources/js/panel/widgets/WidgetGrid.vue) | 90 | `ac5d8e38be9d` | Pemeriksaan terarah |
| [panel/widgets/WidgetRenderer.vue](../resources/js/panel/widgets/WidgetRenderer.vue) | 111 | `e04d0f899f4e` | Pemindaian source |
| [panel/widgets/WidgetShell.vue](../resources/js/panel/widgets/WidgetShell.vue) | 96 | `33d5084bb857` | Pemindaian source |

## Referensi pendukung non-Vue

- `resources/css/panda-panel.css`: token, tema, frozen cell, dan nilai kontras; partial parse graph baris 5–8 diperiksa langsung.
- `resources/js/composables/useAppearance.ts`: pilihan tema dan perubahan OS.
- `resources/js/panel/composables/usePanelStyling.ts`: cakupan palet panel.
- `resources/js/panel/composables/usePanelBranding.ts`: logo/icon per tema.
- `resources/js/panel/palette.ts`: status badge/icon/selection.
- `resources/js/components/ui/{button,badge,alert}/index.ts`: definisi variant.
- `src/Support/PanelTheme.php`: batas kontrak nama token yang diserialisasi.
- `package.json`, `vite.config.ts`, `frontend/browser/vite.config.ts`: stack dan batas build/fixture.
- `stubs/install/app.blade.php.stub`: first-paint theme pada instalasi host kosong.
- `frontend/host/README.md`: peran komponen fallback.
- Source dependency lokal `node_modules/reka-ui/src/Teleport/Teleport.vue`: default target portal; diperiksa langsung di luar inventaris kode aplikasi.

Kembali ke [laporan audit](00_Audit_UIUX_Frontend.md) atau [prompt perancangan](01_Prompt_Perancangan_Shadcn_Vue.md).
