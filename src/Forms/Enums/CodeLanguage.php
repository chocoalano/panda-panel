<?php

declare(strict_types=1);

namespace PandaPanel\Forms\Enums;

/**
 * The grammar a code editor highlights with.
 *
 * Closed, because each case maps to a highlighter the build compiled in. A
 * free string would be a request for a grammar that is not in the bundle,
 * which fails silently as unhighlighted text.
 */
enum CodeLanguage: string
{
    case Plain = 'plain';
    case Json = 'json';
    case Html = 'html';
    case Css = 'css';
    case JavaScript = 'javascript';
    case Php = 'php';
    case Sql = 'sql';
    case Yaml = 'yaml';
    case Markdown = 'markdown';
}
