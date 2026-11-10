interface Props {
  title: string;
  step: number;
}

/** Page provisoire, remplacée au fil des étapes. */
export default function Placeholder({ title, step }: Props) {
  return (
    <section className="card empty">
      <h1>{title}</h1>
      <p className="muted">Cette page arrive à l'étape {step} 🐣</p>
    </section>
  );
}
