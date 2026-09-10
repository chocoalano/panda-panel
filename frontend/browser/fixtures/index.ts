import type { Component } from 'vue';

/**
 * The fixtures a browser check can ask for.
 *
 * One build, many pages: `index.html?fixture=rich-editor` mounts the rich
 * editor, `?fixture=touch-targets` mounts the controls U18 is about. Loaded
 * lazily so a check that wants one component does not pay to compile the
 * others, and so an unrelated fixture failing to import cannot take down a
 * page that never referenced it.
 *
 * Every one of these mounts a *real* published component against the *real*
 * stylesheet. Nothing here restyles or reimplements anything — a fixture that
 * dressed a component up would be measuring the fixture.
 */
export const FIXTURES: Record<string, () => Promise<{ default: Component }>> = {
    /** The original: a wide table pinned at both edges. */
    'frozen-columns': () => import('./FrozenColumnsFixture.vue'),

    /** U08 calibration — a cluster rail beside content, at two widths. */
    cluster: () => import('./ClusterFixture.vue'),

    /** F01 calibration — a repeater whose entries can be removed. */
    repeater: () => import('./RepeaterFixture.vue'),

    /** U17 baseline — the rich editor, its toolbar and its contenteditable. */
    'rich-editor': () => import('./RichEditorFixture.vue'),

    /** U18 baseline — the small controls the audit named. */
    'touch-targets': () => import('./TouchTargetsFixture.vue'),

    /** B02/B03 observation — prose output and an animated surface. */
    observation: () => import('./ObservationFixture.vue'),

    /** R1 acceptance — stacked columns against the axis they are drawn on. */
    'stacked-chart': () => import('./StackedChartFixture.vue'),

    /** DT01-DT05 acceptance — every control a date or a time is picked with. */
    temporal: () => import('./TemporalFixture.vue'),
};
