<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use PandaPanel\Forms\Enums\FieldType;

/**
 * A single- or multiple-choice field, backed either by a static option list
 * or by a relation.
 *
 * Relation options are resolved on the server and serialized as plain
 * value/label pairs. The relation name, the related model class, and the
 * query never reach the browser.
 *
 * The two ways a value is validated are deliberately different. A static list
 * is a whitelist, so the submitted value must be one of its keys. A relation
 * is not: the list is one bounded page of a table that may have thousands of
 * rows, so validity is the database's answer (`exists`) and the options are
 * only what the browser was able to show. Validating a relation against the
 * shown page would refuse a perfectly real key for having sorted too late.
 */
final class Select extends Field
{
    /** @var array<array-key, string> */
    private array $options = [];

    private ?string $relation = null;

    private ?string $titleAttribute = null;

    private bool $searchable = false;

    private bool $multiple = false;

    private int $optionLimit = 50;

    /** @var array{table: string, column: string}|null */
    private ?array $existsIn = null;

    /**
     * Options resolved from the relation, filled in by the schema before it
     * serializes. Kept apart from `$options` so a relation-backed field never
     * starts validating against whatever page it happened to render.
     *
     * @var list<array{value: string, label: string}>|null
     */
    private ?array $resolvedOptions = null;

    public function type(): FieldType
    {
        return FieldType::Select;
    }

    /**
     * @param  array<array-key, string>  $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Options come from a relation on the resource's model.
     *
     * `BelongsTo` writes the foreign key on the record itself. `BelongsToMany`
     * and `MorphToMany` write a set of pivot rows, so the field turns into a
     * multiple select and the schema syncs it after the record is saved —
     * a relation that does not exist yet cannot be synced before the row does.
     */
    public function relationship(string $relation, string $titleAttribute): self
    {
        $this->relation = $relation;
        $this->titleAttribute = $titleAttribute;

        return $this;
    }

    /**
     * Validity is a row existing in `$table`, not membership of the shown
     * options.
     */
    public function existsIn(string $table, string $column): self
    {
        $this->existsIn = ['table' => $table, 'column' => $column];

        return $this;
    }

    public function searchable(bool $searchable = true): self
    {
        $this->searchable = $searchable;

        return $this;
    }

    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function optionLimit(int $limit): self
    {
        $this->optionLimit = max(1, $limit);

        return $this;
    }

    public function getRelation(): ?string
    {
        return $this->relation;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * Whether the value belongs to a related table rather than to a column on
     * the record, so the schema knows to sync it after the write.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function writesToPivot(string $modelClass): bool
    {
        if ($this->relation === null) {
            return false;
        }

        $relation = $this->eloquentRelation($modelClass);

        // `MorphToMany` extends `BelongsToMany`, so one check answers for both.
        return $relation instanceof BelongsToMany;
    }

    /**
     * Fills in the options a relation-backed field renders with, and turns a
     * many-to-many one into a multiple select.
     *
     * Called by the schema, which is the only place that knows the resource
     * model. Doing it here rather than in `toArray()` keeps the component
     * signature free of a model class it has no other use for.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function hydrateRelationship(string $modelClass): void
    {
        if ($this->relation === null) {
            return;
        }

        $relation = $this->eloquentRelation($modelClass);

        if ($relation instanceof BelongsToMany) {
            $this->multiple = true;
        }

        $related = $relation->getRelated();

        $this->existsIn ??= [
            'table' => $related->getTable(),
            'column' => $related->getKeyName(),
        ];

        $this->resolvedOptions = $this->resolveOptions($modelClass);
    }

    /**
     * The value/label pairs the browser receives.
     *
     * @param  class-string<Model>|null  $modelClass  the resource model, needed to resolve a relation
     * @return list<array{value: string, label: string}>
     */
    public function resolveOptions(?string $modelClass = null, ?string $search = null): array
    {
        if ($this->relation === null) {
            return $this->mapOptions($this->options);
        }

        if ($modelClass === null) {
            throw new InvalidArgumentException(
                "The field [{$this->getName()}] uses a relationship, so it needs the resource model to resolve options.",
            );
        }

        $related = $this->eloquentRelation($modelClass)->getRelated();
        $title = $this->titleAttribute ?? $related->getKeyName();

        $query = $related->newQuery();

        if ($search !== null && $search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $query->where($title, 'like', '%'.$escaped.'%');
        }

        /** @var array<array-key, string> $options */
        $options = $query
            ->orderBy($title)
            ->limit($this->optionLimit)
            ->pluck($title, $related->getKeyName())
            ->all();

        return $this->mapOptions($options);
    }

    /**
     * The keys currently selected, for a multiple select backed by a
     * many-to-many.
     *
     * @return list<string>
     */
    public function relatedKeys(Model $record): array
    {
        if ($this->relation === null) {
            return [];
        }

        $relation = $record->{$this->relation}();

        if (! $relation instanceof BelongsToMany) {
            return [];
        }

        return array_values(array_map(strval(...), $relation->allRelatedIds()->all()));
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        if ($this->multiple) {
            return ['array'];
        }

        if ($this->existsIn !== null) {
            return [Rule::exists($this->existsIn['table'], $this->existsIn['column'])];
        }

        // A relation with no resolved table yet: nothing this layer can say
        // about the value, and inventing a rule from the rendered page would
        // refuse keys that are perfectly real.
        if ($this->relation !== null) {
            return [];
        }

        return $this->options === []
            ? []
            : [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    /**
     * The rules for each element of a multiple select.
     *
     * Returned separately because Laravel expects them under a `field.*` key
     * rather than inside the field's own rule list.
     *
     * @return list<mixed>
     */
    public function elementRules(): array
    {
        if (! $this->multiple) {
            return [];
        }

        if ($this->existsIn !== null) {
            return [Rule::exists($this->existsIn['table'], $this->existsIn['column'])];
        }

        return $this->options === []
            ? []
            : [Rule::in(array_map(strval(...), array_keys($this->options)))];
    }

    /**
     * @return string|int|list<string>|null
     */
    protected function castForForm(mixed $value): string|int|array|null
    {
        if ($this->multiple) {
            return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
        }

        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'options' => $this->resolvedOptions ?? $this->mapOptions($this->options),
            'searchable' => $this->searchable,
            'multiple' => $this->multiple,
            'usesRelationship' => $this->relation !== null,
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return Relation<Model, Model, mixed>
     */
    private function eloquentRelation(string $modelClass): Relation
    {
        $name = (string) $this->relation;

        if (! method_exists($modelClass, $name)) {
            throw new InvalidArgumentException(
                "[{$name}] is not a method on [{$modelClass}], so the field [{$this->getName()}] cannot resolve it.",
            );
        }

        $relation = (new $modelClass)->{$name}();

        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException(
                "[{$name}] on [{$modelClass}] is not an Eloquent relation.",
            );
        }

        return $relation;
    }

    /**
     * The attribute a `BelongsTo` writes, so the field can persist to the
     * foreign key rather than to a column named after the relation.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function foreignKeyFor(string $modelClass): ?string
    {
        if ($this->relation === null) {
            return null;
        }

        $relation = $this->eloquentRelation($modelClass);

        return $relation instanceof BelongsTo ? $relation->getForeignKeyName() : null;
    }

    /**
     * @param  array<array-key, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private function mapOptions(array $options): array
    {
        $mapped = [];

        foreach ($options as $value => $label) {
            $mapped[] = ['value' => (string) $value, 'label' => $label];
        }

        return $mapped;
    }
}
