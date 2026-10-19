import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { Clock3, Laptop, MapPin, Trash2 } from 'lucide-react';
import { lang } from '@erag/lang-sync-inertia/react';
import SessionController from '@/actions/App/Http/Controllers/Settings/SessionController';
import Heading from '@/components/heading';
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
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/sessions';

type Session = {
    fingerprint: string;
    device: string;
    platform: string | null;
    ip_address: string;
    last_activity: string;
    is_current: boolean;
};

type PaginatedSessions = {
    data: Session[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

type Props = {
    available: boolean;
    currentSession: Session | null;
    otherSessions: PaginatedSessions | null;
};

function SessionDetails({ session }: { session: Session }) {
    const { __ } = lang();
    const lastActivity = new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(session.last_activity));
    const device = session.platform
        ? __(':browser on :platform', {
              browser: __(session.device),
              platform: __(session.platform),
          })
        : __(session.device);

    return (
        <div className="min-w-0 space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                <p className="font-medium">{device}</p>
                {session.is_current && (
                    <span className="rounded-md bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
                        {__('Current session')}
                    </span>
                )}
            </div>
            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                    <MapPin className="size-3.5" />
                    {session.ip_address === 'Unknown'
                        ? __('Unknown')
                        : session.ip_address}
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <Clock3 className="size-3.5" />
                    {__('Last active :time', { time: lastActivity })}
                </span>
            </div>
        </div>
    );
}

function OtherSession({ session }: { session: Session }) {
    const { __ } = lang();

    return (
        <div className="flex items-center justify-between gap-4 border-b p-4 last:border-b-0">
            <div className="flex min-w-0 items-center gap-4">
                <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                    <Laptop className="size-5 text-muted-foreground" />
                </div>
                <SessionDetails session={session} />
            </div>

            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive"
                    >
                        <Trash2 className="size-4" />
                        <span className="sr-only">{__('Revoke session')}</span>
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>{__('Revoke this session?')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'This removes the selected session. A browser that kept you signed in with "Remember me" can sign in again. If you suspect someone else has access, use "Revoke other sessions" instead.',
                        )}
                    </DialogDescription>
                    <Form
                        {...SessionController.destroy.form(session.fingerprint)}
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
                                >
                                    {processing && <Spinner />}
                                    {__('Revoke session')}
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default function Sessions({
    available,
    currentSession,
    otherSessions,
}: Props) {
    const { __ } = lang();

    setLayoutProps({
        breadcrumbs: [
            {
                title: __('Sessions'),
                href: index(),
            },
        ],
    });

    return (
        <>
            <Head title={__('Sessions')} />

            <h1 className="sr-only">{__('Sessions')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={__('Active sessions')}
                    description={__(
                        'Review browsers signed in to your account and revoke access you no longer need.',
                    )}
                />

                {!available ? (
                    <div className="rounded-lg border bg-muted/40 p-4 text-sm text-muted-foreground">
                        {__(
                            'Session management is not available with the configured session storage. Please try again later or contact the administrator.',
                        )}
                    </div>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            {__(
                                'Browser and location labels are estimates. A copied session cookie may appear as the same session.',
                            )}
                        </p>

                        {currentSession && (
                            <div className="rounded-lg border p-4">
                                <div className="flex items-center gap-4">
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                        <Laptop className="size-5 text-muted-foreground" />
                                    </div>
                                    <SessionDetails session={currentSession} />
                                </div>
                            </div>
                        )}

                        <div className="space-y-3">
                            <h2 className="text-sm font-medium">
                                {__('Other sessions')}
                            </h2>
                            {otherSessions?.data.length ? (
                                <div className="overflow-hidden rounded-lg border">
                                    {otherSessions.data.map((session) => (
                                        <OtherSession
                                            key={session.fingerprint}
                                            session={session}
                                        />
                                    ))}
                                </div>
                            ) : (
                                <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                                    {__('There are no other active sessions.')}
                                </p>
                            )}

                            {otherSessions && otherSessions.last_page > 1 && (
                                <div className="flex items-center justify-between">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={!otherSessions.prev_page_url}
                                        asChild={Boolean(
                                            otherSessions.prev_page_url,
                                        )}
                                    >
                                        {otherSessions.prev_page_url ? (
                                            <Link
                                                href={
                                                    otherSessions.prev_page_url
                                                }
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
                                            current: otherSessions.current_page,
                                            last: otherSessions.last_page,
                                        })}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={!otherSessions.next_page_url}
                                        asChild={Boolean(
                                            otherSessions.next_page_url,
                                        )}
                                    >
                                        {otherSessions.next_page_url ? (
                                            <Link
                                                href={
                                                    otherSessions.next_page_url
                                                }
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
                )}
            </div>

            {available && (
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={__('Revoke sessions')}
                        description={__(
                            'Sign out other browsers or every browser connected to your account.',
                        )}
                    />

                    <div className="flex flex-wrap gap-3">
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="outline">
                                    {__('Revoke other sessions')}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    {__('Revoke all other sessions?')}
                                </DialogTitle>
                                <DialogDescription>
                                    {__(
                                        'Every other browser will be signed out, including those signed in with "Remember me". This browser will remain signed in.',
                                    )}
                                </DialogDescription>
                                <Form
                                    {...SessionController.destroyOthers.form()}
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
                                            >
                                                {processing && <Spinner />}
                                                {__('Revoke other sessions')}
                                            </Button>
                                        </DialogFooter>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button variant="destructive">
                                    {__('Revoke all sessions')}
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>
                                    {__('Revoke every session?')}
                                </DialogTitle>
                                <DialogDescription>
                                    {__(
                                        'All browsers, including this one, will be signed out. You will need to sign in again.',
                                    )}
                                </DialogDescription>
                                <Form {...SessionController.destroyAll.form()}>
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
                                            >
                                                {processing && <Spinner />}
                                                {__('Revoke all sessions')}
                                            </Button>
                                        </DialogFooter>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>
            )}
        </>
    );
}
