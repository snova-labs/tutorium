import path from "node:path";

import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // The repository root has its own package-lock.json (Laravel's Vite build); this app is web/.
  outputFileTracingRoot: path.join(import.meta.dirname),
  turbopack: { root: path.join(import.meta.dirname) },
  poweredByHeader: false,
};

export default nextConfig;
