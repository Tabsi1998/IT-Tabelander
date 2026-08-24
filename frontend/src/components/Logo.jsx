import React from "react";
import { useTheme } from "../context/ThemeContext";

// Banner = wordmark (icon + text), Mark = shield icon only.
// "light" asset = white version for dark backgrounds.
// "dark" asset = navy/orange version for light backgrounds.
const ASSETS = {
  bannerDark: "/assets/img/logo/banner-light.png", // used in dark theme
  bannerLight: "/assets/img/logo/banner-dark.png", // used in light theme
  markDark: "/assets/img/logo/logo-light.png",
  markLight: "/assets/img/logo/logo-dark.png",
};

export function Logo({ variant = "banner", className = "h-9", onDark }) {
  const { isDark } = useTheme();
  // allow forcing a background context (e.g. footer is always dark)
  const dark = onDark != null ? onDark : isDark;
  let src;
  if (variant === "mark") src = dark ? ASSETS.markDark : ASSETS.markLight;
  else src = dark ? ASSETS.bannerDark : ASSETS.bannerLight;
  return <img src={src} alt="IT-Tabelander" className={className} loading="eager" />;
}

export default Logo;
