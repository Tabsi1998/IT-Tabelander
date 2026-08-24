export const EUR = (n) => {
  if (n == null || isNaN(n)) return "–";
  return new Intl.NumberFormat("de-AT", { style: "currency", currency: "EUR" }).format(n);
};

export const cn = (...classes) => classes.filter(Boolean).join(" ");
