import { Head, setLayoutProps } from '@inertiajs/react';
import { dashboard } from '@/routes/admin';

export default function AdminDashboard() {
    setLayoutProps({
        breadcrumbs: [{ title: 'WIP', href: dashboard() }],
    });

    return (
        <>
            <Head title="WIP" />
            <p>WIP</p>
        </>
    );
}
