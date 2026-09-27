import path from "node:path";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /* The repository root is this folder; without it the bundler walks up the
     tree looking for a lockfile and warns about the wrong one. */
  turbopack: { root: path.resolve(".") },
  outputFileTracingRoot: path.resolve("."),

  reactStrictMode: true,
  poweredByHeader: false,

  typescript: { ignoreBuildErrors: false },

  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "X-Frame-Options", value: "DENY" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "Permissions-Policy", value: "geolocation=(self), camera=(self), microphone=()" },
        ],
      },
      {
        /* The service worker must never be served from a stale cache, or a
           released fix can take days to reach a field device. */
        source: "/sw.js",
        headers: [
          { key: "Cache-Control", value: "public, max-age=0, must-revalidate" },
          { key: "Service-Worker-Allowed", value: "/" },
        ],
      },
    ];
  },
};

export default nextConfig;
