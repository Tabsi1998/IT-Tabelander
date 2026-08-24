import React from "react";

// Stylized DualSense controller. Each region's fill is driven by props so the
// configurator can update it live.
export default function ControllerSVG({ colors = {}, className = "" }) {
  const shell = colors.shell_front || "#1A1D22";
  const back = colors.shell_back || "#111317";
  const buttons = colors.buttons || "#E8EAED";
  const dpad = colors.dpad || "#22262B";
  const sticks = colors.sticks || "#22262B";
  const triggers = colors.triggers || "#2B2F36";
  const special = colors.special || "#3A3F46";

  const isLight = (hex) => {
    if (!hex) return false;
    const c = hex.replace("#", "");
    const r = parseInt(c.substr(0, 2), 16), g = parseInt(c.substr(2, 2), 16), b = parseInt(c.substr(4, 2), 16);
    return (r * 299 + g * 587 + b * 114) / 1000 > 150;
  };
  const btnGlyph = isLight(buttons) ? "#22262B" : "#E8EAED";

  return (
    <svg viewBox="0 0 420 340" className={className} role="img" aria-label="DualSense Controller Vorschau">
      <defs>
        <filter id="soft" x="-20%" y="-20%" width="140%" height="140%">
          <feDropShadow dx="0" dy="8" stdDeviation="12" floodColor="#000" floodOpacity="0.45" />
        </filter>
        <radialGradient id="glossy" cx="50%" cy="30%" r="75%">
          <stop offset="0%" stopColor="#ffffff" stopOpacity="0.18" />
          <stop offset="60%" stopColor="#ffffff" stopOpacity="0.02" />
          <stop offset="100%" stopColor="#000000" stopOpacity="0.12" />
        </radialGradient>
      </defs>

      {/* triggers (top) */}
      <rect x="72" y="24" width="86" height="34" rx="17" fill={triggers} />
      <rect x="262" y="24" width="86" height="34" rx="17" fill={triggers} />

      <g filter="url(#soft)">
        {/* back grips */}
        <path d="M96 150 C60 150 40 230 58 300 C74 340 128 320 140 270 L150 200 Z" fill={back} />
        <path d="M324 150 C360 150 380 230 362 300 C346 340 292 320 280 270 L270 200 Z" fill={back} />

        {/* main body */}
        <path
          d="M150 96 C120 96 96 120 96 168 C96 210 118 250 150 258 C176 264 190 240 210 240 C230 240 244 264 270 258 C302 250 324 210 324 168 C324 120 300 96 270 96 Z"
          fill={shell}
        />
        <path
          d="M150 96 C120 96 96 120 96 168 C96 210 118 250 150 258 C176 264 190 240 210 240 C230 240 244 264 270 258 C302 250 324 210 324 168 C324 120 300 96 270 96 Z"
          fill="url(#glossy)"
        />

        {/* touchpad / special accent */}
        <rect x="176" y="112" width="68" height="46" rx="10" fill={special} opacity="0.9" />

        {/* D-Pad */}
        <g fill={dpad}>
          <rect x="126" y="150" width="16" height="44" rx="4" />
          <rect x="112" y="164" width="44" height="16" rx="4" />
        </g>

        {/* action buttons */}
        <g>
          <circle cx="290" cy="172" r="12" fill={buttons} />
          <circle cx="266" cy="150" r="12" fill={buttons} />
          <circle cx="266" cy="194" r="12" fill={buttons} />
          <circle cx="242" cy="172" r="12" fill={buttons} />
          {/* glyphs */}
          <text x="290" y="177" fontSize="12" fill={btnGlyph} textAnchor="middle" fontFamily="monospace">○</text>
          <text x="266" y="155" fontSize="11" fill={btnGlyph} textAnchor="middle" fontFamily="monospace">△</text>
          <text x="266" y="199" fontSize="11" fill={btnGlyph} textAnchor="middle" fontFamily="monospace">✕</text>
          <text x="242" y="177" fontSize="11" fill={btnGlyph} textAnchor="middle" fontFamily="monospace">□</text>
        </g>

        {/* thumbsticks */}
        <g>
          <circle cx="170" cy="210" r="22" fill="#0c0e11" />
          <circle cx="170" cy="210" r="16" fill={sticks} />
          <circle cx="250" cy="210" r="22" fill="#0c0e11" />
          <circle cx="250" cy="210" r="16" fill={sticks} />
        </g>
      </g>
    </svg>
  );
}
