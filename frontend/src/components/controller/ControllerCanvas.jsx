import React from "react";
import { motion } from "framer-motion";
import { mediaUrl } from "../../lib/api";

// Data-driven layered controller preview.
// `colors` maps region_key -> hex. `overlays` is an array of {url, layer, id}.
// Master SVG regions share the exact same coordinate space for both models,
// so any variant recolors/positions exactly on the controller.

const isLight = (hex) => {
  if (!hex) return false;
  const c = hex.replace("#", "");
  const r = parseInt(c.substr(0, 2), 16), g = parseInt(c.substr(2, 2), 16), b = parseInt(c.substr(4, 2), 16);
  return (r * 299 + g * 587 + b * 114) / 1000 > 150;
};

function Front({ c, model }) {
  const shell = c.shell || "#1B1E24";
  const trim = c.trim || shell;
  const touch = c.touchpad || "#3A3F46";
  const dpad = c.dpad || "#22262B";
  const buttons = c.buttons || "#E8EAED";
  const sticks = c.sticks || "#22262B";
  const rings = c.accent_rings || "#4A4F57";
  const glyph = isLight(buttons) ? "#22262B" : "#E8EAED";
  return (
    <g>
      {/* triggers */}
      <path d="M120 40 q40 -26 96 -14 l-6 30 q-46 -8 -84 8 Z" fill={trim} />
      <path d="M420 40 q-40 -26 -96 -14 l6 30 q46 -8 84 8 Z" fill={trim} />
      {/* grips */}
      <path d="M150 150 C96 160 70 250 96 330 C120 388 196 372 214 300 L224 210 Z" fill={shell} />
      <path d="M390 150 C444 160 470 250 444 330 C420 388 344 372 326 300 L316 210 Z" fill={shell} />
      {/* body */}
      <path d="M214 96 C176 96 150 128 150 186 C150 236 176 286 214 296 C246 304 258 276 270 276 C282 276 294 304 326 296 C364 286 390 236 390 186 C390 128 364 96 326 96 Z" fill={shell} />
      <rect x="252" y="150" width="36" height="10" rx="4" fill={trim} opacity="0.6" />
      {/* touchpad */}
      <rect x="238" y="120" width="64" height="52" rx="12" fill={touch} />
      <line x1="270" y1="122" x2="270" y2="170" stroke="#000" strokeOpacity="0.15" />
      {/* dpad */}
      <g fill={dpad}>
        <rect x="196" y="176" width="16" height="46" rx="4" />
        <rect x="181" y="191" width="46" height="16" rx="4" />
      </g>
      {/* buttons */}
      <circle cx="356" cy="199" r="12.5" fill={buttons} />
      <circle cx="332" cy="176" r="12.5" fill={buttons} />
      <circle cx="332" cy="222" r="12.5" fill={buttons} />
      <circle cx="308" cy="199" r="12.5" fill={buttons} />
      <text x="356" y="204" fontSize="12" fill={glyph} textAnchor="middle" fontFamily="monospace">○</text>
      <text x="332" y="181" fontSize="11" fill={glyph} textAnchor="middle" fontFamily="monospace">△</text>
      <text x="332" y="227" fontSize="11" fill={glyph} textAnchor="middle" fontFamily="monospace">✕</text>
      <text x="308" y="204" fontSize="11" fill={glyph} textAnchor="middle" fontFamily="monospace">□</text>
      {/* sticks + accent rings */}
      <g>
        <circle cx="236" cy="238" r="26" fill={rings} />
        <circle cx="236" cy="238" r="20" fill="#0c0e11" />
        <circle cx="236" cy="238" r="15" fill={sticks} />
        <circle cx="322" cy="238" r="26" fill={rings} />
        <circle cx="322" cy="238" r="20" fill="#0c0e11" />
        <circle cx="322" cy="238" r="15" fill={sticks} />
      </g>
    </g>
  );
}

function Back({ c, model, hasPaddles, hasBeyond }) {
  const back = c.back_shell || "#181B20";
  const grips = c.grips || back;
  const paddle = c.paddles || "#C7CCD1";
  const beyond = c.beyond || "#2B2F36";
  return (
    <g>
      <path d="M150 150 C96 160 70 250 96 330 C120 388 196 372 214 300 L224 210 Z" fill={grips} />
      <path d="M390 150 C444 160 470 250 444 330 C420 388 344 372 326 300 L316 210 Z" fill={grips} />
      <path d="M214 96 C176 96 150 128 150 186 C150 236 176 286 214 296 C246 304 258 276 270 276 C282 276 294 304 326 296 C364 286 390 236 390 186 C390 128 364 96 326 96 Z" fill={back} />
      {/* vents */}
      {[0, 1, 2, 3, 4].map((i) => (
        <rect key={i} x={250} y={130 + i * 14} width="40" height="5" rx="2" fill="#000" opacity="0.18" />
      ))}
      {hasPaddles && (
        <motion.g initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.3 }}>
          <path d="M196 250 q-30 6 -34 44 q28 6 44 -4 l4 -34 Z" fill={paddle} />
          <path d="M344 250 q30 6 34 44 q-28 6 -44 -4 l-4 -34 Z" fill={paddle} />
        </motion.g>
      )}
      {hasBeyond && model === "edge" && (
        <motion.rect initial={{ opacity: 0 }} animate={{ opacity: 1 }} x="248" y="250" width="44" height="30" rx="6" fill={beyond} />
      )}
    </g>
  );
}

export default function ControllerCanvas({ model = "dualsense", side = "front", colors = {}, overlays = [], className = "" }) {
  const hasPaddles = !!colors.paddles || overlays.some((o) => o.region === "paddles");
  const hasBeyond = !!colors.beyond;
  return (
    <div className={`relative ${className}`}>
      <motion.div key={side} initial={{ opacity: 0, rotateY: side === "back" ? -18 : 18 }}
        animate={{ opacity: 1, rotateY: 0 }} transition={{ duration: 0.45, ease: [0.16, 1, 0.3, 1] }}>
        <svg viewBox="0 0 540 400" className="w-full" role="img" aria-label={`${model} ${side}`}>
          <defs>
            <filter id="cc-shadow" x="-20%" y="-20%" width="140%" height="140%">
              <feDropShadow dx="0" dy="10" stdDeviation="14" floodColor="#000" floodOpacity="0.5" />
            </filter>
            <radialGradient id="cc-gloss" cx="50%" cy="28%" r="75%">
              <stop offset="0%" stopColor="#fff" stopOpacity="0.16" />
              <stop offset="70%" stopColor="#fff" stopOpacity="0.02" />
              <stop offset="100%" stopColor="#000" stopOpacity="0.14" />
            </radialGradient>
          </defs>
          <g filter="url(#cc-shadow)">
            {side === "front"
              ? <Front c={colors} model={model} />
              : <Back c={colors} model={model} hasPaddles={hasPaddles} hasBeyond={hasBeyond} />}
            <path d="M214 96 C176 96 150 128 150 186 C150 236 176 286 214 296 C246 304 258 276 270 276 C282 276 294 304 326 296 C364 286 390 236 390 186 C390 128 364 96 326 96 Z" fill="url(#cc-gloss)" pointerEvents="none" />
          </g>
        </svg>
      </motion.div>
      {/* uploaded transparent overlays (future photoreal layers) */}
      {overlays.filter((o) => (o.layer?.side || "front") === side && o.url).map((o) => {
        const l = o.layer || {};
        return (
          <img key={o.id} src={mediaUrl(o.url)} alt="" loading="lazy"
            className="pointer-events-none absolute"
            style={{
              left: `${l.x ?? 0}%`, top: `${l.y ?? 0}%`,
              width: `${l.scale ? l.scale * 100 : 40}%`,
              transform: `rotate(${l.rotation || 0}deg)`, zIndex: l.z || 5,
            }} />
        );
      })}
    </div>
  );
}
