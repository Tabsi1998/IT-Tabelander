import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router";
import { Field } from "../../forms/fields.jsx";
import { useAdminData } from "../api.js";
import QrCode from "../../components/QrCode.jsx";
import { Chips, LoadState, PageHeader, Section } from "../ui.jsx";

const FORMATS = [["roll", "Etikettendrucker 62 × 29 mm"], ["sheet", "Normales Blatt (A4)"]];
const FORMAT_KEY = "it-tabelander.label-format";

function dateText(value) {
  const date = value ? new Date(value) : null;
  return date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString("de-AT") : "";
}

function savedFormat() {
  try {
    return window.localStorage.getItem(FORMAT_KEY) === "sheet" ? "sheet" : "roll";
  } catch {
    return "roll";
  }
}

/** The paper size for printing: a small stylesheet of its own while this page
 *  is open, so no other page ever prints on a label. */
function usePrintFormat(format) {
  useEffect(() => {
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = `/print/label-${format}.css`;
    document.head.appendChild(link);
    return () => link.remove();
  }, [format]);
}

/** 62 × 29 mm, black on white whatever the screen's colours. No name: the
 *  label sits on the device in the shop; the number leads to the customer. */
function DeviceLabel({ label, format }) {
  return (
    <div className={`flex h-[29mm] w-[62mm] shrink-0 items-center gap-[2mm] overflow-hidden bg-white p-[1.5mm] text-black ${
      format === "sheet" ? "border border-dashed border-black" : "border border-line print:border-0"}`}>
      <QrCode text={label.status_url} title={`QR-Code zur Status-Seite von ${label.ref}`} className="h-[26mm] w-[26mm] shrink-0" />
      <div className="flex min-w-0 flex-col gap-[0.8mm] leading-tight">
        <b className="whitespace-nowrap text-[11pt]">{label.ref}</b>
        <span className="line-clamp-2 text-[7.5pt] font-semibold">{label.title}</span>
        <span className="text-[6.5pt]">{[label.ticket_ref, dateText(label.created_at)].filter(Boolean).join(" · ")}</span>
        <span className="text-[6.5pt]">Stand: QR-Code scannen</span>
      </div>
    </div>
  );
}

function LabelView({ reference }) {
  const state = useAdminData(`/admin/labels/${encodeURIComponent(reference)}`);
  const [format, setFormat] = useState(savedFormat);
  usePrintFormat(format);

  const choose = (next) => {
    setFormat(next);
    try {
      window.localStorage.setItem(FORMAT_KEY, next);
    } catch {
      // only a convenience
    }
  };

  return (
    <>
      <div className="print:hidden">
        <PageHeader title="Etikett drucken"
          description="Für das angenommene Gerät: Nummer, Gerät, Datum und ein QR-Code zur Status-Seite. Der Kunde scannt ihn und sieht den Stand." />
      </div>
      <LoadState state={state}>
        {(label) => (
          <>
            <div className="print:hidden">
              <Section title="Drucker">
                <Chips label="Drucker" options={FORMATS} value={format} onChange={choose} />
                <p className="m-0 text-[15px] text-body">
                  {format === "roll"
                    ? "Für Etikettendrucker mit 62 mm breiten Etiketten (z. B. Brother DK-11209). Im Druckfenster das Etikettenformat wählen, Ränder: keine."
                    : "Das Etikett kommt oben links auf ein normales Blatt; ausschneiden und aufkleben. Im Druckfenster „Tatsächliche Größe“ wählen."}
                </p>
                <button type="button" onClick={() => window.print()} className="btn-primary self-start">Drucken</button>
              </Section>
            </div>
            <div className="flex flex-col gap-2 print:block">
              <span className="text-[15px] font-semibold print:hidden">Vorschau in Originalgröße</span>
              <DeviceLabel label={label} format={format} />
            </div>
            <p className="m-0 text-sm text-muted print:hidden">
              Kein Name auf dem Etikett: Es klebt sichtbar am Gerät. Über die Nummer findest du den Kunden in Dolibarr.
              {" "}<Link to="/admin/etikett" className="underline">Anderes Etikett</Link>
            </p>
          </>
        )}
      </LoadState>
    </>
  );
}

function LabelLookup() {
  const navigate = useNavigate();
  const [value, setValue] = useState("");
  const open = (event) => {
    event.preventDefault();
    const wanted = value.trim();
    if (wanted) navigate(`/admin/etikett/${encodeURIComponent(wanted)}`);
  };
  return (
    <>
      <PageHeader title="Etikett drucken" description="Für ein angenommenes Gerät: die Anfrage-Nummer (ANF-…) oder die Ticket-Nummer aus Dolibarr eingeben." />
      <Section title="Welche Anfrage?">
        <form onSubmit={open} className="flex max-w-[520px] flex-col gap-3 sm:flex-row sm:items-end">
          <Field label="Anfrage- oder Ticket-Nummer" className="flex-1" placeholder="ANF-7K3M9Q2X" value={value}
            onChange={(event) => setValue(event.target.value)} />
          <button type="submit" className="btn-primary">Etikett zeigen</button>
        </form>
      </Section>
    </>
  );
}

/** The device label (#72), from the overview's newest inquiries or by number. */
export default function Label() {
  const { ref } = useParams();
  return ref ? <LabelView key={ref} reference={ref} /> : <LabelLookup />;
}
