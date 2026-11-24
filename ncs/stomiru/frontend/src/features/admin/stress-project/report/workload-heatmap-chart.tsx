import EllipseIcon from "@/components/icons/ellipse-icon";
import PolygonIcon from "@/components/icons/polygon-icon";
import TriangleIcon from "@/components/icons/triangle-icon";
import ArrowDownIcon from "@/components/icons/arrow-down-icon";
import ArrowLeftIcon from "@/components/icons/arrow-left-icon";
import ArrowRightIcon from "@/components/icons/arrow-right-icon";
import ArrowUpIcon from "@/components/icons/arrow-up-icon";

interface Props {
  currentX: number;
  currentY: number;
  previousX?: number;
  previousY?: number;
  nationalX: number;
  nationalY: number;
}

export const WorkloadHeatmapChart = ({
  currentX,
  currentY,
  previousX,
  previousY,
  nationalX,
  nationalY,
}: Props) => {
  // Formula: 100*EXP((X-8.7)*0.076 + (Y-7.9)*-0.089)
  // SVG: u = X-5 (range 0..7), v = 11-Y (range 0..7)
  // Derived: v = 6.26 + 11.236*ln(R/100) - 0.8539*u
  // => slope in SVG: -0.854 (neg), meaning line goes upper-left→lower-right in SVG
  //    but upper-right→lower-left in screen? No:
  //    As u increases (going right), v decreases (going up in screen)
  //    So line goes from (left, low) → (right, up) = lower-left to upper-right ✓
  const getV = (u: number, R: number) => {
    return 6.26 + 11.236 * Math.log(R / 100) - 0.8539 * u;
  };

  // Place the dot for a given data point
  // xVal = psychological workload (X axis, range 5..12)
  // yVal = control degree (Y axis, range 4..11)
  const getPosition = (xVal: number, yVal: number) => {
    // Clamp values to chart range so dots always stay inside
    const clampedX = Math.min(Math.max(xVal, 5), 12);
    const clampedY = Math.min(Math.max(yVal, 4), 11);
    const chartL = 285 * 0.1509;       // ~43px
    const chartW = 285 * (1 - 0.1509 - 0.0211); // ~236px
    const chartT = 279 * 0.0323;       // ~9px
    const chartH = 279 * (1 - 0.0323 - 0.1429); // ~231px
    const xPos = chartL + ((clampedX - 5) / 7) * chartW;
    const yPos = chartT + chartH - ((clampedY - 4) / 7) * chartH;
    return { x: xPos, y: yPos };
  };

  const riskLines = [70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180];

  const currentPos = getPosition(currentX, currentY);
  const previousPos = previousX != null && previousY != null ? getPosition(previousX, previousY) : null;
  const nationalPos = getPosition(nationalX, nationalY);

  // Colors matching the chart.js reference:
  // White (#FFF) above R=100 (low risk), progressing through yellow → orange → red
  // We map the 12 riskLine bands + base to these 6 color zones:
  const getBandColor = (r: number): string => {
    if (r < 100) return '#FFFFFF';   // < R100: white (low risk)
    if (r < 120) return '#FEFAD4';   // R100-120: very light yellow
    if (r < 140) return '#FBF655';   // R120-140: yellow
    if (r < 160) return '#F69E3D';   // R140-160: orange
    if (r < 180) return '#F26522';   // R160-180: dark orange
    return '#FF0000';                 // R>=180: red (highest risk)
  };

  return (
    <div className="relative w-[285px] h-[279px]">
      <div className="absolute" style={{ inset: '3.23% 2.11% 14.29% 15.09%' }}>
        <svg viewBox="0 0 7 7" preserveAspectRatio="none" className="w-full h-full block">
          {/* Base: white fill for the low-risk zone (above R=70 lines) */}
          <rect x="-1" y="-1" width="9" height="9" fill="#FFFFFF" />

          {/* Band below R=180 line (red, highest risk bottom-right corner) */}
          <polygon
            points={`0,${getV(0, 180)} 7,${getV(7, 180)} 7,8 0,8`}
            fill="#FF0000"
          />
          {/* Bands between consecutive risk lines, painted from low-risk outward */}
          {riskLines.slice(0, -1).map((r, i) => {
            const nextR = riskLines[i + 1];
            return (
              <polygon
                key={`band-${r}`}
                points={`0,${getV(0, r)} 7,${getV(7, r)} 7,${getV(7, nextR)} 0,${getV(0, nextR)}`}
                fill={getBandColor(r)}
              />
            );
          })}

          {/* Grid Lines (every 0.5 units) */}
          {Array.from({ length: 15 }).map((_, i) => {
            const pos = i * 0.5;
            return (
              <g key={`grid-${i}`}>
                <line x1="0" y1={pos} x2="7" y2={pos} stroke="rgba(0,0,0,0.65)" strokeWidth="0.03" />
                <line x1={pos} y1="0" x2={pos} y2="7" stroke="rgba(0,0,0,0.65)" strokeWidth="0.03" />
              </g>
            );
          })}

          {/* Diagonal Risk Lines */}
          {riskLines.map((r) => (
            <line
              key={`dline-${r}`}
              x1="0" y1={getV(0, r)}
              x2="7" y2={getV(7, r)}
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
        <p className="relative shrink-0 w-full">4</p>
      </div>

      {/* X-axis labels */}
      <div className="absolute flex items-center justify-between text-center font-medium text-[10px] leading-normal" style={{ inset: '86.74% 0 8.96% 12.28%' }}>
        <p className="relative shrink-0 w-3.5">5</p>
        <p className="relative shrink-0 w-3.5">6</p>
        <p className="relative shrink-0 w-3.5">7</p>
        <p className="relative shrink-0 w-3.5">8</p>
        <p className="relative shrink-0 w-3.5">9</p>
        <p className="relative shrink-0 w-3.5">10</p>
        <p className="relative shrink-0 w-3.5">11</p>
        <p className="relative shrink-0 w-3.5">12</p>
      </div>

      {/* Axes Titles and Arrows */}
      <div className="absolute flex flex-col h-[243px] items-end justify-between left-0 top-0">
        <div className="flex flex-col gap-[2px] items-center relative shrink-0">
          <ArrowUpIcon className="relative shrink-0 size-[12px]" />
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">多</p>
        </div>
        <div className="flex h-[140px] items-center justify-center relative shrink-0 w-[17px]">
          <div className="-rotate-90 flex-none">
            <p className="font-medium leading-normal text-[14px] text-center whitespace-nowrap">仕事のコントロール度</p>
          </div>
        </div>
        <div className="flex flex-col gap-[2px] items-center relative shrink-0">
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">少</p>
          <ArrowDownIcon className="relative shrink-0 size-[12px]" />
        </div>
      </div>

      <div className="absolute flex items-center justify-between left-[42px] top-[264px] w-[237px]">
        <div className="flex gap-[2px] items-center justify-center relative shrink-0">
          <ArrowLeftIcon className="relative shrink-0 size-[12px]" />
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">小</p>
        </div>
        <p className="font-medium leading-normal text-[14px] text-center whitespace-nowrap">心理的な仕事の負担(量)</p>
        <div className="flex gap-[2px] items-center justify-center relative shrink-0">
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">大</p>
          <ArrowRightIcon className="relative shrink-0 size-[12px]" />
        </div>
      </div>

      <div className="absolute inset-0 pointer-events-none">
        <TriangleIcon className="absolute" style={{ left: currentPos.x - 10, top: currentPos.y - 9, width: 20, height: 18 }} />
        {previousPos && <EllipseIcon className="absolute" style={{ left: previousPos.x - 7, top: previousPos.y - 7, width: 14, height: 14 }} />}
        <PolygonIcon className="absolute" style={{ left: nationalPos.x - 9.5, top: nationalPos.y - 9.5, width: 19, height: 19 }} />
      </div>

      {/* Risk Labels — on top of everything, exact positions from Figma (node 77:3692) */}
      {([
        { label: '70',  inset: '19.35% 79.96% 75.59% 15.09%' },
        { label: '80',  inset: '37.29% 79.71% 57.41% 15.07%' },
        { label: '90',  inset: '48.05% 73.40% 46.66% 21.39%' },
        { label: '100', inset: '55.29% 66.09% 38.48% 27.63%' },
        { label: '110', inset: '62.78% 59.22% 31.46% 35.04%' },
        { label: '120', inset: '69.25% 53.36% 24.76% 40.63%' },
        { label: '130', inset: '75.36% 47.85% 18.41% 45.88%' },
        { label: '140', inset: '78.94% 40.48% 14.83% 53.25%' },
        { label: '150', inset: '79.28% 30.55% 14.72% 63.44%' },
        { label: '160', inset: '78.94% 20.13% 14.83% 73.60%' },
        { label: '170', inset: '78.92% 10.20% 15.08% 83.79%' },
        { label: '180', inset: '79.64% 2.13%  14.36% 91.86%' },
      ] as const).map(({ label, inset }) => (
        <div key={label} className="absolute flex items-center justify-center pointer-events-none" style={{ inset }}>
          <div style={{ transform: 'rotate(-41deg)' }}>
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
