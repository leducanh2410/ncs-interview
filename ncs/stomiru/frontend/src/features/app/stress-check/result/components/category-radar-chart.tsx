import {
  RadarChart as RechartsRadarChart,
  PolarGrid,
  PolarAngleAxis,
  PolarRadiusAxis,
  Radar,
  ResponsiveContainer,
} from "recharts";
import { CustomAngleTick } from "./custom-angle-tick";
import type { ScaleItem } from "./types";

const MAX_SCORE_PER_SCALE = 4;
const DANGER_ZONE_VALUE = 1.5;

export function CategoryRadarChart({ items }: { items: ScaleItem[] }) {
  const data = items.map((item) => ({
    subject: item.label,
    score: item.score,
    previousScore: item.previousScore,
    dangerZone: DANGER_ZONE_VALUE,
    fullMark: item.maxScore,
  }));

  return (
    <div className="h-[300px] w-[400px] shrink-0">
      <ResponsiveContainer width="100%" height="100%">
        <RechartsRadarChart data={data} cx="50%" cy="53%" outerRadius={100}>
          <PolarGrid stroke="#DBDBDB" strokeWidth={1} />
          <PolarAngleAxis
            dataKey="subject"
            tick={<CustomAngleTick />}
            tickLine={false}
          />
          <PolarRadiusAxis
            angle={90}
            domain={[0, MAX_SCORE_PER_SCALE]}
            tickCount={5}
            tick={false}
            axisLine={false}
          />
          <Radar
            name="dangerZone"
            dataKey="dangerZone"
            stroke="#f44336"
            fill="#f44336"
            fillOpacity={0.15}
            strokeWidth={1}
            dot={false}
          />
          {items.some((item) => item.previousScore !== undefined) && (
            <Radar
              name="previousScore"
              dataKey="previousScore"
              stroke="#000000"
              fill="none"
              strokeWidth={1}
              strokeDasharray="6 4"
              dot={false}
            />
          )}
          <Radar
            name="score"
            dataKey="score"
            stroke="#15ca42"
            fill="#15ca42"
            fillOpacity={0.25}
            strokeWidth={1.5}
            dot={{
              r: 3,
              fill: "#15ca42",
              stroke: "#15ca42",
              strokeWidth: 0,
              fillOpacity: 1,
            }}
          />
        </RechartsRadarChart>
      </ResponsiveContainer>
    </div>
  );
}
