import path from "node:path";

import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // A self-contained server for the Docker image (deploy/docker/web.Dockerfile).
  output: "standalone",
  // No image optimisation is used; this keeps the platform-specific image library out of the
  // runtime, so an image built on x86 runs on ARM.
  images: { unoptimized: true },
  // The repository root has its own package-lock.json (Laravel's Vite build); this app is web/.
  outputFileTracingRoot: path.join(import.meta.dirname),
  turbopack: { root: path.join(import.meta.dirname) },
  poweredByHeader: false,
};

export default nextConfig;
