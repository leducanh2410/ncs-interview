import React from 'react';
import { Skeleton } from '@mantine/core';
import {
    CHIP_HEIGHT,
    DATE_COL_WIDTH,
    EMP_COL_WIDTH,
    GRID_GROUP_HEADER_HEIGHT,
    GRID_HEADER_HEIGHT,
    GRID_HEIGHT,
    ROW_HEIGHT,
} from '@/pages/manage/shift-planning/partials/grid_layout';

// Render more date cells than a single viewport holds, then clip the overflow.
// This makes the skeleton read like the real horizontally-scrollable grid at any
// width, instead of leaving an empty gap on wide screens.
const DATE_CELL_COUNT = 14;

// Enough data rows to cover the grid body below the two header rows. Derived from
// the shared geometry so it keeps filling GRID_HEIGHT if ROW_HEIGHT changes; the
// container clips any overflow.
const FILL_ROWS = Math.ceil((GRID_HEIGHT - GRID_GROUP_HEADER_HEIGHT - GRID_HEADER_HEIGHT) / ROW_HEIGHT);

const BORDER = '1px solid var(--mantine-color-gray-2)';
const cellBase: React.CSSProperties = { width: DATE_COL_WIDTH, flex: '0 0 auto', boxSizing: 'border-box' };

/**
 * Static loading placeholder that mirrors the {@link PlanGrid} geometry: a frozen
 * employee column, a group-header row, a column-header row, and chip-sized cells.
 * Shown during the initial data fetch and while the canvas chunk is still loading.
 */
const PlanGridSkeleton: React.FC<{ rows?: number }> = ({ rows = FILL_ROWS }) => {
    const headerCells = Array.from({ length: DATE_CELL_COUNT }, (_, i) => (
        <div
            key={i}
            style={{
                ...cellBase,
                height: GRID_HEADER_HEIGHT,
                borderRight: BORDER,
                display: 'flex',
                alignItems: 'center',
                padding: '0 12px',
            }}
        >
            <Skeleton height={9} width={72} radius="xl" />
        </div>
    ));

    return (
        <div style={{ height: GRID_HEIGHT, overflow: 'hidden', background: 'var(--mantine-color-body)' }}>
            {/* Group-header row (month bands) */}
            <div style={{ display: 'flex', height: GRID_GROUP_HEADER_HEIGHT, borderBottom: BORDER }}>
                <div style={{ width: EMP_COL_WIDTH, flex: '0 0 auto', borderRight: BORDER }} />
                <div style={{ display: 'flex' }}>
                    {Array.from({ length: DATE_CELL_COUNT }, (_, i) => (
                        <div key={i} style={{ ...cellBase, height: GRID_GROUP_HEADER_HEIGHT, borderRight: BORDER }} />
                    ))}
                </div>
            </div>

            {/* Column-header row */}
            <div
                style={{
                    display: 'flex',
                    height: GRID_HEADER_HEIGHT,
                    borderBottom: BORDER,
                    background: 'var(--mantine-color-gray-0)',
                }}
            >
                <div
                    style={{
                        width: EMP_COL_WIDTH,
                        flex: '0 0 auto',
                        borderRight: BORDER,
                        display: 'flex',
                        alignItems: 'center',
                        padding: '0 12px',
                    }}
                >
                    <Skeleton height={10} width={96} radius="xl" />
                </div>
                <div style={{ display: 'flex' }}>{headerCells}</div>
            </div>

            {/* Data rows: name + staff meta in the frozen column, a chip block per day */}
            {Array.from({ length: rows }, (_, r) => (
                <div key={r} style={{ display: 'flex', height: ROW_HEIGHT, borderBottom: BORDER }}>
                    <div
                        style={{
                            width: EMP_COL_WIDTH,
                            flex: '0 0 auto',
                            borderRight: BORDER,
                            display: 'flex',
                            flexDirection: 'column',
                            justifyContent: 'center',
                            gap: 6,
                            padding: '0 12px',
                        }}
                    >
                        <Skeleton height={10} width="80%" radius="xl" />
                        <Skeleton height={8} width="55%" radius="xl" />
                    </div>
                    <div style={{ display: 'flex' }}>
                        {Array.from({ length: DATE_CELL_COUNT }, (_, c) => (
                            <div
                                key={c}
                                style={{
                                    ...cellBase,
                                    height: ROW_HEIGHT,
                                    borderRight: BORDER,
                                    display: 'flex',
                                    alignItems: 'center',
                                    padding: '5px 6px',
                                }}
                            >
                                <Skeleton height={CHIP_HEIGHT} width="100%" radius={4} />
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
};

export default PlanGridSkeleton;
