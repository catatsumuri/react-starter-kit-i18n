import type { Auth, NotificationFeed } from '@/types/auth';

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
            notifications: NotificationFeed;
            sidebarOpen: boolean;
            lang: Record<string, string>;
            [key: string]: unknown;
        };
    }
}
