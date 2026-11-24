import StringHelper from "@/helpers/string.helper";

export function CustomAngleTick({
  x = 0,
  y = 0,
  payload,
  cx = 0,
  cy = 0,
}: {
  x?: number;
  y?: number;
  payload?: { value: string };
  cx?: number;
  cy?: number;
}) {
  const label = payload?.value ?? "";
  const lines = label.length > 6 ? StringHelper.splitLabel(label, 18) : [label];

  const angle = Math.atan2(y - cy, x - cx);
  const normalizedAngle = angle < 0 ? angle + 2 * Math.PI : angle;
  const angleInDegrees = (normalizedAngle * 180) / Math.PI;
  const isHorizontalSideLabel =
    angleInDegrees <= 45 ||
    angleInDegrees >= 315 ||
    (angleInDegrees >= 135 && angleInDegrees <= 225);
  const pushDistance = isHorizontalSideLabel
    ? 7
    : angleInDegrees >= 60 && angleInDegrees <= 120
      ? 17
      : 27;

  const newX = x + Math.cos(angle) * pushDistance;
  const newY = y + Math.sin(angle) * pushDistance;
  const dx = newX - cx;
  const textAnchor = dx > 10 ? "start" : dx < -10 ? "end" : "middle";

  return (
    <text
      x={newX}
      y={newY}
      textAnchor={textAnchor}
      dominantBaseline="central"
      fontSize={12}
      fill="#000000"
    >
      {lines.map((line, i) => (
        <tspan key={i} x={newX} dy={i === 0 ? 0 : 15}>
          {line}
        </tspan>
      ))}
    </text>
  );
}
