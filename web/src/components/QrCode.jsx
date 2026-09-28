import { useMemo } from "react";
import { qrModules, qrPath } from "../lib/qr.js";

const QUIET = 4;

/** A QR code as SVG: sharp at any size, printed black on white. */
export default function QrCode({ text, title, className = "" }) {
  const modules = useMemo(() => qrModules(text), [text]);
  const size = modules.length + QUIET * 2;
  return (
    <svg viewBox={`0 0 ${size} ${size}`} role="img" aria-label={title} className={className} shapeRendering="crispEdges">
      <rect width={size} height={size} fill="#fff" />
      <path d={qrPath(modules, QUIET)} fill="#000" />
    </svg>
  );
}
