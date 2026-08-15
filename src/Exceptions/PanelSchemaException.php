<?php

declare(strict_types=1);

namespace PandaPanel\Exceptions;

use InvalidArgumentException;

/**
 * A schema that cannot mean what it says.
 *
 * Separate from `PanelRegistrationException` because these are mistakes in a
 * table, form, or action definition rather than in how a panel was assembled
 * — and because they were all, until now, mistakes that produced no error at
 * all. Two columns with the same name serialized as two columns and then
 * fought over one key in the row map; two form fields with the same name
 * collapsed into one validation rule and quietly left the other unvalidated;
 * two actions with the same name gave the action endpoint a choice it
 * resolved by taking the first.
 *
 * Every message here names the schema, the name that is wrong, and what to do
 * about it. A message that says only "duplicate column" leaves somebody
 * reading a resource with forty columns.
 */
final class PanelSchemaException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $names
     */
    public static function duplicateColumns(array $names): self
    {
        return new self(sprintf(
            'A table declares more than one column named %s. A column name is the key its '
                .'cell, its visibility, its search term and its sort are all stored under, so two '
                .'of them are one column the table cannot tell apart. Rename one, or point the '
                .'second at the same attribute with a different name.',
            self::list($names),
        ));
    }

    /**
     * @param  list<string>  $names
     */
    public static function duplicateFields(array $names): self
    {
        return new self(sprintf(
            'A form declares more than one field named %s. Only one of them can validate and '
                .'only one can persist, so the other is submitted and silently discarded. Rename '
                .'one, or use dehydrateTo() if two inputs really do write the same column.',
            self::list($names),
        ));
    }

    /**
     * @param  list<string>  $names
     */
    public static function duplicateActions(string $set, array $names): self
    {
        return new self(sprintf(
            'The %s declare more than one action named %s. The action endpoint resolves an '
                .'action by its name, so it would always run the first and never the second — '
                .'including when the second is the one the button was drawn for.',
            $set,
            self::list($names),
        ));
    }

    public static function emptyName(string $what): self
    {
        return new self(sprintf(
            '%s %s was declared with an empty name. The name is how the server matches it to a '
                .'value, a rule and a request, so it cannot be blank.',
            // "A action" reads as a typo in the thing that is supposed to be
            // teaching somebody what they got wrong.
            str_contains('aeiou', mb_substr($what, 0, 1)) ? 'An' : 'A',
            $what,
        ));
    }

    public static function unusableActionName(string $name): self
    {
        return new self(sprintf(
            'The action name [%s] cannot be used. It travels to the action endpoint as an '
                .'identifier, so it may contain letters, numbers, dashes, dots and underscores '
                .'and nothing else — try [%s].',
            $name,
            preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) ?? 'an-action',
        ));
    }

    /**
     * @param  list<string>  $available
     */
    public static function unknownDefaultSort(string $column, array $available): self
    {
        return new self(sprintf(
            'defaultSort() names [%s], which is not a column of this table%s. The table would '
                .'fall back to its natural order and nothing would say why.',
            $column,
            $available === []
                ? ' — it declares no columns at all'
                : '. It has: '.implode(', ', $available),
        ));
    }

    /**
     * @param  list<string>  $names
     */
    public static function duplicateFilters(array $names): self
    {
        return new self(sprintf(
            'A table declares more than one filter named %s. Filter state travels in the query '
                .'string keyed by the filter name, so the second one writes over the first and '
                .'only one of them can ever hold a value.',
            self::list($names),
        ));
    }

    /**
     * @param  list<string>  $names
     */
    public static function duplicateExportColumns(array $names): self
    {
        return new self(sprintf(
            'An exporter declares more than one column named %s. The file would carry two '
                .'identical headings, and the column picker keys its selection by name — so '
                .'choosing one chooses both and unchecking it removes neither.',
            self::list($names),
        ));
    }

    public static function unusableColumnSpan(string $context, string $value): self
    {
        return new self(sprintf(
            '%s declares a column span of [%s], which is neither a number nor "full". It would '
                .'otherwise be read as 1 — a quarter of the width that was asked for, with '
                .'nothing to say why.',
            $context,
            $value,
        ));
    }

    /**
     * @param  list<string>  $unknown
     * @param  list<string>  $known
     */
    public static function unknownBreakpoints(string $context, array $unknown, array $known): self
    {
        return new self(sprintf(
            '%s declares a column span at %s, which %s not a breakpoint this grid has. It has: '
                .'%s. A key that is not one of those is a line of configuration that does '
                .'nothing.',
            $context,
            self::list($unknown),
            count($unknown) === 1 ? 'is' : 'are',
            implode(', ', $known),
        ));
    }

    public static function inertAction(string $name): self
    {
        return new self(sprintf(
            'The action [%s] does nothing. Give it ->url() to make it a link, ->action() to '
                .'make it a callback, ->form() to make it open a form, or ->modal() to make it '
                .'open a modal. As it stands it renders a button that responds to being pressed '
                .'by doing nothing at all.',
            $name,
        ));
    }

    public static function missingModel(string $resource): self
    {
        return new self(sprintf(
            '[%s] does not declare a model. Add one to the resource:'
                ."\n\n    protected static string \$model = YourModel::class;\n\n"
                .'Everything the resource does — its query, its pages, its policy checks — '
                .'starts from it.',
            $resource,
        ));
    }

    /**
     * @param  list<string>  $names
     */
    private static function list(array $names): string
    {
        return '['.implode('], [', $names).']';
    }
}
