import React from 'react';
import { CloseButton } from '@mantine/core';
import clsx from 'clsx';
import { PlanChip, positionById, shiftTimeLabel } from '@/pages/manage/shift-planning/partials/data';
import styles from '@/pages/manage/shift-planning/index.module.css';

type Props = {
    chip: PlanChip;
    selected?: boolean;
    unsaved?: boolean; // has changes not yet persisted to the draft — shows a corner dot
    onSelect?: () => void;
    onRemove?: () => void;
};

// A planned/live assignment chip: working hours · position. Colour follows the
// shift-detail page — green = fixed, blue = temp — while the fill encodes status:
// solid = real live data (finalized or staged for removal), dashed = draft or pending live
// update. A staged removal is additionally struck through.
const Chip: React.FC<Props> = ({ chip, selected, unsaved, onSelect, onRemove }) => {
    const position = positionById(chip.positionId);
    const isFinalized = chip.source === 'live' && chip.action == null;
    const isRemoving = chip.action === 'remove';
    const isSolid = isFinalized || isRemoving;
    const closeColor = isRemoving ? 'red' : isFinalized ? 'white' : chip.isFixed ? 'teal' : 'blue';

    return (
        <span
            className={clsx(
                styles.chip,
                chip.isFixed ? styles.chipFixed : styles.chipTemp,
                isSolid ? styles.chipFinalized : styles.chipDraft,
                onSelect && styles.chipClickable,
                selected && styles.chipSelected,
            )}
            onClick={onSelect}
            style={isRemoving ? { textDecoration: 'line-through', opacity: 0.7 } : undefined}
        >
            {unsaved && <span className={styles.chipUnsavedDot} aria-hidden />}
            <span className={styles.chipBody}>
                <span className={styles.chipTime}>{shiftTimeLabel(chip.shiftTypeId)}</span>
                <span className={styles.chipSep}>·</span>
                <span className={clsx(styles.chipPos, position && !position.isActive && styles.chipInactivePos)}>
                    {position?.name}
                </span>
            </span>
            {onRemove && (
                <CloseButton
                    size={14}
                    variant="transparent"
                    c={closeColor}
                    onClick={(e) => {
                        e.stopPropagation();
                        onRemove();
                    }}
                />
            )}
        </span>
    );
};

export default Chip;
