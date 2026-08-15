<?php

declare(strict_types=1);

namespace PandaPanel\Forms;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Forms\Components\Field;
use PandaPanel\Forms\Components\FormComponent;
use PandaPanel\Forms\Components\PasswordInput;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Layouts\Relationship;
use PandaPanel\Forms\Layouts\Wizard;
use PandaPanel\Support\ColumnCount;

/**
 * The declarative description of a resource form.
 *
 * The schema owns three things that must agree: what renders, what
 * validates, and what persists. Because all three derive from the same field
 * list, a field cannot be validated without being declared, and a value
 * cannot be persisted without a field that dehydrates it. Anything the
 * browser sends that has no field here is discarded.
 */
final class FormSchema
{
    /** @var list<FormComponent> */
    private array $components = [];

    private int $columns = 1;

    /** @var class-string<Model>|null */
    private ?string $modelClass = null;

    private string $page = 'create';

    public static function make(): self
    {
        return new self;
    }

    /**
     * @param  array<array-key, FormComponent>  $components
     */
    public function schema(array $components): self
    {
        $this->components = array_values($components);

        return $this;
    }

    public function columns(int $columns): self
    {
        $this->columns = ColumnCount::clamp($columns);

        return $this;
    }

    /**
     * Turns a call meant for a field into a sentence saying so.
     *
     * `columnSpanFull()` reads naturally at the end of a `$schema->columns(2)
     * ->schema([...])` chain, and PHP's own answer — "Call to unknown method
     * PandaPanel\Forms\FormSchema::columnSpanFull()" — names the class without
     * naming the mistake, which is that a span belongs to a component inside
     * the schema rather than to the schema. The schema is the root; there is
     * nothing outside it to span.
     *
     * Only the calls that have an obvious right home are translated. Anything
     * else gets the ordinary error, because a guess dressed up as a
     * suggestion is worse than no suggestion.
     *
     * @param  list<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): never
    {
        $onAField = ['columnSpan', 'columnSpanFull', 'hidden', 'visible', 'required', 'disabled'];

        throw new BadMethodCallException(in_array($method, $onAField, true)
            ? sprintf(
                '%s() belongs to a field, not to the form schema. Move it onto the '
                    ."component you meant:\n\n    \$schema->columns(2)->schema([\n"
                    ."        TextInput::make('name')->%s(),\n    ]);",
                $method,
                $method,
            )
            : sprintf('Call to undefined method %s::%s().', self::class, $method));
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function model(string $modelClass): self
    {
        $this->modelClass = $modelClass;

        return $this;
    }

    /**
     * Which page the schema is being built for, so `hiddenOn()` can apply.
     */
    public function forPage(string $page): self
    {
        $this->page = $page;

        return $this;
    }

    public function getPage(): string
    {
        return $this->page;
    }

    /**
     * @return class-string<Model>|null
     */
    public function getModelClass(): ?string
    {
        return $this->modelClass;
    }

    /**
     * Every field visible on the current page.
     *
     * @return list<Field>
     */
    public function fields(?Model $record = null): array
    {
        $fields = [];

        foreach ($this->components as $component) {
            foreach ($component->fields() as $field) {
                if (! $field->isHiddenOn($this->page, $record)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * The top-level components, for a caller assembling one schema out of
     * several — a relation form is a record form and a pivot form side by
     * side, and neither should have to know it was merged.
     *
     * @return list<FormComponent>
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields() as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The Laravel rule set for the whole form.
     *
     * A confirmed password also needs its confirmation field to exist in the
     * rules, otherwise `confirmed` has nothing to compare against.
     *
     * @return array<string, list<mixed>>
     */
    /**
     * The wizard this form is, if it is one.
     *
     * A wizard owns the whole form or none of it: a form that is partly
     * stepped would have no answer to "which step is this field in".
     */
    public function wizard(): ?Wizard
    {
        foreach ($this->components as $component) {
            if ($component instanceof Wizard) {
                return $component;
            }
        }

        return null;
    }

    /**
     * The rules for one step, taken from the whole form's rules.
     *
     * Derived rather than declared: the step already knows which fields it
     * holds, so validating one is a subset of validating all. A second
     * definition of a step's contents could disagree with the first, and the
     * disagreement would only show up as a form that cannot be submitted.
     *
     * @return array<string, list<mixed>>
     */
    public function validationRulesForStep(int $step, ?Model $record = null): array
    {
        $wizard = $this->wizard();

        if ($wizard === null) {
            return [];
        }

        $names = $wizard->fieldNamesForStep($step);

        if ($names === []) {
            return [];
        }

        $rules = $this->validationRules($record);

        return array_filter(
            $rules,
            static function (string $key) use ($names): bool {
                // A confirmation field belongs to the step its password is in.
                return in_array($key, $names, true)
                    || in_array(str_replace('_confirmation', '', $key), $names, true);
            },
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * The Laravel rule set for the whole form.
     *
     * A confirmed password also needs its confirmation field to exist in the
     * rules, otherwise `confirmed` has nothing to compare against.
     *
     * @return array<string, list<mixed>>
     */
    public function validationRules(?Model $record = null): array
    {
        $this->hydrateRelationshipFields();

        $this->assertUniqueFieldNames();

        $rules = [];

        foreach ($this->fields($record) as $field) {
            $rules[$field->getName()] = $field->validationRules($record);

            if ($field instanceof PasswordInput && $field->isConfirmed()) {
                $rules[$field->getName().'_confirmation'] = ['nullable', 'string'];
            }

            // A field whose value is a list validates its elements under
            // `field.*`, which Laravel will not infer from a rule list on
            // `field` itself. Asked of every field rather than of the types
            // that happen to need it today.
            $elementRules = $field->elementRules();

            if ($elementRules !== []) {
                $rules[$field->getName().'.*'] = $elementRules;
            }

            // A repeater's children live at `items.*.title`, a key only the
            // field that owns them can produce.
            foreach ($field->nestedRules($record) as $path => $nested) {
                $rules[$path] = $nested;
            }
        }

        return $rules;
    }

    /**
     * Resolves every relation-backed select against the schema's model.
     *
     * The schema is the only layer that knows the model class, so this is the
     * only place a select can learn what its relation points at. Idempotent,
     * because rules, serialization, and dehydration each need it and none of
     * them can assume it has already run.
     */
    private function hydrateRelationshipFields(): void
    {
        if ($this->modelClass === null) {
            return;
        }

        foreach ($this->fields() as $field) {
            if ($field instanceof Select && $field->getRelation() !== null) {
                $field->hydrateRelationship($this->modelClass);
            }
        }
    }

    /**
     * The relation groups in this schema, whose fields belong to a related
     * record rather than to the one being edited.
     *
     * @return list<Relationship>
     */
    public function relationshipGroups(): array
    {
        return self::collectGroups($this->components);
    }

    /**
     * @param  list<FormComponent>  $components
     * @return list<Relationship>
     */
    private static function collectGroups(array $components): array
    {
        $groups = [];

        foreach ($components as $component) {
            if ($component instanceof Relationship) {
                $groups[] = $component;

                // A relation group inside a relation group would mean two
                // records written from one nesting level, with no way to say
                // which owns which. Its children are fields, not another
                // group.
                continue;
            }

            $groups = [...$groups, ...self::collectGroups($component->children())];
        }

        return $groups;
    }

    /**
     * The field names that belong to a relation group rather than to the
     * record being edited.
     *
     * @return list<string>
     */
    private function relationshipFieldNames(): array
    {
        $names = [];

        foreach ($this->relationshipGroups() as $group) {
            foreach ($group->fields() as $field) {
                $names[] = $field->getName();
            }
        }

        return $names;
    }

    /**
     * Turns validated input into the attributes to persist.
     *
     * A field that declines to dehydrate, such as an untouched password, is
     * dropped here rather than written as an empty value.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function dehydrate(array $validated, ?Model $record = null): array
    {
        $this->hydrateRelationshipFields();

        $relationFields = $this->relationshipFieldNames();
        $attributes = [];

        foreach ($this->fields($record) as $field) {
            $name = $field->getName();

            // A field can be rendered and validated and still not written —
            // a confirmation box, or one whose real effect is a side effect.
            if (! $field->isDehydrated($record)) {
                continue;
            }

            // A relation group's fields belong to another record and are
            // written by the group after this one is saved.
            if (in_array($name, $relationFields, true)) {
                continue;
            }

            if (! array_key_exists($name, $validated)) {
                continue;
            }

            $value = $validated[$name];

            if (! $field->shouldDehydrate($value)) {
                continue;
            }

            // A many-to-many select has no column to write to; it is synced
            // after the record exists.
            if ($field instanceof Select && $this->modelClass !== null && $field->writesToPivot($this->modelClass)) {
                continue;
            }

            $attributes[$this->dehydrateKeyFor($field)] = $field->mutate($value, $record);
        }

        return $attributes;
    }

    /**
     * The column a field writes to.
     *
     * A `BelongsTo` select is named after the relation but persists the
     * foreign key, so the schema resolves that here rather than making every
     * form spell out `->dehydrateTo('author_id')` beside `->relationship('author')`.
     */
    private function dehydrateKeyFor(Field $field): string
    {
        if ($field instanceof Select && $this->modelClass !== null) {
            $foreignKey = $field->foreignKeyFor($this->modelClass);

            if ($foreignKey !== null) {
                return $foreignKey;
            }
        }

        return $field->getDehydrateKey();
    }

    /**
     * Writes everything that could not be written with the record itself:
     * related records and many-to-many membership.
     *
     * Called after the record is saved and inside the same transaction. It
     * has to be after — a `HasOne` child and a pivot row both need a key that
     * does not exist until the record does — and it has to be inside, or a
     * form that half-saved would leave the record without the relations it
     * was submitted with.
     *
     * @param  array<string, mixed>  $validated
     */
    public function saveRelations(Model $record, array $validated): void
    {
        $this->hydrateRelationshipFields();

        foreach ($this->relationshipGroups() as $group) {
            $group->save($record, $validated);
        }

        if ($this->modelClass === null) {
            return;
        }

        foreach ($this->fields() as $field) {
            if (! $field instanceof Select || ! $field->writesToPivot($this->modelClass)) {
                continue;
            }

            $name = $field->getName();

            if (! array_key_exists($name, $validated)) {
                continue;
            }

            $relation = $record->{(string) $field->getRelation()}();

            if ($relation instanceof BelongsToMany) {
                $relation->sync(is_array($validated[$name]) ? $validated[$name] : []);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The schema serialized against values that are not the record's.
     *
     * For rebuilding a live form: the browser sends what has been typed so
     * far, and the schema is re-serialized with those values in place. Only
     * fields the schema declares are read out of the state, so a key that was
     * never a field is discarded here exactly as it is on submit.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function toArrayWithState(?Model $record, array $state): array
    {
        $form = $this->toArray($record);

        $form['schema'] = self::applyState($form['schema'], $state);

        return $form;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private static function applyState(array $components, array $state): array
    {
        $applied = [];

        foreach ($components as $component) {
            if (($component['component'] ?? null) === 'field') {
                $name = $component['name'] ?? null;

                if (is_string($name) && array_key_exists($name, $state)) {
                    $component['value'] = $state[$name];
                }

                $applied[] = $component;

                continue;
            }

            // Layouts nest under `schema`; a wizard and a tab set nest one
            // level deeper still, under their own lists.
            foreach (['schema', 'steps', 'tabs'] as $key) {
                if (isset($component[$key]) && is_array($component[$key])) {
                    $component[$key] = self::applyState(array_values($component[$key]), $state);
                }
            }

            $applied[] = $component;
        }

        return $applied;
    }

    /**
     * Refuses a form that submits the same name twice.
     *
     * After hydration rather than in `schema()`, because a relation group
     * namespaces its children — `profile.bio` and `bio` are two names, and
     * checking before that ran would call them one.
     *
     * Two fields with one name is not a cosmetic problem: only one rule
     * survives into the validator and only one value survives into the write,
     * so the other field is rendered, filled in, submitted, and discarded
     * without a word.
     */
    private function assertUniqueFieldNames(): void
    {
        $names = [];

        foreach ($this->components as $component) {
            foreach ($component->fields() as $field) {
                $names[] = $field->getName();
            }
        }

        $duplicates = array_values(array_unique(array_diff_assoc($names, array_unique($names))));

        if ($duplicates !== []) {
            throw PanelSchemaException::duplicateFields($duplicates);
        }
    }

    /**
     * @return array{columns: int, schema: list<array<string, mixed>>}
     */
    public function toArray(?Model $record = null): array
    {
        $this->hydrateRelationshipFields();

        $this->assertUniqueFieldNames();
        $this->fillManyToManySelects($record);

        return [
            'columns' => $this->columns,
            'schema' => $this->serializeComponents($record),
        ];
    }

    /**
     * A many-to-many select's value is the set of related keys, which lives
     * in a pivot table rather than in an attribute the field could read.
     */
    private function fillManyToManySelects(?Model $record): void
    {
        if ($record === null || $this->modelClass === null) {
            return;
        }

        foreach ($this->fields() as $field) {
            if ($field instanceof Select && $field->writesToPivot($this->modelClass)) {
                $keys = $field->relatedKeys($record);

                $field->formatUsing(static fn (): array => $keys);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeComponents(?Model $record): array
    {
        $serialized = [];

        foreach ($this->components as $component) {
            $child = $component->toArray($record, $this->page);

            if ($child !== null) {
                $serialized[] = $child;
            }
        }

        return $serialized;
    }
}
