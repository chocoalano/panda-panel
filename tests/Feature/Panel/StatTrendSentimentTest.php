<?php

declare(strict_types=1);

use PandaPanel\Widgets\Enums\StatSentiment;
use PandaPanel\Widgets\Support\Stat;

/*
|--------------------------------------------------------------------------
| What a movement means, as opposed to which way it went
|--------------------------------------------------------------------------
|
| The stats widget coloured a trend from its direction: up was green, down was
| red. For revenue and signups that reads as intended and the assumption is
| invisible. For cost, churn, error rate, downtime and complaints it is exactly
| backwards — a rising error rate was reported as good news, in green, and the
| worse it got the greener it looked.
|
| Direction is arithmetic and the widget can work it out. Meaning is not, and
| the wire carried no way to say it. These cover the smallest addition that
| does: an optional statement of which way is better, resolved to a sentiment
| the frontend can colour.
|
| The old declaration still compiles and still renders. What changed is what it
| *means*: a trend nobody described is now neutral rather than green, which is
| a documented behaviour change — see `docs/widgets/stats.md`.
*/

/*
 * T1 / T2 / T3 / T4 — direction and meaning are independent
 */

it('calls a rise in a metric that should rise good news', function (): void {
    $trend = Stat::make('Revenue', 12_045)
        ->trend('up', 12.4)
        ->higherIsBetter()
        ->toArray()['trend'];

    expect($trend['direction'])->toBe('up')
        ->and($trend['sentiment'])->toBe('positive');
});

it('calls a rise in a metric that should fall bad news', function (): void {
    // The case the old behaviour got backwards, and the reason this exists.
    $trend = Stat::make('Infrastructure cost', 8_400)
        ->trend('up', 12.4)
        ->lowerIsBetter()
        ->toArray()['trend'];

    expect($trend['direction'])->toBe('up')
        ->and($trend['sentiment'])->toBe('negative');
});

it('calls a fall in a metric that should fall good news', function (): void {
    $trend = Stat::make('Error rate', 0.4)
        ->trend('down', 30.0)
        ->lowerIsBetter()
        ->toArray()['trend'];

    expect($trend['direction'])->toBe('down')
        ->and($trend['sentiment'])->toBe('positive');
});

it('calls a fall in a metric that should rise bad news', function (): void {
    $trend = Stat::make('Revenue', 9_100)
        ->trend('down', 8.0)
        ->higherIsBetter()
        ->toArray()['trend'];

    expect($trend['direction'])->toBe('down')
        ->and($trend['sentiment'])->toBe('negative');
});

/*
 * T5 / T6 — nothing moved, and nobody said
 */

it('has no opinion about a figure that did not move', function (): void {
    $trend = Stat::make('Open tickets', 12)
        ->trend('neutral', 0.0)
        ->lowerIsBetter()
        ->toArray()['trend'];

    // "Lower is better" says nothing about a number that stayed put.
    expect($trend['sentiment'])->toBe('neutral');
});

it('has no opinion about a figure nobody described', function (): void {
    $trend = Stat::make('Sessions', 1_204)
        ->trend('up', 5.0)
        ->toArray()['trend'];

    // The default, and the behaviour change: a figure whose meaning nobody
    // stated is a figure whose meaning is not known. Colouring it green
    // because the number rose is a claim the widget is not entitled to make.
    expect($trend['sentiment'])->toBe('neutral');
});

/*
 * W1–W6 — the wire
 */

it('says nothing about a stat that declares no trend', function (): void {
    expect(Stat::make('Users', 42)->toArray()['trend'])->toBeNull();
});

it('serializes each meaning by name', function (): void {
    foreach (StatSentiment::cases() as $sentiment) {
        $trend = Stat::make('Thing', 1)
            ->trend('up', 1.0)
            ->sentiment($sentiment)
            ->toArray()['trend'];

        expect($trend['sentiment'])->toBe($sentiment->value);
    }
});

it('keeps the direction and value the old payload carried', function (): void {
    $trend = Stat::make('Revenue', 12_045)->trend('up', 12.4)->toArray()['trend'];

    // Additive: an older frontend reading `direction` and `value` finds them
    // exactly where they were, and ignores a key it does not know.
    expect($trend)->toHaveKey('direction', 'up')
        ->and($trend)->toHaveKey('value', 12.4);
});

it('cannot be given a meaning that is not one of the three', function (): void {
    // An enum rather than a string, which is the package's own convention for
    // a closed set the frontend maps to classes — and is what `trend()`'s own
    // free-form `$direction` argument is still missing.
    expect(fn () => StatSentiment::from('excellent'))
        ->toThrow(ValueError::class);
});

/*
 * T10 — the declaration that predates all of this
 */

it('renders a stat written before any of this existed', function (): void {
    $stat = Stat::make('Revenue', 12_045)
        ->description('This month')
        ->trend('up', 12.4)
        ->chart([1, 2, 3]);

    $array = $stat->toArray();

    expect($array['label'])->toBe('Revenue')
        ->and($array['trend']['direction'])->toBe('up')
        ->and($array['trend']['value'])->toBe(12.4)
        ->and($array['chart'])->toBe([1, 2, 3]);
});

it('has no opinion about a direction it does not recognise', function (): void {
    // `trend()` takes a plain string, so this is reachable. Guessing would
    // put a colour on a figure whose direction is not even understood.
    $trend = Stat::make('Thing', 1)
        ->trend('sideways', 5.0)
        ->higherIsBetter()
        ->toArray()['trend'];

    expect($trend['sentiment'])->toBe('neutral');
});
