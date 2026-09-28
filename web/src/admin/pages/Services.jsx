import { useState } from "react";
import { Link } from "react-router";
import { adminRequest, errorText, useAdminData } from "../api.js";
import { LoadState, Notice, PageHeader, Toggle } from "../ui.jsx";

/** The services as tabs on the website, in this order (#58). */
export default function Services() {
  const state = useAdminData("/admin/services");
  const [error, setError] = useState("");

  const save = async (change, request) => {
    setError("");
    const before = state.data;
    state.setData(change);
    try {
      await request();
    } catch (failure) {
      state.setData(before);
      setError(errorText(failure));
    }
  };

  const move = (index, step) => {
    const list = [...state.data];
    const [item] = list.splice(index, 1);
    list.splice(index + step, 0, item);
    save(list, () => adminRequest("POST", "/admin/services/order", { ids: list.map((entry) => entry.id) }));
  };

  const toggle = (service) => save(
    state.data.map((item) => (item.id === service.id ? { ...item, active: !item.active } : item)),
    () => adminRequest("PUT", `/admin/services/${service.id}`, { title: service.title, active: !service.active }),
  );

  return (
    <>
      <PageHeader title="Leistungen" description="Jede Leistung ist ein Tab auf der Website. Keine Preise – jede Anfrage bekommt ein eigenes Angebot."
        actions={<Link to="/admin/leistungen/neu" className="btn-primary">Neue Leistung</Link>} />
      <Notice tone="error">{error}</Notice>
      <LoadState state={state}>
        {(services) => (services.length === 0 ? (
          <p className="card m-0 p-5 text-body">Noch keine Leistungen. Solange zeigt die Website die Texte aus dem Entwurf.</p>
        ) : (
          <ol className="m-0 flex list-none flex-col gap-3 p-0">
            {services.map((service, index) => (
              <li key={service.id} className="card flex flex-col gap-3 p-4 md:flex-row md:items-center md:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                  <span className="font-display text-lg font-bold text-muted" aria-hidden="true">{index + 1}</span>
                  <div className="min-w-0">
                    <b className="block truncate font-display text-lg">{service.title}</b>
                    <span className="text-sm text-muted">{service.active ? "auf der Website" : "versteckt"} · /leistungen/{service.slug}</span>
                  </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <Toggle checked={service.active} onChange={() => toggle(service)} label={`${service.title} auf der Website zeigen`} hideLabel />
                  <button type="button" className="btn-outline min-h-[44px] border-line px-3" disabled={index === 0}
                    aria-label={`${service.title} nach oben`} onClick={() => move(index, -1)}>↑</button>
                  <button type="button" className="btn-outline min-h-[44px] border-line px-3" disabled={index === services.length - 1}
                    aria-label={`${service.title} nach unten`} onClick={() => move(index, 1)}>↓</button>
                  <a href={`/leistungen/${service.slug}`} target="_blank" rel="noopener noreferrer" className="btn-outline min-h-[44px] border-line px-4 text-[15px]"
                    aria-label={`Vorschau von ${service.title}`}>Vorschau ↗</a>
                  <Link to={`/admin/leistungen/${service.id}`} className="btn-primary min-h-[44px] px-4 text-[15px]"
                    aria-label={`${service.title} bearbeiten`}>Bearbeiten</Link>
                </div>
              </li>
            ))}
          </ol>
        ))}
      </LoadState>
    </>
  );
}
