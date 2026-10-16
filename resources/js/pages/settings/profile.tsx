import { Form, Head, setLayoutProps, usePage } from '@inertiajs/react';
/* @chisel-email-verification */
import { Link } from '@inertiajs/react';
/* @end-chisel-email-verification */
import { lang } from '@erag/lang-sync-inertia/react';
import { useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';
/* @chisel-email-verification */
import { send } from '@/routes/verification';
/* @end-chisel-email-verification */

type PageProps = {
    auth: Auth;
};

export default function Profile(
    /* @chisel-email-verification */
    {
        mustVerifyEmail,
        status,
    }: {
        mustVerifyEmail: boolean;
        status?: string;
    },
    /* @end-chisel-email-verification */
) {
    const { auth } = usePage<PageProps>().props;
    const { __ } = lang();
    const [isEmailChanged, setIsEmailChanged] = useState(false);
    const [passwordInputKey, setPasswordInputKey] = useState(0);

    setLayoutProps({
        breadcrumbs: [
            {
                title: __('Profile settings'),
                href: edit(),
            },
        ],
    });

    return (
        <>
            <Head title={__('Profile settings')} />

            <h1 className="sr-only">{__('Profile settings')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={__('Profile')}
                    description={__('Update your name and email address')}
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnSuccess={['current_password']}
                    resetOnError={['current_password']}
                    onSuccess={() => setIsEmailChanged(false)}
                    onError={() => setPasswordInputKey((key) => key + 1)}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">{__('Name')}</Label>

                                <Input
                                    id="name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    placeholder={__('Full name')}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {__('Email address')}
                                </Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.email}
                                    onChange={(event) =>
                                        setIsEmailChanged(
                                            event.target.value !==
                                                auth.user.email,
                                        )
                                    }
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder={__('Email address')}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {isEmailChanged && (
                                <div className="grid gap-2">
                                    <Label htmlFor="current_password">
                                        {__('Current password')}
                                    </Label>

                                    <PasswordInput
                                        key={passwordInputKey}
                                        id="current_password"
                                        className="mt-1 block w-full"
                                        name="current_password"
                                        required
                                        autoComplete="current-password"
                                        placeholder={__('Current password')}
                                    />

                                    <p className="text-sm text-muted-foreground">
                                        {__(
                                            'Enter your current password to confirm the email address change.',
                                        )}
                                    </p>

                                    <InputError
                                        className="mt-2"
                                        message={errors.current_password}
                                    />
                                </div>
                            )}

                            {/* @chisel-email-verification */}
                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            {__(
                                                'Your email address is unverified.',
                                            )}{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                {__(
                                                    'Click here to re-send the verification email.',
                                                )}
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                {__(
                                                    'A new verification link has been sent to your email address.',
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            {/* @end-chisel-email-verification */}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {__('Save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}
