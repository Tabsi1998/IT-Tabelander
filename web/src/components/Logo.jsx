/** The banner logo. "auto" follows the colour scheme; "on-navy" is for the
 *  dark footer and menu areas. Files: web/public/brand (#54 dropped the big PNGs). */
export default function Logo({ variant = "auto", className = "h-[34px] w-auto", alt = "IT-Tabelander" }) {
  const onLight = "/brand/banner-on-light.webp";
  const onDark = "/brand/banner-on-dark.webp";
  if (variant === "on-navy") {
    return <img src={onDark} alt={alt} width="651" height="136" className={className} />;
  }
  return (
    <picture>
      <source srcSet={onDark} media="(prefers-color-scheme: dark)" />
      <img src={onLight} alt={alt} width="705" height="136" className={className} />
    </picture>
  );
}
