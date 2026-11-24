import { createFileRoute } from "@tanstack/react-router";
import z from "zod";
import Login from "@/features/auth/login";

const searchSchema = z.object({
  error: z.string().optional(),
});

export const Route = createFileRoute("/(auth)/auth/login")({
  validateSearch: searchSchema,
  component: Login,
});
