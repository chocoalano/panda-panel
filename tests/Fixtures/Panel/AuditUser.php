<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel;

use App\Panels\Admin\Resources\Users\UserResource;
use Inertia\Inertia;
use Inertia\Response;
use PandaPanel\Resources\Concerns\InteractsWithRecord;
use PandaPanel\Resources\Pages\ResourcePage;

/**
 * A custom resource page on a nested path that operates on one record.
 */
final class AuditUser extends ResourcePage
{
    use InteractsWithRecord;

    protected static string $resource = UserResource::class;

    protected static ?string $routePath = '{record}/audit';

    public function render(string $record): Response
    {
        $model = $this->resolveRecord($record);

        return Inertia::render('panel/Page', [
            'page' => [
                'title' => 'Audit',
                'heading' => 'Audit',
                'subheading' => null,
                'breadcrumbs' => [],
                'headerActions' => [],
                'scope' => self::renderHookScope(),
            ],
            'auditedRecordKey' => $model->getKey(),
        ]);
    }
}
