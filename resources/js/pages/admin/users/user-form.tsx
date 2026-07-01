import { Form, Link } from '@inertiajs/react';
import { lang } from '@erag/lang-sync-inertia/react';
import { KeyRound, Mail, Save, ShieldCheck, UserRound, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, show, store, update } from '@/routes/admin/users';

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
};

type UserFormProps = {
    user?: ManagedUser;
};

export default function UserForm({ user }: UserFormProps) {
    const { __ } = lang();
    const [isAdmin, setIsAdmin] = useState(user?.is_admin ?? false);
    const isEditing = user !== undefined;

    return (
        <Form
            {...(isEditing ? update.form(user.id) : store.form())}
            options={{
                preserveScroll: true,
            }}
            className="max-w-2xl space-y-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label
                            htmlFor="name"
                            className="flex items-center gap-2"
                        >
                            <UserRound className="size-4 text-muted-foreground" />
                            {__('Name')}
                        </Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={user?.name ?? ''}
                            required
                            autoComplete="name"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label
                            htmlFor="email"
                            className="flex items-center gap-2"
                        >
                            <Mail className="size-4 text-muted-foreground" />
                            {__('Email address')}
                        </Label>
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            defaultValue={user?.email ?? ''}
                            required
                            autoComplete="username"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label
                            htmlFor="password"
                            className="flex items-center gap-2"
                        >
                            <KeyRound className="size-4 text-muted-foreground" />
                            {isEditing ? __('New password') : __('Password')}
                        </Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            required={!isEditing}
                            autoComplete="new-password"
                        />
                        {isEditing && (
                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'Leave the password fields blank to keep the current password.',
                                )}
                            </p>
                        )}
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label
                            htmlFor="password_confirmation"
                            className="flex items-center gap-2"
                        >
                            <KeyRound className="size-4 text-muted-foreground" />
                            {__('Confirm password')}
                        </Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            required={!isEditing}
                            autoComplete="new-password"
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <div className="flex items-start gap-3 rounded-lg border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <input
                            type="hidden"
                            name="is_admin"
                            value={isAdmin ? '1' : '0'}
                        />
                        <Checkbox
                            id="is_admin"
                            checked={isAdmin}
                            onCheckedChange={(checked) =>
                                setIsAdmin(checked === true)
                            }
                        />
                        <div className="grid gap-1.5">
                            <Label
                                htmlFor="is_admin"
                                className="flex items-center gap-2"
                            >
                                <ShieldCheck className="size-4 text-muted-foreground" />
                                {__('Admin role')}
                            </Label>
                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'Allow this user to access the admin area.',
                                )}
                            </p>
                            <InputError message={errors.is_admin} />
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button disabled={processing}>
                            <Save />
                            {isEditing ? __('Save') : __('Create')}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={isEditing ? show(user.id) : index()}>
                                <X />
                                {__('Cancel')}
                            </Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
