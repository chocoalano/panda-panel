<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Relations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the relation fixtures need.
 *
 * Created by the tests that use them rather than by a migration: these models
 * exist for the panel's own test suite and have no place in the
 * application's schema.
 */
final class RelationSchema
{
    public static function create(): void
    {
        if (Schema::hasTable('fixture_projects')) {
            return;
        }

        Schema::create('fixture_projects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('fixture_tasks', function (Blueprint $table): void {
            $table->id();
            // Nullable so a child can be dissociated rather than deleted.
            $table->foreignId('project_id')->nullable();
            $table->string('name');
            // For the editable-column fixtures.
            $table->string('status')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->softDeletes();
        });

        Schema::create('fixture_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('fixture_label_project', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->foreignId('label_id');
            $table->string('role')->nullable();
        });

        Schema::create('fixture_briefs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id');
            $table->string('summary')->nullable();
        });
    }
}
