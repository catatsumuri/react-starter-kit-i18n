import { Link, usePage, usePoll } from '@inertiajs/react';
import { lang } from '@erag/lang-sync-inertia/react';
import { Bell } from 'lucide-react';
import MarkNotificationAsReadController from '@/actions/App/Http/Controllers/MarkNotificationAsReadController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

function formatDateTime(value: string | null): string {
    if (value === null) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export function NotificationButton() {
    const { __ } = lang();
    const { notifications } = usePage().props;

    usePoll(15_000, {
        only: ['notifications'],
    });

    return (
        <DropdownMenu>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="group relative size-9 cursor-pointer"
                            aria-label={__('Notifications')}
                        >
                            <Bell className="size-5 opacity-80 group-hover:opacity-100" />
                            {notifications.unread_count > 0 && (
                                <span className="absolute -top-0.5 -right-0.5 flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-4 font-semibold text-white">
                                    {notifications.unread_count > 99
                                        ? '99+'
                                        : notifications.unread_count}
                                </span>
                            )}
                        </Button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent>
                    <p>{__('Notifications')}</p>
                </TooltipContent>
            </Tooltip>
            <DropdownMenuContent
                align="end"
                className="max-h-[min(28rem,calc(100vh-5rem))] w-80 max-w-[calc(100vw-2rem)] overflow-y-auto p-0"
            >
                <DropdownMenuLabel className="px-3 py-2.5">
                    {__('Notifications')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {notifications.items.length === 0 ? (
                    <div className="px-3 py-8 text-center text-sm text-muted-foreground">
                        {__('No notifications yet.')}
                    </div>
                ) : (
                    <div className="p-1">
                        {notifications.items.map((notification) => (
                            <DropdownMenuItem
                                key={notification.id}
                                asChild
                                className="p-0"
                            >
                                <Link
                                    href={MarkNotificationAsReadController(
                                        notification.id,
                                    )}
                                    as="button"
                                    className={cn(
                                        'flex w-full cursor-pointer items-start gap-3 rounded-sm px-3 py-2.5 text-left',
                                        notification.read_at === null &&
                                            'bg-accent/50',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'mt-1.5 size-2 shrink-0 rounded-full',
                                            notification.read_at === null
                                                ? 'bg-primary'
                                                : 'bg-transparent',
                                        )}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-sm font-medium">
                                            {notification.title}
                                        </span>
                                        <span className="mt-0.5 line-clamp-2 block text-xs text-muted-foreground">
                                            {notification.body}
                                        </span>
                                        {notification.created_at !== null && (
                                            <time
                                                dateTime={
                                                    notification.created_at
                                                }
                                                className="mt-1 block text-[11px] text-muted-foreground"
                                            >
                                                {formatDateTime(
                                                    notification.created_at,
                                                )}
                                            </time>
                                        )}
                                    </span>
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </div>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
