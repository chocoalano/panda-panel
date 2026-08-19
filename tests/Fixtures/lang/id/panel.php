<?php

declare(strict_types=1);

/*
 * The same file in Indonesian — the one an application writes once and every
 * table, form, infolist, filter and export column in every panel follows.
 *
 * `user.name` is here as a dotted key on purpose: a relation attribute is
 * named `user.name`, and Laravel's `Arr::get()` checks the literal key before
 * it splits on dots, so a flat entry works without nesting.
 */

return [
    'fields' => [
        'name' => 'Nama lengkap',
        'created_at' => 'Dibuat pada',
        'user.name' => 'Nama pengguna',
    ],

    'relations' => [
        'posts' => 'Artikel',
    ],

    'resources' => [
        'User' => 'Pengguna',
    ],

    'actions' => [
        'impersonate' => 'Masuk sebagai',
    ],

    'tabs' => [
        'active' => 'Aktif',
    ],

    'blocks' => [
        'paragraph' => 'Paragraf',
    ],

    'values' => [
        'published' => 'Terbit',
    ],

    'clusters' => [
        'Settings' => 'Pengaturan',
    ],

    'panels' => [
        'admin' => 'Administrasi',
    ],
];
