/**
 * A small, deliberately limited Markdown renderer for the editor's preview.
 *
 * Not a general Markdown implementation and not trying to be. It exists so
 * the preview button shows something true about what was typed, without
 * pulling in a parser this application has not agreed to depend on.
 *
 * The order matters more than the rules do: every character is HTML-escaped
 * **first**, and the tags below are the only ones that can exist afterwards.
 * That is what makes it safe to put the result in `v-html` — nothing the
 * author typed can become markup, because by the time markup is added there
 * is no author input left that could be mistaken for it.
 *
 * Markdown is stored as Markdown, exactly as typed. This is a preview, not a
 * conversion step: nothing here is ever persisted.
 */

function escape(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Only `http`, `https`, and `mailto` links survive. A `javascript:` URL in a
 * preview would be the one way typed text could still become behaviour.
 */
function safeUrl(url: string): string | null {
    const trimmed = url.trim();

    return /^(https?:\/\/|mailto:|\/)/i.test(trimmed) ? trimmed : null;
}

function inline(text: string): string {
    return (
        escape(text)
            // Links first: their label may itself contain emphasis.
            .replace(
                /\[([^\]]+)\]\(([^)\s]+)\)/g,
                (match, label: string, url: string) => {
                    const href = safeUrl(url);

                    return href === null
                        ? match
                        : `<a href="${href}" rel="noopener noreferrer" target="_blank">${label}</a>`;
                },
            )
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
            .replace(/~~([^~]+)~~/g, '<del>$1</del>')
    );
}

/**
 * Renders a subset: headings, blockquotes, fenced code, lists, paragraphs,
 * and the inline marks above. Anything else is shown as the text it is.
 */
export function renderMarkdown(source: string): string {
    const lines = source.replace(/\r\n/g, '\n').split('\n');
    const html: string[] = [];

    let list: 'ul' | 'ol' | null = null;
    let fenced = false;
    let code: string[] = [];

    const closeList = (): void => {
        if (list !== null) {
            html.push(`</${list}>`);
            list = null;
        }
    };

    for (const line of lines) {
        if (line.trimStart().startsWith('```')) {
            if (fenced) {
                html.push(`<pre><code>${escape(code.join('\n'))}</code></pre>`);
                code = [];
            } else {
                closeList();
            }

            fenced = !fenced;

            continue;
        }

        if (fenced) {
            code.push(line);

            continue;
        }

        if (line.trim() === '') {
            closeList();

            continue;
        }

        const heading = /^(#{1,6})\s+(.*)$/.exec(line);

        if (heading !== null) {
            closeList();

            const level = heading[1].length;

            html.push(`<h${level}>${inline(heading[2])}</h${level}>`);

            continue;
        }

        const quote = /^>\s?(.*)$/.exec(line);

        if (quote !== null) {
            closeList();
            html.push(`<blockquote>${inline(quote[1])}</blockquote>`);

            continue;
        }

        const bullet = /^\s*[-*]\s+(.*)$/.exec(line);

        if (bullet !== null) {
            if (list !== 'ul') {
                closeList();
                html.push('<ul>');
                list = 'ul';
            }

            html.push(`<li>${inline(bullet[1])}</li>`);

            continue;
        }

        const ordered = /^\s*\d+\.\s+(.*)$/.exec(line);

        if (ordered !== null) {
            if (list !== 'ol') {
                closeList();
                html.push('<ol>');
                list = 'ol';
            }

            html.push(`<li>${inline(ordered[1])}</li>`);

            continue;
        }

        closeList();
        html.push(`<p>${inline(line)}</p>`);
    }

    // An unclosed fence is text the author is still typing, not a failure.
    if (fenced && code.length > 0) {
        html.push(`<pre><code>${escape(code.join('\n'))}</code></pre>`);
    }

    closeList();

    return html.join('');
}
