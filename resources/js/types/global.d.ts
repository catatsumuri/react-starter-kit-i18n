import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            lang: Record<string, string>;
            locale: string;
            localeName: string;
            locales: Record<string, string>;
            [key: string]: unknown;
        };
    }
}
