// The steps of an inquiry (#43, #78) in the customer's words; since #122 only
// the customer area shows them. `bar` is the place on the timeline (0: none).
export const STEPS = {
  eingegangen: { label: "Eingegangen", text: "Deine Anfrage ist da. Ich melde mich mit einer ersten Einschätzung.", bar: 1 },
  angebot_bereit: { label: "Angebot bereit", text: "Dein Angebot ist fertig. Du findest es unter Angebote; ohne deine Zusage passiert nichts.", bar: 2, badge: true },
  in_arbeit: { label: "In Arbeit", text: "Ich arbeite an deinem Gerät.", bar: 3 },
  wartet_auf_dich: { label: "Wartet auf dich", text: "Ich brauche noch eine Information von dir. Antworte einfach hier unten oder auf meine E-Mail.", bar: 2, badge: true },
  pausiert: { label: "Pausiert", text: "Kurz pausiert, zum Beispiel weil ein Ersatzteil unterwegs ist.", bar: 3 },
  abholbereit: { label: "Abholbereit", text: "Dein Gerät ist fertig und kann abgeholt werden.", bar: 4, badge: true },
  abgeschlossen: { label: "Abgeschlossen", text: "Erledigt. Danke für dein Vertrauen!", bar: 5 },
  abgebrochen: { label: "Beendet", text: "Diese Anfrage ist beendet. Hast du noch etwas? Dann starte einfach eine neue.", bar: 0 },
};

/** The five places of the timeline; the step decides how the current one is called. */
export const TIMELINE = ["Eingegangen", "Angebot oder Rückfrage", "In Arbeit", "Fertig", "Abgeschlossen"];

export function stepOf(item) {
  return STEPS[item?.step] || STEPS.eingegangen;
}

/** Badge tone for a step: needs you, done, or plain. */
export function stepTone(item) {
  if (stepOf(item).badge) return "warn";
  return item?.step === "abgeschlossen" ? "good" : "plain";
}
