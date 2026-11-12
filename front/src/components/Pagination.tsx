interface Props {
  page: number;
  nbPages: number;
  onChange: (page: number) => void;
}

export default function Pagination({ page, nbPages, onChange }: Props) {
  if (nbPages <= 1) return null;
  return (
    <nav className="pagination" aria-label="Pagination">
      <button type="button" className="btn ghost" disabled={page <= 1} onClick={() => onChange(page - 1)}>
        ← Précédente
      </button>
      <span className="muted">
        Page {page} / {nbPages}
      </span>
      <button type="button" className="btn ghost" disabled={page >= nbPages} onClick={() => onChange(page + 1)}>
        Suivante →
      </button>
    </nav>
  );
}
