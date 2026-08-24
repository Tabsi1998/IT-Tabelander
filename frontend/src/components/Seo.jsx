import React from "react";
import { Helmet } from "react-helmet-async";
import { useSettings } from "../context/SettingsContext";

const CANONICAL_BASE = "https://it.tabelander.co.at";

export function Seo({ title, description, path = "", jsonLd, image }) {
  const { settings } = useSettings();
  const fullTitle = title
    ? `${title} | IT-Tabelander`
    : settings.seo_default_title || "IT-Tabelander";
  const desc = description || settings.seo_default_description || "";
  const canonical = `${CANONICAL_BASE}${path}`;

  return (
    <Helmet>
      <title>{fullTitle}</title>
      <meta name="description" content={desc} />
      <link rel="canonical" href={canonical} />
      <meta property="og:type" content="website" />
      <meta property="og:title" content={fullTitle} />
      <meta property="og:description" content={desc} />
      <meta property="og:url" content={canonical} />
      <meta property="og:site_name" content="IT-Tabelander" />
      {image && <meta property="og:image" content={image} />}
      <meta name="twitter:card" content="summary_large_image" />
      <meta name="twitter:title" content={fullTitle} />
      <meta name="twitter:description" content={desc} />
      {jsonLd &&
        (Array.isArray(jsonLd) ? jsonLd : [jsonLd]).map((obj, i) => (
          <script key={i} type="application/ld+json">
            {JSON.stringify(obj)}
          </script>
        ))}
    </Helmet>
  );
}

export function orgJsonLd(settings) {
  const ld = {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    name: "IT-Tabelander",
    description: settings?.seo_default_description || "IT-Service, Reparatur & Gaming-Hardware",
    url: CANONICAL_BASE,
    areaServed: settings?.service_area || "Tirol",
  };
  if (settings?.email) ld.email = settings.email;
  if (settings?.phone) ld.telephone = settings.phone;
  if (settings?.address || settings?.city) {
    ld.address = {
      "@type": "PostalAddress",
      streetAddress: settings?.address || undefined,
      addressLocality: settings?.city || undefined,
      postalCode: settings?.postal_code || undefined,
      addressRegion: settings?.region || undefined,
      addressCountry: settings?.country || "AT",
    };
  }
  return ld;
}

export function breadcrumbJsonLd(items) {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: items.map((it, i) => ({
      "@type": "ListItem",
      position: i + 1,
      name: it.name,
      item: `${CANONICAL_BASE}${it.path}`,
    })),
  };
}

export default Seo;
