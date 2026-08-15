/**
 * Every file the package ships, pulled into one build.
 *
 * This is the package's compile check and nothing else — no application ever
 * imports it. The globs are eager so that Rollup has to resolve, parse and
 * compile each module rather than emitting a lazy chunk that would only fail
 * at runtime, in a browser this build never reaches.
 *
 * Written as globs rather than as a list of imports for the obvious reason: a
 * hand-written list would go stale the first time somebody added a component,
 * and a build that silently stopped covering new files is worse than no build.
 *
 * `import.meta.glob` is Vite's, which is also how the panel's own registries
 * resolve components in a real application — so the one thing this build
 * proves about resolution is the thing that matters there too.
 */
const modules = {
    ...import.meta.glob('../resources/js/**/*.vue', { eager: true }),
    ...import.meta.glob('../resources/js/**/*.ts', { eager: true }),
};

// Referenced so nothing here is tree-shaken away before it has been compiled.
export default Object.keys(modules).sort();

// The stylesheet compiles too: it is Tailwind 4, where the theme, the custom
// variants and the `@source` scan all live in CSS rather than in a config
// file, and a broken one is as much a build failure as a broken component.
import '../resources/css/panda-panel.css';
