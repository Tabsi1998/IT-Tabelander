import React, { useId } from "react";
import { motion } from "framer-motion";
import { mediaUrl } from "../../lib/api";

const REGIONS = [
  "shell", "trim", "touchpad", "accent_rings", "dpad", "buttons", "sticks",
  "back_shell", "grips", "paddles", "beyond",
];

const isLight = (hex) => {
  if (!hex) return false;
  const value = hex.replace("#", "");
  if (!/^[0-9a-f]{6}$/i.test(value)) return false;
  const [r, g, b] = [0, 2, 4].map((offset) => parseInt(value.slice(offset, offset + 2), 16));
  return (r * 299 + g * 587 + b * 114) / 1000 > 150;
};

/* Part masks aligned to the 768×512 transparent controller product views. */
function RegionShapes({ model, side, region }) {
  const edge = model === "edge";

  if (side === "front") {
    if (region === "shell") return edge ? <>
      <path d="M24 245C32 123 87 43 224 28L258 54 249 176 190 302 155 430C132 493 67 501 36 431 18 383 16 314 24 245Z" />
      <path d="M744 245C736 123 681 43 544 28L510 54 519 176 578 302 613 430C636 493 701 501 732 431 750 383 752 314 744 245Z" />
    </> : <>
      <path d="M25 250C35 133 89 47 230 35L257 67 251 157 192 281 155 421C132 489 66 500 36 429 20 386 17 318 25 250Z" />
      <path d="M743 250C733 133 679 47 538 35L511 67 517 157 576 281 613 421C636 489 702 500 732 429 748 386 751 318 743 250Z" />
    </>;
    if (region === "trim") return edge
      ? <path fillRule="evenodd" clipRule="evenodd" d="M250 165Q384 190 518 165L584 315Q557 332 534 346L485 306Q438 324 384 324T283 306L234 346Q211 332 184 315ZM216 242a54 54 0 1 0 108 0 54 54 0 1 0-108 0Zm228 0a54 54 0 1 0 108 0 54 54 0 1 0-108 0Z" />
      : <path fillRule="evenodd" clipRule="evenodd" d="M250 166Q384 190 518 166L580 325Q552 340 526 353L480 321Q434 332 384 332T288 321L242 353Q216 340 188 325ZM210 245a51 51 0 1 0 102 0 51 51 0 1 0-102 0Zm246 0a51 51 0 1 0 102 0 51 51 0 1 0-102 0Z" />;
    if (region === "touchpad") return edge
      ? <path d="M258 50Q384 26 510 50L497 151Q493 174 469 177H299Q275 174 271 151Z" />
      : <path d="M239 42Q384 19 529 42L515 151Q511 181 481 184H287Q257 181 253 151Z" />;
    if (region === "dpad") return edge ? <>
      <path d="M141 94Q163 81 184 96L181 130Q163 147 144 130Z" /><path d="M94 139Q109 117 135 124L156 151 133 177Q107 181 91 161Z" />
      <path d="M190 124Q216 117 231 139L234 161Q218 181 192 177L169 151Z" /><path d="M144 173Q163 157 181 173L184 207Q163 222 141 207Z" />
    </> : <>
      <path d="M137 92Q160 82 181 96L181 126 160 147 139 126Z" /><path d="M94 135Q108 115 134 120L155 143 134 166Q108 172 92 153Z" />
      <path d="M186 120Q212 115 226 135L228 153Q212 172 186 166L165 143Z" /><path d="M139 160 160 139 181 160 181 190Q160 205 139 190Z" />
    </>;
    if (region === "buttons") return edge ? <>
      <circle cx="610" cy="96" r="23" /><circle cx="658" cy="145" r="23" /><circle cx="610" cy="194" r="23" /><circle cx="562" cy="145" r="23" />
    </> : <>
      <circle cx="607" cy="99" r="24" /><circle cx="657" cy="152" r="24" /><circle cx="607" cy="204" r="24" /><circle cx="555" cy="152" r="24" />
    </>;
    if (region === "sticks") return edge ? <>
      <circle cx="270" cy="242" r="34" /><circle cx="498" cy="242" r="34" />
    </> : <>
      <circle cx="261" cy="245" r="35" /><circle cx="507" cy="245" r="35" />
    </>;
    if (region === "accent_rings") return edge ? <>
      <circle cx="270" cy="242" r="48" /><circle cx="498" cy="242" r="48" />
    </> : <>
      <circle cx="261" cy="245" r="47" /><circle cx="507" cy="245" r="47" />
    </>;
    return null;
  }

  if (region === "back_shell") return edge
    ? <path d="M126 48Q384 7 642 48L613 116Q587 163 566 232L531 376Q494 405 453 417H315Q274 405 237 376L202 232Q181 163 155 116Z" />
    : <path d="M203 54Q384 27 565 54L620 175 566 302Q529 286 492 281H276Q239 286 202 302L148 175Z" />;
  if (region === "grips") return edge ? <>
    <path d="M27 215C40 115 82 55 153 44L205 82 232 238 190 381 150 473C111 503 55 470 32 411 14 361 17 281 27 215Z" />
    <path d="M741 215C728 115 686 55 615 44L563 82 536 238 578 381 618 473C657 503 713 470 736 411 754 361 751 281 741 215Z" />
  </> : <>
    <path d="M5 246C16 137 64 66 187 46L222 80 202 218 166 350 124 467C82 507 24 462 7 405-5 360-3 292 5 246Z" />
    <path d="M763 246C752 137 704 66 581 46L546 80 566 218 602 350 644 467C686 507 744 462 761 405 773 360 771 292 763 246Z" />
  </>;
  if (region === "paddles") return edge ? <>
    <path d="M275 195Q300 184 316 211L309 305Q293 329 271 306L259 224Z" />
    <path d="M493 195Q468 184 452 211L459 305Q475 329 497 306L509 224Z" />
  </> : <>
    <path d="M238 214Q267 204 284 231L276 332Q258 357 232 331L218 247Z" />
    <path d="M530 214Q501 204 484 231L492 332Q510 357 536 331L550 247Z" />
  </>;
  if (region === "beyond" && edge) return <>
    <rect x="326" y="236" width="116" height="92" rx="18" />
    <path d="M247 225Q272 216 288 242L279 350Q261 370 239 344L228 260Z" />
    <path d="M521 225Q496 216 480 242L489 350Q507 370 529 344L540 260Z" />
  </>;
  return null;
}

function RegionTint({ model, side, region, color }) {
  const generatedId = useId().replace(/:/g, "");
  if (!color) return null;
  const clipId = `controller-${generatedId}-${model}-${side}-${region}`;
  return <g data-region={region}>
    <defs><clipPath id={clipId}><RegionShapes model={model} side={side} region={region} /></clipPath></defs>
    <rect width="768" height="512" fill={color} clipPath={`url(#${clipId})`} opacity="0.96" style={{ mixBlendMode: "color" }} />
    <rect width="768" height="512" fill={color} clipPath={`url(#${clipId})`} opacity={isLight(color) ? 0.12 : 0.68} style={{ mixBlendMode: "multiply" }} />
    <rect width="768" height="512" fill={color} clipPath={`url(#${clipId})`} opacity={isLight(color) ? 0.52 : 0.06} style={{ mixBlendMode: "screen" }} />
  </g>;
}

function PartColorOverlay({ model, side, colors }) {
  return <svg viewBox="0 0 768 512" preserveAspectRatio="xMidYMid meet" className="pointer-events-none absolute inset-0 z-10 h-full w-full" aria-hidden="true">
    {REGIONS.map((region) => <RegionTint key={region} model={model} side={side} region={region} color={colors[region]} />)}
  </svg>;
}

function PhotoPreview({ model, photo, side, colors, overlays }) {
  return <div className="relative isolate aspect-[3/2]">
    <img src={photo} alt={`${model === "edge" ? "DualSense Edge" : "DualSense"} Controller ${side === "front" ? "Vorderseite" : "Rückseite"}`}
      className="relative z-0 h-full w-full select-none object-contain" loading="eager" draggable="false" />
    <PartColorOverlay model={model} side={side} colors={colors} />
    {overlays.filter((overlay) => (overlay.layer?.side || "front") === side && overlay.url).map((overlay) => {
      const layer = overlay.layer || {};
      return <img key={overlay.id} src={mediaUrl(overlay.url)} alt="" loading="lazy" className="pointer-events-none absolute z-20 object-contain"
        style={{ left: `${layer.x ?? 0}%`, top: `${layer.y ?? 0}%`, width: `${layer.scale ? layer.scale * 100 : 40}%`, transform: `rotate(${layer.rotation || 0}deg)`, zIndex: (layer.z || 5) + 20 }} />;
    })}
  </div>;
}

function SvgFallback({ side, colors, model }) {
  const shell = colors.shell || colors.back_shell || "#E8EAED";
  const center = colors.trim || "#171A20";
  const touchpad = colors.touchpad || "#292D35";
  const buttons = colors.buttons || "#20242A";
  const sticks = colors.sticks || "#20242A";
  const grips = colors.grips || shell;
  const glyph = isLight(buttons) ? "#20242A" : "#F4F5F7";
  return <svg viewBox="0 0 768 512" className="w-full" role="img" aria-label={`${model} ${side}`}>
    <defs><filter id="controller-shadow"><feDropShadow dx="0" dy="14" stdDeviation="16" floodOpacity="0.5" /></filter></defs>
    <g filter="url(#controller-shadow)">
      <path d="M154 105C80 116 36 257 66 402 81 472 160 481 203 350L244 205Z" fill={side === "front" ? shell : grips} />
      <path d="M614 105C688 116 732 257 702 402 687 472 608 481 565 350L524 205Z" fill={side === "front" ? shell : grips} />
      <path d="M215 74Q384 38 553 74L604 302Q555 330 514 350H254Q213 330 164 302Z" fill={side === "front" ? center : (colors.back_shell || center)} />
      {side === "front" && <>
        <path d="M265 75Q384 48 503 75L489 170H279Z" fill={touchpad} />
        <circle cx="286" cy="270" r="42" fill={sticks} /><circle cx="482" cy="270" r="42" fill={sticks} />
        <circle cx="590" cy="160" r="22" fill={buttons} /><circle cx="638" cy="208" r="22" fill={buttons} />
        <circle cx="590" cy="256" r="22" fill={buttons} /><circle cx="542" cy="208" r="22" fill={buttons} />
        <text x="590" y="168" textAnchor="middle" fill={glyph} fontSize="22">△</text>
      </>}
      {side === "back" && colors.paddles && <RegionShapes model={model} side="back" region="paddles" />}
    </g>
  </svg>;
}

export default function ControllerCanvas({ model = "dualsense", side = "front", colors = {}, overlays = [], photoFront = "", photoBack = "", className = "" }) {
  const photo = side === "front" ? mediaUrl(photoFront) : mediaUrl(photoBack);
  return <div className={`relative ${className}`} data-testid="controller-live-preview">
    <motion.div key={`${model}-${side}`} initial={{ opacity: 0, rotateY: side === "back" ? -12 : 12, scale: 0.98 }}
      animate={{ opacity: 1, rotateY: 0, scale: 1 }} transition={{ duration: 0.38, ease: [0.16, 1, 0.3, 1] }}>
      {photo ? <PhotoPreview model={model} photo={photo} side={side} colors={colors} overlays={overlays} /> : <SvgFallback side={side} colors={colors} model={model} />}
    </motion.div>
  </div>;
}
