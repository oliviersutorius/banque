import { useEffect, useRef, type FormEvent, type ReactNode } from 'react';

interface Props {
  titre: string;
  children: ReactNode;
  /** Libellé du bouton principal ; absent : pas de bouton principal */
  valider?: string;
  danger?: boolean;
  desactive?: boolean;
  erreur?: string | null;
  onValider?: () => void;
  onFermer: () => void;
}

/** Fenêtre modale (élément <dialog>) avec un formulaire : Entrée valide, Échap ferme. */
export default function Modal({ titre, children, valider, danger, desactive, erreur, onValider, onFermer }: Props) {
  const dialog = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    dialog.current?.showModal();
  }, []);

  function soumettre(e: FormEvent) {
    e.preventDefault();
    if (!desactive) onValider?.();
  }

  return (
    <dialog ref={dialog} className="dialog" onCancel={onFermer}>
      <form onSubmit={soumettre}>
        <h2>{titre}</h2>
        {children}
        {erreur && <p className="error">{erreur}</p>}
        <div className="dialog-actions">
          <button type="button" className="btn ghost" onClick={onFermer}>
            Annuler
          </button>
          {valider && (
            <button type="submit" className={'btn' + (danger ? ' danger' : '')} disabled={desactive}>
              {valider}
            </button>
          )}
        </div>
      </form>
    </dialog>
  );
}
