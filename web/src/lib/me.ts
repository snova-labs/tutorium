import "server-only";

import { cache } from "react";

import { api } from "@/lib/api";
import type { Me } from "@/lib/types";

/** The signed-in person, fetched once per request however many components ask. */
export const getMe = cache(async (): Promise<Me> => (await api<{ data: Me }>("me")).data);
