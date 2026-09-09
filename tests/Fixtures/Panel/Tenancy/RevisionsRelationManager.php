<?php

declare(strict_types=1);

namespace Tests\Fixtures\Panel\Tenancy;

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Resources\RelationManager;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\TableSchema;

/**
 * A relation manager on a tenant-scoped resource, carrying an action with a
 * form.
 *
 * Its reason for existing is the one test that could not otherwise be written:
 * whether the relation action-form endpoint keeps tenants apart. Every other
 * scope failure — a related record from another owner, a relation the resource
 * never declared — is visible without tenancy; this one is only visible when
 * two tenants own structurally identical rows.
 */
final class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Revisions';

    protected static ?string $recordTitleAttribute = 'note';

    public static function table(TableSchema $table, Model $owner): TableSchema
    {
        return $table
            ->columns([TextColumn::make('note')])
            ->recordActions([
                Action::make('annotate')
                    ->label('Annotate')
                    ->schema(fn (?Model $record): FormSchema => FormSchema::make()->schema([
                        TextInput::make('note')->required(),
                    ]))
                    ->action(function (Model $record, array $data): void {
                        $record->forceFill(['note' => $data['note']])->save();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function form(FormSchema $schema, Model $owner): FormSchema
    {
        return $schema->schema([TextInput::make('note')->required()]);
    }
}
