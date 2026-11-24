import EllipseIcon from "@/components/icons/ellipse-icon";
import PolygonIcon from "@/components/icons/polygon-icon";
import TriangleIcon from "@/components/icons/triangle-icon";
import ArrowDownIcon from "@/components/icons/arrow-down-icon";
import ArrowLeftIcon from "@/components/icons/arrow-left-icon";
import ArrowRightIcon from "@/components/icons/arrow-right-icon";
import ArrowUpIcon from "@/components/icons/arrow-up-icon";

interface Props {
  currentX: number;   // Boss support score (X axis: 4..10)
  currentY: number;   // Colleague support score (Y axis: 5..11)
  previousX?: number;
  previousY?: number;
  nationalX: number;
  nationalY: number;
}

export const SupportHeatmapChart = ({
  currentX,
  currentY,
  previousX,
  previousY,
  nationalX,
  nationalY,
}: Props) => {
  // Formula: 100*EXP((Boss-7.5)*-0.097 + (Colleague-8.1)*-0.097)
  // SVG: u = Boss-4 (0..6), v = 11-Colleague (0..6, top=Colleague=11)
  // Derived: v = u - 0.6 + 10.309*ln(R/100)
  // Slope in SVG: +1 (positive), meaning the line goes from upper-LEFT
  // to lower-RIGHT in SVG. In screen coords (Y down) this means the line
  // descends from upper-left to lower-right. High risk (R>100) is in the
  // upper-left (low Boss + low Colleague), low risk is lower-right.
  const getV = (u: number, R: number) => {
    return u - 0.6 + 10.309 * Math.log(R / 100);
  };

  const getPosition = (bossX: number, colleagueY: number) => {
    // Clamp values to chart range so dots always stay inside
    const clampedX = Math.min(Math.max(bossX, 4), 10);
    const clampedY = Math.min(Math.max(colleagueY, 5), 11);
    const chartL = 285 * 0.1509;       // ~43px
    const chartW = 285 * (1 - 0.1509 - 0.0211); // ~236px
    const chartT = 279 * 0.0323;       // ~9px
    const chartH = 279 * (1 - 0.0323 - 0.1429); // ~231px
    const xPos = chartL + ((clampedX - 4) / 6) * chartW;
    const yPos = chartT + chartH - ((clampedY - 5) / 6) * chartH;
    return { x: xPos, y: yPos };
  };

  const riskLines = [70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180];

  const currentPos = getPosition(currentX, currentY);
  const previousPos = previousX != null && previousY != null ? getPosition(previousX, previousY) : null;
  const nationalPos = getPosition(nationalX, nationalY);

  // Support chart color logic — matches Figma exactly:
  // NO RED zone. Darkest = dark orange (#F26522) at R>=160.
  // High risk = BOTTOM-LEFT (low boss + low colleague support)
  const getBandColor = (r: number): string => {
    if (r > 160) return '#F26522'; // includes 160-170, 170-180, and beyond-180 zones
    if (r > 140) return '#F69E3D'; // orange
    if (r > 120) return '#FBF655'; // yellow
    if (r > 100) return '#FEFAD4'; // light yellow
    return '#FFFFFF';               // white (R<100, upper-right low risk)
  };

  return (
    <div className="relative w-[285px] h-[279px]">
      <div className="absolute" style={{ inset: '3.23% 2.11% 14.29% 15.09%' }}>
        <svg viewBox="0 0 6 6" preserveAspectRatio="none" className="w-full h-full block">
          {/* Base fill: white (lowest risk) */}
          <rect x="-1" y="-1" width="8" height="8" fill="#FFFFFF" />

          {/* Band beyond R=180 (tiny corner at bottom-left, X=4/Y=5) — red */}
          <polygon
            points={`-1,${getV(-1, 180)} 7,${getV(7, 180)} 7,8 -1,8`}
            fill="#FF0000"
          />

          {/* Bands between consecutive risk lines (parallelograms) */}
          {riskLines.slice(0, -1).map((r, i) => {
            const nextR = riskLines[i + 1];
            return (
              <polygon
                key={`band-${r}`}
                points={`-1,${getV(-1, r)} 7,${getV(7, r)} 7,${getV(7, nextR)} -1,${getV(-1, nextR)}`}
                fill={getBandColor(nextR)}
              />
            );
          })}

          {/* Grid Lines (every 0.5 units) */}
          {Array.from({ length: 13 }).map((_, i) => {
            const pos = i * 0.5;
            return (
              <g key={`grid-${i}`}>
                <line x1="0" y1={pos} x2="6" y2={pos} stroke="rgba(0,0,0,0.65)" strokeWidth="0.03" />
                <line x1={pos} y1="0" x2={pos} y2="6" stroke="rgba(0,0,0,0.65)" strokeWidth="0.03" />
              </g>
            );
          })}

          {/* Diagonal Risk Lines */}
          {riskLines.map((r) => (
            <line
              key={`dline-${r}`}
              x1="0" y1={getV(0, r)}
              x2="6" y2={getV(6, r)}
              stroke="rgba(60,60,60,0.6)"
              strokeWidth="0.025"
            />
          ))}

        </svg>
      </div>


      {/* Y-axis labels */}
      <div className="absolute flex flex-col items-end justify-between text-right font-medium text-[10px] leading-normal" style={{ inset: '0 87.02% 12.9% 8.77%' }}>
        <p className="relative shrink-0 w-full">11</p>
        <p className="relative shrink-0 w-full">10</p>
        <p className="relative shrink-0 w-full">9</p>
        <p className="relative shrink-0 w-full">8</p>
        <p className="relative shrink-0 w-full">7</p>
        <p className="relative shrink-0 w-full">6</p>
        <p className="relative shrink-0 w-full">5</p>
      </div>

      {/* X-axis labels */}
      <div className="absolute flex items-center justify-between text-center font-medium text-[10px] leading-normal" style={{ inset: '86.74% 0 8.96% 12.28%' }}>
        <p className="relative shrink-0 w-3.5">4</p>
        <p className="relative shrink-0 w-3.5">5</p>
        <p className="relative shrink-0 w-3.5">6</p>
        <p className="relative shrink-0 w-3.5">7</p>
        <p className="relative shrink-0 w-3.5">8</p>
        <p className="relative shrink-0 w-3.5">9</p>
        <p className="relative shrink-0 w-3.5">10</p>
      </div>

      {/* Y-axis title and arrows */}
      <div className="absolute flex flex-col h-[243px] items-end justify-between left-0 top-0">
        <div className="flex flex-col gap-[2px] items-center relative shrink-0">
          <ArrowUpIcon className="relative shrink-0 size-[12px]" />
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">多</p>
        </div>
        <div className="flex h-[140px] items-center justify-center relative shrink-0 w-[17px]">
          <div className="-rotate-90 flex-none">
            <p className="font-medium leading-normal text-[14px] text-center whitespace-nowrap">同僚からのサポート</p>
          </div>
        </div>
        <div className="flex flex-col gap-[2px] items-center relative shrink-0">
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">少</p>
          <ArrowDownIcon className="relative shrink-0 size-[12px]" />
        </div>
      </div>

      {/* X-axis title and arrows */}
      <div className="absolute flex items-center justify-between left-[42px] top-[264px] w-[237px]">
        <div className="flex gap-[2px] items-center justify-center relative shrink-0">
          <ArrowLeftIcon className="relative shrink-0 size-[12px]" />
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">少</p>
        </div>
        <p className="font-medium leading-normal text-[14px] text-center whitespace-nowrap">上司からのサポート</p>
        <div className="flex gap-[2px] items-center justify-center relative shrink-0">
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">多</p>
          <ArrowRightIcon className="relative shrink-0 size-[12px]" />
        </div>
      </div>

      <div className="absolute inset-0 pointer-events-none">
        <TriangleIcon className="absolute" style={{ left: currentPos.x - 10, top: currentPos.y - 9, width: 20, height: 18 }} />
        {previousPos && <EllipseIcon className="absolute" style={{ left: previousPos.x - 7, top: previousPos.y - 7, width: 14, height: 14 }} />}
        <PolygonIcon className="absolute" style={{ left: nationalPos.x - 9.5, top: nationalPos.y - 9.5, width: 19, height: 19 }} />
      </div>

      {/* Risk Labels — on top of everything, exact positions from Figma (node 77:4116) */}
      {([
        { label: '70', inset: '17.18% 1.72%  77.75% 93.31%' },
        { label: '80', inset: '28.77% 9.18%  65.90% 85.61%' },
        { label: '90', inset: '37.01% 17.25% 57.66% 77.54%' },
        { label: '100', inset: '43.41% 24.63% 50.24% 69.18%' },
        { label: '110', inset: '51.19% 31.12% 42.97% 63.19%' },
        { label: '120', inset: '57.63% 37.52% 36.28% 56.54%' },
        { label: '130', inset: '63.12% 43.58% 30.53% 50.24%' },
        { label: '140', inset: '68.19% 48.84% 25.46% 44.97%' },
        { label: '150', inset: '73.09% 53.66% 20.82% 40.40%' },
        { label: '160', inset: '77.86% 58.31% 15.79% 35.50%' },
        { label: '170', inset: '78.46% 66.64% 15.44% 27.41%' },
        { label: '180', inset: '78.46% 74.71% 15.44% 19.34%' },
      ] as const).map(({ label, inset }) => (
        <div key={label} className="absolute flex items-center justify-center pointer-events-none" style={{ inset }}>
          <div style={{ transform: 'rotate(45.7deg)' }}>
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill' }}
            >
              {label}
            </p>
          </div>
        </div>
      ))}
    </div>
  );
};


