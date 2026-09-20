import { Head } from '@inertiajs/react';

export default function Welcome() {
    return (
        <>
            <Head title="Playground" />

            <main className="flex min-h-screen items-center justify-center bg-background text-foreground">
                <div className="text-center">
                    <h1 className="text-4xl font-semibold">WIP</h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Playground
                    </p>
                </div>
            </main>
        </>
    );
}
