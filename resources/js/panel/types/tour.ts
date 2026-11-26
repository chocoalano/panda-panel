/**
 * A guided tour of one screen, as `PandaPanel\Tours\Tour` serializes it.
 */
export type TourSide = 'top' | 'right' | 'bottom' | 'left';

export interface TourStepDefinition {
    title: string;
    description: string | null;
    /** A CSS selector, or null for a step shown in the middle of the screen. */
    target: string | null;
    side: TourSide;
}

export interface TourDefinition {
    /** What "already seen" is remembered under. */
    key: string;
    steps: TourStepDefinition[];
}
