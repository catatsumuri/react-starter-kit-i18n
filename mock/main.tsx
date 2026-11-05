import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import Welcome from '../resources/js/pages/welcome';
import '../resources/css/app.css';

// Render the actual Inertia page with preview-only route lookup.
const pathname = window.location.pathname.replace(/\/+$/, '') || '/';

function PreviewApp() {
    if (pathname === '/') return <Welcome />;

    return (
        <main className="flex min-h-screen flex-col items-center justify-center gap-5 bg-[#0a0a0a] px-6 text-center text-white">
            <p className="text-sm tracking-[0.2em] text-[#f53003] uppercase">
                404 / Preview
            </p>
            <h1 className="text-4xl font-bold">Page not found.</h1>
            <a className="underline" href="/">
                Return home
            </a>
        </main>
    );
}

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <PreviewApp />
    </StrictMode>,
);
