/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  pageExtensions: ["page.tsx"],
  output: "export",
  webpack: (config) => {
    // pdfjs-dist tries to import 'canvas' in Node.js environments
    config.resolve.alias.canvas = false;
    return config;
  },
};

export default nextConfig;
