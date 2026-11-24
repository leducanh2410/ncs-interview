// Shared geometry constants for the planning grid and its loading skeleton.
//
// Kept in a standalone, dependency-free module on purpose: the skeleton renders
// before (and without) the glide-data-grid canvas chunk, but must match the real
// DataEditor dimensions exactly so the loading → loaded transition is seamless.
export const EMP_COL_WIDTH = 220;
export const DATE_COL_WIDTH = 144;
// Fits one two-line chip; any overflow shows as a "+N" badge to its right.
export const ROW_HEIGHT = 40;
// One two-line chip block (hours over position). Shared by the canvas draw and the
// skeleton placeholder so the loading → loaded swap doesn't change the chip size.
export const CHIP_HEIGHT = 30;
export const GRID_HEADER_HEIGHT = 36;
export const GRID_GROUP_HEADER_HEIGHT = 30;
export const GRID_HEIGHT = 600;

// Header shift-summary rows (shown when the admin selects shifts to display under each
// day). The top band keeps the date label + the finalize-toggle click target; each
// selected shift adds one two-line block below it. Shared by the canvas draw and the
// click hit-test so the two can't drift.
export const HEADER_DATE_ZONE = 24;
export const SHIFT_ROW_H = 30;

// Header height grows with the number of selected shift summaries. Zero selected keeps
// the original single-line header so the default view (and skeleton) is unchanged.
export const headerHeightFor = (selectedShiftCount: number): number =>
    selectedShiftCount === 0 ? GRID_HEADER_HEIGHT : HEADER_DATE_ZONE + selectedShiftCount * SHIFT_ROW_H;
