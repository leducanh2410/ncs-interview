import EllipseIcon from "@/components/icons/ellipse-icon";
import PolygonIcon from "@/components/icons/polygon-icon";
import TriangleIcon from "@/components/icons/triangle-icon";
import ArrowDownIcon from "@/components/icons/arrow-down-icon";
import ArrowLeftIcon from "@/components/icons/arrow-left-icon";
import ArrowRightIcon from "@/components/icons/arrow-right-icon";
import ArrowUpIcon from "@/components/icons/arrow-up-icon";
import imgGroup9 from "@/assets/icons/heatmap_gradient.svg";
import imgLine3 from "@/assets/icons/heatmap_grid_h.svg";
import imgLine4 from "@/assets/icons/heatmap_grid_v.svg";
import imgGroup1 from "@/assets/icons/heatmap_overlay.svg";

interface HeatmapChartProps {
  currentX: number;
  currentY: number;
  previousX?: number;
  previousY?: number;
  nationalX: number;
  nationalY: number;
  type: 'workload' | 'support';
}

export const HeatmapChart = ({
  currentX,
  currentY,
  previousX,
  previousY,
  nationalX,
  nationalY,
  type,
}: HeatmapChartProps) => {
  const getPosition = (x: number, y: number) => {
    if (type === 'workload') {
      const xPos = 43 + ((x - 5) / 7) * 236;
      const yPos = 240 - ((y - 4) / 7) * 236;
      return { x: xPos, y: yPos };
    } else {
      const xPos = 39 + ((x - 4) / 6) * 236;
      const yPos = 240 - ((y - 5) / 6) * 236;
      return { x: xPos, y: yPos };
    }
  };

  const currentPos = getPosition(currentX, currentY);
  const previousPos = previousX && previousY ? getPosition(previousX, previousY) : null;
  const nationalPos = getPosition(nationalX, nationalY);

  return (
    <div className="relative w-[285px] h-[279px]">
      {/* Heatmap background with gradient */}
      <div className="absolute inset-0 z-0 pointer-events-none" style={{ transform: type === 'support' ? 'scaleX(-1)' : 'none' }}>
        <div className="absolute" style={{ inset: '3.23% 2.11% 14.29% 15.09%' }}>
        <div className="flex items-center justify-center size-full">
          <div className="-rotate-90 flex-none h-[236px] w-[230.134px]">
            <div className="relative size-full">
              <img alt="" className="absolute block max-w-none size-full" src={imgGroup9} />
            </div>
          </div>
        </div>
      </div>

      {/* Horizontal grid lines */}
      <div className="absolute flex flex-col items-start justify-between" style={{ inset: '1.43% 2.11% 13.98% 15.09%' }}>
        {Array.from({ length: 15 }).map((_, i) => (
          <div key={`h-${i}`} className="h-0 relative shrink-0 w-full">
            <div className="absolute inset-[-1px_0_0_0]">
              <img alt="" className="block max-w-none size-full" src={imgLine3} />
            </div>
          </div>
        ))}
      </div>

      {/* Vertical grid lines */}
      <div className="absolute flex items-center justify-center" style={{ inset: '1.08% 2.11% 13.98% 15.09%' }}>
        <div className="-rotate-90 flex-none h-[236px] w-[237px]">
          <div className="flex flex-col items-start justify-between relative size-full">
            {Array.from({ length: 15 }).map((_, i) => (
              <div key={`v-${i}`} className="h-0 relative shrink-0 w-full">
                <div className="absolute inset-[-1px_0_0_0]">
                  <img alt="" className="block max-w-none size-full" src={imgLine4} />
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Diagonal gradient overlay */}
      <div className="absolute flex items-center justify-center" style={{ inset: '1.08% 2.46% 14.34% 14.74%' }}>
        <div className="-scale-y-100 flex-none h-[236.002px] rotate-90 w-[236px]">
          <div className="relative size-full">
            <div className="absolute" style={{ inset: '0 -0.32% -0.28% 0' }}>
              <img alt="" className="block max-w-none size-full" src={imgGroup1} />
            </div>
          </div>
        </div>
      </div>
      </div>

      {/* Y-axis labels */}
      <div className="absolute flex flex-col items-end justify-between text-right font-medium text-[10px] leading-normal" style={{ inset: '0 87.02% 12.9% 8.77%' }}>
        {type === 'workload' ? (
          <>
            <p className="relative shrink-0 w-full">11</p>
            <p className="relative shrink-0 w-full">10</p>
            <p className="relative shrink-0 w-full">9</p>
            <p className="relative shrink-0 w-full">8</p>
            <p className="relative shrink-0 w-full">7</p>
            <p className="relative shrink-0 w-full">6</p>
            <p className="relative shrink-0 w-full">5</p>
            <p className="relative shrink-0 w-full">4</p>
          </>
        ) : (
          <>
            <p className="relative shrink-0 w-full">11</p>
            <p className="relative shrink-0 w-full">10</p>
            <p className="relative shrink-0 w-full">9</p>
            <p className="relative shrink-0 w-full">8</p>
            <p className="relative shrink-0 w-full">7</p>
            <p className="relative shrink-0 w-full">6</p>
            <p className="relative shrink-0 w-full">5</p>
          </>
        )}
      </div>

      {/* X-axis labels */}
      <div className="absolute flex items-center justify-between text-center font-medium text-[10px] leading-normal" style={{ inset: '86.74% 0 8.96% 12.28%' }}>
        {type === 'workload' ? (
          <>
            <p className="relative shrink-0 w-3.5">5</p>
            <p className="relative shrink-0 w-3.5">6</p>
            <p className="relative shrink-0 w-3.5">7</p>
            <p className="relative shrink-0 w-3.5">8</p>
            <p className="relative shrink-0 w-3.5">9</p>
            <p className="relative shrink-0 w-3.5">10</p>
            <p className="relative shrink-0 w-3.5">11</p>
            <p className="relative shrink-0 w-3.5">12</p>
          </>
        ) : (
          <>
            <p className="relative shrink-0 w-3.5">4</p>
            <p className="relative shrink-0 w-3.5">5</p>
            <p className="relative shrink-0 w-3.5">6</p>
            <p className="relative shrink-0 w-3.5">7</p>
            <p className="relative shrink-0 w-3.5">8</p>
            <p className="relative shrink-0 w-3.5">9</p>
            <p className="relative shrink-0 w-3.5">10</p>
          </>
        )}
      </div>

      {/* Y-axis label and arrows (left side) */}
      <div className="absolute flex flex-col h-[243px] items-end justify-between left-0 top-0">
        <div className="flex flex-col gap-[2px] items-center relative shrink-0">
          <ArrowUpIcon className="relative shrink-0 size-[12px]" />
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">多</p>
        </div>
        <div className="flex h-[140px] items-center justify-center relative shrink-0 w-[17px]">
          <div className="-rotate-90 flex-none">
            <p className="font-medium leading-normal text-[14px] text-center whitespace-nowrap">
              {type === 'workload' ? '仕事のコントロール度' : '同僚からのサポート'}
            </p>
          </div>
        </div>
        <div className="flex flex-col gap-[2px] items-center relative shrink-0">
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">少</p>
          <ArrowDownIcon className="relative shrink-0 size-[12px]" />
        </div>
      </div>

      {/* X-axis label and arrows (bottom) */}
      <div className="absolute flex items-center justify-between left-[42px] top-[264px] w-[237px]">
        <div className="flex gap-[2px] items-center justify-center relative shrink-0">
          <ArrowLeftIcon className="relative shrink-0 size-[12px]" />
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">小</p>
        </div>
        <p className="font-medium leading-normal text-[14px] text-center whitespace-nowrap">
          {type === 'workload' ? '心理的な仕事の負担(量)' : '上司からのサポート'}
        </p>
        <div className="flex gap-[2px] items-center justify-center relative shrink-0">
          <p className="font-normal leading-normal text-[10px] text-center whitespace-nowrap">大</p>
          <ArrowRightIcon className="relative shrink-0 size-[12px]" />
        </div>
      </div>

      {/* Diagonal health risk values */}
      <div className="absolute inset-0 z-0 pointer-events-none" style={{ transform: type === 'support' ? 'scaleX(-1)' : 'none' }}>
        <div className="absolute" style={{ inset: '19.35% 2.13% 14.36% 15.07%' }}>
        <div className="absolute flex items-center justify-center" style={{ inset: '19.35% 79.96% 75.59% 15.09%' }}>
          <div className="-rotate-41 flex-none size-[10px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              70
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '37.29% 79.71% 57.41% 15.07%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[11px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              80
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center " style={{ inset: '48.05% 73.4% 46.66% 21.39%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[11px] ">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              90
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '55.29% 66.09% 38.48% 27.63%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[15px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              100
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '62.78% 59.22% 31.46% 35.04%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[13px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              110
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '69.25% 53.36% 24.76% 40.63%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[14px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              120
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '19.35% 79.96% 75.59% 15.09%' }}>
          <div className="-rotate-41 flex-none size-[10px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              130
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '78.94% 40.48% 14.83% 53.25%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[15px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              140
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '79.28% 30.55% 14.72% 63.44%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[14px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              150
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '78.94% 20.13% 14.83% 73.6%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[15px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              160
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '78.92% 10.2% 15.08% 83.79%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[14px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              170
            </p>
          </div>
        </div>
        <div className="absolute flex items-center justify-center" style={{ inset: '79.64% 2.13% 14.36% 91.86%' }}>
          <div className="-rotate-41 flex-none h-[10px] w-[14px]">
            <p
              className="font-medium leading-normal text-[8px] text-[#2200FF] text-center whitespace-nowrap"
              style={{ WebkitTextStroke: '2px white', paintOrder: 'stroke fill', transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
            >
              180
            </p>
          </div>
        </div>

      </div>
      </div>

      <div 
        className="absolute inset-0 pointer-events-none"
        style={{ transform: type === 'support' ? 'scaleX(-1)' : 'none' }}
      >
        <TriangleIcon
          className="absolute"
          style={{
            left: currentPos.x - 10,
            top: currentPos.y - 9,
            width: 20,
            height: 18,
          }}
        />
        {previousPos && (
          <EllipseIcon
            className="absolute"
            style={{
              left: previousPos.x - 7,
              top: previousPos.y - 7,
              width: 14,
              height: 14,
            }}
          />
        )}
        <PolygonIcon
          className="absolute"
          style={{
            left: nationalPos.x - 9.5,
            top: nationalPos.y - 9.5,
            width: 19,
            height: 19,
          }}
        />
      </div>
    </div>
  );
};
