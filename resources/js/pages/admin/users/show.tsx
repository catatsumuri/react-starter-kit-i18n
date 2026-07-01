import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { lang } from '@erag/lang-sync-inertia/react';
import type React from 'react';
import {
    ArrowLeft,
    CalendarClock,
    Mail,
    Pencil,
    ShieldCheck,
    Trash2,
    UserRound,
} from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { destroy, edit, index, show } from '@/routes/admin/users';

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

type DetailItemProps = {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    children: React.ReactNode;
};

function formatDate(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function DetailItem({ icon: Icon, label, children }: DetailItemProps) {
    return (
        <div className="flex gap-3">
            <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-muted">
                <Icon className="size-4 text-muted-foreground" />
            </div>
            <div className="min-w-0 space-y-1">
                <dt className="text-sm font-medium text-muted-foreground">
                    {label}
                </dt>
                <dd className="text-sm break-words">{children}</dd>
            </div>
        </div>
    );
}

export default function AdminUsersShow({ user }: { user: ManagedUser }) {
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
        ],
    });

    return (
        <>
            <Head title={user.name} />

            <div className="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <Heading
                        title={user.name}
                        description={__('User details')}
                    />

                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={index()}>
                                <ArrowLeft />
                                {__('Back')}
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={edit(user.id)}>
                                <Pencil />
                                {__('Edit')}
                            </Link>
                        </Button>
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    disabled={!user.can_delete}
                                >
                                    <Trash2 />
                                    {__('Delete')}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle className="flex items-center gap-2">
                                    <UserRound className="size-5 text-muted-foreground" />
                                    <span>{__('Delete user?')}</span>
                                </DialogTitle>
                                <DialogDescription>
                                    {__(
                                        'This user account will be permanently deleted.',
                                    )}
                                </DialogDescription>

                                <Form
                                    {...destroy.form(user.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button variant="secondary">
                                                    {__('Cancel')}
                                                </Button>
                                            </DialogClose>

                                            <Button
                                                variant="destructive"
                                                disabled={processing}
                                                asChild
                                            >
                                                <button type="submit">
                                                    <Trash2 />
                                                    {__('Delete')}
                                                </button>
                                            </Button>
                                        </DialogFooter>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <Card className="max-w-3xl rounded-xl">
                    <CardHeader>
                        <CardTitle>{__('Account details')}</CardTitle>
                        <CardDescription>
                            {__('Update account details and admin access.')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-6 sm:grid-cols-2">
                            <DetailItem icon={UserRound} label={__('Name')}>
                                {user.name}
                            </DetailItem>
                            <DetailItem icon={Mail} label={__('Email address')}>
                                {user.email}
                            </DetailItem>
                            <DetailItem icon={ShieldCheck} label={__('Roles')}>
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
                            </DetailItem>
                            <DetailItem
                                icon={CalendarClock}
                                label={__('Created at')}
                            >
                                {formatDate(user.created_at)}
                            </DetailItem>
                            <DetailItem
                                icon={CalendarClock}
                                label={__('Updated at')}
                            >
                                {formatDate(user.updated_at)}
                            </DetailItem>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
