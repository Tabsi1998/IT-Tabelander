import React from "react";
import { useTheme } from "../context/ThemeContext";
import { useSettings } from "../context/SettingsContext";
import { mediaUrl } from "../lib/api";

const DEFAULTS = {
  bannerDark: "/assets/img/logo/banner-light.png", // dark theme -> white banner
  bannerLight: "/assets/img/logo/banner-dark.png", // light theme -> navy banner
  markDark: "/assets/img/logo/logo-light.png",
  markLight: "/assets/img/logo/logo-dark.png",
};

export function Logo({ variant = "banner", className = "h-9", onDark }) {
  const { isDark } = useTheme();
  const { settings } = useSettings();
  const dark = onDark != null ? onDark : isDark;
  // admin-uploaded overrides (logo_dark_url shown on dark bg, logo_light_url on light bg)
  const override = dark ? settings.logo_dark_url : settings.logo_light_url;
  let src;
  if (override) src = mediaUrl(override);
  else if (variant === "mark") src = dark ? DEFAULTS.markDark : DEFAULTS.markLight;
  else src = dark ? DEFAULTS.bannerDark : DEFAULTS.bannerLight;
  return <img src={src} alt="IT-Tabelander" className={className} loading="eager" />;
}

export default Logo;
