// Client-side compatibility checks based on option specs.
// Returns an array of { level: 'error'|'warning', message }.
export function checkPCCompatibility(sel) {
  const issues = [];
  const cpu = sel.cpu, mb = sel.mainboard, ram = sel.ram, cooler = sel.cooler,
    gpu = sel.gpu, psu = sel.psu, kase = sel.case;

  const spec = (o) => o?.specs || {};

  // CPU socket <-> Mainboard socket
  if (cpu && mb && spec(cpu).socket && spec(mb).socket && spec(cpu).socket !== spec(mb).socket) {
    issues.push({ level: "error", message: `CPU-Sockel (${spec(cpu).socket}) passt nicht zum Mainboard (${spec(mb).socket}).` });
  }
  // Mainboard RAM type <-> RAM
  if (mb && ram && spec(mb).ram_type && spec(ram).ram_type && spec(mb).ram_type !== spec(ram).ram_type) {
    issues.push({ level: "error", message: `RAM-Typ (${spec(ram).ram_type}) passt nicht zum Mainboard (${spec(mb).ram_type}).` });
  }
  // Cooler socket support
  if (cooler && cpu && Array.isArray(spec(cooler).socket) && spec(cpu).socket && !spec(cooler).socket.includes(spec(cpu).socket)) {
    issues.push({ level: "error", message: `CPU-Kühler unterstützt den Sockel ${spec(cpu).socket} nicht.` });
  }
  // Case form factor <-> Mainboard
  if (kase && mb && Array.isArray(spec(kase).form_factors) && spec(mb).form_factor && !spec(kase).form_factors.includes(spec(mb).form_factor)) {
    issues.push({ level: "error", message: `Gehäuse unterstützt den Mainboard-Formfaktor ${spec(mb).form_factor} nicht.` });
  }
  // GPU length <-> case
  if (gpu && kase && spec(gpu).length_mm && spec(kase).max_gpu_mm && spec(gpu).length_mm > spec(kase).max_gpu_mm) {
    issues.push({ level: "error", message: `Grafikkarte (${spec(gpu).length_mm} mm) ist länger als das Gehäuse erlaubt (${spec(kase).max_gpu_mm} mm).` });
  }
  // Cooler clearance
  if (cooler && kase) {
    if (spec(cooler).type === "air" && spec(cooler).height_mm && spec(kase).max_cooler_mm && spec(cooler).height_mm > spec(kase).max_cooler_mm) {
      issues.push({ level: "error", message: `Luftkühler (${spec(cooler).height_mm} mm) ist höher als im Gehäuse möglich (${spec(kase).max_cooler_mm} mm).` });
    }
    if (spec(cooler).type === "aio" && spec(cooler).radiator_mm && spec(kase).max_radiator_mm && spec(cooler).radiator_mm > spec(kase).max_radiator_mm) {
      issues.push({ level: "warning", message: `Radiator (${spec(cooler).radiator_mm} mm) ggf. zu groß für das Gehäuse (max. ${spec(kase).max_radiator_mm} mm) – bitte prüfen.` });
    }
  }
  // PSU wattage
  if (psu && spec(psu).wattage) {
    let recommended = 0;
    if (gpu && spec(gpu).recommended_psu_w) recommended = Math.max(recommended, spec(gpu).recommended_psu_w);
    const tdpSum = (spec(cpu).tdp || 0) + (spec(gpu).tdp || 0);
    if (tdpSum) recommended = Math.max(recommended, Math.round(tdpSum * 1.6));
    if (recommended && spec(psu).wattage < recommended) {
      issues.push({ level: "warning", message: `Netzteil (${spec(psu).wattage}W) könnte für die gewählte Hardware knapp sein (empfohlen ~${recommended}W).` });
    }
  }
  return issues;
}
