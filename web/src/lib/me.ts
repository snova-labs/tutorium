import "server-only";

import { cache } from "react";

import { api } from "@/lib/api";
import type { Me, Terms } from "@/lib/types";

/** The signed-in person, fetched once per request however many components ask. */
export const getMe = cache(async (): Promise<Me> => (await api<{ data: Me }>("me")).data);

/** What this academy calls things, fetched once per request. Every label is rendered from it. */
export const getTerms = cache(async (): Promise<Terms> => (await api<{ data: Terms }>("terminology")).data);
