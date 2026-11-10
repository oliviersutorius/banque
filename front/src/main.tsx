import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import '@fontsource-variable/dm-sans';
import '@fontsource-variable/fraunces';
import '@fontsource-variable/nunito';
import '@fontsource-variable/quicksand';
import App from './App';
import './styles.css';

const root = document.getElementById('root');
if (!root) {
  throw new Error('Élément #root introuvable');
}

createRoot(root).render(
  <StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
