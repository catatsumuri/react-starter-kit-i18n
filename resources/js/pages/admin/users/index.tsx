import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { lang } from '@erag/lang-sync-inertia/react';
import {
    Eye,
    Pencil,
    ShieldCheck,
    Trash2,
    UserRoundPlus,
    UsersRound,
} from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { create, destroy, edit, index, show } from '@/routes/admin/users';

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    created_at: string | null;
    can_delete: boolean;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedUsers = {
    data: ManagedUser[];
    links: PaginationLink[];
    from: number | null;
    to: number | null;
    total: number;
};

export default function AdminUsersIndex({ users }: { users: PaginatedUsers }) {
    const { __ } = lang();

    setLayoutProps({
        breadcrumbs: [
            {
                title: __('User management'),
                href: index(),
            },
        ],
    });

    return (
        <>
            <Head title={__('User management')} />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title={__('User management')}
                        description={__('Create, edit, and remove users.')}
                    />

                    <Button asChild>
                        <Link href={create()}>
                            <UserRoundPlus />
                            {__('Create user')}
                        </Link>
                    </Button>
                </div>

                <section className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card text-card-foreground dark:border-sidebar-border">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-sm">
                            <thead className="border-b bg-muted/40 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-3 font-medium">
                                        {__('Name')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {__('Email address')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {__('Roles')}
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        {__('Actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.data.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="border-b last:border-b-0"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            <Link
                                                href={show(user.id)}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {user.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {user.email}
                                        </td>
                                        <td className="px-4 py-3">
                                            {user.roles.length > 0 ? (
                                                <div className="flex flex-wrap gap-1.5">
                                                    {user.roles.map((role) => (
                                                        <Badge
                                                            key={role}
                                                            variant="secondary"
                                                            className="gap-1.5"
                                                        >
                                                            <ShieldCheck className="size-3" />
                                                            {role}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {__('None')}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link href={show(user.id)}>
                                                        <Eye />
                                                        {__('View')}
                                                    </Link>
                                                </Button>

                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link href={edit(user.id)}>
                                                        <Pencil />
                                                        {__('Edit')}
                                                    </Link>
                                                </Button>

                                                <Dialog>
                                                    <DialogTrigger asChild>
                                                        <Button
                                                            variant="destructive"
                                                            size="sm"
                                                            disabled={
                                                                !user.can_delete
                                                            }
                                                        >
                                                            <Trash2 />
                                                            {__('Delete')}
                                                        </Button>
                                                    </DialogTrigger>
                                                    <DialogContent>
                                                        <DialogTitle className="flex items-center gap-2">
                                                            <UsersRound className="size-5 text-muted-foreground" />
                                                            <span>
                                                                {__(
                                                                    'Delete user?',
                                                                )}
                                                            </span>
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            {__(
                                                                'This user account will be permanently deleted.',
                                                            )}
                                                        </DialogDescription>

                                                        <Form
                                                            {...destroy.form(
                                                                user.id,
                                                            )}
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <DialogFooter className="gap-2">
                                                                    <DialogClose
                                                                        asChild
                                                                    >
                                                                        <Button variant="secondary">
                                                                            {__(
                                                                                'Cancel',
                                                                            )}
                                                                        </Button>
                                                                    </DialogClose>

                                                                    <Button
                                                                        variant="destructive"
                                                                        disabled={
                                                                            processing
                                                                        }
                                                                        asChild
                                                                    >
                                                                        <button type="submit">
                                                                            <Trash2 />
                                                                            {__(
                                                                                'Delete',
                                                                            )}
                                                                        </button>
                                                                    </Button>
                                                                </DialogFooter>
                                                            )}
                                                        </Form>
                                                    </DialogContent>
                                                </Dialog>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-muted-foreground">
                            {users.from && users.to
                                ? `${users.from}-${users.to} / ${users.total}`
                                : `0 / ${users.total}`}
                        </p>

                        <div className="flex flex-wrap gap-2">
                            {users.links.map((link) => (
                                <Button
                                    key={`${link.label}-${link.url}`}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    disabled={link.url === null}
                                    asChild={link.url !== null}
                                >
                                    {link.url ? (
                                        <Link
                                            href={link.url}
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
                                    ) : (
                                        <span
                                            dangerouslySetInnerHTML={{
                                                __html: link.label,
                                            }}
                                        />
                                    )}
                                </Button>
                            ))}
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
