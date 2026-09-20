import { Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

type Survey = {
    id: number;
    answer: string;
};

type WelcomeProps = {
    surveys: Survey[];
};

export default function Welcome({ surveys }: WelcomeProps) {
    return (
        <>
            <Head title="アンケート分析" />

            <main className="min-h-screen bg-background px-4 py-10 text-foreground sm:px-6">
                <section className="mx-auto flex max-w-3xl flex-col gap-8">
                    <header className="flex items-start justify-between gap-4">
                        <div className="flex flex-col gap-2">
                            <h1 className="text-3xl font-semibold">
                                アンケート分析
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                アンケート回答 {surveys.length}件
                            </p>
                        </div>

                        <Button
                            type="button"
                            disabled
                            title="分析機能は準備中です"
                        >
                            分析
                        </Button>
                    </header>

                    <div className="overflow-hidden rounded-lg border">
                        {surveys.map((survey) => (
                            <article
                                key={survey.id}
                                className="grid grid-cols-[3rem_1fr] border-b last:border-b-0"
                            >
                                <div className="border-r px-3 py-4 text-sm text-muted-foreground">
                                    {survey.id}
                                </div>
                                <p className="px-4 py-4 text-sm leading-6">
                                    {survey.answer}
                                </p>
                            </article>
                        ))}
                    </div>
                </section>
            </main>
        </>
    );
}
