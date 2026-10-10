import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Clock3, Laptop, MapPin, ShieldCheck } from 'lucide-react';
import { lang } from '@erag/lang-sync-inertia/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/security-activity';

type Activity = {
    id: number;
    event: string;
    occurred_at: string | null;
    auth_method: string | null;
    device: string;
    platform: string | null;
    ip_address: string;
};

type PaginatedActivities = {
    data: Activity[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const eventLabels: Record<string, string> = {
    'authentication.login_succeeded': 'Signed in',
    'authentication.two_factor_enabled': 'Two-factor authentication enabled',
    'authentication.two_factor_confirmed':
        'Two-factor authentication confirmed',
    'authentication.two_factor_disabled': 'Two-factor authentication disabled',
    'authentication.recovery_codes_regenerated': 'Recovery codes regenerated',
    'authentication.passkey_registered': 'Passkey registered',
    'authentication.passkey_deleted': 'Passkey deleted',
    'account.password_changed': 'Password changed',
    'account.email_changed': 'Email address changed',
    'account.email_verified': 'Email address verified',
    'session.revoked': 'Session access revoked',
    'account.deleted': 'Account deleted',
};

const methodLabels: Record<string, string> = {
    password: 'Password',
    totp: 'Authenticator code',
    recovery_code: 'Recovery code',
    passkey: 'Passkey',
    reset_token: 'Password reset link',
};

export default function SecurityActivity({
    activities,
}: {
    activities: PaginatedActivities;
}) {
    const { __ } = lang();

    setLayoutProps({
        breadcrumbs: [{ title: __('Security activity'), href: index() }],
    });

    return (
        <>
            <Head title={__('Security activity')} />
            <h1 className="sr-only">{__('Security activity')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={__('Security activity')}
                    description={__(
                        'Review recent sign-ins and important security changes to your account.',
                    )}
                />

                <p className="text-sm text-muted-foreground">
                    {__(
                        'Browser, platform, and IP address labels are estimates and are not proof of identity.',
                    )}
                </p>

                {activities.data.length === 0 ? (
                    <div className="rounded-lg border p-6 text-center text-sm text-muted-foreground">
                        {__('No security activity has been recorded yet.')}
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-lg border">
                        {activities.data.map((activity) => {
                            const device = activity.platform
                                ? __(':browser on :platform', {
                                      browser: __(activity.device),
                                      platform: __(activity.platform),
                                  })
                                : __(activity.device);
                            const occurredAt = activity.occurred_at
                                ? new Intl.DateTimeFormat(undefined, {
                                      dateStyle: 'medium',
                                      timeStyle: 'short',
                                  }).format(new Date(activity.occurred_at))
                                : __('Unknown');

                            return (
                                <div
                                    key={activity.id}
                                    className="flex gap-4 border-b p-4 last:border-b-0"
                                >
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                        <ShieldCheck className="size-5 text-muted-foreground" />
                                    </div>
                                    <div className="min-w-0 space-y-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium">
                                                {__(
                                                    eventLabels[
                                                        activity.event
                                                    ] ?? 'Security activity',
                                                )}
                                            </p>
                                            {activity.auth_method && (
                                                <span className="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                    {__(
                                                        methodLabels[
                                                            activity.auth_method
                                                        ] ??
                                                            activity.auth_method,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                            <span className="inline-flex items-center gap-1.5">
                                                <Clock3 className="size-3.5" />
                                                {occurredAt}
                                            </span>
                                            <span className="inline-flex items-center gap-1.5">
                                                <Laptop className="size-3.5" />
                                                {device}
                                            </span>
                                            <span className="inline-flex items-center gap-1.5">
                                                <MapPin className="size-3.5" />
                                                {activity.ip_address ===
                                                'Unknown'
                                                    ? __('Unknown')
                                                    : activity.ip_address}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                {activities.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!activities.prev_page_url}
                            asChild={Boolean(activities.prev_page_url)}
                        >
                            {activities.prev_page_url ? (
                                <Link
                                    href={activities.prev_page_url}
                                    preserveScroll
                                >
                                    {__('Previous')}
                                </Link>
                            ) : (
                                <span>{__('Previous')}</span>
                            )}
                        </Button>
                        <span className="text-sm text-muted-foreground">
                            {__('Page :current of :last', {
                                current: activities.current_page,
                                last: activities.last_page,
                            })}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!activities.next_page_url}
                            asChild={Boolean(activities.next_page_url)}
                        >
                            {activities.next_page_url ? (
                                <Link
                                    href={activities.next_page_url}
                                    preserveScroll
                                >
                                    {__('Next')}
                                </Link>
                            ) : (
                                <span>{__('Next')}</span>
                            )}
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}
