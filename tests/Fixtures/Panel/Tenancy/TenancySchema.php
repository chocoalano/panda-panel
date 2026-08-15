<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single-database tenancy: two workspaces, documents belonging to one each,
 * and a pivot saying which users may enter which workspace.
 *
 * The single-database arrangement rather than a database per tenant, because
 * it is the one with something to test. With a connection per tenant the
 * boundary is the connection and the panel has nothing to scope; here a
 * missing `where` is a data leak, which is exactly the failure the scoping is
 * for.
 */
final class TenancySchema
{
    public static function create(): void
    {
        if (Schema::hasTable('fixture_workspaces')) {
            return;
        }

        Schema::create('fixture_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('fixture_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id');
            $table->string('title');
        });

        Schema::create('fixture_workspace_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id');
            $table->foreignId('user_id');
        });
    }
}
