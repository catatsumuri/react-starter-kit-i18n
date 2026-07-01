import { Head, setLayoutProps } from '@inertiajs/react';
import { lang } from '@erag/lang-sync-inertia/react';
import Heading from '@/components/heading';
import { create, index } from '@/routes/admin/users';
import UserForm from './user-form';

export default function AdminUsersCreate() {
    const { __ } = lang();

    setLayoutProps({
        breadcrumbs: [
            {
                title: __('User management'),
                href: index(),
            },
            {
                title: __('Create user'),
                href: create(),
            },
        ],
    });

    return (
        <>
            <Head title={__('Create user')} />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
                <Heading
                    title={__('Create user')}
                    description={__(
                        'Add a user account and optional admin role.',
                    )}
                />

                <UserForm />
            </div>
        </>
    );
}
