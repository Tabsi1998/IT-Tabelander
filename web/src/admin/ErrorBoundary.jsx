import { Component } from "react";

/** A mistake in one admin page shows a message, never a white screen (#57). */
export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    console.error("Admin page failed", error, info?.componentStack);
  }

  render() {
    if (!this.state.error) return this.props.children;
    return (
      <div role="alert" className="card flex max-w-[640px] flex-col gap-3 p-6">
        <h1 className="m-0 text-2xl font-bold">Diese Seite konnte nicht angezeigt werden.</h1>
        <p className="m-0 text-body">
          Deine gespeicherten Daten sind davon nicht betroffen. Lade die Seite neu; wenn es wieder passiert,
          schick mir bitte diese Meldung:
        </p>
        <pre className="m-0 overflow-x-auto whitespace-pre-wrap rounded-lg bg-page p-3 text-sm">{String(this.state.error?.message || this.state.error)}</pre>
        <button type="button" className="btn-primary self-start" onClick={() => window.location.reload()}>Seite neu laden</button>
      </div>
    );
  }
}
