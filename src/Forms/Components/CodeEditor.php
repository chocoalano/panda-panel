<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Components;

use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Forms\Enums\FieldType;

/**
 * Source text, shown in a monospaced editor.
 *
 * The language is an enum rather than a free string because the frontend maps
 * each case to a highlighter that was compiled in — a name resolved from data
 * would be a request for a grammar that is not in the bundle.
 */
final class CodeEditor extends Field
{
    private CodeLanguage $language = CodeLanguage::Plain;

    private int $rows = 12;

    private ?int $maxLength = null;

    public function type(): FieldType
    {
        return FieldType::CodeEditor;
    }

    public function language(CodeLanguage $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function rows(int $rows): self
    {
        $this->rows = max(1, $rows);

        return $this;
    }

    public function maxLength(int $length): self
    {
        $this->maxLength = max(1, $length);

        return $this;
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        $rules = ['string'];

        if ($this->language === CodeLanguage::Json) {
            $rules[] = 'json';
        }

        if ($this->maxLength !== null) {
            $rules[] = 'max:'.$this->maxLength;
        }

        return $rules;
    }

    protected function castForForm(mixed $value): ?string
    {
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? null : $encoded;
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraArray(): array
    {
        return [
            'language' => $this->language->value,
            'rows' => $this->rows,
            'maxLength' => $this->maxLength,
        ];
    }
}
