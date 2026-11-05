// Preview-only Inertia stand-ins for the same React pages used by Laravel.
import {
    useEffect,
    useState,
    type AnchorHTMLAttributes,
    type FormHTMLAttributes,
    type ReactNode,
} from 'react';

type PreviewFormProps = Omit<
    FormHTMLAttributes<HTMLFormElement>,
    'children'
> & {
    children: (state: {
        processing: boolean;
        errors: Record<string, string>;
    }) => ReactNode;
    resetOnSuccess?: boolean;
    options?: { preserveScroll?: boolean };
};

export function Form({
    children,
    resetOnSuccess: _reset,
    options: _options,
    ...props
}: PreviewFormProps) {
    const [errors, setErrors] = useState<Record<string, string>>({});

    return (
        <form
            {...props}
            onSubmit={(event) => {
                event.preventDefault();
                setErrors({
                    form: 'Please use the Laravel site to submit this form. This preview does not save submissions.',
                });
            }}
        >
            {children({ processing: false, errors })}
        </form>
    );
}

export function Head({ title }: { title?: string }) {
    useEffect(() => {
        if (title) document.title = title;
    }, [title]);
    return null;
}

type LinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    href: string;
    prefetch?: boolean;
};

export function Link({
    href,
    children,
    prefetch: _prefetch,
    ...props
}: LinkProps) {
    return (
        <a href={href} {...props}>
            {children}
        </a>
    );
}

export function usePage() {
    return {
        props: {
            auth: {
                user: null as null | { name: string },
            },
        },
    };
}
