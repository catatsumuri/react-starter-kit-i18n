import { Head, setLayoutProps } from '@inertiajs/react';
import { lang } from '@erag/lang-sync-inertia/react';
import Heading from '@/components/heading';
import { edit, index, show } from '@/routes/admin/users';
import UserForm from './user-form';

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    is_admin: boolean;
    can_delete: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export default function AdminUsersEdit({ user }: { user: ManagedUser }) {
    const { __ } = lang();

    setLayoutProps({
        breadcrumbs: [
            {
                title: __('User management'),
                href: index(),
            },
            {
                title: user.name,
                href: show(user.id),
            },
            {
                title: __('Edit user'),
                href: edit(user.id),
            },
        ],
    });

    return (
        <>
            <Head title={__('Edit user')} />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
                <Heading
                    title={__('Edit user')}
                    description={__('Update account details and admin access.')}
                />

                <UserForm user={user} />
            </div>
        </>
    );
}
