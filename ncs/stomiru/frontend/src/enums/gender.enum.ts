export const GenderValues = ["male", "female"] as const;
export type Gender = (typeof GenderValues)[number];

const GenderEnum = Object.freeze({
  Male: { value: "male", label: "男性" },
  Female: { value: "female", label: "女性" },
});

export default GenderEnum;
