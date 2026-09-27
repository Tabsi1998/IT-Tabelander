import { useEffect, useState } from "react";
import { getJson } from "./api.js";

/** Load one API address after the first render. Server and browser start
 *  with the same empty state, so prerendered pages hydrate cleanly. */
export function useApi(path) {
  const [state, setState] = useState({ data: null, error: null, loading: Boolean(path) });

  useEffect(() => {
    if (!path) return undefined;
    const controller = new AbortController();
    getJson(path, { signal: controller.signal })
      .then((data) => setState({ data, error: null, loading: false }))
      .catch((error) => {
        if (error.name !== "AbortError") setState({ data: null, error, loading: false });
      });
    return () => controller.abort();
  }, [path]);

  return state;
}
