import { createContext, useContext } from "react";

/** Buttons anywhere on the page open a tab of the contact area (#49):
 *  "nachricht", "anfrage" or "status", optionally with a preset. */
export const ContactContext = createContext({ openContact: () => {} });

export function useContact() {
  return useContext(ContactContext);
}

/** A link to the contact area that also picks the tab; works without JavaScript. */
export function ContactLink({ tab, preset, className, children, onNavigate }) {
  const { openContact } = useContact();
  return (
    <a
      href="/#kontakt"
      className={className}
      onClick={(event) => {
        event.preventDefault();
        onNavigate?.();
        openContact(tab, preset);
      }}
    >
      {children}
    </a>
  );
}
